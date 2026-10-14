<?php
/**
 * Turns an intake questionnaire into the structured clinical-summary draft.
 *
 * Diagnoses, clinical impression and treatment plan come from Google Gemini
 * (when enabled) or from the keyword rules below. Everything else in the
 * letter is built only from what the patient actually wrote: nothing is
 * invented, and parts that need the physician are left as blank lines.
 */

defined( 'ABSPATH' ) || exit;

class Shoval_Intake_AI {

	const NAME_TOKEN = '{{שם}}';
	const BLANK      = '_________________________________________________________________________________';

	const CLINICIAN_NAME   = "פרופ' גל שובל";
	const CLINICIAN_ROLE   = 'פסיכיאטר ילדים, נוער ומבוגרים';
	const CLINICIAN_CLINIC = 'מרכז בריאות הנפש "גהה" ואוניברסיטת תל-אביב';

	/**
	 * @return array{summary: array, source: string}
	 */
	public static function build_summary( array $data, $use_ai = true ) {
		$full_name = trim( self::field( $data, 'firstName' ) . ' ' . self::field( $data, 'lastName' ) );
		$is_female = self::field( $data, 'gender' ) === 'נקבה';
		$id_number = self::field( $data, 'idNumber' );

		$rules      = self::infer_from_rules( $data, $full_name, $is_female );
		$diagnoses  = $rules['diagnoses'];
		$impression = $rules['clinicalImpression'];
		$plan       = $rules['treatmentPlan'];
		$source     = 'rules';

		if ( $use_ai && Shoval_Intake_Settings::get( 'ai_enabled' ) && Shoval_Intake_Settings::get( 'gemini_api_key' ) ) {
			$ai = self::ask_gemini( $data, $is_female );
			if ( $ai && $ai['diagnoses'] ) {
				$source    = 'gemini:' . $ai['model'];
				$diagnoses = $ai['diagnoses'];
				if ( $ai['clinicalImpression'] !== '' ) {
					$impression = str_replace( self::NAME_TOKEN, $full_name, $ai['clinicalImpression'] );
				}
				if ( $ai['treatmentPlan'] ) {
					$plan = $ai['treatmentPlan'];
				}
			}
		}

		if ( ! $diagnoses ) {
			$diagnoses = array(
				array( 'name' => '.1 __________________________________________________', 'icd10' => 'ICD-10: _______' ),
				array( 'name' => '.2 __________________________________________________', 'icd10' => 'ICD-10: _______' ),
			);
		}

		$summary = array(
			'patient'                        => array(
				'fullName' => $full_name,
				'idNumber' => $id_number,
				'age'      => self::field( $data, 'age' ),
				'city'     => self::field( $data, 'city' ),
				'gender'   => self::field( $data, 'gender' ),
			),
			'date'                           => self::israel_date(),
			'letterAddressedTo'              => "לכב'\nהרופא המטפל\nוכל גורם טיפולי / יעוצי / חינוכי רלבנטי, כולל ועדת אפיון וזכאות",
			'subject'                        => 'הנדון: ' . $full_name . ' ת.ז. ' . $id_number . ' – סיכום הערכה קלינית ותוכנית טיפולית',
			'diagnoses'                      => $diagnoses,
			'otherMedicalConditions'         => '0',
			'rxTreatments'                   => self::rx( $data ),
			'openingParagraph'               => self::opening( $data, $full_name, $is_female ),
			'medicalDevelopmentalBackground' => self::background( $data, $is_female ),
			'mentalStatusExamination'        => '',
			'clinicalImpression'             => $impression,
			'treatmentPlan'                  => $plan,
			'closingGreeting'                => 'בכבוד רב ובברכה,',
			'signatoryName'                  => self::CLINICIAN_NAME,
			'signatoryRole'                  => self::CLINICIAN_ROLE,
			'signatoryClinic'                => self::CLINICIAN_CLINIC,
		);

		return array( 'summary' => $summary, 'source' => $source );
	}

	public static function israel_date( $timestamp = null ) {
		return wp_date( 'j/n/y', $timestamp ? $timestamp : time(), new DateTimeZone( 'Asia/Jerusalem' ) );
	}

	private static function field( array $data, $key ) {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';
	}

	private static function one_line( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	private static function current_meds( array $data ) {
		if ( ! empty( $data['noCurrentMedications'] ) || empty( $data['currentMedications'] ) || ! is_array( $data['currentMedications'] ) ) {
			return array();
		}
		return array_values( array_filter( $data['currentMedications'], function ( $m ) {
			return is_array( $m ) && isset( $m['name'] ) && trim( $m['name'] ) !== '';
		} ) );
	}

	private static function past_meds( array $data ) {
		if ( ! empty( $data['noPastMedications'] ) || empty( $data['pastMedications'] ) || ! is_array( $data['pastMedications'] ) ) {
			return array();
		}
		return array_values( array_filter( $data['pastMedications'], function ( $m ) {
			return is_array( $m ) && isset( $m['medication'] ) && trim( $m['medication'] ) !== '';
		} ) );
	}

	private static function rx( array $data ) {
		$list = array();
		foreach ( self::current_meds( $data ) as $m ) {
			$list[] = trim( $m['name'] . ' ' . ( isset( $m['dosage'] ) ? $m['dosage'] : '' ) );
		}
		return $list ? implode( ', ', $list ) : '0';
	}

	private static function opening( array $data, $full_name, $f ) {
		$age      = self::field( $data, 'age' );
		$city     = self::field( $data, 'city' );
		$parents  = self::field( $data, 'parentsNames' );
		$children = self::field( $data, 'childrenCount' );

		$text = $full_name;
		if ( $age !== '' ) {
			$text .= ', ' . ( $f ? 'בת' : 'בן' ) . ' ' . $age . ( is_numeric( $age ) ? ' שנים' : '' );
		}
		$with_parents = $parents !== '' ? ( $f ? ' עם הוריה, ' : ' עם הוריו, ' ) . $parents : '';
		if ( $city !== '' ) {
			$text .= ', ' . ( $f ? 'תושבת ' : 'תושב ' ) . $city;
			if ( $with_parents !== '' ) {
				$text .= ', בה ' . ( $f ? 'מתגוררת' : 'מתגורר' ) . $with_parents;
			}
		} elseif ( $with_parents !== '' ) {
			$text .= ', ' . ( $f ? 'מתגוררת' : 'מתגורר' ) . $with_parents;
		}
		$text .= '.';

		if ( ctype_digit( $children ) && (int) $children > 1 ) {
			$text .= $f
				? ' היא אחת מבין ' . (int) $children . ' ילדים במשפחתה הגרעינית.'
				: ' הוא אחד מבין ' . (int) $children . ' ילדים במשפחתו הגרעינית.';
		} elseif ( $children === '1' ) {
			$text .= $f ? ' היא בת יחידה להוריה.' : ' הוא בן יחיד להוריו.';
		}

		$text .= ' האם/ההורים נכחו בפגישה ומסרו מידע התפתחותי, רפואי ואנמנסטי מפורט כנדרש.';

		$reason = rtrim( self::one_line( self::field( $data, 'referralReason' ) ), " .\t" );
		$text  .= "\n\n" . ( $f ? 'הוערכה' : 'הוערך' ) . ' במרפאתי לראשונה היום לצורך איבחון אינטגרטיבי בשל ' . ( $reason !== '' ? $reason : 'הערכה קלינית' ) . '.';

		return $text;
	}

	private static function background( array $data, $f ) {
		$parts = array();

		$past = array();
		foreach ( self::past_meds( $data ) as $m ) {
			$bits = array();
			if ( ! empty( $m['startYear'] ) ) {
				$bits[] = 'החל מ-' . $m['startYear'];
			}
			if ( ! empty( $m['duration'] ) ) {
				$bits[] = 'משך: ' . $m['duration'];
			}
			if ( ! empty( $m['administration'] ) ) {
				$bits[] = 'אופן נטילה: ' . $m['administration'];
			}
			if ( ! empty( $m['maxDosage'] ) ) {
				$bits[] = 'מינון מרבי: ' . $m['maxDosage'];
			}
			$past[] = trim( $m['medication'] ) . ( $bits ? ' (' . implode( ', ', $bits ) . ')' : '' );
		}
		if ( $past ) {
			$parts[] = ( $f ? 'בעבר טופלה ב: ' : 'בעבר טופל ב: ' ) . implode( '; ', $past ) . '.';
		}

		$docs = rtrim( self::one_line( self::field( $data, 'previousDocuments' ) ), ' .' );
		if ( $docs !== '' ) {
			$parts[] = 'מסמכים ואבחונים קודמים שהוצגו: ' . $docs . '.';
		}

		if ( ! empty( $data['uploadedFileNames'] ) && is_array( $data['uploadedFileNames'] ) ) {
			$parts[] = 'קבצים שצורפו לשאלון: ' . implode( ', ', array_map( 'strval', $data['uploadedFileNames'] ) ) . '.';
		}

		return 'ברקע רפואי, מיילדותי והתפתחותי: ______________________________________. ' . implode( ' ', $parts );
	}

	/* ---------------------------------------------------------------------
	 * Google Gemini
	 * ------------------------------------------------------------------- */

	private static function system_instruction( $is_female ) {
		$impression = $is_female
			? self::NAME_TOKEN . ' אובחנה במרפאתי כסובלת מ[שם ההפרעה בעברית, ובסוגריים באנגלית/קיצור]'
			: self::NAME_TOKEN . ' אובחן במרפאתי כסובל מ[שם ההפרעה בעברית, ובסוגריים באנגלית/קיצור]';

		return "אתה מסייע לפרופ' גל שובל (מומחה בפסיכיאטריה כללית ובפסיכיאטריה של הילד והמתבגר, מרכז בריאות הנפש \"גהה\" ואוניברסיטת תל-אביב) להכין טיוטה למכתב סיכום הערכה קלינית.\n"
			. "אתה מקבל שאלון קבלה שמילאו המטופל/ת או ההורים לפני הפגישה. השאלון אינו כולל פרטים מזהים. הרופא יעבור על הטיוטה, ישלים ויאשר אותה.\n\n"
			. "החזר JSON עם שלושה שדות בלבד:\n"
			. "1. diagnoses – אבחנות באנגלית עם קוד ICD-10 תקני. הסק אבחנה רק אם נכתבה במפורש בשאלון, אם צוין אבחון קודם, או אם הטיפול התרופתי מעיד עליה באופן חד-משמעי. דוגמאות:\n"
			. "   אוטיזם / על הרצף -> Autism spectrum disorder (ASD), F84.0\n"
			. "   הפרעת קשב וריכוז -> Attention deficit hyperactivity disorder (ADHD), F90.9 (או F90.0/F90.1/F90.2 אם הסוג צוין)\n"
			. "   הפרעת תקשורת חברתית -> Social communications disorder (SCD), F80.82\n"
			. "   חרדה -> Anxiety disorder, F41.9 ; דיכאון -> Major depressive disorder, F32.9 ; OCD -> Obsessive-compulsive disorder (OCD), F42\n"
			. "   טיקים / טורט -> Tic disorder / Tourette syndrome, F95.2 ; לקות למידה -> Specific learning disorder, F81.9\n"
			. "   לכל אבחנה מלא את השדה evidence בציטוט מדויק, מילה במילה, מתוך השאלון שעליו מבוססת האבחנה (למשל \"הפרעת קשב וריכוז\" או \"ריטלין\"). אם אין ציטוט כזה – אל תכלול את האבחנה.\n"
			. "   אם אין בסיס ברור – החזר מערך ריק. אל תמציא אבחנות.\n"
			. "2. clinicalImpression – משפט התרשמות אחד בעברית בתבנית: \"" . $impression . ".\" התואם בדיוק לאבחנות. השתמש במחרוזת " . self::NAME_TOKEN . " במקום שם המטופל. אם אין אבחנות – מחרוזת ריקה.\n"
			. "3. treatmentPlan – אם יש אבחנות: 3 סעיפים בעברית, ללא מספור: (1) פסיכותרפיה / טיפול רגשי מותאם (למשל CBT, קבוצה חברתית) לרבות הדרכת הורים כשרלוונטי; (2) התאמות במסגרת החינוכית/התעסוקתית (למשל ועדת אפיון וזכאות, סל שילוב) כשרלוונטי לגיל; (3) התייחסות לטיפול תרופתי והמשך מעקב פסיכיאטרי סדיר. אם אין אבחנות – מערך ריק.\n\n"
			. "כללים: אל תכלול עובדות שלא מופיעות בשאלון (אין להמציא רקע, היסטוריה, תוצאות בדיקה או תרופות). אל תציין מינונים שלא נמסרו. כתוב בעברית רפואית תקנית, בלשון " . ( $is_female ? 'נקבה' : 'זכר' ) . ' בהתאם למין המטופל/ת.';
	}

	/** Only clinical content is sent – never name, ID, phone, e-mail, city or parents' names. */
	private static function gemini_payload( array $data, $is_female ) {
		$current = array();
		foreach ( self::current_meds( $data ) as $m ) {
			$current[] = array( 'name' => $m['name'], 'dosage' => isset( $m['dosage'] ) ? $m['dosage'] : '' );
		}
		$past = array();
		foreach ( self::past_meds( $data ) as $m ) {
			$past[] = array_intersect_key( $m, array_flip( array( 'medication', 'startYear', 'duration', 'administration', 'maxDosage' ) ) );
		}

		$questionnaire = array(
			'gender'             => self::field( $data, 'gender' ),
			'age'                => self::field( $data, 'age' ),
			'reasonForReferral'  => self::field( $data, 'referralReason' ),
			'currentMedications' => $current ? $current : 'אין טיפול תרופתי קבוע כעת.',
			'pastMedications'    => $past ? $past : 'אין טיפולים תרופתיים קודמים.',
			'previousDocuments'  => self::field( $data, 'previousDocuments' ),
		);

		return array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => self::system_instruction( $is_female ) ) ) ),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => "להלן נתוני שאלון הקבלה (ללא פרטים מזהים):\n" . wp_json_encode( $questionnaire, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) ),
				),
			),
			'generationConfig'  => array(
				'temperature'      => 0.2,
				'responseMimeType' => 'application/json',
				'responseSchema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'diagnoses'          => array(
							'type'  => 'ARRAY',
							'items' => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'name'     => array( 'type' => 'STRING' ),
									'icd10'    => array( 'type' => 'STRING' ),
									'evidence' => array( 'type' => 'STRING' ),
								),
								'required'   => array( 'name', 'icd10', 'evidence' ),
							),
						),
						'clinicalImpression' => array( 'type' => 'STRING' ),
						'treatmentPlan'      => array(
							'type'  => 'ARRAY',
							'items' => array( 'type' => 'STRING' ),
						),
					),
					'required'   => array( 'diagnoses', 'clinicalImpression', 'treatmentPlan' ),
				),
			),
		);
	}

	/**
	 * @return array|null [ 'model', 'diagnoses', 'clinicalImpression', 'treatmentPlan' ]
	 */
	public static function ask_gemini( array $data, $is_female ) {
		$key = (string) Shoval_Intake_Settings::get( 'gemini_api_key' );
		if ( $key === '' ) {
			return null;
		}
		$body = wp_json_encode( self::gemini_payload( $data, $is_female ) );

		foreach ( Shoval_Intake_Settings::gemini_models() as $model ) {
			for ( $try = 1; $try <= 2; $try++ ) {
				$response = wp_remote_post(
					'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent',
					array(
						'timeout' => 45,
						'headers' => array(
							'Content-Type'   => 'application/json',
							'x-goog-api-key' => $key,
						),
						'body'    => $body,
					)
				);
				// 503 = model temporarily overloaded: wait a moment and try once more.
				if ( $try === 1 && ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) === 503 ) {
					sleep( 3 );
					continue;
				}
				break;
			}

			if ( is_wp_error( $response ) ) {
				error_log( 'Shoval Intake: Gemini (' . $model . ') request failed: ' . $response->get_error_message() );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$json = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( $code !== 200 ) {
				$message = isset( $json['error']['message'] ) ? $json['error']['message'] : 'HTTP ' . $code;
				error_log( 'Shoval Intake: Gemini (' . $model . ') error: ' . substr( $message, 0, 300 ) );
				if ( $code === 429 || ( isset( $json['error']['status'] ) && $json['error']['status'] === 'RESOURCE_EXHAUSTED' ) ) {
					break;
				}
				continue;
			}

			$text   = isset( $json['candidates'][0]['content']['parts'][0]['text'] ) ? $json['candidates'][0]['content']['parts'][0]['text'] : '';
			$parsed = self::validate_ai_output( json_decode( $text, true ), self::clinical_text( $data ) );
			if ( $parsed ) {
				$parsed['model'] = $model;
				return $parsed;
			}
			error_log( 'Shoval Intake: Gemini (' . $model . ') returned an unusable response.' );
		}
		return null;
	}

	/** Everything the patient wrote that Gemini sees (used to check its quotes). */
	private static function clinical_text( array $data ) {
		$parts = array( self::field( $data, 'referralReason' ), self::field( $data, 'previousDocuments' ) );
		foreach ( self::current_meds( $data ) as $m ) {
			$parts[] = $m['name'] . ' ' . ( isset( $m['dosage'] ) ? $m['dosage'] : '' );
		}
		foreach ( self::past_meds( $data ) as $m ) {
			$parts[] = implode( ' ', array_map( 'strval', $m ) );
		}
		return implode( "\n", $parts );
	}

	private static function normalize_for_match( $text ) {
		$text = mb_strtolower( (string) $text );
		$text = str_replace( array( '"', "'", '״', '׳', '`', '“', '”', '‘', '’' ), '', $text );
		$text = preg_replace( '/[\s\p{P}]+/u', ' ', $text );
		return trim( $text );
	}

	/** A quote counts only if it has at least 3 letters and really appears in the patient's text. */
	private static function quote_is_grounded( $quote, $source ) {
		$quote = self::normalize_for_match( $quote );
		if ( preg_match_all( '/\p{L}/u', $quote ) < 3 ) {
			return false;
		}
		return mb_strpos( self::normalize_for_match( $source ), $quote ) !== false;
	}

	private static function validate_ai_output( $out, $source ) {
		if ( ! is_array( $out ) ) {
			return null;
		}
		$is_blank = function ( $s ) {
			return trim( (string) $s ) === '' || strpos( (string) $s, '___' ) !== false;
		};

		$diagnoses = array();
		if ( isset( $out['diagnoses'] ) && is_array( $out['diagnoses'] ) ) {
			foreach ( array_slice( $out['diagnoses'], 0, 6 ) as $d ) {
				if ( ! is_array( $d ) || ! isset( $d['name'] ) || $is_blank( $d['name'] ) ) {
					continue;
				}
				if ( ! isset( $d['evidence'] ) || ! self::quote_is_grounded( $d['evidence'], $source ) ) {
					error_log( 'Shoval Intake: dropped an AI diagnosis that is not supported by a quote from the questionnaire.' );
					continue;
				}
				$icd = isset( $d['icd10'] ) ? strtoupper( trim( preg_replace( '/^ICD-?10:?\s*/i', '', (string) $d['icd10'] ) ) ) : '';
				if ( ! preg_match( '/^[A-TV-Z][0-9][0-9AB](\.[0-9A-TV-Z]{1,4})?$/', $icd ) ) {
					$icd = '';
				}
				$diagnoses[] = array(
					'name'  => sanitize_text_field( mb_substr( (string) $d['name'], 0, 200 ) ),
					'icd10' => $icd,
				);
			}
		}

		$impression = isset( $out['clinicalImpression'] ) && is_string( $out['clinicalImpression'] ) && ! $is_blank( $out['clinicalImpression'] )
			? sanitize_textarea_field( mb_substr( $out['clinicalImpression'], 0, 1500 ) )
			: '';

		$plan = array();
		if ( isset( $out['treatmentPlan'] ) && is_array( $out['treatmentPlan'] ) ) {
			foreach ( array_slice( $out['treatmentPlan'], 0, 6 ) as $item ) {
				if ( is_string( $item ) && ! $is_blank( $item ) ) {
					$plan[] = sanitize_textarea_field( mb_substr( preg_replace( '/^\s*\d+[.)]\s*/u', '', $item ), 0, 1500 ) );
				}
			}
		}

		// The impression and plan must match the diagnoses; without grounded diagnoses, drop them too.
		if ( ! $diagnoses ) {
			$impression = '';
			$plan       = array();
		}

		return array(
			'diagnoses'          => $diagnoses,
			'clinicalImpression' => $impression,
			'treatmentPlan'      => $plan,
		);
	}

	/* ---------------------------------------------------------------------
	 * Keyword rules (used when Gemini is off, fails or finds nothing)
	 * ------------------------------------------------------------------- */

	/**
	 * Each term is [ 'he', stem-regex ] (matched as a whole word, optionally
	 * with Hebrew prefix letters) or [ 'en', regex, case_sensitive ].
	 */
	private static function terms() {
		return array(
			'autism'     => array(
				array( 'he', 'אוטיזם' ), array( 'he', 'אוטיסט(?:ית|ים|יות|י)?' ), array( 'he', 'אספרגר' ),
				array( 'he', 'על ה?רצף' ), array( 'he', 'ספקטרום' ),
				array( 'en', 'ASD|PDD', false ), array( 'en', 'autis(?:m|tic)', false ), array( 'en', 'asperger\'?s?', false ),
			),
			'scd'        => array(
				array( 'he', 'תקשורת חברתית' ), array( 'he', 'קשיי תקשורת' ), array( 'he', 'הפרעת תקשורת' ),
				array( 'he', 'תקשורתי(?:ת|ים|ות)?' ), array( 'en', 'SCD', false ),
			),
			'adhd'       => array(
				array( 'he', 'קשב' ), array( 'he', 'ריכוז' ), array( 'he', 'היפראקטיבי(?:ת|ים|ות)?' ), array( 'he', 'היפראקטיביות' ),
				array( 'he', 'ריטלין' ), array( 'he', 'קונצרטה' ), array( 'he', 'אטנט' ), array( 'he', 'ויו?ואנס' ),
				array( 'he', 'פוקלין' ), array( 'he', 'אינטוניב' ), array( 'he', 'גואנפ[סצ]ין' ), array( 'he', 'מתילפנידאט' ),
				array( 'en', 'ADHD', false ), array( 'en', 'ADD', true ), array( 'en', 'attention[- ]deficit', false ),
				array( 'en', 'ritalin|concerta|attent|vyvanse|focalin|intuniv|guanfacine|methylphenidate', false ),
			),
			'anxiety'    => array(
				array( 'he', 'חרד(?:ה|ות|תי|תית|תיים|תיות)' ), array( 'he', 'פאניקה' ), array( 'he', 'פוביה' ), array( 'he', 'פוביות' ),
				array( 'en', 'anxiety|anxious|panic|phobia', false ),
			),
			'depression' => array(
				array( 'he', 'דיכאון' ), array( 'he', 'דכאון' ), array( 'he', 'דיכאוני(?:ת|ים|ות)?' ), array( 'he', 'מצב רוח ירוד' ),
				array( 'en', 'depress(?:ion|ive|ed)', false ),
			),
			'ocd'        => array(
				array( 'he', 'טורדנ(?:י|ית|יים|יות|ות)' ), array( 'he', 'כפייתי(?:ת|ים|ות)?' ), array( 'he', 'כפייתיות' ),
				array( 'he', 'אובססיבי(?:ת|ים|ות)?' ), array( 'en', 'OCD', false ), array( 'en', 'obsessive', false ),
			),
			'learning'   => array(
				array( 'he', 'דיסלקצי(?:ה|ות)' ), array( 'he', 'דיסגרפי(?:ה|ות)' ), array( 'he', 'דיסקלקולי(?:ה|ות)' ),
				array( 'he', 'לקו(?:ת|יות) למידה' ), array( 'en', 'dyslexi(?:a|c)', false ), array( 'en', 'learning (?:disabilit(?:y|ies)|disorders?)', false ),
			),
			'tics'       => array(
				array( 'he', 'טיק(?:ים)?' ), array( 'he', 'טורט' ), array( 'en', 'tics?|tourette\'?s?', false ),
			),
		);
	}

	private static function term_regex( array $term ) {
		if ( $term[0] === 'he' ) {
			return '/(?<![\p{L}\p{N}])(?:[והבכלמש]{1,3})?(?:' . $term[1] . ')(?![\p{L}\p{N}])/u';
		}
		return '/(?<![A-Za-z])(?:' . $term[1] . ')(?![A-Za-z])/u' . ( $term[2] ? '' : 'i' );
	}

	/** True if any term appears in the text and is not directly negated ("ללא", "אין", "לא" ...). */
	public static function mentions( $text, array $terms ) {
		foreach ( $terms as $term ) {
			if ( ! preg_match_all( self::term_regex( $term ), $text, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $matches[0] as $match ) {
				$before = mb_strcut( $text, max( 0, $match[1] - 60 ), min( 60, $match[1] ) );
				if ( ! preg_match( '/(?:^|[\s,.;:(])(?:ללא|אין|לא|בלי|שולל|שלילת|no|not|without)(?:\s+\S+){0,2}\s*$/iu', $before ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function infer_from_rules( array $data, $full_name, $f ) {
		$meds = array();
		foreach ( self::current_meds( $data ) as $m ) {
			$meds[] = $m['name'] . ' ' . ( isset( $m['dosage'] ) ? $m['dosage'] : '' );
		}
		foreach ( self::past_meds( $data ) as $m ) {
			$meds[] = $m['medication'] . ' ' . ( isset( $m['administration'] ) ? $m['administration'] : '' );
		}
		$text = implode( "\n", array( self::field( $data, 'referralReason' ), self::field( $data, 'previousDocuments' ), implode( "\n", $meds ) ) );

		$t        = self::terms();
		$autism   = self::mentions( $text, $t['autism'] );
		$scd      = ! $autism && self::mentions( $text, $t['scd'] );
		$adhd     = self::mentions( $text, $t['adhd'] );
		$anxiety  = self::mentions( $text, $t['anxiety'] );
		$depress  = self::mentions( $text, $t['depression'] );
		$ocd      = self::mentions( $text, $t['ocd'] );
		$learning = self::mentions( $text, $t['learning'] );
		$tics     = self::mentions( $text, $t['tics'] );

		$dx = array();
		if ( $autism ) {
			$dx[] = array( 'name' => 'Autism spectrum disorder (ASD)', 'icd10' => 'F84.0' );
		} elseif ( $scd ) {
			$dx[] = array( 'name' => 'Social communications disorder (SCD)', 'icd10' => 'F80.82' );
		}
		if ( $adhd ) {
			$dx[] = array( 'name' => 'ADHD (dysregulation disorder)', 'icd10' => 'F90.9' );
		}
		if ( $anxiety ) {
			$dx[] = array( 'name' => 'Anxiety disorder', 'icd10' => 'F41.9' );
		}
		if ( $depress ) {
			$dx[] = array( 'name' => 'Depressive disorder', 'icd10' => 'F32.9' );
		}
		if ( $ocd ) {
			$dx[] = array( 'name' => 'Obsessive-compulsive disorder (OCD)', 'icd10' => 'F42' );
		}
		if ( $learning ) {
			$dx[] = array( 'name' => 'Specific learning disorder', 'icd10' => 'F81.9' );
		}
		if ( $tics ) {
			$dx[] = array( 'name' => 'Tic disorder / Tourette syndrome', 'icd10' => 'F95.2' );
		}

		$diagnosed  = $f ? 'אובחנה' : 'אובחן';
		$suffering  = $f ? 'כסובלת' : 'כסובל';
		$lead       = $full_name . ' ' . $diagnosed . ' במרפאתי ' . $suffering . ' מ';
		$impression = '';
		$plan       = array();

		if ( $autism && $adhd ) {
			$impression = $lead . 'הפרעה על הספקטרום האוטיסטי (Autism Spectrum Disorder - ASD) ועימה הפרעת קשב וריכוז (הפרעת ויסות).';
			$plan       = array(
				'טיפול פרטני בפסיכותרפיה, לרבות הדרכת הורים סדירה, בהשתתפות שני ההורים. גישה מועדפת קוגניטיבית-התנהגותית, כולל התמקדות במיומנויות ויסות, תפקוד (בהתאם לגילו) ובדגש על מיומנויות בין אישיות ותקשורתיות. כן יוכל להסתייע מאוד מקבוצה חברתית טיפולית.',
				'בהמשך ניתן יהיה לשקול בהתאם להשתלבותו ולתפקודו במסגרת בית הספר הקצאת סל שילוב במסגרת החינוכית ע"י ועדת אפיון וזכאות לצורך סיוע לימודי, התנהגותי ורגשי, בהתאם לצרכים המשתנים, כגון: תכנית תפקודית פרטנית, הוראה מתקנת, שעות שילוב, טיפול אישי, טיפול קבוצתי. ממליץ להעביר סיכום איבחון זה לבית הספר על מנת שהוא יערך בהתייחסות בהתאם לאיבחון העדכני.',
				'איזון תרופתי ומעקב: בחינת מענה תרופתי לשיפור הויסות והקשב (כגון Guanfacine / ממריצים) בהתאם למשקל ולתגובה הקלינית. המשך מעקב פסיכיאטרי רציף וסדיר במרפאה.',
			);
		} elseif ( $autism ) {
			$impression = $lead . 'הפרעה על הספקטרום האוטיסטי (Autism Spectrum Disorder - ASD).';
			$plan       = array(
				'טיפול רגשי פרטני ממוקד תקשורת וקשר בינאישי, בליווי הדרכת הורים שוטפת לשני ההורים.',
				'פנייה לוועדת אפיון וזכאות לקביעת סל שילוב ומענים חינוכיים מותאמים (תכנית תפקודית, סייעת/משלבת, שעות שילוב, טיפולים פרא-רפואיים).',
				'השתלבות בקבוצה לפיתוח מיומנויות חברתיות והמשך מעקב קליני סדיר במרפאה.',
			);
		} elseif ( $scd && $adhd ) {
			$impression = $lead . 'הפרעה מורכבת הכוללת הפרעה בתקשורת החברתית ועימה הפרעת קשב וריכוז (הפרעת ויסות).';
			$plan       = array(
				'טיפול פרטני בפסיכותרפיה, לרבות הדרכת הורים סדירה, בהשתתפות שני ההורים. גישה מועדפת קוגניטיבית-התנהגותית, כולל התמקדות במיומנויות ויסות, תפקוד ובדגש על מיומנויות בין אישיות ותקשורתיות.',
				'הקצאת סל שילוב במסגרת החינוכית ע"י ועדת אפיון וזכאות (הוראה מתקנת, שעות שילוב, טיפול רגשי) בהתאם לאיבחון העדכני.',
				'איזון תרופתי לשיפור הויסות והקשב, והמשך מעקב פסיכיאטרי רציף וסדיר במרפאה.',
			);
		} elseif ( $adhd ) {
			$impression = $lead . 'הפרעת קשב וריכוז (ADHD) המלווה בקשיים בתפקודים ניהוליים וקשיי ויסות.';
			$plan       = array(
				'טיפול תרופתי קבוע ומותאם אישית בתכשיר ממריץ / מגביר קשב (כגון מתילפנידאט / אטנט) בהתאם למשקל ולתגובה הקלינית.',
				'הדרכת הורים ממוקדת לסמכות הורית, ניהול שגרת יום וכלים להתמודדות עם מוסחות ואי-שקט.',
				'התאמות לימודיות במסגרת בית הספר: הארכת זמן במבחנים, תיווך הוראות, הימנעות מהסחות דעת והפסקות יזומות.',
			);
		} elseif ( $anxiety ) {
			$impression = $lead . 'הפרעת חרדה המשפיעה על התפקוד היומיומי והרגשי.';
			$plan       = array(
				'טיפול פסיכותרפי קוגניטיבי-התנהגותי (CBT) ממוקד חרדה, חשיפות הדרגתיות ורכישת מנגנוני הרגעה עצמית.',
				'הדרכת הורים סדירה למניעת שימור הימנעויות ולחיזוק תחושת המסוגלות והביטחון בבית ובמסגרת החינוכית.',
				'שקילת שילוב טיפול תרופתי תומך (ממשפחת ה-SSRI) במידת הצורך בהתאם לעוצמת הסימפטומים, לצד מעקב פסיכיאטרי תקופתי.',
			);
		}

		return array(
			'diagnoses'          => $dx,
			'clinicalImpression' => $impression,
			'treatmentPlan'      => $plan,
		);
	}
}
