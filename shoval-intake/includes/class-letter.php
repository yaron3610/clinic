<?php
/**
 * Turns an intake questionnaire into the content of the clinical-summary letter.
 *
 * The letter follows the clinic's fixed template: every Dx. and Rx. line comes
 * from the lists in the settings, and the background, mental status examination,
 * clinical impression and treatment plan are the clinic's standard text in the
 * patient's gender. Only the name, age, city, family and medications come from
 * the questionnaire. Nothing is sent to outside services.
 */

defined( 'ABSPATH' ) || exit;

class Shoval_Intake_Letter {

	const NAME_TOKEN = '{{שם}}';

	const CLINICIAN_NAME   = "פרופ' גל שובל";
	const CLINICIAN_ROLE   = 'פסיכיאטר ילדים, נוער ומבוגרים';
	const CLINICIAN_CLINIC = 'מרכז בריאות הנפש "גהה" ואוניברסיטת תל-אביב';

	/** Position in the family: questionnaire value => [ label on the form, male, female ]. */
	const FAMILY_POSITIONS = array(
		'1'        => array( 'בכור/ה', 'הבכור', 'הבכורה' ),
		'2'        => array( 'שני/ה', 'השני', 'השנייה' ),
		'3'        => array( 'שלישי/ת', 'השלישי', 'השלישית' ),
		'4'        => array( 'רביעי/ת', 'הרביעי', 'הרביעית' ),
		'5'        => array( 'חמישי/ת', 'החמישי', 'החמישית' ),
		'6'        => array( 'שישי/ת', 'השישי', 'השישית' ),
		'7'        => array( 'שביעי/ת', 'השביעי', 'השביעית' ),
		'8'        => array( 'שמיני/ת', 'השמיני', 'השמינית' ),
		'youngest' => array( 'צעיר/ה (הקטן/ה במשפחה)', 'הצעיר', 'הצעירה' ),
		'only'     => array( 'ילד/ה יחיד/ה', '', '' ),
	);

	/** @return array ClinicalSummaryStructured, consumed by Shoval_Intake_Docx and Shoval_Intake_Email. */
	public static function build_summary( array $data ) {
		$first     = self::field( $data, 'firstName' );
		$full_name = trim( $first . ' ' . self::field( $data, 'lastName' ) );
		$f         = self::field( $data, 'gender' ) === 'נקבה';
		$id_number = self::field( $data, 'idNumber' );

		return array(
			'patient'                        => array(
				'fullName' => $full_name,
				'idNumber' => $id_number,
				'age'      => self::field( $data, 'age' ),
				'city'     => self::field( $data, 'city' ),
				'gender'   => self::field( $data, 'gender' ),
			),
			'date'                           => self::letter_date(),
			'issued'                         => self::israel_date(),
			'letterAddressedTo'              => "לכב׳\nהרופא המטפל\nוכל גורם טיפולי / יעוצי / חינוכי רלבנטי, כולל ועדת אפיון וזכאות",
			'subject'                        => 'הנדון: ' . $full_name . ' ת.ז. ' . $id_number . ' – סיכום הערכה קלינית ותוכנית טיפולית',
			'diagnoses'                      => Shoval_Intake_Settings::diagnoses(),
			'otherMedicalConditions'         => '0',
			'rxTreatments'                   => implode( "\n", Shoval_Intake_Settings::medications() ),
			'openingParagraph'               => self::opening( $data, $full_name, $f ),
			'medicalDevelopmentalBackground' => self::by_gender( 'ברקע מיילדותי, רפואי והתפתחותי- {יליד|ילידת} ישראל, הריון תקין, לידה תקינה, ללא סיבוכים פרינטליים ידועים, מבחינת התפתחות פסיכומוטורית מוקדמת- ללא כל עיכובים פורמליים. {בריא|בריאה} מבחינה רפואית בדרך כלל, {ואינו נוטל|ואינה נוטלת} טיפול רפואי נוסף כלשהוא קבוע.', $f ),
			'medicationHistory'              => self::medication_history( $data, $f ),
			'mentalStatusExamination'        => self::by_gender( 'בבדיקת{ו|ה} המנטלית במרפאה- קשר עין תקין, {משתף|משתפת} פעולה באופן מלא, קיימת הדדיות מלאה, {מתבטא|מתבטאת} באופן בוגר, אך לקוני, קונקרטי, אפקט ללא גוון שולט, מתגוון ותואם, אי שקט קל מבחינה פסיכומוטורית, מוסחות קלה, ללא טיקים/תנועות סטריאוטיפיות, ללא עדות להפרעה פסיכוטית כעת או בעבר, ללא עדות להפרעת מצב רוח מג׳ורית כעת או בעבר, ללא מסוכנות מיידית {לעצמו|לעצמה} או לאחרים. אינטיליגנציה קלינית תקינה.', $f ),
			'clinicalImpression'             => $full_name . self::by_gender( ' {אובחן|אובחנה} במרפאתי {כסובל|כסובלת} מהפרעה מורכבת, הכוללת הפרעת קשב וריכוז (הפרעת ויסות) והפרעת חרדה.', $f ),
			'treatmentPlan'                  => self::treatment_plan( $first !== '' ? $first : $full_name, $f ),
			'closingGreeting'                => 'בכבוד רב ובברכה,',
			'signatoryName'                  => self::CLINICIAN_NAME,
			'signatoryRole'                  => self::CLINICIAN_ROLE,
			'signatoryClinic'                => self::CLINICIAN_CLINIC,
		);
	}

	/** The letter date: month and year, with the day left blank for the physician ("__/10/26"). */
	public static function letter_date( $timestamp = null ) {
		return '__/' . wp_date( 'm/y', $timestamp ? $timestamp : time(), new DateTimeZone( 'Asia/Jerusalem' ) );
	}

	/** Today's full date ("23/10/26"), used in file names. */
	public static function israel_date( $timestamp = null ) {
		return wp_date( 'd/m/y', $timestamp ? $timestamp : time(), new DateTimeZone( 'Asia/Jerusalem' ) );
	}

	/** "{male|female}" → the form that matches the patient's gender. */
	private static function by_gender( $text, $f ) {
		return preg_replace_callback(
			'/\{([^|{}]*)\|([^|{}]*)\}/u',
			function ( $m ) use ( $f ) {
				return $f ? $m[2] : $m[1];
			},
			$text
		);
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

	/** "נוטל כעת: … , ונטל בעבר: …" – or that there is no medication, now or in the past. */
	private static function medication_history( array $data, $f ) {
		$current = array();
		foreach ( self::current_meds( $data ) as $m ) {
			$current[] = trim( $m['name'] . ' ' . ( isset( $m['dosage'] ) ? $m['dosage'] : '' ) );
		}

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

		if ( ! $current && ! $past ) {
			return self::by_gender( '{אינו נוטל|אינה נוטלת} טיפול תרופתי, וגם לא {נטל|נטלה} בעבר.', $f );
		}
		$now  = $current
			? self::by_gender( '{נוטל|נוטלת} כעת: ', $f ) . implode( ', ', $current )
			: self::by_gender( '{אינו נוטל|אינה נוטלת} טיפול תרופתי כעת', $f );
		$then = $past
			? self::by_gender( '{ונטל|ונטלה} בעבר: ', $f ) . implode( '; ', $past )
			: self::by_gender( 'ולא {נטל|נטלה} טיפול תרופתי בעבר', $f );
		return $now . ', ' . $then . '.';
	}

	/**
	 * The parents as the questionnaire gives them: [ mother, father, legacy free text ].
	 * Submissions from before version 1.1 have one "parentsNames" field instead.
	 */
	public static function parents( array $data ) {
		return array( self::field( $data, 'motherName' ), self::field( $data, 'fatherName' ), self::field( $data, 'parentsNames' ) );
	}

	/** The parents for the clinic's screens: "אם: דנה, אב: יוסי". */
	public static function parents_label( array $data ) {
		list( $mother, $father, $legacy ) = self::parents( $data );
		$parts = array_filter( array( $mother !== '' ? 'אם: ' . $mother : '', $father !== '' ? 'אב: ' . $father : '' ) );
		return $parts ? implode( ', ', $parts ) : $legacy;
	}

	/** The family position as the parents chose it on the form ("בכור/ה"). */
	public static function family_position_label( array $data ) {
		$position = self::field( $data, 'familyPosition' );
		return array_key_exists( $position, self::FAMILY_POSITIONS ) ? self::FAMILY_POSITIONS[ $position ][0] : '';
	}

	/** Hebrew "and": attached to a Hebrew name ("ויוסי"), with a hyphen before anything else ("ו-John"). */
	private static function and_name( $name ) {
		return ( preg_match( '/^\p{Hebrew}/u', $name ) ? 'ו' : 'ו-' ) . $name;
	}

	private static function opening( array $data, $full_name, $f ) {
		$age      = self::field( $data, 'age' );
		$city     = self::field( $data, 'city' );
		$children = self::field( $data, 'childrenCount' );
		$position = self::field( $data, 'familyPosition' );

		list( $mother, $father, $legacy ) = self::parents( $data );
		if ( $mother !== '' && $father !== '' ) {
			$with_parents = self::by_gender( ' עם {הוריו|הוריה}, ', $f ) . $mother . ' ' . self::and_name( $father );
		} elseif ( $mother !== '' ) {
			$with_parents = self::by_gender( ' עם {אמו|אמה}, ', $f ) . $mother;
		} elseif ( $father !== '' ) {
			$with_parents = self::by_gender( ' עם {אביו|אביה}, ', $f ) . $father;
		} elseif ( $legacy !== '' ) {
			$with_parents = self::by_gender( ' עם {הוריו|הוריה}, ', $f ) . $legacy;
		} else {
			$with_parents = '';
		}

		$text = $full_name;
		if ( $age !== '' ) {
			$text .= ', ' . ( $f ? 'בת' : 'בן' ) . ' ' . $age . ( is_numeric( $age ) ? ' שנים' : '' );
		}
		if ( $city !== '' ) {
			$text .= ', ' . ( $f ? 'תושבת ' : 'תושב ' ) . $city;
			if ( $with_parents !== '' ) {
				$text .= ', בה ' . ( $f ? 'מתגוררת' : 'מתגורר' ) . $with_parents;
			}
		} elseif ( $with_parents !== '' ) {
			$text .= ', ' . ( $f ? 'מתגוררת' : 'מתגורר' ) . $with_parents;
		}
		$text .= '.';

		$count = ctype_digit( $children ) ? (int) $children : 0;
		if ( $position === 'only' || $count === 1 ) {
			$text .= self::by_gender( ' {הוא בן יחיד להוריו|היא בת יחידה להוריה}.', $f );
		} elseif ( array_key_exists( $position, self::FAMILY_POSITIONS ) ) {
			$text .= ' ' . ( $f ? 'היא ' : 'הוא ' ) . self::FAMILY_POSITIONS[ $position ][ $f ? 2 : 1 ]
				. ( $count > 1 ? ' מבין ' . $count . ' ילדים' : '' )
				. self::by_gender( ' {במשפחתו|במשפחתה} הגרעינית.', $f );
		} elseif ( $count > 1 ) {
			$text .= self::by_gender( ' {הוא אחד|היא אחת} מבין ' . $count . ' ילדים {במשפחתו|במשפחתה} הגרעינית.', $f );
		}

		// Who came to the appointment: one parent if only one is named, otherwise both.
		if ( $mother !== '' && $father === '' ) {
			$text .= self::by_gender( ' {אמו|אמה} נכחה בפגישה במרפאה ומסרה מידע רפואי ואנאמנסטי מפורט כנדרש.', $f );
		} elseif ( $father !== '' && $mother === '' ) {
			$text .= self::by_gender( ' {אביו|אביה} נכח בפגישה במרפאה ומסר מידע רפואי ואנאמנסטי מפורט כנדרש.', $f );
		} else {
			$text .= ' שני ההורים נכחו בפגישה במרפאה ומסרו מידע רפואי ואנאמנסטי מפורט כנדרש.';
		}

		// The referral reason is free text, so it gets its own sentence instead of being glued after "בשל".
		$reason = rtrim( self::one_line( self::field( $data, 'referralReason' ) ), " .\t" );
		$text  .= "\n\n" . ( $f ? 'הוערכה' : 'הוערך' ) . ' במרפאתי לראשונה היום לצורך איבחון אינטגרטיבי.'
			. ( $reason !== '' ? ' סיבת הפנייה: ' . $reason . '.' : '' );

		return $text;
	}

	/** The clinic's standard treatment plan – the same for every patient, in the patient's gender. */
	private static function treatment_plan( $name, $f ) {
		$plan = array(
			'טיפול פרטני רציף בפסיכותרפיה, כולל הדרכת הורים בהשתתפות שני ההורים. גישה שולטת מועדפת: קוגניטיבית-התנהגותית. כמו כן מומלץ מאוד המשך הטיפול הקבוצתי לשיפור המיומנויות החברתיות.',
			'תחילת טיפול תרופתי לשיפור הפרעת הקשב (הויסות) באמצעות גוואנפצין T. Guanfacine XR. יש להתחיל במינון של 1 מ"ג ליום. נטילה בערב, לאחר האוכל. הוסבר כי ההשפעה אינה מיידית.'
				. "\n" . 'ניתן לנסות קודם לכן גם טיפול תרופתי באמצעות ריטלין במינון של 5 מ"ג ואטנט במינון של 5 מ"ג. להערכתי זהו אינו הטיפול המיטבי {עבורו|עבורה} (ולכן המלצתי על גוואנפצין), אולם לאחר שהדבר יווכח, ממליץ על אישור הטיפול בגוואנפצין במסגרת סל הבריאות כקו שלישי לטיפול בהפרעת קשב.',
			'המשך מעקב חוזר בעוד חודש ימים.',
			'בהתאם {לתפקודו|לתפקודה} של ' . self::NAME_TOKEN . ' בבי"ס, לנוכח {קשייו|קשייה} ולצורך סיוע ושיפור {תפקודו|תפקודה} במסגרת החינוכית, ממליץ על קיום דיון רב-מקצועי במסגרת ועדת איפיון וזכאות לגבי אופי הסיוע וההתאמות להן {הוא זקוק|היא זקוקה}. לנוכח התמשכות ועוצמת {קשייו|קשייה}, נראה שיש צורך בהקצאה של סל שילוב רחב, ובו ינתנו כל או חלק מהנ"ל בהתאם לצרכים המשתנים: (א) תכנית טיפולית ותפקודית פרטנית, בהובלת יועצת ומחנכת ובתיאום עם {הוריו ועימו|הוריה ועימה}, להתמודדות עם הקשיים המרכזיים בכל נקודת זמן. כמו כן הוראה מתקנת/מותאמת. (ב) התאמות למידה בהתאם לאמור בחוזר מנכ"ל משרד החינוך לילדים עם קשיים אלה.',
		);
		// The name goes in after the gender forms, so nothing in it is read as a "{male|female}" pair.
		return array_map(
			function ( $item ) use ( $name, $f ) {
				return str_replace( self::NAME_TOKEN, $name, self::by_gender( $item, $f ) );
			},
			$plan
		);
	}
}
