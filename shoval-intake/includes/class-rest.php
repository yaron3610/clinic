<?php
/**
 * POST /wp-json/shoval-intake/v1/submit – receives the questionnaire (multipart, with files).
 */

defined( 'ABSPATH' ) || exit;

class Shoval_Intake_Rest {

	const NS               = 'shoval-intake/v1';
	const MAX_FILES        = 10;
	const MAX_TOTAL_BYTES  = 15728640; // 15 MB, as stated on the form.
	const RATE_LIMIT       = 5;        // submissions per IP per hour.
	const ALLOWED_TYPES    = array(
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	);

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function error( $message, $status = 400 ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => $message ), $status );
	}

	public static function submit( WP_REST_Request $request ) {
		// The REST server already unslashes body params, so they are used as-is below.
		$p = $request->get_body_params();

		// Bots fill the hidden "website" field: pretend success, store nothing.
		if ( ! empty( $p['website'] ) ) {
			return new WP_REST_Response( array( 'success' => true, 'message' => 'השאלון נשלח ונקלט בהצלחה במערכת המרפאה.' ), 200 );
		}
		if ( ! Shoval_Intake_Frontend::verify_form_token( isset( $p['_si_token'] ) ? (string) $p['_si_token'] : '' ) ) {
			return self::error( 'תוקף הטופס פג. נא לרענן את הדף ולשלוח שוב (הפרטים שמילאתם יישמרו אם תעתיקו אותם לפני הרענון).' );
		}

		$ip_key = 'shoval_intake_rl_' . md5( self::client_ip() . wp_salt( 'nonce' ) );
		$count  = (int) get_transient( $ip_key );
		if ( $count >= self::RATE_LIMIT ) {
			return self::error( 'נשלחו יותר מדי שאלונים מהחיבור הזה בשעה האחרונה. נא לנסות שוב מאוחר יותר או ליצור קשר עם המרפאה.', 429 );
		}

		$data = self::sanitize_form( $p );
		if ( is_wp_error( $data ) ) {
			return self::error( $data->get_error_message() );
		}

		$files = self::validate_files( $request->get_file_params() );
		if ( is_wp_error( $files ) ) {
			return self::error( $files->get_error_message() );
		}
		$data['uploadedFileNames'] = wp_list_pluck( $files, 'name' );

		$post_id = Shoval_Intake_Storage::create( $data, $files );
		if ( is_wp_error( $post_id ) ) {
			error_log( 'Shoval Intake: could not save a submission: ' . $post_id->get_error_message() );
			return self::error( 'אירעה שגיאה בשמירת השאלון. נא לנסות שוב או ליצור קשר עם המרפאה בטלפון.', 500 );
		}

		set_transient( $ip_key, $count + 1, HOUR_IN_SECONDS );
		Shoval_Intake_Processor::queue( $post_id );

		return new WP_REST_Response( array( 'success' => true, 'message' => 'השאלון נשלח ונקלט בהצלחה במערכת המרפאה.' ), 200 );
	}

	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	private static function text( array $p, $key, $max = 200 ) {
		$value = isset( $p[ $key ] ) && is_scalar( $p[ $key ] ) ? sanitize_text_field( (string) $p[ $key ] ) : '';
		return mb_substr( $value, 0, $max );
	}

	private static function textarea( array $p, $key, $max = 5000 ) {
		$value = isset( $p[ $key ] ) && is_scalar( $p[ $key ] ) ? sanitize_textarea_field( (string) $p[ $key ] ) : '';
		return mb_substr( $value, 0, $max );
	}

	/** Israeli ID check digit (5–9 digits, padded to 9). */
	public static function valid_israeli_id( $id ) {
		if ( ! preg_match( '/^\d{5,9}$/', $id ) ) {
			return false;
		}
		$id  = str_pad( $id, 9, '0', STR_PAD_LEFT );
		$sum = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$n    = (int) $id[ $i ] * ( $i % 2 + 1 );
			$sum += $n > 9 ? $n - 9 : $n;
		}
		return $sum % 10 === 0;
	}

	private static function json_rows( array $p, $key, array $fields ) {
		$raw  = isset( $p[ $key ] ) && is_string( $p[ $key ] ) ? json_decode( $p[ $key ], true ) : null;
		$rows = array();
		if ( ! is_array( $raw ) ) {
			return $rows;
		}
		foreach ( array_slice( $raw, 0, 20 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array();
			foreach ( $fields as $f ) {
				$clean[ $f ] = isset( $row[ $f ] ) && is_scalar( $row[ $f ] ) ? mb_substr( sanitize_text_field( (string) $row[ $f ] ), 0, 200 ) : '';
			}
			if ( $clean[ $fields[0] ] !== '' ) {
				$rows[] = $clean;
			}
		}
		return $rows;
	}

	/** @return array|WP_Error */
	public static function sanitize_form( array $p ) {
		$gender = self::text( $p, 'gender', 10 );
		$data   = array(
			'firstName'            => self::text( $p, 'firstName', 60 ),
			'lastName'             => self::text( $p, 'lastName', 60 ),
			'gender'               => in_array( $gender, array( 'זכר', 'נקבה', 'אחר' ), true ) ? $gender : '',
			'idNumber'             => preg_replace( '/\D/', '', self::text( $p, 'idNumber', 20 ) ),
			'age'                  => self::text( $p, 'age', 20 ),
			'city'                 => self::text( $p, 'city', 80 ),
			'motherName'           => self::text( $p, 'motherName', 60 ),
			'fatherName'           => self::text( $p, 'fatherName', 60 ),
			'childrenCount'        => '',
			'familyPosition'       => '',
			'phone'                => preg_replace( '/[^\d+\-\s()]/', '', self::text( $p, 'phone', 30 ) ),
			'email'                => sanitize_email( self::text( $p, 'email', 120 ) ),
			'referralReason'       => self::textarea( $p, 'referralReason' ),
			'currentMedications'   => array(),
			'noCurrentMedications' => ! empty( $p['noCurrentMedications'] ) && $p['noCurrentMedications'] !== 'false',
			'pastMedications'      => array(),
			'noPastMedications'    => ! empty( $p['noPastMedications'] ) && $p['noPastMedications'] !== 'false',
			'previousDocuments'    => self::textarea( $p, 'previousDocuments' ),
			'consent'              => ! empty( $p['consent'] ) && $p['consent'] !== 'false',
			'submittedAt'          => time(),
		);

		$children = self::text( $p, 'childrenCount', 3 );
		if ( ctype_digit( $children ) && (int) $children <= 20 ) {
			$data['childrenCount'] = (string) (int) $children;
		}
		$position = self::text( $p, 'familyPosition', 10 );
		if ( array_key_exists( $position, Shoval_Intake_Letter::FAMILY_POSITIONS ) ) {
			$data['familyPosition'] = $position;
		}
		if ( ! $data['noCurrentMedications'] ) {
			$data['currentMedications'] = self::json_rows( $p, 'currentMedications', array( 'name', 'dosage' ) );
		}
		if ( ! $data['noPastMedications'] ) {
			$data['pastMedications'] = self::json_rows( $p, 'pastMedications', array( 'medication', 'startYear', 'duration', 'administration', 'maxDosage' ) );
		}

		$required = array(
			'firstName'      => 'שם פרטי',
			'lastName'       => 'שם משפחה',
			'idNumber'       => 'תעודת זהות',
			'age'            => 'גיל',
			'phone'          => 'טלפון נייד',
			'email'          => 'דואר אלקטרוני',
			'referralReason' => 'סיבת הפנייה',
		);
		foreach ( $required as $key => $label ) {
			if ( $data[ $key ] === '' ) {
				return new WP_Error( 'si_required', 'נא למלא את השדה "' . $label . '".' );
			}
		}
		if ( ! self::valid_israeli_id( $data['idNumber'] ) ) {
			return new WP_Error( 'si_id', 'מספר תעודת הזהות אינו תקין. נא לבדוק את הספרות (כולל ספרת הביקורת).' );
		}
		if ( ! is_email( $data['email'] ) ) {
			return new WP_Error( 'si_email', 'כתובת הדואר האלקטרוני אינה תקינה.' );
		}
		if ( strlen( preg_replace( '/\D/', '', $data['phone'] ) ) < 9 ) {
			return new WP_Error( 'si_phone', 'מספר הטלפון אינו תקין.' );
		}
		if ( ! $data['consent'] ) {
			return new WP_Error( 'si_consent', 'יש לאשר את הסכמתך לשימוש במידע לפני השליחה.' );
		}

		return $data;
	}

	/** The original file name, kept readable (Hebrew included) – it is only ever displayed, never used as a path. */
	private static function display_name( $name ) {
		$name = wp_basename( str_replace( '\\', '/', $name ) );
		$name = preg_replace( '/[\/:*?"<>|\x00-\x1F\x7F]+/u', '_', $name );
		$name = trim( preg_replace( '/\s+/u', ' ', $name ), ' .' );
		if ( $name === '' || ! preg_match( '//u', $name ) ) {
			$name = 'file';
		}
		return mb_substr( $name, 0, 120 );
	}

	/**
	 * @param array $params $request->get_file_params()
	 * @return array|WP_Error Validated files: [ name, tmp_name, size, type, ext ].
	 */
	public static function validate_files( array $params ) {
		if ( empty( $params['uploadedFiles'] ) || ! is_array( $params['uploadedFiles']['name'] ) ) {
			return array();
		}
		$f     = $params['uploadedFiles'];
		$files = array();
		$total = 0;

		foreach ( $f['name'] as $i => $name ) {
			$error = (int) $f['error'][ $i ];
			if ( $error === UPLOAD_ERR_NO_FILE ) {
				continue;
			}
			if ( $error !== UPLOAD_ERR_OK ) {
				return new WP_Error( 'si_upload', in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'אחד הקבצים גדול מדי.' : 'העלאת אחד הקבצים נכשלה. נא לנסות שוב.' );
			}
			if ( count( $files ) >= self::MAX_FILES ) {
				return new WP_Error( 'si_upload', 'ניתן לצרף עד ' . self::MAX_FILES . ' קבצים.' );
			}

			$name  = self::display_name( (string) $name );
			$tmp   = (string) $f['tmp_name'][ $i ];
			$size  = (int) $f['size'][ $i ];
			$total += $size;
			if ( $total > self::MAX_TOTAL_BYTES ) {
				return new WP_Error( 'si_upload', 'גודל הקבצים המצורפים עולה על 15MB. נא לצרף פחות קבצים או קבצים קטנים יותר.' );
			}

			$check = wp_check_filetype_and_ext( $tmp, $name, self::ALLOWED_TYPES );
			$ext   = $check['ext'] ? $check['ext'] : '';
			if ( $ext === '' || ! isset( self::ALLOWED_TYPES[ $ext ] ) ) {
				return new WP_Error( 'si_upload', 'הקובץ "' . $name . '" אינו מסוג נתמך. ניתן לצרף PDF, Word או תמונות (JPG / PNG).' );
			}

			$files[] = array(
				'name'     => $name,
				'tmp_name' => $tmp,
				'size'     => $size,
				'type'     => self::ALLOWED_TYPES[ $ext ],
				'ext'      => $ext,
			);
		}
		return $files;
	}
}
