<?php
/**
 * Builds the clinical-summary Word document (.docx) in Prof. Shoval's letter format.
 *
 * Port of REPORT_AI/docx_generator.ts; the XML mirrors what the "docx" npm
 * package produced. Layout rules:
 *  - Two-column tables are plain left-to-right: cell 0 = left (English),
 *    cell 1 = right (Hebrew). Never use bidiVisual on tables.
 *  - Fax number is 03-5471283, as on the official letterhead.
 *  - The stamp box is right-aligned, brain logo on the left inside the box.
 */

defined( 'ABSPATH' ) || exit;

class Shoval_Intake_Docx {

	const NAVY  = '002060';
	const DARK  = '1E293B';
	const GREY  = '94A3B8';

	// A4 (11906 twips) minus 1200 left and right margins: tables span exactly the text width,
	// so the Hebrew letterhead lines up with the right edge of the body text.
	const TEXT_W = 9506;
	const HALF_W = 4753;

	const NS_W   = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	const NS_R   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	const NS_WP  = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
	const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

	/**
	 * @param array $s ClinicalSummaryStructured (see Shoval_Intake_AI::build_summary()).
	 * @return string The .docx file contents.
	 */
	public static function generate( array $s ) {
		$stamp_png = self::stamp_image();
		$body      = self::body( $s, $stamp_png !== '' );

		$document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="' . self::NS_W . '" xmlns:r="' . self::NS_R . '" xmlns:wp="' . self::NS_WP . '">'
			. '<w:body>' . $body
			. '<w:sectPr><w:footerReference w:type="default" r:id="rId3"/>'
			. '<w:pgSz w:w="11906" w:h="16838" w:orient="portrait"/>'
			. '<w:pgMar w:top="1080" w:right="1200" w:bottom="1080" w:left="1200" w:header="708" w:footer="708" w:gutter="0"/>'
			. '<w:pgNumType/><w:bidi w:val="0"/><w:docGrid w:linePitch="360"/></w:sectPr>'
			. '</w:body></w:document>';

		$footer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:ftr xmlns:w="' . self::NS_W . '" xmlns:r="' . self::NS_R . '">' . self::footer_table() . '</w:ftr>';

		$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' . self::NS_REL . '">'
			. '<Relationship Id="rId1" Type="' . self::NS_R . '/styles" Target="styles.xml"/>'
			. '<Relationship Id="rId2" Type="' . self::NS_R . '/settings" Target="settings.xml"/>'
			. '<Relationship Id="rId3" Type="' . self::NS_R . '/footer" Target="footer1.xml"/>'
			. ( $stamp_png !== '' ? '<Relationship Id="rId4" Type="' . self::NS_R . '/image" Target="media/stamp.png"/>' : '' )
			. '</Relationships>';

		$now   = gmdate( 'Y-m-d\TH:i:s\Z' );
		$files = array(
			'[Content_Types].xml'          => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
				. '<Default Extension="png" ContentType="image/png"/>'
				. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
				. '<Default Extension="xml" ContentType="application/xml"/>'
				. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
				. '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
				. '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
				. '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
				. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
				. '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
				. '</Types>',
			'_rels/.rels'                  => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' . self::NS_REL . '">'
				. '<Relationship Id="rId1" Type="' . self::NS_R . '/officeDocument" Target="word/document.xml"/>'
				. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
				. '<Relationship Id="rId3" Type="' . self::NS_R . '/extended-properties" Target="docProps/app.xml"/>'
				. '</Relationships>',
			'docProps/core.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
				. '<dc:title>' . self::esc( 'סיכום הערכה קלינית ותוכנית טיפולית' ) . '</dc:title>'
				. '<dc:creator>' . self::esc( "מרפאת פרופ' גל שובל" ) . '</dc:creator>'
				. '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
				. '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
				. '</cp:coreProperties>',
			'docProps/app.xml'             => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Shoval Intake</Application></Properties>',
			'word/document.xml'            => $document,
			'word/_rels/document.xml.rels' => $rels,
			'word/styles.xml'              => self::styles_xml(),
			'word/settings.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<w:settings xmlns:w="' . self::NS_W . '"><w:evenAndOddHeaders w:val="false"/>'
				. '<w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat>'
				. '</w:settings>',
			'word/footer1.xml'             => $footer,
		);
		if ( $stamp_png !== '' ) {
			$files['word/media/stamp.png'] = $stamp_png;
		}

		return self::zip( $files );
	}

	/* ---------------------------------------------------------------------
	 * Document body
	 * ------------------------------------------------------------------- */

	private static function body( array $s, $has_image ) {
		$x         = '';
		$is_female = isset( $s['patient']['gender'] ) && $s['patient']['gender'] === 'נקבה';
		$full_name = ! empty( $s['patient']['fullName'] ) ? $s['patient']['fullName'] : 'המטופל/ת';
		$none      = array( 'none' );
		$navy14    = array( 'single', 14, self::NAVY );

		// 1. Letterhead: English (left) | Hebrew (right), navy rule underneath.
		$en = function ( $text, $size, $o = array() ) {
			return self::p( array( self::r( $text, $o + array( 'size' => $size, 'color' => self::NAVY ) ) ), array( 'align' => 'left', 'before' => 20, 'after' => isset( $o['after'] ) ? $o['after'] : 20, 'line' => 240 ) );
		};
		$he = function ( $text, $size, $o = array() ) {
			return self::p( array( self::r( $text, $o + array( 'size' => $size, 'color' => self::NAVY, 'rtl' => true ) ) ), array( 'bidi' => true, 'align' => 'right', 'before' => 20, 'after' => isset( $o['after'] ) ? $o['after'] : 20, 'line' => 240 ) );
		};
		$head_borders = array( 'top' => $none, 'left' => $none, 'bottom' => $navy14, 'right' => $none );
		$x .= self::table(
			array( self::HALF_W, self::HALF_W ),
			array( 'top' => $none, 'left' => $none, 'bottom' => $navy14, 'right' => $none, 'insideH' => $none, 'insideV' => $none ),
			array(
				array(
					self::tc(
						$en( 'Prof. Gal Shoval, M.D.', 22, array( 'bold' => true ) )
						. $en( 'Adult Psychiatrist', 20, array( 'bold' => true ) )
						. $en( 'Child & Adolescent Psychiatrist', 20, array( 'bold' => true ) )
						. $en( 'Geha Mental Health Center and Tel Aviv University', 18, array( 'italic' => true ) )
						. $en( 'Clinic: 81 Sokolov St., Ramat Hasharon', 18, array( 'underline' => true, 'after' => 40 ) ),
						self::HALF_W,
						array( 'borders' => $head_borders, 'margins' => array( 'left' => 0, 'right' => 60, 'bottom' => 120 ) )
					),
					self::tc(
						$he( 'פרופ׳ גל שובל', 22, array( 'bold' => true ) )
						. $he( 'מומחה בפסיכיאטריה כללית', 20, array( 'bold' => true ) )
						. $he( 'מומחה בפסיכיאטריה של הילד והמתבגר', 20, array( 'bold' => true ) )
						. $he( 'המרכז לבריאות הנפש "גהה" ואוניברסיטת תל-אביב', 18 )
						. $he( 'מרפאה פרטית: רח׳ סוקולוב 81, רמת השרון', 18, array( 'underline' => true, 'after' => 40 ) ),
						self::HALF_W,
						array( 'borders' => $head_borders, 'margins' => array( 'left' => 60, 'right' => 0, 'bottom' => 120 ) )
					),
				),
			)
		);

		// 2. Confidentiality notice.
		$x .= self::p( array( self::r( '- סודי רפואי -', array( 'bold' => true, 'size' => 22, 'color' => self::NAVY, 'rtl' => true ) ) ), array( 'bidi' => true, 'align' => 'center', 'before' => 140, 'after' => 120 ) );

		// 3. Date (left) | addressed to (right).
		$recipients = ! empty( $s['letterAddressedTo'] )
			? explode( "\n", $s['letterAddressedTo'] )
			: array( 'לכב׳', 'הרופא המטפל', 'וכל גורם טיפולי / יעוצי / חינוכי רלבנטי, כולל ועדת אפיון וזכאות' );
		$date       = ! empty( $s['date'] ) ? trim( $s['date'] ) : Shoval_Intake_AI::israel_date();
		$no_borders = array( 'top' => $none, 'left' => $none, 'bottom' => $none, 'right' => $none );
		$recipient_paras = '';
		foreach ( $recipients as $line ) {
			$recipient_paras .= self::p( array( self::r( $line, array( 'size' => 22, 'color' => self::DARK, 'rtl' => true ) ) ), array( 'bidi' => true, 'align' => 'right', 'before' => 15, 'after' => 15 ) );
		}
		$x .= self::table(
			array( 2738, self::TEXT_W - 2738 ),
			array( 'top' => $none, 'left' => $none, 'bottom' => $none, 'right' => $none, 'insideH' => $none, 'insideV' => $none ),
			array(
				array(
					self::tc( self::p( array( self::r( $date, array( 'size' => 22, 'color' => self::DARK ) ) ), array( 'align' => 'left', 'before' => 20, 'after' => 20 ) ), 2738, array( 'borders' => $no_borders, 'margins' => array( 'left' => 0, 'right' => 60 ) ) ),
					self::tc( $recipient_paras, self::TEXT_W - 2738, array( 'borders' => $no_borders, 'margins' => array( 'left' => 60, 'right' => 0 ) ) ),
				),
			)
		);

		// 4. Subject line.
		$subject = isset( $s['subject'] ) ? (string) $s['subject'] : '';
		if ( $subject === '' ) {
			$id      = ! empty( $s['patient']['idNumber'] ) ? ' ת.ז. ' . $s['patient']['idNumber'] : '';
			$subject = 'הנדון: ' . $full_name . $id . ' – סיכום הערכה קלינית ותוכנית טיפולית';
		}
		if ( mb_strpos( $subject, 'הנדון:' ) !== 0 ) {
			$subject = 'הנדון: ' . preg_replace( '/^הנדון\s*[:\-–]?\s*/u', '', $subject );
		}
		$x .= self::p( array( self::r( $subject, array( 'bold' => true, 'underline' => true, 'size' => 23, 'color' => self::NAVY, 'rtl' => true ) ) ), array( 'bidi' => true, 'align' => 'center', 'before' => 220, 'after' => 180 ) );

		// 5. Dx.
		$x .= self::ltr_heading( 'Dx.', 140, 50 );
		if ( ! empty( $s['diagnoses'] ) ) {
			$rows = array();
			foreach ( array_values( $s['diagnoses'] ) as $n => $d ) {
				$name = trim( preg_replace( '/^\.?\d+[.)]?\s+/', '', (string) $d['name'] ) );
				$icd  = trim( preg_replace( '/^ICD-10:\s*/i', '', isset( $d['icd10'] ) ? (string) $d['icd10'] : '' ) );
				if ( strpos( $name, '___' ) !== false || $name === '' ) {
					// Blank line for the physician: "1. ______" (left-to-right, like the rest of the Dx. area).
					$name = ( $n + 1 ) . '. ' . str_repeat( '_', 48 );
					$icd  = str_repeat( '_', 10 );
				}
				$rows[] = array(
					self::tc( self::left_value( $name, 15, 15 ), 6845, array( 'borders' => $no_borders, 'margins' => array( 'top' => 25, 'left' => 0, 'bottom' => 25, 'right' => 60 ) ) ),
					self::tc( self::p( array( self::r( $icd, array( 'size' => 22, 'color' => self::DARK ) ) ), array( 'align' => 'right', 'before' => 15, 'after' => 15 ) ), self::TEXT_W - 6845, array( 'borders' => $no_borders, 'margins' => array( 'top' => 25, 'left' => 60, 'bottom' => 25, 'right' => 0 ) ) ),
				);
			}
			$x .= self::table( array( 6845, self::TEXT_W - 6845 ), array( 'top' => $none, 'left' => $none, 'bottom' => $none, 'right' => $none, 'insideH' => $none, 'insideV' => $none ), $rows );
		} else {
			$x .= self::ltr_text( '0', 20, 40 );
		}

		// 6. Other medical conditions.
		$other = isset( $s['otherMedicalConditions'] ) ? trim( (string) $s['otherMedicalConditions'] ) : '';
		$x    .= self::ltr_heading( 'Other medical conditions:', 120, 40 );
		$x    .= self::left_values( $other, 15, 60 );

		// 7. Rx. – one medication per line.
		$rx = isset( $s['rxTreatments'] ) ? trim( (string) $s['rxTreatments'] ) : '';
		$x .= self::ltr_heading( 'Rx.', 120, 40 );
		$x .= self::left_values( $rx, 15, 120 );

		// 8. Hebrew narrative.
		$x .= self::rtl_para( array( self::rr( 'שלום רב,' ) ), 120, 60 );

		if ( ! empty( $s['openingParagraph'] ) ) {
			$opening = preg_replace( '/^שלום רב,?\s*/u', '', $s['openingParagraph'] );
			foreach ( explode( "\n\n", $opening ) as $block ) {
				if ( trim( $block ) !== '' ) {
					$x .= self::rtl_para( array( self::rr( trim( $block ) ) ), 60, 60 );
				}
			}
		}

		if ( ! empty( $s['medicalDevelopmentalBackground'] ) ) {
			foreach ( explode( "\n", $s['medicalDevelopmentalBackground'] ) as $line ) {
				$line = trim( $line );
				if ( $line !== '' ) {
					$text = mb_strpos( $line, 'ברקע' ) === 0 ? $line : 'ברקע רפואי, מיילדותי והתפתחותי: ' . $line;
					$x   .= self::rtl_para( array( self::rr( $text ) ), 60, 60 );
				}
			}
		}

		// Mental status examination – blank lines for the physician unless given.
		$mse_lead = $is_female ? 'בבדיקתה המנטלית– ' : 'בבדיקתו המנטלית– ';
		$mse      = isset( $s['mentalStatusExamination'] ) ? (string) $s['mentalStatusExamination'] : '';
		if ( trim( $mse ) !== '' && strpos( $mse, '_____' ) === false ) {
			foreach ( explode( "\n", $mse ) as $line ) {
				$line = trim( $line );
				if ( $line !== '' ) {
					$text = mb_strpos( $line, 'בבדיקת' ) === 0 ? $line : $mse_lead . $line;
					$x   .= self::rtl_para( array( self::rr( $text ) ), 80, 60 );
				}
			}
		} else {
			// Blank ruled lines for the physician, with the heading kept on the same page as the lines.
			$label = $is_female ? 'בבדיקתה המנטלית:' : 'בבדיקתו המנטלית:';
			$x    .= self::p( array( self::rr( $label ) ), array( 'bidi' => true, 'keep' => true, 'align' => 'right', 'before' => 80, 'after' => 0, 'line' => 280 ) );
			$x    .= self::rule_lines( 4 );
		}

		// Clinical impression – entire paragraph bold.
		$impression = isset( $s['clinicalImpression'] ) ? (string) $s['clinicalImpression'] : '';
		if ( trim( $impression ) !== '' && strpos( $impression, '_____' ) === false ) {
			foreach ( explode( "\n", $impression ) as $line ) {
				if ( trim( $line ) !== '' ) {
					$x .= self::rtl_para( array( self::rr( trim( $line ), array( 'bold' => true ) ) ), 120, 80 );
				}
			}
		} else {
			$lead = $full_name . ( $is_female ? ' אובחנה במרפאתי כסובלת מ-' : ' אובחן במרפאתי כסובל מ-' );
			$x   .= self::p( array( self::rr( $lead, array( 'bold' => true ) ) ), array( 'bidi' => true, 'keep' => true, 'align' => 'right', 'before' => 160, 'after' => 0, 'line' => 280 ) );
			$x   .= self::rule_lines( 2 );
		}

		// 9. Treatment plan (the heading never stays alone at the bottom of a page).
		$x .= self::p(
			array( self::rr( 'בהתאם למצב' . ( $is_female ? 'ה' : 'ו' ) . ', הומלצה התכנית הטיפולית הבאה, אשר פורטה והוסברה בעל פה גם במרפאה:', array( 'bold' => true, 'underline' => true ) ) ),
			array( 'bidi' => true, 'keep' => true, 'align' => 'right', 'before' => 180, 'after' => 100, 'line' => 280 )
		);
		$plan        = isset( $s['treatmentPlan'] ) && is_array( $s['treatmentPlan'] ) ? $s['treatmentPlan'] : array();
		$plan_blanks = ! $plan;
		foreach ( $plan as $item ) {
			if ( strpos( (string) $item, '_____' ) !== false ) {
				$plan_blanks = true;
			}
		}
		if ( ! $plan_blanks ) {
			foreach ( array_values( $plan ) as $i => $item ) {
				$clean = preg_replace( '/^\d+[.)]\s*/', '', (string) $item );
				$x    .= self::rtl_para( array( self::rr( ( $i + 1 ) . '. ' ), self::rr( $clean ) ), 60, 60 );
			}
		} else {
			$x .= self::rule_lines( 3, true );
		}

		// 10. Closing – kept on the same page as the stamp.
		$x .= self::p(
			array( self::rr( ! empty( $s['closingGreeting'] ) ? $s['closingGreeting'] : 'בכבוד רב ובברכה,' ) ),
			array( 'bidi' => true, 'keep' => true, 'align' => 'right', 'before' => 180, 'after' => 100, 'line' => 280 )
		);

		// 11. Stamp box. The clinic contact details are in the page footer (every page), so they are
		// not repeated here – a second copy at the end of the body used to spill onto an empty page.
		$x .= self::stamp_table( $has_image );

		return $x;
	}

	/**
	 * A bilingual contact line such as "Tel: 03-5492263 :טל׳".
	 * Written as a left-to-right paragraph, so it reads "Tel: …" from the left and "טל׳: …" from the right.
	 * The Hebrew geresh (׳) belongs to the Hebrew word, unlike an ASCII apostrophe, which would jump to the wrong side.
	 */
	private static function contact_line( $text, $align, $size ) {
		return self::p( array( self::r( $text, array( 'size' => $size, 'color' => self::NAVY ) ) ), array( 'align' => $align, 'before' => 15, 'after' => 15 ) );
	}

	/**
	 * Full-width ruled lines for the physician to fill in (a borderless table whose rows are underlined).
	 * Real rules, unlike rows of "____", never wrap and always end exactly at both margins.
	 *
	 * @param int  $count    Number of lines.
	 * @param bool $numbered Start each line with "1.", "2." … (treatment plan).
	 */
	private static function rule_lines( $count, $numbered = false ) {
		$grey = array( 'single', 6, self::GREY );
		$none = array( 'none' );
		$rows = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$runs   = $numbered ? array( self::rr( $i . '.' ) ) : array();
			$rows[] = '<w:trPr><w:trHeight w:val="400" w:hRule="atLeast"/><w:cantSplit/></w:trPr>'
				. self::tc(
					self::p( $runs, array( 'bidi' => true, 'align' => 'right', 'before' => 120, 'after' => 0 ) ),
					self::TEXT_W,
					array( 'margins' => array( 'left' => 0, 'right' => 0 ) )
				);
		}
		return self::table(
			array( self::TEXT_W ),
			array( 'top' => $none, 'left' => $none, 'bottom' => $grey, 'right' => $none, 'insideH' => $grey, 'insideV' => $none ),
			array_map( function ( $row ) {
				return array( $row );
			}, $rows )
		);
	}

	private static function stamp_table( $has_image ) {
		$s10 = array( 'single', 10, self::NAVY );
		$s6  = array( 'single', 6, self::NAVY );
		$no  = array( 'none' );

		$logo = $has_image
			? '<w:r>' . self::drawing( 'rId4', 38, 38 ) . '</w:r>'
			: self::r( '🧠', array( 'size' => 28, 'color' => self::NAVY, 'font' => false ) );

		// The stamp never splits across pages: rows cannot break, and the first row is kept with the second.
		$no_split  = '<w:trPr><w:cantSplit/></w:trPr>';
		$logo_cell = self::tc(
			'<w:p><w:pPr><w:keepNext/><w:bidi w:val="0"/><w:spacing w:after="10" w:before="10"/><w:jc w:val="center"/></w:pPr>' . $logo . '</w:p>',
			1100,
			array(
				'borders' => array( 'top' => $s10, 'left' => $s10, 'bottom' => $s6, 'right' => $no ),
				'margins' => array( 'top' => 60, 'left' => 40, 'bottom' => 40, 'right' => 20 ),
			)
		);
		$name_cell = self::tc(
			self::p( array( self::r( 'פרופ׳ גל שובל', array( 'bold' => true, 'size' => 21, 'color' => self::NAVY, 'rtl' => true ) ) ), array( 'bidi' => true, 'keep' => true, 'align' => 'center', 'before' => 5, 'after' => 5 ) )
			. self::p( array( self::r( 'פסיכיאטר ילדים, נוער ומבוגרים', array( 'size' => 17, 'color' => self::NAVY, 'rtl' => true ) ) ), array( 'bidi' => true, 'keep' => true, 'align' => 'center', 'before' => 5, 'after' => 10 ) ),
			2900,
			array(
				'borders' => array( 'top' => $s10, 'left' => $no, 'bottom' => $s6, 'right' => $s10 ),
				'margins' => array( 'top' => 60, 'left' => 20, 'bottom' => 40, 'right' => 60 ),
			)
		);
		$license_cell = self::tc(
			self::p( array( self::r( 'מ.ר. 32238   |   מ.מ. 22646   |   מ.ר.מ 21535', array( 'bold' => true, 'size' => 15, 'color' => self::NAVY, 'rtl' => true ) ) ), array( 'bidi' => true, 'align' => 'center', 'before' => 10, 'after' => 10 ) ),
			4000,
			array(
				'span'    => 2,
				'borders' => array( 'top' => $s6, 'left' => $s10, 'bottom' => $s10, 'right' => $s10 ),
				'margins' => array( 'top' => 40, 'left' => 40, 'bottom' => 50, 'right' => 40 ),
			)
		);

		return self::table(
			array( 1100, 2900 ),
			array( 'top' => $s10, 'left' => $s10, 'bottom' => $s10, 'right' => $s10, 'insideH' => $s6, 'insideV' => $no ),
			array( array( $no_split . $logo_cell, $name_cell ), array( $no_split . $license_cell ) ),
			'right'
		);
	}

	private static function footer_table() {
		$navy14 = array( 'single', 14, self::NAVY );
		$none   = array( 'none' );
		return self::table(
			array( self::HALF_W, self::HALF_W ),
			array( 'top' => $navy14, 'left' => $none, 'bottom' => $none, 'right' => $none, 'insideH' => $none, 'insideV' => $none ),
			array(
				array(
					self::tc(
						self::contact_line( 'Cell: 054-4493441 :נייד', 'left', 17 ) . self::contact_line( 'Email: shovgal@tau.ac.il :אימייל', 'left', 17 ),
						self::HALF_W,
						array( 'margins' => array( 'top' => 50, 'left' => 0, 'bottom' => 20, 'right' => 60 ) )
					),
					self::tc(
						self::contact_line( 'Tel: 03-5492263 :טל׳', 'right', 17 ) . self::contact_line( 'Fax: 03-5471283 :פקס', 'right', 17 ),
						self::HALF_W,
						array( 'margins' => array( 'top' => 50, 'left' => 60, 'bottom' => 20, 'right' => 0 ) )
					),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------------- */

	private static function ltr_heading( $text, $before, $after ) {
		return self::p( array( self::r( $text, array( 'bold' => true, 'underline' => true, 'size' => 22, 'color' => self::DARK ) ) ), array( 'bidi' => false, 'keep' => true, 'align' => 'left', 'before' => $before, 'after' => $after, 'line' => 260 ) );
	}

	private static function ltr_text( $text, $before, $after ) {
		return self::p( array( self::r( $text, array( 'size' => 22, 'color' => self::DARK ) ) ), array( 'bidi' => false, 'align' => 'left', 'before' => $before, 'after' => $after, 'line' => 260 ) );
	}

	/** True if the text contains Hebrew letters. */
	private static function has_hebrew( $text ) {
		return (bool) preg_match( '/\p{Hebrew}/u', (string) $text );
	}

	/**
	 * One line of the left-aligned English area (Dx. / Other medical conditions / Rx.).
	 * A line that contains Hebrew (e.g. "ריטלין 10 מ"ג בבוקר") is written right-to-left, so its words
	 * stay in reading order, but is still placed on the left under its English heading.
	 */
	private static function left_value( $text, $before, $after ) {
		$hebrew = self::has_hebrew( $text );
		return self::p(
			array( self::r( $text, array( 'size' => 22, 'color' => self::DARK, 'rtl' => $hebrew ) ) ),
			array( 'bidi' => $hebrew, 'align' => 'left', 'before' => $before, 'after' => $after, 'line' => 260 )
		);
	}

	/** Several values, one per line ("\n"-separated, or "0" when empty). */
	private static function left_values( $text, $before, $after ) {
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", (string) $text ) ), 'strlen' ) );
		if ( ! $lines ) {
			$lines = array( '0' );
		}
		$x = '';
		foreach ( $lines as $i => $line ) {
			$x .= self::left_value( $line, $i === 0 ? $before : 0, $i === count( $lines ) - 1 ? $after : 0 );
		}
		return $x;
	}

	/** Hebrew run in the body text style (Arial 11pt, dark, RTL). */
	private static function rr( $text, array $o = array() ) {
		return self::r( $text, $o + array( 'size' => 22, 'color' => self::DARK, 'rtl' => true ) );
	}

	private static function rtl_para( array $runs, $before, $after ) {
		return self::p( $runs, array( 'bidi' => true, 'align' => 'right', 'before' => $before, 'after' => $after, 'line' => 280 ) );
	}

	/**
	 * Paragraph. $o: bidi (true = Hebrew, right-to-left), align (left|right|center, as seen on the page),
	 * before, after, line.
	 *
	 * Every paragraph states its direction explicitly, so no viewer has to guess it from its own locale.
	 * Alignment: in a right-to-left paragraph Word (and LibreOffice / Google Docs, which copy it) read
	 * jc="left" / jc="right" as start / end of the line – so jc="right" puts Hebrew text on the LEFT.
	 * A Hebrew paragraph that should sit on the right therefore gets no jc at all (start = right).
	 */
	private static function p( array $runs, array $o = array() ) {
		$rtl = ! empty( $o['bidi'] );
		$ppr = ! empty( $o['keep'] ) ? '<w:keepNext/>' : '';   // keep with the next paragraph (headings, closing + stamp)
		$ppr .= $rtl ? '<w:bidi/>' : '<w:bidi w:val="0"/>';
		$ppr .= '<w:spacing w:after="' . (int) ( isset( $o['after'] ) ? $o['after'] : 0 ) . '" w:before="' . (int) ( isset( $o['before'] ) ? $o['before'] : 0 ) . '"'
			. ( isset( $o['line'] ) ? ' w:line="' . (int) $o['line'] . '" w:lineRule="auto"' : '' ) . '/>';

		$align = isset( $o['align'] ) ? (string) $o['align'] : '';
		if ( $rtl ) {
			$align = $align === 'right' ? '' : ( $align === 'left' ? 'right' : $align );
		}
		if ( $align !== '' ) {
			$ppr .= '<w:jc w:val="' . $align . '"/>';
		}
		return '<w:p><w:pPr>' . $ppr . '</w:pPr>' . implode( '', $runs ) . '</w:p>';
	}

	/** Text run. $o: bold, italic, underline, size, color, rtl, font (false = none). */
	private static function r( $text, array $o = array() ) {
		$rpr = ( isset( $o['font'] ) && $o['font'] === false ) ? '' : '<w:rFonts w:ascii="Arial" w:cs="Arial" w:eastAsia="Arial" w:hAnsi="Arial"/>';
		if ( ! empty( $o['bold'] ) ) {
			$rpr .= '<w:b/><w:bCs/>';
		}
		if ( ! empty( $o['italic'] ) ) {
			$rpr .= '<w:i/><w:iCs/>';
		}
		if ( ! empty( $o['color'] ) ) {
			$rpr .= '<w:color w:val="' . $o['color'] . '"/>';
		}
		if ( ! empty( $o['size'] ) ) {
			$rpr .= '<w:sz w:val="' . (int) $o['size'] . '"/><w:szCs w:val="' . (int) $o['size'] . '"/>';
		}
		if ( ! empty( $o['underline'] ) ) {
			$rpr .= '<w:u w:val="single"/>';
		}
		if ( ! empty( $o['rtl'] ) ) {
			$rpr .= '<w:rtl/>';
		}

		$text = str_replace( "\r", '', (string) $text );
		if ( ! empty( $o['rtl'] ) && self::has_hebrew( $text ) ) {
			// Inside Hebrew text, keep a short English phrase on one line ("Autism Spectrum Disorder - ASD"):
			// a phrase broken across lines of a right-to-left paragraph reads out of order.
			$text = preg_replace_callback(
				'/[A-Za-z0-9][A-Za-z0-9\-]*(?: [A-Za-z0-9\-]+)+/',
				function ( $m ) {
					return strlen( $m[0] ) <= 40 ? str_replace( ' ', "\u{00A0}", $m[0] ) : $m[0];
				},
				$text
			);
		}

		$parts = explode( "\n", $text );
		$body  = '';
		foreach ( $parts as $i => $part ) {
			if ( $i > 0 ) {
				$body .= '<w:br/>';
			}
			$body .= '<w:t xml:space="preserve">' . self::esc( $part ) . '</w:t>';
		}
		return '<w:r>' . ( $rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '' ) . $body . '</w:r>';
	}

	/** Table cell. $o: borders [side => spec], margins [side => twips], span. */
	private static function tc( $content, $width, array $o = array() ) {
		$pr = '<w:tcW w:type="dxa" w:w="' . (int) $width . '"/>';
		if ( ! empty( $o['span'] ) ) {
			$pr .= '<w:gridSpan w:val="' . (int) $o['span'] . '"/>';
		}
		if ( ! empty( $o['borders'] ) ) {
			$pr .= '<w:tcBorders>';
			foreach ( array( 'top', 'left', 'bottom', 'right' ) as $side ) {
				if ( isset( $o['borders'][ $side ] ) ) {
					$pr .= self::border( $side, $o['borders'][ $side ] );
				}
			}
			$pr .= '</w:tcBorders>';
		}
		if ( ! empty( $o['margins'] ) ) {
			$pr .= '<w:tcMar>';
			foreach ( array( 'top', 'left', 'bottom', 'right' ) as $side ) {
				if ( isset( $o['margins'][ $side ] ) ) {
					$pr .= '<w:' . $side . ' w:type="dxa" w:w="' . (int) $o['margins'][ $side ] . '"/>';
				}
			}
			$pr .= '</w:tcMar>';
		}
		return '<w:tc><w:tcPr>' . $pr . '</w:tcPr>' . $content . '</w:tc>';
	}

	/**
	 * @param int[]    $cols    Column widths (twips).
	 * @param array    $borders top/left/bottom/right/insideH/insideV => spec.
	 * @param array[]  $rows    Each row is a list of tc() strings.
	 * @param string   $align   Optional table alignment.
	 */
	private static function table( array $cols, array $borders, array $rows, $align = '' ) {
		// Tables are always left-to-right (cell 0 = left), stated explicitly: without bidiVisual="0"
		// some viewers (e.g. LibreOffice) mirror the columns of a document that contains Hebrew.
		$x = '<w:tbl><w:tblPr><w:bidiVisual w:val="0"/><w:tblW w:type="dxa" w:w="' . (int) array_sum( $cols ) . '"/>';
		if ( $align !== '' ) {
			$x .= '<w:jc w:val="' . $align . '"/>';
		}
		$x .= '<w:tblInd w:w="0" w:type="dxa"/><w:tblBorders>';
		foreach ( array( 'top', 'left', 'bottom', 'right', 'insideH', 'insideV' ) as $side ) {
			$x .= self::border( $side, isset( $borders[ $side ] ) ? $borders[ $side ] : array( 'none' ) );
		}
		$x .= '</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>';
		foreach ( $cols as $w ) {
			$x .= '<w:gridCol w:w="' . (int) $w . '"/>';
		}
		$x .= '</w:tblGrid>';
		foreach ( $rows as $cells ) {
			$x .= '<w:tr>' . implode( '', $cells ) . '</w:tr>';
		}
		return $x . '</w:tbl>';
	}

	/** Border spec: [ 'none' ] or [ 'single', size, color ]. */
	private static function border( $side, array $spec ) {
		if ( $spec[0] === 'none' ) {
			return '<w:' . $side . ' w:val="none"/>';
		}
		return '<w:' . $side . ' w:val="' . $spec[0] . '" w:color="' . $spec[2] . '" w:sz="' . (int) $spec[1] . '"/>';
	}

	private static function drawing( $rel_id, $width_px, $height_px ) {
		$cx = (int) $width_px * 9525;
		$cy = (int) $height_px * 9525;
		return '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
			. '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:effectExtent t="0" r="0" b="0" l="0"/>'
			. '<wp:docPr id="1" name="Clinic stamp" descr=""/>'
			. '<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
			. '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
			. '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
			. '<pic:nvPicPr><pic:cNvPr id="0" name="stamp.png" descr=""/><pic:cNvPicPr><a:picLocks noChangeAspect="1" noChangeArrowheads="1"/></pic:cNvPicPr></pic:nvPicPr>'
			. '<pic:blipFill><a:blip r:embed="' . $rel_id . '" cstate="none"/><a:srcRect/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
			. '<pic:spPr bwMode="auto"><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
			. '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>';
	}

	private static function esc( $text ) {
		$text = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $text );
		return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	private static function styles_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:styles xmlns:w="' . self::NS_W . '">'
			. '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:cs="Arial" w:eastAsia="Arial" w:hAnsi="Arial"/><w:lang w:val="en-US" w:bidi="he-IL"/></w:rPr></w:rPrDefault><w:pPrDefault/></w:docDefaults>'
			. '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
			. '<w:style w:type="character" w:default="1" w:styleId="DefaultParagraphFont"><w:name w:val="Default Paragraph Font"/><w:uiPriority w:val="1"/><w:semiHidden/><w:unhideWhenUsed/></w:style>'
			. '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:uiPriority w:val="99"/><w:semiHidden/><w:unhideWhenUsed/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
			. '</w:styles>';
	}

	/** The clinic's brain logo used inside the stamp box. */
	private static function stamp_image() {
		$path = SHOVAL_INTAKE_DIR . 'assets/stamp-logo.png';
		return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	}

	/* ---------------------------------------------------------------------
	 * Minimal ZIP writer (deflate), so the plugin does not depend on ZipArchive.
	 * ------------------------------------------------------------------- */

	public static function zip( array $files ) {
		$t        = getdate();
		$dos_time = ( $t['hours'] << 11 ) | ( $t['minutes'] << 5 ) | ( $t['seconds'] >> 1 );
		$dos_date = ( ( $t['year'] - 1980 ) << 9 ) | ( $t['mon'] << 5 ) | $t['mday'];

		$data    = '';
		$central = '';
		$count   = 0;
		foreach ( $files as $name => $content ) {
			$content    = (string) $content;
			$crc        = crc32( $content );
			$compressed = gzdeflate( $content, 6 );
			$method     = 8;
			if ( $compressed === false || strlen( $compressed ) >= strlen( $content ) ) {
				$compressed = $content;
				$method     = 0;
			}
			$offset = strlen( $data );
			$data  .= pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, $method, $dos_time, $dos_date, $crc, strlen( $compressed ), strlen( $content ), strlen( $name ), 0 )
				. $name . $compressed;
			$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, $dos_time, $dos_date, $crc, strlen( $compressed ), strlen( $content ), strlen( $name ), 0, 0, 0, 0, 0, $offset )
				. $name;
			$count++;
		}

		return $data . $central
			. pack( 'VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen( $central ), strlen( $data ), 0 );
	}
}
