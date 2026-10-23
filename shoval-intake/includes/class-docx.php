<?php
/**
 * Builds the clinical-summary Word document (.docx) in Prof. Shoval's letter format.
 *
 * Port of REPORT_AI/docx_generator.ts, with the clinic's "Letterhead Gal Shoval Final"
 * file as the page header and footer (logo, colours and page number as in that file)
 * and the clinic stamp from "חותמת6" under the closing. Layout rules:
 *  - Two-column tables are plain left-to-right: cell 0 = left (English),
 *    cell 1 = right (Hebrew). Never use bidiVisual on tables.
 *  - The letterhead is in the page header, so every page starts with it.
 *  - The stamp sits on the right under the closing line, on the same page.
 */

defined( 'ABSPATH' ) || exit;

class Shoval_Intake_Docx {

	const NAVY  = '002060';
	const DARK  = '1E293B';
	const CYAN  = '0099CC';  // dashed lines and contact line of the letterhead file

	// A4 (11906 twips) minus 1200 left and right margins: tables span exactly the text width.
	const TEXT_W = 9506;
	const HALF_W = 4753;

	// The letterhead's dashed lines reach 0.35" (504 twips) from the page edges, past the text margins.
	const RULE_IND = -696;
	const RULE_W   = 10898;

	const NS_W   = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	const NS_R   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	const NS_WP  = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
	const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

	/** Images from the letterhead and stamp files (assets/letter/). */
	const MEDIA = array( 'letterhead-en.emf', 'letterhead-he.emf', 'logo.png', 'icon-mail.png', 'icon-phone.png', 'icon-web.png', 'stamp.png' );

	/**
	 * @param array $s ClinicalSummaryStructured (see Shoval_Intake_Letter::build_summary()).
	 * @return string The .docx file contents.
	 */
	public static function generate( array $s ) {
		$media = self::media();
		$body  = self::body( $s, isset( $media['stamp.png'] ) );

		$document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="' . self::NS_W . '" xmlns:r="' . self::NS_R . '" xmlns:wp="' . self::NS_WP . '">'
			. '<w:body>' . $body
			. '<w:sectPr><w:headerReference w:type="default" r:id="rId3"/><w:footerReference w:type="default" r:id="rId4"/>'
			. '<w:pgSz w:w="11906" w:h="16838" w:orient="portrait"/>'
			. '<w:pgMar w:top="2098" w:right="1200" w:bottom="1440" w:left="1200" w:header="1020" w:footer="284" w:gutter="0"/>'
			. '<w:pgNumType w:start="1"/><w:bidi w:val="0"/><w:docGrid w:linePitch="360"/></w:sectPr>'
			. '</w:body></w:document>';

		$image = function ( $name ) use ( $media ) {
			return isset( $media[ $name ] ) ? array( self::NS_R . '/image', 'media/' . $name ) : null;
		};
		$files = array(
			'[Content_Types].xml'          => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
				. '<Default Extension="png" ContentType="image/png"/>'
				. '<Default Extension="emf" ContentType="image/x-emf"/>'
				. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
				. '<Default Extension="xml" ContentType="application/xml"/>'
				. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
				. '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
				. '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
				. '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
				. '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
				. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
				. '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
				. '</Types>',
			'_rels/.rels'                  => self::rels(
				array(
					'rId1' => array( self::NS_R . '/officeDocument', 'word/document.xml' ),
					'rId2' => array( 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', 'docProps/core.xml' ),
					'rId3' => array( self::NS_R . '/extended-properties', 'docProps/app.xml' ),
				)
			),
			'docProps/core.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
				. '<dc:title>' . self::esc( 'סיכום הערכה קלינית ותוכנית טיפולית' ) . '</dc:title>'
				. '<dc:creator>' . self::esc( "מרפאת פרופ' גל שובל" ) . '</dc:creator>'
				. '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:created>'
				. '<dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:modified>'
				. '</cp:coreProperties>',
			'docProps/app.xml'             => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Shoval Intake</Application></Properties>',
			'word/document.xml'            => $document,
			'word/_rels/document.xml.rels' => self::rels(
				array(
					'rId1' => array( self::NS_R . '/styles', 'styles.xml' ),
					'rId2' => array( self::NS_R . '/settings', 'settings.xml' ),
					'rId3' => array( self::NS_R . '/header', 'header1.xml' ),
					'rId4' => array( self::NS_R . '/footer', 'footer1.xml' ),
					'rId5' => $image( 'stamp.png' ),
				)
			),
			'word/styles.xml'              => self::styles_xml(),
			'word/settings.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<w:settings xmlns:w="' . self::NS_W . '"><w:evenAndOddHeaders w:val="false"/>'
				. '<w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat>'
				. '</w:settings>',
			'word/header1.xml'             => self::header_xml( $media ),
			'word/_rels/header1.xml.rels'  => self::rels(
				array(
					'rId1' => $image( 'letterhead-en.emf' ),
					'rId2' => $image( 'letterhead-he.emf' ),
					'rId3' => $image( 'logo.png' ),
				)
			),
			'word/footer1.xml'             => self::footer_xml( $media ),
			'word/_rels/footer1.xml.rels'  => self::rels(
				array(
					'rId1' => $image( 'icon-mail.png' ),
					'rId2' => $image( 'icon-phone.png' ),
					'rId3' => $image( 'icon-web.png' ),
					'rId4' => array( self::NS_R . '/hyperlink', 'mailto:shovalclinic@gmail.com', true ),
				)
			),
		);
		foreach ( $media as $name => $bytes ) {
			$files[ 'word/media/' . $name ] = $bytes;
		}

		return self::zip( $files );
	}

	/** @param array $list rId => [ type, target, external? ] (null entries are skipped). */
	private static function rels( array $list ) {
		$x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' . self::NS_REL . '">';
		foreach ( array_filter( $list ) as $id => $rel ) {
			$x .= '<Relationship Id="' . $id . '" Type="' . $rel[0] . '" Target="' . self::esc( $rel[1] ) . '"' . ( ! empty( $rel[2] ) ? ' TargetMode="External"' : '' ) . '/>';
		}
		return $x . '</Relationships>';
	}

	/** @return array file name => bytes, for the images that are present. */
	private static function media() {
		$out = array();
		foreach ( self::MEDIA as $name ) {
			$path = SHOVAL_INTAKE_DIR . 'assets/letter/' . $name;
			if ( is_readable( $path ) ) {
				$out[ $name ] = (string) file_get_contents( $path );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Letterhead (page header and footer, as in "Letterhead Gal Shoval Final")
	 * ------------------------------------------------------------------- */

	/**
	 * English credentials (left), logo (centre) and Hebrew credentials (right), placed on the page
	 * where the letterhead file has them, then the dashed cyan line. The body starts below it.
	 */
	private static function header_xml( array $media ) {
		$images = '';
		if ( isset( $media['letterhead-en.emf'] ) ) {
			$images .= self::anchor( 'rId1', 2174875, 765810, 302943, 299085, 101, 'Letterhead English' );
		}
		if ( isset( $media['letterhead-he.emf'] ) ) {
			$images .= self::anchor( 'rId2', 2006600, 765810, 5305540, 285115, 102, 'Letterhead Hebrew' );
		}
		if ( isset( $media['logo.png'] ) ) {
			$images .= self::anchor( 'rId3', 917575, 732155, 'center', 330200, 103, 'Clinic logo' );
		}

		// One paragraph of fixed height whose bottom border is the dashed line under the letterhead.
		$p = '<w:p><w:pPr><w:pBdr><w:bottom w:val="dashSmallGap" w:sz="8" w:space="1" w:color="' . self::CYAN . '"/></w:pBdr>'
			. '<w:bidi w:val="0"/><w:spacing w:before="0" w:after="0" w:line="855" w:lineRule="exact"/>'
			. '<w:ind w:left="' . self::RULE_IND . '" w:right="' . self::RULE_IND . '"/></w:pPr>' . $images . '</w:p>';

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:hdr xmlns:w="' . self::NS_W . '" xmlns:r="' . self::NS_R . '" xmlns:wp="' . self::NS_WP . '">' . $p . '</w:hdr>';
	}

	/** Dashed cyan line, then e-mail (left), mobile (centre) and website (right), then the page number. */
	private static function footer_xml( array $media ) {
		$rpr  = '<w:rFonts w:ascii="Calibri" w:cs="Calibri" w:eastAsia="Calibri" w:hAnsi="Calibri"/><w:b/><w:bCs/><w:color w:val="' . self::CYAN . '"/><w:spacing w:val="20"/><w:sz w:val="20"/><w:szCs w:val="20"/>';
		$text = function ( $t, $extra = '' ) use ( $rpr ) {
			return '<w:r><w:rPr>' . $rpr . $extra . '</w:rPr><w:t xml:space="preserve">' . self::esc( $t ) . '</w:t></w:r>';
		};
		$icon = function ( $name, $rel_id, $id ) use ( $media ) {
			return isset( $media[ $name ] ) ? '<w:r><w:rPr><w:position w:val="-6"/></w:rPr>' . self::inline( $rel_id, 168910, 168910, $id, $name ) . '</w:r>' : '';
		};
		$cell = function ( $runs, $align, $width ) {
			return self::tc(
				'<w:p><w:pPr><w:bidi w:val="0"/><w:spacing w:before="0" w:after="0"/><w:jc w:val="' . $align . '"/></w:pPr>' . $runs . '</w:p>',
				$width,
				array( 'margins' => array( 'top' => 300, 'left' => 0, 'bottom' => 0, 'right' => 0 ) )
			);
		};

		$none  = array( 'none' );
		$third = (int) ( self::RULE_W / 3 );
		$cols  = array( $third, self::RULE_W - 2 * $third, $third );
		$table = self::table(
			$cols,
			array( 'top' => array( 'dashSmallGap', 8, self::CYAN ), 'left' => $none, 'bottom' => $none, 'right' => $none, 'insideH' => $none, 'insideV' => $none ),
			array(
				array(
					$cell( $icon( 'icon-mail.png', 'rId1', 201 ) . $text( '  ' ) . '<w:hyperlink r:id="rId4" w:history="1">' . $text( 'shovalclinic@gmail.com', '<w:u w:val="single"/>' ) . '</w:hyperlink>', 'left', $cols[0] ),
					$cell( $icon( 'icon-phone.png', 'rId2', 202 ) . $text( ' 054-4493441' ), 'center', $cols[1] ),
					$cell( $icon( 'icon-web.png', 'rId3', 203 ) . $text( ' shovalclinic.co.il' ), 'right', $cols[2] ),
				),
			),
			'',
			self::RULE_IND
		);

		$num  = '<w:rPr><w:rFonts w:ascii="Calibri" w:cs="Calibri" w:eastAsia="Calibri" w:hAnsi="Calibri"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr>';
		$page = '<w:p><w:pPr><w:bidi w:val="0"/><w:spacing w:before="200" w:after="0"/><w:jc w:val="center"/></w:pPr>'
			. '<w:r>' . $num . '<w:fldChar w:fldCharType="begin"/></w:r>'
			. '<w:r>' . $num . '<w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
			. '<w:r>' . $num . '<w:fldChar w:fldCharType="separate"/></w:r>'
			. '<w:r>' . $num . '<w:t>1</w:t></w:r>'
			. '<w:r>' . $num . '<w:fldChar w:fldCharType="end"/></w:r></w:p>';

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:ftr xmlns:w="' . self::NS_W . '" xmlns:r="' . self::NS_R . '" xmlns:wp="' . self::NS_WP . '">' . $table . $page . '</w:ftr>';
	}

	/* ---------------------------------------------------------------------
	 * Document body
	 * ------------------------------------------------------------------- */

	private static function body( array $s, $has_stamp ) {
		$x         = '';
		$is_female = isset( $s['patient']['gender'] ) && $s['patient']['gender'] === 'נקבה';
		$full_name = ! empty( $s['patient']['fullName'] ) ? $s['patient']['fullName'] : 'המטופל/ת';
		$none      = array( 'none' );

		// 1. Confidentiality notice (the letterhead itself is in the page header).
		$x .= self::p( array( self::r( '- סודי רפואי -', array( 'bold' => true, 'size' => 22, 'color' => self::NAVY, 'rtl' => true ) ) ), array( 'bidi' => true, 'align' => 'center', 'before' => 0, 'after' => 120 ) );

		// 2. Date (left) | addressed to (right).
		$recipients = ! empty( $s['letterAddressedTo'] )
			? explode( "\n", $s['letterAddressedTo'] )
			: array( 'לכב׳', 'הרופא המטפל', 'וכל גורם טיפולי / יעוצי / חינוכי רלבנטי, כולל ועדת אפיון וזכאות' );
		$date       = ! empty( $s['date'] ) ? trim( $s['date'] ) : Shoval_Intake_Letter::letter_date();
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

		// 3. Subject line.
		$subject = isset( $s['subject'] ) ? (string) $s['subject'] : '';
		if ( $subject === '' ) {
			$id      = ! empty( $s['patient']['idNumber'] ) ? ' ת.ז. ' . $s['patient']['idNumber'] : '';
			$subject = 'הנדון: ' . $full_name . $id . ' – סיכום הערכה קלינית ותוכנית טיפולית';
		}
		if ( mb_strpos( $subject, 'הנדון:' ) !== 0 ) {
			$subject = 'הנדון: ' . preg_replace( '/^הנדון\s*[:\-–]?\s*/u', '', $subject );
		}
		$x .= self::p( array( self::r( $subject, array( 'bold' => true, 'underline' => true, 'size' => 23, 'color' => self::NAVY, 'rtl' => true ) ) ), array( 'bidi' => true, 'align' => 'center', 'before' => 200, 'after' => 160 ) );

		// 4. Dx. – every line of the clinic's list: name (left), ICD-10 (right).
		$x .= self::ltr_heading( 'Dx.', 120, 40 );
		if ( ! empty( $s['diagnoses'] ) ) {
			$rows = array();
			foreach ( $s['diagnoses'] as $d ) {
				$rows[] = array(
					self::tc( self::left_value( (string) $d['name'], 0, 0 ), 6845, array( 'borders' => $no_borders, 'margins' => array( 'top' => 10, 'left' => 0, 'bottom' => 10, 'right' => 60 ) ) ),
					self::tc( self::p( array( self::r( isset( $d['icd10'] ) ? (string) $d['icd10'] : '', array( 'size' => 22, 'color' => self::DARK ) ) ), array( 'align' => 'right', 'before' => 0, 'after' => 0, 'line' => 260 ) ), self::TEXT_W - 6845, array( 'borders' => $no_borders, 'margins' => array( 'top' => 10, 'left' => 60, 'bottom' => 10, 'right' => 0 ) ) ),
				);
			}
			$x .= self::table( array( 6845, self::TEXT_W - 6845 ), array( 'top' => $none, 'left' => $none, 'bottom' => $none, 'right' => $none, 'insideH' => $none, 'insideV' => $none ), $rows );
		} else {
			$x .= self::ltr_text( '0', 20, 40 );
		}

		// 5. Other medical conditions.
		$other = isset( $s['otherMedicalConditions'] ) ? trim( (string) $s['otherMedicalConditions'] ) : '';
		$x    .= self::ltr_heading( 'Other medical conditions:', 120, 40 );
		$x    .= self::left_values( $other, 0, 40 );

		// 6. Rx. – every line of the clinic's list, one per line.
		$rx = isset( $s['rxTreatments'] ) ? trim( (string) $s['rxTreatments'] ) : '';
		$x .= self::ltr_heading( 'Rx.', 120, 40 );
		$x .= self::left_values( $rx, 0, 120 );

		// 7. Hebrew narrative.
		$x .= self::rtl_para( array( self::rr( 'שלום רב,' ) ), 120, 60 );

		if ( ! empty( $s['openingParagraph'] ) ) {
			$opening = preg_replace( '/^שלום רב,?\s*/u', '', $s['openingParagraph'] );
			foreach ( explode( "\n\n", $opening ) as $block ) {
				if ( trim( $block ) !== '' ) {
					$x .= self::rtl_para( array( self::rr( trim( $block ) ) ), 60, 60 );
				}
			}
		}

		// Background, medications (now and in the past) and the mental status examination.
		foreach ( array( 'medicalDevelopmentalBackground', 'medicationHistory', 'mentalStatusExamination' ) as $key ) {
			foreach ( explode( "\n", isset( $s[ $key ] ) ? (string) $s[ $key ] : '' ) as $line ) {
				if ( trim( $line ) !== '' ) {
					$x .= self::rtl_para( array( self::rr( trim( $line ) ) ), 60, 60 );
				}
			}
		}

		// Clinical impression – entire paragraph bold – then an empty line before the treatment plan.
		foreach ( explode( "\n", isset( $s['clinicalImpression'] ) ? (string) $s['clinicalImpression'] : '' ) as $line ) {
			if ( trim( $line ) !== '' ) {
				$x .= self::rtl_para( array( self::rr( trim( $line ), array( 'bold' => true ) ) ), 120, 60 );
			}
		}
		$x .= self::rtl_para( array(), 0, 0 );

		// 8. Treatment plan (the heading never stays alone at the bottom of a page).
		$x .= self::p(
			array( self::rr( 'בהתאם למצב' . ( $is_female ? 'ה' : 'ו' ) . ', הומלצה התכנית הטיפולית הבאה, אשר פורטה והוסברה בעל פה גם במרפאה:', array( 'bold' => true, 'underline' => true ) ) ),
			array( 'bidi' => true, 'keep' => true, 'align' => 'right', 'before' => 60, 'after' => 100, 'line' => 280 )
		);
		$plan = isset( $s['treatmentPlan'] ) && is_array( $s['treatmentPlan'] ) ? $s['treatmentPlan'] : array();
		foreach ( array_values( $plan ) as $i => $item ) {
			$clean = preg_replace( '/^\d+[.)]\s*/', '', (string) $item );
			$x    .= self::rtl_para( array( self::rr( ( $i + 1 ) . '. ' ), self::rr( $clean ) ), 60, 80 );
		}

		// 9. Closing, kept on the same page as the stamp.
		$x .= self::p(
			array( self::rr( ! empty( $s['closingGreeting'] ) ? $s['closingGreeting'] : 'בכבוד רב ובברכה,' ) ),
			array( 'bidi' => true, 'keep' => true, 'align' => 'right', 'before' => 180, 'after' => 60, 'line' => 280 )
		);

		// 10. The clinic stamp, on the right under the closing: one 600 dpi picture of the stamp as Word
		// shows it in "חותמת6" (rounded black frame, brain, name, title and licence numbers), at its size there.
		if ( $has_stamp ) {
			$x .= '<w:p><w:pPr><w:keepLines/><w:bidi/><w:spacing w:after="0" w:before="0"/></w:pPr><w:r>'
				. self::inline( 'rId5', 1744980, 685800, 301, 'Clinic stamp' ) . '</w:r></w:p>';
		}

		return $x;
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

	/** Text run. $o: bold, italic, underline, size, color, rtl. */
	private static function r( $text, array $o = array() ) {
		$rpr = '<w:rFonts w:ascii="Arial" w:cs="Arial" w:eastAsia="Arial" w:hAnsi="Arial"/>';
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
		return '<w:r><w:rPr>' . $rpr . '</w:rPr>' . $body . '</w:r>';
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
	 * @param int      $indent  Distance of the table from the left margin (twips; negative = into the margin).
	 */
	private static function table( array $cols, array $borders, array $rows, $align = '', $indent = 0 ) {
		// Tables are always left-to-right (cell 0 = left), stated explicitly: without bidiVisual="0"
		// some viewers (e.g. LibreOffice) mirror the columns of a document that contains Hebrew.
		$x = '<w:tbl><w:tblPr><w:bidiVisual w:val="0"/><w:tblW w:type="dxa" w:w="' . (int) array_sum( $cols ) . '"/>';
		if ( $align !== '' ) {
			$x .= '<w:jc w:val="' . $align . '"/>';
		}
		$x .= '<w:tblInd w:w="' . (int) $indent . '" w:type="dxa"/><w:tblBorders>';
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

	/** Border spec: [ 'none' ] or [ style, size, color ] (style: single, dashSmallGap ...). */
	private static function border( $side, array $spec ) {
		if ( $spec[0] === 'none' ) {
			return '<w:' . $side . ' w:val="none"/>';
		}
		return '<w:' . $side . ' w:val="' . $spec[0] . '" w:color="' . $spec[2] . '" w:sz="' . (int) $spec[1] . '"/>';
	}

	/** A picture in the line of text (sizes in EMU). */
	private static function inline( $rel_id, $cx, $cy, $id, $name ) {
		return '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
			. '<wp:extent cx="' . (int) $cx . '" cy="' . (int) $cy . '"/><wp:effectExtent t="0" r="0" b="0" l="0"/>'
			. self::picture( $rel_id, $cx, $cy, $id, $name )
			. '</wp:inline></w:drawing>';
	}

	/** A picture at a fixed spot on the page: $x from the left edge (or 'center'), $y from the top edge (EMU). */
	private static function anchor( $rel_id, $cx, $cy, $x, $y, $id, $name ) {
		$h = $x === 'center' ? '<wp:align>center</wp:align>' : '<wp:posOffset>' . (int) $x . '</wp:posOffset>';
		return '<w:r><w:drawing><wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="' . ( 251658240 + (int) $id ) . '" behindDoc="0" locked="1" layoutInCell="1" allowOverlap="1">'
			. '<wp:simplePos x="0" y="0"/>'
			. '<wp:positionH relativeFrom="page">' . $h . '</wp:positionH>'
			. '<wp:positionV relativeFrom="page"><wp:posOffset>' . (int) $y . '</wp:posOffset></wp:positionV>'
			. '<wp:extent cx="' . (int) $cx . '" cy="' . (int) $cy . '"/><wp:effectExtent t="0" r="0" b="0" l="0"/><wp:wrapNone/>'
			. self::picture( $rel_id, $cx, $cy, $id, $name )
			. '</wp:anchor></w:drawing></w:r>';
	}

	private static function picture( $rel_id, $cx, $cy, $id, $name ) {
		return '<wp:docPr id="' . (int) $id . '" name="' . self::esc( $name ) . '" descr=""/>'
			. '<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
			. '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
			. '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
			. '<pic:nvPicPr><pic:cNvPr id="0" name="' . self::esc( $name ) . '" descr=""/><pic:cNvPicPr><a:picLocks noChangeAspect="1" noChangeArrowheads="1"/></pic:cNvPicPr></pic:nvPicPr>'
			. '<pic:blipFill><a:blip r:embed="' . $rel_id . '" cstate="none"/><a:srcRect/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
			. '<pic:spPr bwMode="auto"><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . (int) $cx . '" cy="' . (int) $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
			. '</pic:pic></a:graphicData></a:graphic>';
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
