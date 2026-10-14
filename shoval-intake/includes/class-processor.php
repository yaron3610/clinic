<?php
/**
 * Background pipeline for a saved submission: summary (AI / rules) -> Word -> e-mail.
 *
 * The patient gets an answer as soon as the questionnaire is saved. Processing
 * then runs right after the response is sent (PHP-FPM / LiteSpeed), or through
 * WP-Cron. An hourly job retries anything that did not finish.
 */

defined( 'ABSPATH' ) || exit;

class Shoval_Intake_Processor {

	const MAX_ATTEMPTS = 5;

	public static function register() {
		add_action( 'shoval_intake_process', array( __CLASS__, 'process' ) );
		add_action( 'shoval_intake_hourly', array( __CLASS__, 'retry_pending' ) );
		add_action( 'shoval_intake_daily', array( 'Shoval_Intake_Storage', 'cleanup_expired' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( 'shoval_intake_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'shoval_intake_hourly' );
		}
		if ( ! wp_next_scheduled( 'shoval_intake_daily' ) ) {
			wp_schedule_event( time() + 600, 'daily', 'shoval_intake_daily' );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( 'shoval_intake_hourly' );
		wp_clear_scheduled_hook( 'shoval_intake_daily' );
		wp_unschedule_hook( 'shoval_intake_process' );
	}

	/** Queues a new submission and, where the server allows it, processes it after the response is sent. */
	public static function queue( $post_id ) {
		wp_schedule_single_event( time(), 'shoval_intake_process', array( (int) $post_id ) );

		add_action(
			'shutdown',
			function () use ( $post_id ) {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				} elseif ( function_exists( 'litespeed_finish_request' ) ) {
					litespeed_finish_request();
				} else {
					spawn_cron();
					return;
				}
				ignore_user_abort( true );
				self::process( $post_id );
			},
			1000
		);
	}

	/**
	 * @param int  $post_id
	 * @param bool $force   Regenerate the summary even if one exists (admin "reprocess").
	 */
	public static function process( $post_id, $force = false ) {
		$post_id = (int) $post_id;
		if ( get_post_type( $post_id ) !== Shoval_Intake_Storage::POST_TYPE ) {
			return;
		}
		if ( ! $force && Shoval_Intake_Storage::status( $post_id ) === 'done' ) {
			return;
		}
		$lock = 'shoval_intake_lock_' . $post_id;
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$attempts = (int) get_post_meta( $post_id, '_si_attempts', true ) + 1;
		update_post_meta( $post_id, '_si_attempts', $attempts );
		Shoval_Intake_Storage::set_status( $post_id, 'processing' );

		try {
			$data    = Shoval_Intake_Storage::get_data( $post_id );
			$summary = $force ? null : Shoval_Intake_Storage::get_summary( $post_id );
			$docx    = '';

			$docx_name = (string) get_post_meta( $post_id, '_si_docx', true );
			if ( $summary && $docx_name !== '' ) {
				$path = Shoval_Intake_Storage::file_path( $post_id, $docx_name );
				$docx = $path !== '' && is_readable( $path ) ? (string) file_get_contents( $path ) : '';
			}

			if ( ! $summary || $docx === '' ) {
				$built   = Shoval_Intake_AI::build_summary( $data );
				$summary = $built['summary'];
				$docx    = Shoval_Intake_Docx::generate( $summary );

				$docx_name = Shoval_Intake_Storage::random_name( 'docx' );
				if ( ! Shoval_Intake_Storage::put_file( $post_id, $docx_name, $docx ) ) {
					throw new RuntimeException( 'could not save the Word file' );
				}
				$old = (string) get_post_meta( $post_id, '_si_docx', true );
				if ( $old !== '' && $old !== $docx_name ) {
					$old_path = Shoval_Intake_Storage::file_path( $post_id, $old );
					if ( $old_path !== '' && is_file( $old_path ) ) {
						unlink( $old_path );
					}
				}
				update_post_meta( $post_id, '_si_docx', $docx_name );
				update_post_meta( $post_id, '_si_summary', wp_slash( wp_json_encode( $summary, JSON_UNESCAPED_UNICODE ) ) );
				update_post_meta( $post_id, '_si_source', $built['source'] );
			}

			$result = Shoval_Intake_Email::send_report( $summary, $docx, $data, self::uploads( $post_id ), $post_id );
			if ( ! $result['ok'] ) {
				throw new RuntimeException( 'e-mail: ' . $result['error'] );
			}
			update_post_meta( $post_id, '_si_emailed_at', time() );
			update_post_meta( $post_id, '_si_emailed_via', $result['via'] );
			Shoval_Intake_Storage::set_status( $post_id, 'done' );
		} catch ( Throwable $e ) {
			Shoval_Intake_Storage::set_status( $post_id, 'failed', $e->getMessage() );
			error_log( 'Shoval Intake: processing submission #' . $post_id . ' failed (attempt ' . $attempts . '): ' . $e->getMessage() );
		}

		delete_transient( $lock );
	}

	/** Uploaded files of a submission with absolute paths. */
	public static function uploads( $post_id ) {
		$out = array();
		foreach ( Shoval_Intake_Storage::get_files( $post_id ) as $f ) {
			$path = Shoval_Intake_Storage::file_path( $post_id, $f['file'] );
			if ( $path !== '' && is_file( $path ) ) {
				$out[] = array(
					'name' => $f['name'],
					'path' => $path,
					'size' => (int) $f['size'],
					'type' => $f['type'],
				);
			}
		}
		return $out;
	}

	/** Hourly: finish anything that is stuck or failed (up to MAX_ATTEMPTS). */
	public static function retry_pending() {
		$ids = get_posts(
			array(
				'post_type'      => Shoval_Intake_Storage::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 20,
				'meta_query'     => array(
					array(
						'key'     => '_si_status',
						'value'   => array( 'received', 'processing', 'failed' ),
						'compare' => 'IN',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$received = (int) get_post_meta( $id, '_si_received_at', true );
			$attempts = (int) get_post_meta( $id, '_si_attempts', true );
			if ( $attempts < self::MAX_ATTEMPTS && time() - $received > 2 * MINUTE_IN_SECONDS ) {
				self::process( $id );
			}
		}
	}

	/** Settings screen "send test e-mail": a fictional patient, rules only. */
	public static function send_test_email() {
		$data = array(
			'firstName'            => 'ישראל',
			'lastName'             => 'ישראלי (בדיקה)',
			'gender'               => 'זכר',
			'idNumber'             => '000000018',
			'age'                  => '9',
			'city'                 => 'רמת השרון',
			'parentsNames'         => 'דנה ויוסי',
			'childrenCount'        => '3',
			'phone'                => '050-0000000',
			'email'                => 'test@example.com',
			'referralReason'       => 'בדיקת מערכת – קשיי קשב וריכוז בכיתה, אי שקט ופעלתנות יתר.',
			'currentMedications'   => array(),
			'noCurrentMedications' => true,
			'pastMedications'      => array(),
			'noPastMedications'    => true,
			'previousDocuments'    => 'אבחון דידקטי משנת 2025 (לדוגמה).',
		);
		try {
			$built = Shoval_Intake_AI::build_summary( $data, false );
			$docx  = Shoval_Intake_Docx::generate( $built['summary'] );
			return Shoval_Intake_Email::send_report( $built['summary'], $docx, $data, array(), 0 );
		} catch ( Throwable $e ) {
			return array( 'ok' => false, 'via' => '', 'error' => $e->getMessage() );
		}
	}
}
