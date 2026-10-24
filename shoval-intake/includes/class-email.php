<?php
/**
 * Clinic e-mail: the RTL report e-mail (port of REPORT_AI/email_sender.ts and
 * rtl_email_engine.ts) and delivery through Brevo, falling back to wp_mail().
 */

defined( 'ABSPATH' ) || exit;

class Shoval_Intake_Email {

	const RLM            = "\u{200F}";
	const MAX_ATTACH_SIZE = 20971520; // 20 MB for uploads attached to one e-mail.

	/**
	 * Sends the report for one submission.
	 *
	 * @param array  $summary ClinicalSummaryStructured.
	 * @param string $docx    Word file contents.
	 * @param array  $data    The original form answers.
	 * @param array  $uploads [ [ 'name', 'path', 'size', 'type' ] ].
	 * @param int    $post_id Submission ID (0 for the test e-mail).
	 * @param array  $skip    Addresses that already received this report (a retry sends only to the others).
	 * @return array [ 'ok' => bool, 'via' => string, 'error' => string, 'sent' => addresses that got it now ]
	 */
	public static function send_report( array $summary, $docx, array $data, array $uploads, $post_id = 0, array $skip = array() ) {
		$all = Shoval_Intake_Settings::report_emails();
		if ( ! $all ) {
			return array( 'ok' => false, 'via' => '', 'error' => 'לא הוגדר מייל תקין לקבלת הסיכומים.', 'sent' => array() );
		}
		$skip = array_map( 'strtolower', $skip );
		$to   = array_values( array_filter( $all, function ( $address ) use ( $skip ) {
			return ! in_array( strtolower( $address ), $skip, true );
		} ) );
		if ( ! $to ) {
			return array( 'ok' => true, 'via' => '', 'error' => '', 'sent' => array() );
		}

		$name      = ! empty( $summary['patient']['fullName'] ) ? $summary['patient']['fullName'] : 'מטופל';
		$docx_name = self::docx_filename( $summary );

		$attachments = array(
			array(
				'name'    => $docx_name,
				'content' => $docx,
				'type'    => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			),
		);

		$uploads_note = '';
		if ( $uploads ) {
			$total = array_sum( wp_list_pluck( $uploads, 'size' ) );
			if ( ! Shoval_Intake_Settings::get( 'attach_uploads' ) ) {
				$uploads_note = 'הקבצים שהמטופל צירף שמורים באתר ולא צורפו למייל.';
			} elseif ( $total > self::MAX_ATTACH_SIZE ) {
				$uploads_note = 'הקבצים שהמטופל צירף גדולים מדי למייל ושמורים באתר.';
			} else {
				// wp_mail() needs each attachment as a file with a unique name, so number repeated names.
				$used = array( strtolower( $docx_name ) => true );
				foreach ( $uploads as $u ) {
					if ( is_readable( $u['path'] ) ) {
						$base = self::safe_filename( pathinfo( $u['name'], PATHINFO_FILENAME ) );
						$ext  = strtolower( pathinfo( $u['name'], PATHINFO_EXTENSION ) );
						$file = $base . '.' . $ext;
						for ( $n = 2; isset( $used[ strtolower( $file ) ] ); $n++ ) {
							$file = $base . ' (' . $n . ').' . $ext;
						}
						$used[ strtolower( $file ) ] = true;
						$attachments[] = array(
							'name'    => $file,
							'content' => file_get_contents( $u['path'] ),
							'type'    => $u['type'],
						);
					}
				}
			}
		}

		$subject = self::RLM . 'סיכום קליני חדש – ' . $name;
		$html    = self::build_html( $summary, $docx_name, $data, $uploads, $uploads_note, $post_id );
		$text    = "התקבל שאלון קבלה חדש למטופל.\n\n"
			. 'שם המטופל: ' . $name . "\n"
			. 'תעודת זהות: ' . ( ! empty( $summary['patient']['idNumber'] ) ? $summary['patient']['idNumber'] : 'לא צוין' ) . "\n"
			. 'טלפון: ' . ( isset( $data['phone'] ) ? $data['phone'] : '' ) . "\n"
			. 'תאריך: ' . ( isset( $summary['date'] ) ? $summary['date'] : '' ) . "\n\n"
			. "מצורפת טיוטת סיכום ההערכה הקלינית שנוצרה מהשאלון בפורמט Word (.docx). יש לעבור עליה, להשלים ולאשר לפני שימוש.";

		// One e-mail per address, so an address that one service refuses (or that fails) never stops the others.
		// Each address goes through Brevo, then the site's own mail server: the first that accepts it.
		$senders = array();
		if ( Shoval_Intake_Settings::get( 'brevo_api_key' ) && Shoval_Intake_Settings::get( 'brevo_from' ) ) {
			$senders['brevo'] = function ( $address ) use ( $subject, $html, $text, $attachments ) {
				return self::send_brevo( $address, $subject, $html, $text, $attachments );
			};
		}
		$senders['wp_mail'] = function ( $address ) use ( $subject, $html, $attachments ) {
			return self::send_wp_mail( $address, $subject, $html, $attachments );
		};

		$sent   = array();
		$via    = array();
		$errors = array();
		foreach ( $to as $address ) {
			$failures = array();
			foreach ( $senders as $service => $send ) {
				$result = $send( $address );
				if ( $result === true ) {
					$sent[]          = $address;
					$via[ $service ] = true;
					continue 2;
				}
				$failures[] = $service . ': ' . $result;
				error_log( 'Shoval Intake: ' . $service . ' delivery failed: ' . $result );
			}
			$errors[] = $address . ' – ' . implode( '. ', $failures );
		}

		return array(
			'ok'    => ! $errors,
			'via'   => implode( ', ', array_keys( $via ) ),
			'error' => implode( ' | ', $errors ),
			'sent'  => $sent,
		);
	}

	/** "סיכום_קליני_<name>_<date>.docx" – dated by the day the draft was made (the letter itself leaves the day blank). */
	public static function docx_filename( array $summary ) {
		$name = ! empty( $summary['patient']['fullName'] ) ? $summary['patient']['fullName'] : 'מטופל';
		$date = ! empty( $summary['issued'] ) ? $summary['issued'] : ( isset( $summary['date'] ) ? $summary['date'] : '' );
		return self::safe_filename( 'סיכום_קליני_' . preg_replace( '/\s+/u', '_', $name ) . '_' . str_replace( '/', '-', $date ) ) . '.docx';
	}

	/**
	 * Brevo transactional API (free plan: 300 e-mails a day). The sender must be confirmed in Brevo.
	 * Attachment names are sent in plain Latin letters: Brevo garbles Hebrew file names.
	 *
	 * @return true|string
	 */
	private static function send_brevo( $to, $subject, $html, $text, array $attachments ) {
		list( $name, $email ) = self::parse_sender( (string) Shoval_Intake_Settings::get( 'brevo_from' ) );

		$files = array();
		foreach ( array_values( $attachments ) as $i => $a ) {
			$file = $a['name'];
			if ( ! preg_match( '/^[\x20-\x7E]+$/', $file ) ) {
				$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
				$file = $i === 0 && preg_match( '/(\d+-\d+-\d+)\.docx$/', $file, $m )
					? 'clinical-summary-' . $m[1] . '.docx'   // the Word draft is always the first attachment
					: 'attachment-' . ( $i + 1 ) . '.' . $ext;
			}
			$files[] = array( 'name' => $file, 'content' => base64_encode( $a['content'] ) );
		}

		$payload = array(
			'sender'      => $name !== '' ? array( 'name' => $name, 'email' => $email ) : array( 'email' => $email ),
			'to'          => array( array( 'email' => $to ) ),
			'subject'     => $subject,
			'htmlContent' => $html,
			'textContent' => $text,
		);
		if ( $files ) {
			$payload['attachment'] = $files;
		}
		$response = wp_remote_post(
			'https://api.brevo.com/v3/smtp/email',
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'api-key'      => trim( (string) Shoval_Intake_Settings::get( 'brevo_api_key' ) ),
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $json['message'] ) ? (string) $json['message'] : 'HTTP ' . $code;
	}

	/** "Name <address>" or "address" → [ name, address ]. */
	private static function parse_sender( $value ) {
		if ( preg_match( '/^(.*)<([^>]+)>$/u', trim( $value ), $m ) ) {
			return array( trim( $m[1] ), trim( $m[2] ) );
		}
		return array( '', trim( $value ) );
	}

	/** @return true|string */
	private static function send_wp_mail( $to, $subject, $html, array $attachments ) {
		// wp_mail() names attachments after their file, so write named copies to a private temp folder.
		$tmp = Shoval_Intake_Storage::base_dir() . '/tmp-' . Shoval_Intake_Storage::random_name();
		if ( ! Shoval_Intake_Storage::ensure_private_dir() || ! wp_mkdir_p( $tmp ) ) {
			return 'temporary folder is not writable';
		}
		$paths = array();
		foreach ( $attachments as $a ) {
			$path = $tmp . '/' . $a['name'];
			if ( file_put_contents( $path, $a['content'] ) !== false ) {
				$paths[] = $path;
			}
		}

		$failure = '';
		$catch   = function ( $wp_error ) use ( &$failure ) {
			$failure = $wp_error->get_error_message();
		};
		add_action( 'wp_mail_failed', $catch );
		$sent = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ), $paths );
		remove_action( 'wp_mail_failed', $catch );

		foreach ( $paths as $path ) {
			unlink( $path );
		}
		rmdir( $tmp );

		return $sent ? true : ( $failure !== '' ? $failure : 'wp_mail returned false' );
	}

	private static function safe_filename( $name ) {
		$name = preg_replace( '/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', (string) $name );
		$name = trim( $name, ' ._' );
		return $name !== '' ? mb_substr( $name, 0, 120 ) : 'file';
	}

	/* ---------------------------------------------------------------------
	 * HTML
	 * ------------------------------------------------------------------- */

	private static function e( $text ) {
		return esc_html( (string) $text );
	}

	private static function ltr( $text ) {
		if ( (string) $text === '' ) {
			return '';
		}
		return '<span dir="ltr" style="direction: ltr !important; unicode-bidi: embed !important; display: inline-block !important; text-align: left !important;">' . self::e( $text ) . '</span>';
	}

	private static function section( $title, $content, $underline = false ) {
		return '<div dir="rtl" style="margin-bottom: 22px; direction: rtl; text-align: right;">'
			. '<div dir="rtl" style="font-size: 15px; font-weight: bold; color: #002060; margin-bottom: 6px; ' . ( $underline ? 'text-decoration: underline; ' : '' ) . 'direction: rtl; text-align: right;">' . self::e( $title ) . '</div>'
			. '<div dir="rtl" style="font-size: 14px; line-height: 1.6; color: #334155; direction: rtl; text-align: right;">' . $content . '</div>'
			. '</div>';
	}

	private static function para( $text, $extra = '' ) {
		return '<p dir="rtl" style="margin: 0; line-height: 1.6; font-size: 14px; color: #334155; direction: rtl; text-align: right; white-space: pre-line;' . $extra . '">' . self::e( $text ) . '</p>';
	}

	private static function numbered( array $items ) {
		$html = '';
		foreach ( array_values( $items ) as $i => $item ) {
			$html .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" dir="rtl" style="width: 100%; border-collapse: collapse; margin-bottom: 8px; direction: rtl; text-align: right;"><tr dir="rtl">'
				. '<td dir="rtl" valign="top" style="width: 28px; padding-left: 6px; padding-top: 1px; font-weight: bold; font-size: 14.5px; color: #002060; text-align: right; direction: rtl; line-height: 1.6;">' . ( $i + 1 ) . '.</td>'
				. '<td dir="rtl" valign="top" style="font-size: 14px; color: #334155; line-height: 1.6; text-align: right; direction: rtl;">' . self::e( preg_replace( '/^\d+[.)]\s*/', '', (string) $item ) ) . '</td>'
				. '</tr></table>';
		}
		return $html;
	}

	private static function card_row( $label1, $value1, $label2, $value2 ) {
		$cell = function ( $label, $value ) {
			return '<td dir="rtl" style="padding: 6px 8px; width: 50%; direction: rtl; text-align: right; color: #1e293b;"><strong style="color: #002060;">' . self::e( $label ) . '</strong> ' . $value . '</td>';
		};
		return '<tr dir="rtl">' . $cell( $label1, $value1 ) . $cell( $label2, $value2 ) . '</tr>';
	}

	public static function build_html( array $s, $filename, array $data, array $uploads, $uploads_note, $post_id ) {
		$p       = isset( $s['patient'] ) ? $s['patient'] : array();
		$name    = ! empty( $p['fullName'] ) ? $p['fullName'] : 'מטופל';
		$subject = preg_replace( '/^הנדון:\s*/u', '', ! empty( $s['subject'] ) ? $s['subject'] : 'הערכה פסיכיאטרית - ' . $name );
		$dash    = '—';

		// Diagnoses.
		$dx = '';
		if ( ! empty( $s['diagnoses'] ) ) {
			$dx = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" dir="rtl" style="width: 100%; border-collapse: collapse; margin-top: 8px; direction: rtl; text-align: right;"><tbody>';
			foreach ( $s['diagnoses'] as $d ) {
				$dx .= '<tr dir="rtl" style="direction: rtl;">'
					. '<td dir="rtl" style="padding: 6px 8px; text-align: right; font-weight: bold; color: #1e293b; font-size: 14.5px; border-bottom: 1px solid #f1f5f9; direction: rtl;">' . self::e( $d['name'] ) . '</td>'
					. '<td dir="rtl" style="padding: 6px 8px; text-align: left; color: #002060; font-size: 14px; font-family: monospace; font-weight: bold; border-bottom: 1px solid #f1f5f9; direction: rtl;">' . self::ltr( isset( $d['icd10'] ) ? $d['icd10'] : '' ) . '</td>'
					. '</tr>';
			}
			$dx .= '</tbody></table>';
		} else {
			$dx = '<p dir="rtl" style="margin: 4px 0; color: #64748b; direction: rtl; text-align: right;">0</p>';
		}

		$plan = ! empty( $s['treatmentPlan'] )
			? self::numbered( $s['treatmentPlan'] )
			: '<p dir="rtl" style="margin: 4px 0; color: #64748b; direction: rtl; text-align: right;">יושלם על ידי הרופא.</p>';

		$admin_link = $post_id ? admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' ) : '';

		$h  = '';
		// Top notice bar.
		$h .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" dir="rtl" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 24px; direction: rtl; text-align: right;"><tr dir="rtl"><td dir="rtl" style="padding: 10px 16px; font-size: 13.5px; color: #475569; direction: rtl; text-align: right;">'
			. '📄 מצורף קובץ Word רשמי: <strong style="color: #002060;">' . self::ltr( $filename ) . '</strong><br>'
			. '<span style="color: #b45309;">טיוטה שהופקה אוטומטית משאלון הקבלה – יש לעבור עליה, להשלים ולאשר לפני שימוש.</span>'
			. ( $admin_link ? '<br><a href="' . esc_url( $admin_link ) . '" style="color: #00909e;">לצפייה בשאלון המלא ובקבצים באתר</a>' : '' )
			. '</td></tr></table>';

		// Letterhead.
		$h .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" dir="rtl" style="width: 100%; border-collapse: collapse; border-bottom: 2.5px solid #002060; padding-bottom: 16px; margin-bottom: 22px; direction: rtl;"><tr dir="rtl">'
			. '<td dir="rtl" valign="top" style="width: 50%; text-align: right; direction: rtl; font-family: Arial, sans-serif; padding-bottom: 16px; padding-left: 12px;">'
			. '<div dir="rtl" style="font-size: 19px; font-weight: bold; color: #002060; margin-bottom: 3px;">פרופ\' גל שובל</div>'
			. '<div dir="rtl" style="font-size: 13.5px; font-weight: bold; color: #002060; margin-bottom: 2px;">מומחה בפסיכיאטריה כללית</div>'
			. '<div dir="rtl" style="font-size: 13.5px; font-weight: bold; color: #002060; margin-bottom: 3px;">מומחה בפסיכיאטריה של הילד והמתבגר</div>'
			. '<div dir="rtl" style="font-size: 12.5px; color: #002060; line-height: 1.4;">מרכז בריאות הנפש "גהה" ואוניברסיטת תל-אביב<br>מרפאה פרטית: רח\' סוקולוב 81, רמת השרון</div>'
			. '</td>'
			. '<td dir="ltr" valign="top" style="width: 50%; text-align: left; direction: ltr; font-family: Arial, sans-serif; padding-bottom: 16px; padding-right: 12px;">'
			. '<div dir="ltr" style="font-size: 17px; font-weight: bold; color: #002060; margin-bottom: 3px; text-align: left;">Prof. Gal Shoval, M.D.</div>'
			. '<div dir="ltr" style="font-size: 13.5px; font-weight: bold; color: #002060; margin-bottom: 2px; text-align: left;">Adult Psychiatrist</div>'
			. '<div dir="ltr" style="font-size: 13.5px; font-weight: bold; color: #002060; margin-bottom: 3px; text-align: left;">Child &amp; Adolescent Psychiatrist</div>'
			. '<div dir="ltr" style="font-size: 12.5px; color: #002060; line-height: 1.4; text-align: left;">Geha Mental Health Center and Tel Aviv University<br>Clinic: 81 Sokolov St., Ramat Hasharon</div>'
			. '</td></tr></table>';

		$h .= '<div dir="rtl" style="text-align: center; margin: 12px 0 16px 0; font-size: 15px; font-weight: bold; color: #002060; letter-spacing: 0.5px;">- סודי רפואי -</div>';

		// Date and recipient.
		$h .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" dir="rtl" style="width: 100%; border-collapse: collapse; margin-bottom: 20px; direction: rtl;"><tr dir="rtl">'
			. '<td dir="rtl" valign="top" style="width: 65%; text-align: right; direction: rtl;"><div dir="rtl" style="font-size: 14px; font-weight: bold; color: #1e293b; line-height: 1.5; white-space: pre-line;">' . self::e( ! empty( $s['letterAddressedTo'] ) ? $s['letterAddressedTo'] : 'הרופא המטפל' ) . '</div></td>'
			. '<td dir="rtl" valign="top" style="width: 35%; text-align: left; direction: rtl;"><div dir="rtl" style="font-size: 14px; font-weight: bold; color: #002060; text-align: left;">תאריך: ' . self::ltr( ! empty( $s['date'] ) ? $s['date'] : $dash ) . '</div></td>'
			. '</tr></table>';

		// Subject.
		$h .= '<div dir="rtl" style="text-align: center; margin: 24px 0 20px 0; border-bottom: 1.5px solid #cbd5e1; padding-bottom: 10px;"><h2 dir="rtl" style="font-size: 18px; font-weight: bold; color: #002060; margin: 0; text-decoration: underline; text-align: center;">הנדון: ' . self::e( $subject ) . '</h2></div>';

		// Patient card (with contact details for the clinic).
		$phone   = isset( $data['phone'] ) ? $data['phone'] : '';
		$email   = isset( $data['email'] ) ? $data['email'] : '';
		$parents = Shoval_Intake_Letter::parents_label( $data );
		$h    .= '<div dir="rtl" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 18px; margin-bottom: 22px; direction: rtl; text-align: right;">'
			. '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" dir="rtl" style="width: 100%; border-collapse: collapse; font-size: 14px; direction: rtl; text-align: right;">'
			. self::card_row( 'שם המטופל/ת:', self::e( $name ), 'תעודת זהות:', self::ltr( ! empty( $p['idNumber'] ) ? $p['idNumber'] : 'לא צוין' ) )
			. self::card_row( 'גיל:', ! empty( $p['age'] ) ? self::e( $p['age'] ) : $dash, 'יישוב מגורים:', self::e( ! empty( $p['city'] ) ? $p['city'] : $dash ) )
			. self::card_row( 'טלפון:', $phone !== '' ? '<a href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', $phone ) ) . '" dir="ltr" style="color: #002060; text-decoration: none; unicode-bidi: embed;">' . self::e( $phone ) . '</a>' : $dash, 'דוא"ל:', $email !== '' ? '<a href="mailto:' . esc_attr( $email ) . '" dir="ltr" style="color: #002060; text-decoration: none; unicode-bidi: embed;">' . self::e( $email ) . '</a>' : $dash )
			. ( $parents !== '' || ! empty( $p['gender'] ) ? self::card_row( 'מין:', self::e( ! empty( $p['gender'] ) ? $p['gender'] : $dash ), 'הורים:', self::e( $parents !== '' ? $parents : $dash ) ) : '' )
			. '</table></div>';

		$h .= self::section( 'אבחנות (Diagnoses / Dx.):', $dx, true );
		if ( isset( $s['otherMedicalConditions'] ) && (string) $s['otherMedicalConditions'] !== '' ) {
			$h .= self::section( 'מצבים רפואיים נוספים:', self::para( $s['otherMedicalConditions'] ) );
		}
		if ( isset( $s['rxTreatments'] ) && (string) $s['rxTreatments'] !== '' ) {
			$h .= self::section( 'טיפול תרופתי קבוע (Rx.):', self::para( $s['rxTreatments'] ) );
		}

		$h .= '<div dir="rtl" style="margin: 20px 0 10px 0; font-size: 15px; font-weight: bold; color: #002060;">שלום רב,</div>';
		if ( ! empty( $s['openingParagraph'] ) ) {
			$h .= self::section( 'סיבת הפנייה ותלונות עיקריות:', self::para( preg_replace( '/^שלום רב,?\s*/u', '', $s['openingParagraph'] ) ) );
		}
		if ( ! empty( $s['medicalDevelopmentalBackground'] ) ) {
			$h .= self::section( 'רקע רפואי, התפתחותי ומשפחתי:', self::para( $s['medicalDevelopmentalBackground'] ) );
		}
		if ( ! empty( $s['medicationHistory'] ) ) {
			$h .= self::section( 'טיפול תרופתי כעת ובעבר:', self::para( $s['medicationHistory'] ) );
		}
		if ( ! empty( $s['mentalStatusExamination'] ) ) {
			$h .= self::section( 'בדיקה פסיכיאטרית (Mental Status Examination):', self::para( $s['mentalStatusExamination'] ) );
		}
		if ( ! empty( $s['clinicalImpression'] ) ) {
			$h .= self::section( 'דיון והתרשמות קלינית:', self::para( $s['clinicalImpression'], ' font-weight: bold; color: #1e293b; font-size: 14.5px;' ) );
		}
		$h .= self::section( 'המלצות ותוכנית טיפולית:', $plan, true );

		$h .= '<div dir="rtl" style="margin: 22px 0 16px 0; font-size: 14.5px; font-weight: bold; color: #002060;">' . self::e( ! empty( $s['closingGreeting'] ) ? $s['closingGreeting'] : 'בכבוד רב ובברכה,' ) . '</div>';

		// Stamp box.
		$h .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" dir="rtl" style="margin: 20px 0 28px auto; width: 340px; border: 2px solid #002060; border-collapse: collapse; background-color: #ffffff; direction: rtl; text-align: right;">'
			. '<tr dir="rtl"><td dir="rtl" valign="middle" align="center" style="width: 75px; text-align: center; padding: 10px 6px; border-bottom: 1.5px solid #002060;"><span style="font-size: 36px; line-height: 1;">☀️</span></td>'
			. '<td dir="rtl" valign="middle" align="center" style="text-align: center; padding: 10px 12px; border-bottom: 1.5px solid #002060;"><div dir="rtl" style="font-size: 17px; font-weight: bold; color: #002060; margin-bottom: 2px;">פרופ\' גל שובל</div><div dir="rtl" style="font-size: 13px; color: #002060;">פסיכיאטר ילדים, נוער ומבוגרים</div></td></tr>'
			. '<tr dir="rtl"><td colspan="2" dir="rtl" align="center" style="text-align: center; padding: 7px 10px;"><div dir="ltr" style="font-size: 11px; font-weight: bold; color: #002060; letter-spacing: 0.3px; text-align: center; unicode-bidi: embed;">מ.ר. 32238 &nbsp;|&nbsp; מ.מ. 22646 &nbsp;|&nbsp; מ.ר.מ 21535</div></td></tr>'
			. '</table>';

		// Footer contact line.
		$h .= '<div dir="rtl" style="border-top: 2px solid #002060; padding-top: 14px; margin-top: 28px;">'
			. '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" dir="rtl" style="width: 100%; border-collapse: collapse; font-size: 12.5px; color: #002060; direction: rtl;"><tr dir="rtl">'
			. '<td dir="rtl" valign="top" style="width: 50%; text-align: right;"><div>Tel: <span dir="ltr" style="unicode-bidi: embed; font-weight: bold;">03-5492263</span> :טל\'</div><div style="margin-top: 3px;">Fax: <span dir="ltr" style="unicode-bidi: embed; font-weight: bold;">03-5471283</span> :פקס</div></td>'
			. '<td dir="ltr" valign="top" style="width: 50%; text-align: left; direction: ltr;"><div>Cell: <span style="font-weight: bold;">054-4493441</span> :נייד</div><div style="margin-top: 3px;">Email: <a href="mailto:shovgal@tau.ac.il" style="color: #002060; text-decoration: none; font-weight: bold;">shovgal@tau.ac.il</a> :אימייל</div></td>'
			. '</tr></table></div>';

		// The patient's answers as submitted.
		$h .= self::raw_answers( $data, $uploads, $uploads_note );

		return self::wrap( $h, 'סיכום הערכה קלינית – ' . $name, 'התקבל שאלון קבלה חדש – ' . $name );
	}

	private static function raw_answers( array $data, array $uploads, $uploads_note ) {
		$h = '<div dir="rtl" style="margin-top: 36px; padding-top: 18px; border-top: 1px dashed #cbd5e1; direction: rtl; text-align: right;">'
			. '<div style="font-size: 16px; font-weight: bold; color: #002060; margin-bottom: 14px;">תשובות השאלון כפי שנמסרו</div>';

		$h .= self::section( 'סיבת הפנייה:', self::para( isset( $data['referralReason'] ) ? $data['referralReason'] : '' ) );

		if ( ! empty( $data['noCurrentMedications'] ) ) {
			$meds = self::para( 'אין טיפול תרופתי קבוע.' );
		} else {
			$items = array();
			foreach ( (array) ( isset( $data['currentMedications'] ) ? $data['currentMedications'] : array() ) as $m ) {
				$items[] = trim( $m['name'] . ( $m['dosage'] !== '' ? ' – ' . $m['dosage'] : '' ) );
			}
			$meds = $items ? self::numbered( $items ) : self::para( 'לא צוין.' );
		}
		$h .= self::section( 'תרופות כעת:', $meds );

		if ( ! empty( $data['noPastMedications'] ) ) {
			$past = self::para( 'אין טיפולים תרופתיים קודמים.' );
		} else {
			$rows = '';
			foreach ( (array) ( isset( $data['pastMedications'] ) ? $data['pastMedications'] : array() ) as $m ) {
				$rows .= '<tr>';
				foreach ( array( 'medication', 'startYear', 'duration', 'administration', 'maxDosage' ) as $k ) {
					$rows .= '<td dir="rtl" style="padding: 6px 8px; border: 1px solid #e2e8f0; font-size: 13.5px; color: #334155; text-align: right;">' . self::e( isset( $m[ $k ] ) ? $m[ $k ] : '' ) . '</td>';
				}
				$rows .= '</tr>';
			}
			if ( $rows !== '' ) {
				$th   = 'style="padding: 6px 8px; border: 1px solid #e2e8f0; background-color: #f8fafc; color: #002060; font-size: 13px; text-align: right;"';
				$past = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" dir="rtl" style="width: 100%; border-collapse: collapse; direction: rtl;">'
					. '<tr><th ' . $th . '>תרופה</th><th ' . $th . '>שנת התחלה</th><th ' . $th . '>משך</th><th ' . $th . '>אופן נטילה</th><th ' . $th . '>מינון מרבי</th></tr>'
					. $rows . '</table>';
			} else {
				$past = self::para( 'לא צוין.' );
			}
		}
		$h .= self::section( 'טיפולים תרופתיים בעבר:', $past );

		$h .= self::section( 'מסמכים ואבחונים קודמים:', self::para( ! empty( $data['previousDocuments'] ) ? $data['previousDocuments'] : 'לא צוין.' ) );

		if ( $uploads ) {
			$names = array();
			foreach ( $uploads as $u ) {
				$names[] = $u['name'] . ' (' . size_format( $u['size'] ) . ')';
			}
			$h .= self::section( 'קבצים שצורפו:', self::numbered( $names ) . ( $uploads_note !== '' ? self::para( $uploads_note, ' color: #b45309;' ) : '' ) );
		}

		return $h . '</div>';
	}

	private static function wrap( $inner, $title, $preview ) {
		return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
			. '<html xmlns="http://www.w3.org/1999/xhtml" dir="rtl" lang="he" xml:lang="he"><head>'
			. '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />'
			. '<meta name="format-detection" content="telephone=no, date=no, address=no, email=no" /><title>' . self::e( $title ) . '</title>'
			. '<!--[if mso]><style type="text/css">body, table, td, p, a, li { font-family: Arial, \'Segoe UI\', sans-serif !important; } table { border-collapse: collapse; }</style><![endif]-->'
			. '<style type="text/css">body{margin:0!important;padding:0!important;width:100%!important;background-color:#f1f5f9!important;color:#1e293b;font-family:Arial,\'Segoe UI\',Tahoma,Helvetica,sans-serif;direction:rtl!important;text-align:right!important;}'
			. '[dir="rtl"],table[dir="rtl"],td[dir="rtl"],div[dir="rtl"],p[dir="rtl"]{direction:rtl!important;text-align:right!important;}'
			. 'span[dir="ltr"],a[dir="ltr"]{direction:ltr!important;unicode-bidi:embed!important;display:inline-block!important;}'
			. '@media screen and (max-width:640px){.mobile-padding{padding:16px 14px!important;}}</style>'
			. '</head><body dir="rtl" style="margin: 0; padding: 0; background-color: #f1f5f9; direction: rtl; text-align: right;">'
			. '<div style="display: none; max-height: 0px; overflow: hidden; font-size: 1px; line-height: 1px; color: #ffffff; opacity: 0;">' . self::e( $preview ) . '</div>'
			. '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" dir="rtl" style="border-collapse: collapse; background-color: #f1f5f9; direction: rtl;"><tr><td align="center" dir="rtl" style="padding: 24px 12px;">'
			. '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" dir="rtl" style="max-width: 680px; width: 100%; margin: 0 auto; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; border-collapse: separate; overflow: hidden; direction: rtl; text-align: right;">'
			. '<tr><td dir="rtl" class="mobile-padding" style="padding: 28px 32px; direction: rtl; text-align: right; font-family: Arial, \'Segoe UI\', Tahoma, sans-serif; font-size: 15px; line-height: 1.6; color: #1e293b;">'
			. $inner
			. '</td></tr></table></td></tr></table></body></html>';
	}
}
