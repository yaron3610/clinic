<?php
/**
 * Admin screens: the submissions list, a read-only view of each submission,
 * secure file downloads and "process again / resend".
 */

defined( 'ABSPATH' ) || exit;

class Shoval_Intake_Admin {

	private static $statuses = array(
		'received'   => array( 'התקבל', '#2271b1' ),
		'processing' => array( 'בעיבוד', '#dba617' ),
		'done'       => array( 'נשלח למייל', '#00a32a' ),
		'failed'     => array( 'נכשל – ינוסה שוב', '#d63638' ),
	);

	public static function register() {
		$pt = Shoval_Intake_Storage::POST_TYPE;
		add_filter( "manage_{$pt}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$pt}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( "bulk_actions-edit-{$pt}", array( __CLASS__, 'bulk_actions' ) );
		add_action( "add_meta_boxes_{$pt}", array( __CLASS__, 'meta_boxes' ) );
		add_action( 'admin_post_shoval_intake_download', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_shoval_intake_reprocess', array( __CLASS__, 'reprocess' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function columns( $columns ) {
		return array(
			'cb'          => $columns['cb'],
			'title'       => 'מטופל/ת',
			'si_received' => 'התקבל',
			'si_status'   => 'סטטוס',
			'si_files'    => 'קבצים',
			'si_docx'     => 'טיוטת סיכום',
		);
	}

	public static function column( $column, $post_id ) {
		switch ( $column ) {
			case 'si_received':
				$t = (int) get_post_meta( $post_id, '_si_received_at', true );
				echo esc_html( $t ? wp_date( 'd/m/Y H:i', $t, new DateTimeZone( 'Asia/Jerusalem' ) ) : '' );
				break;
			case 'si_status':
				self::status_badge( $post_id );
				break;
			case 'si_files':
				$n = count( Shoval_Intake_Storage::get_files( $post_id ) );
				echo $n ? esc_html( $n ) : '&ndash;';
				break;
			case 'si_docx':
				if ( get_post_meta( $post_id, '_si_docx', true ) ) {
					echo '<a class="button button-small" href="' . esc_url( self::download_url( $post_id, 'docx' ) ) . '">הורדת Word</a>';
				} else {
					echo '&ndash;';
				}
				break;
		}
	}

	private static function status_badge( $post_id ) {
		$status = Shoval_Intake_Storage::status( $post_id );
		$info   = isset( self::$statuses[ $status ] ) ? self::$statuses[ $status ] : array( $status, '#646970' );
		printf( '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:%1$s;color:#fff;font-size:12px;">%2$s</span>', esc_attr( $info[1] ), esc_html( $info[0] ) );
	}

	public static function row_actions( $actions, $post ) {
		if ( $post->post_type !== Shoval_Intake_Storage::POST_TYPE ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'], $actions['view'] );
		if ( isset( $actions['edit'] ) ) {
			$actions['edit'] = '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">צפייה</a>';
		}
		return $actions;
	}

	public static function bulk_actions( $actions ) {
		unset( $actions['edit'] );
		return $actions;
	}

	public static function meta_boxes() {
		remove_meta_box( 'submitdiv', Shoval_Intake_Storage::POST_TYPE, 'side' );
		remove_meta_box( 'slugdiv', Shoval_Intake_Storage::POST_TYPE, 'normal' );
		add_meta_box( 'shoval_intake_details', 'פרטי השאלון', array( __CLASS__, 'details_box' ), Shoval_Intake_Storage::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'shoval_intake_actions', 'טיוטת סיכום ומייל', array( __CLASS__, 'actions_box' ), Shoval_Intake_Storage::POST_TYPE, 'side', 'high' );
	}

	private static function row( $label, $value ) {
		echo '<tr><th scope="row" style="width:200px;">' . esc_html( $label ) . '</th><td style="white-space:pre-line;">' . ( $value === '' ? '&ndash;' : esc_html( $value ) ) . '</td></tr>';
	}

	public static function details_box( $post ) {
		$d = Shoval_Intake_Storage::get_data( $post->ID );
		$g = function ( $k ) use ( $d ) {
			return isset( $d[ $k ] ) && is_scalar( $d[ $k ] ) ? (string) $d[ $k ] : '';
		};

		echo '<table class="form-table" role="presentation" dir="rtl"><tbody>';
		self::row( 'שם', trim( $g( 'firstName' ) . ' ' . $g( 'lastName' ) ) );
		self::row( 'תעודת זהות', $g( 'idNumber' ) );
		self::row( 'מין', $g( 'gender' ) );
		self::row( 'גיל', $g( 'age' ) );
		self::row( 'יישוב', $g( 'city' ) );
		self::row( 'הורים', Shoval_Intake_Letter::parents_label( $d ) );
		self::row( 'מספר ילדים במשפחה', $g( 'childrenCount' ) );
		self::row( 'מיקום במשפחה', Shoval_Intake_Letter::family_position_label( $d ) );
		self::row( 'טלפון', $g( 'phone' ) );
		self::row( 'דוא"ל', $g( 'email' ) );
		self::row( 'סיבת הפנייה', $g( 'referralReason' ) );

		$meds = array();
		if ( ! empty( $d['noCurrentMedications'] ) ) {
			$meds[] = 'אין טיפול תרופתי קבוע';
		} elseif ( ! empty( $d['currentMedications'] ) ) {
			foreach ( $d['currentMedications'] as $m ) {
				$meds[] = trim( $m['name'] . ( $m['dosage'] !== '' ? ' – ' . $m['dosage'] : '' ) );
			}
		}
		self::row( 'תרופות כעת', implode( "\n", $meds ) );

		$past = array();
		if ( ! empty( $d['noPastMedications'] ) ) {
			$past[] = 'אין טיפולים תרופתיים קודמים';
		} elseif ( ! empty( $d['pastMedications'] ) ) {
			foreach ( $d['pastMedications'] as $m ) {
				$past[] = implode( ' | ', array_filter( array( $m['medication'], $m['startYear'], $m['duration'], $m['administration'], $m['maxDosage'] ) ) );
			}
		}
		self::row( 'טיפולים תרופתיים בעבר', implode( "\n", $past ) );
		self::row( 'מסמכים ואבחונים קודמים', $g( 'previousDocuments' ) );
		self::row( 'הסכמה לשימוש במידע', ! empty( $d['consent'] ) ? 'אושרה' : '' );
		echo '</tbody></table>';

		$files = Shoval_Intake_Storage::get_files( $post->ID );
		if ( $files ) {
			echo '<h3>קבצים שצורפו</h3><ul>';
			foreach ( $files as $i => $f ) {
				echo '<li><a href="' . esc_url( self::download_url( $post->ID, (string) $i ) ) . '">' . esc_html( $f['name'] ) . '</a> (' . esc_html( size_format( $f['size'] ) ) . ')</li>';
			}
			echo '</ul>';
		}
	}

	public static function actions_box( $post ) {
		echo '<p>סטטוס: ';
		self::status_badge( $post->ID );
		echo '</p>';

		$error = (string) get_post_meta( $post->ID, '_si_error', true );
		if ( $error !== '' ) {
			echo '<p style="color:#d63638;">' . esc_html( $error ) . '</p>';
		}
		$emailed = (int) get_post_meta( $post->ID, '_si_emailed_at', true );
		if ( $emailed ) {
			echo '<p>נשלח למייל: ' . esc_html( wp_date( 'd/m/Y H:i', $emailed, new DateTimeZone( 'Asia/Jerusalem' ) ) ) . ' (' . esc_html( (string) get_post_meta( $post->ID, '_si_emailed_via', true ) ) . ')</p>';
		}

		if ( get_post_meta( $post->ID, '_si_docx', true ) ) {
			echo '<p><a class="button button-primary" href="' . esc_url( self::download_url( $post->ID, 'docx' ) ) . '">הורדת טיוטת הסיכום (Word)</a></p>';
		}

		$url = wp_nonce_url( admin_url( 'admin-post.php?action=shoval_intake_reprocess&post=' . $post->ID ), 'shoval_intake_reprocess_' . $post->ID );
		echo '<p><a class="button" href="' . esc_url( $url . '&regenerate=0' ) . '">שליחה חוזרת למייל</a></p>';
		echo '<p><a class="button" href="' . esc_url( $url . '&regenerate=1' ) . '" onclick="return confirm(\'להפיק את הטיוטה מחדש ולשלוח שוב?\');">הפקה מחדש ושליחה</a></p>';
	}

	private static function download_url( $post_id, $file ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=shoval_intake_download&post=' . (int) $post_id . '&file=' . rawurlencode( $file ) ),
			'shoval_intake_download_' . (int) $post_id
		);
	}

	public static function download() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || get_post_type( $post_id ) !== Shoval_Intake_Storage::POST_TYPE ) {
			wp_die( 'אין הרשאה.', 403 );
		}
		check_admin_referer( 'shoval_intake_download_' . $post_id );

		$which = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
		if ( $which === 'docx' ) {
			$stored = (string) get_post_meta( $post_id, '_si_docx', true );
			$label  = Shoval_Intake_Email::docx_filename( (array) Shoval_Intake_Storage::get_summary( $post_id ) );
			$type   = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		} else {
			$files = Shoval_Intake_Storage::get_files( $post_id );
			$index = ctype_digit( $which ) ? (int) $which : -1;
			if ( ! isset( $files[ $index ] ) ) {
				wp_die( 'הקובץ לא נמצא.', 404 );
			}
			$stored = $files[ $index ]['file'];
			$label  = $files[ $index ]['name'];
			$type   = $files[ $index ]['type'];
		}

		$path = Shoval_Intake_Storage::file_path( $post_id, $stored );
		if ( $path === '' || ! is_readable( $path ) ) {
			wp_die( 'הקובץ לא נמצא.', 404 );
		}

		$label = preg_replace( '/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', $label );
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Length: ' . filesize( $path ) );
		$ascii = 'intake-file.' . strtolower( pathinfo( $label, PATHINFO_EXTENSION ) );
		header( 'Content-Disposition: attachment; filename="' . $ascii . "\"; filename*=UTF-8''" . rawurlencode( $label ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path );
		exit;
	}

	public static function reprocess() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || get_post_type( $post_id ) !== Shoval_Intake_Storage::POST_TYPE ) {
			wp_die( 'אין הרשאה.', 403 );
		}
		check_admin_referer( 'shoval_intake_reprocess_' . $post_id );

		delete_transient( 'shoval_intake_lock_' . $post_id );
		Shoval_Intake_Processor::process( $post_id, ! empty( $_GET['regenerate'] ) );

		$ok = Shoval_Intake_Storage::status( $post_id ) === 'done';
		wp_safe_redirect( add_query_arg( 'si_reprocessed', $ok ? 'ok' : 'fail', get_edit_post_link( $post_id, 'url' ) ) );
		exit;
	}

	public static function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->post_type !== Shoval_Intake_Storage::POST_TYPE ) {
			return;
		}
		if ( isset( $_GET['si_reprocessed'] ) ) {
			$ok = $_GET['si_reprocessed'] === 'ok';
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p>' . ( $ok ? 'הטיוטה נשלחה שוב למייל.' : 'השליחה נכשלה – ראו את הודעת השגיאה בתיבת "טיוטת סיכום ומייל".' ) . '</p></div>';
		}
		if ( ! Shoval_Intake_Settings::report_emails() ) {
			echo '<div class="notice notice-warning"><p>לא הוגדר מייל לקבלת הסיכומים. <a href="' . esc_url( admin_url( 'edit.php?post_type=' . Shoval_Intake_Storage::POST_TYPE . '&page=' . Shoval_Intake_Settings::PAGE ) ) . '">להגדרות</a></p></div>';
		}
	}
}
