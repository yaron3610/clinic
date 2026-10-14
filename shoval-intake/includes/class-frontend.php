<?php
/**
 * Front end: the questionnaire page, the [shoval_intake_form] form and the
 * [shoval_intake_button] buttons that link to it from the homepage.
 */

defined( 'ABSPATH' ) || exit;

class Shoval_Intake_Frontend {

	const PAGE_OPTION = 'shoval_intake_page_id';
	const PAGE_SLUG   = 'intake-form';

	public static function register() {
		add_shortcode( 'shoval_intake_form', array( __CLASS__, 'form_shortcode' ) );
		add_shortcode( 'shoval_intake_button', array( __CLASS__, 'button_shortcode' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_theme_styles' ), 100 );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	public static function page_id() {
		return (int) get_option( self::PAGE_OPTION );
	}

	public static function page_url() {
		$id = self::page_id();
		if ( $id && get_post_status( $id ) === 'publish' ) {
			return get_permalink( $id );
		}
		return home_url( '/' . self::PAGE_SLUG . '/' );
	}

	/** Creates the questionnaire page on activation (or adopts an existing one). */
	public static function ensure_page() {
		$id = self::page_id();
		if ( $id && get_post_type( $id ) === 'page' && ! in_array( get_post_status( $id ), array( false, 'trash' ), true ) ) {
			return $id;
		}
		$existing = get_page_by_path( self::PAGE_SLUG );
		if ( $existing && $existing->post_status !== 'trash' ) {
			update_option( self::PAGE_OPTION, $existing->ID );
			return $existing->ID;
		}
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => 'שאלון קבלה למטופל חדש',
				'post_name'      => self::PAGE_SLUG,
				'post_content'   => '[shoval_intake_form]',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( self::PAGE_OPTION, $id );
		}
		return $id;
	}

	public static function is_intake_page() {
		if ( ! is_page() ) {
			return false;
		}
		$id = get_queried_object_id();
		if ( $id && $id === self::page_id() ) {
			return true;
		}
		$post = get_post( $id );
		return $post && has_shortcode( $post->post_content, 'shoval_intake_form' );
	}

	public static function template( $template ) {
		return self::is_intake_page() ? SHOVAL_INTAKE_DIR . 'templates/intake-page.php' : $template;
	}

	public static function robots( array $robots ) {
		if ( self::is_intake_page() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	public static function post_state( $states, $post ) {
		if ( $post->ID === self::page_id() ) {
			$states['shoval_intake'] = 'דף שאלון הקבלה';
		}
		return $states;
	}

	public static function enqueue() {
		if ( self::is_intake_page() ) {
			self::enqueue_form_assets();
		}
	}

	/** The questionnaire page has its own design; the theme's reset/base styles would only fight it. */
	public static function dequeue_theme_styles() {
		if ( ! self::is_intake_page() ) {
			return;
		}
		foreach ( wp_styles()->queue as $handle ) {
			if ( strpos( $handle, 'hello-elementor' ) === 0 ) {
				wp_dequeue_style( $handle );
			}
		}
	}

	public static function enqueue_form_assets() {
		$uploads = wp_upload_dir( null, false );
		$font    = $uploads['basedir'] . '/elementor/google-fonts/css/assistant.css';
		if ( file_exists( $font ) ) {
			// The site already hosts the Assistant font locally (Elementor) – reuse it.
			wp_enqueue_style( 'shoval-intake-font', $uploads['baseurl'] . '/elementor/google-fonts/css/assistant.css', array(), (string) filemtime( $font ) );
		} else {
			wp_enqueue_style( 'shoval-intake-font', 'https://fonts.googleapis.com/css2?family=Assistant:wght@300;400;600;700;800&display=swap', array(), null );
		}
		wp_enqueue_style( 'shoval-intake', SHOVAL_INTAKE_URL . 'assets/intake.css', array(), SHOVAL_INTAKE_VERSION );
		wp_enqueue_script( 'shoval-intake', SHOVAL_INTAKE_URL . 'assets/intake.js', array(), SHOVAL_INTAKE_VERSION, true );
		wp_localize_script(
			'shoval-intake',
			'ShovalIntake',
			array(
				'endpoint'      => esc_url_raw( rest_url( Shoval_Intake_Rest::NS . '/submit' ) ),
				'maxTotalBytes' => Shoval_Intake_Rest::MAX_TOTAL_BYTES,
				'maxFiles'      => Shoval_Intake_Rest::MAX_FILES,
			)
		);
	}

	/** Signed timestamp embedded in the form; checked on submit to filter out direct bot posts. */
	public static function form_token() {
		$t = time();
		return $t . '.' . hash_hmac( 'sha256', 'shoval-intake|' . $t, wp_salt( 'nonce' ) );
	}

	public static function verify_form_token( $token ) {
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 2 || ! ctype_digit( $parts[0] ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', 'shoval-intake|' . $parts[0], wp_salt( 'nonce' ) );
		$age      = time() - (int) $parts[0];
		// At least 3 seconds to fill the form; cached pages may carry an old token, so allow up to 60 days.
		return hash_equals( $expected, $parts[1] ) && $age >= 3 && $age <= 60 * DAY_IN_SECONDS;
	}

	public static function form_shortcode() {
		self::enqueue_form_assets();
		ob_start();
		include SHOVAL_INTAKE_DIR . 'templates/form.php';
		return ob_get_clean();
	}

	/**
	 * [shoval_intake_button variant="hero"] – the round gradient button under the homepage title.
	 * [shoval_intake_button variant="box"]  – the "new patients" box above the contact form.
	 */
	public static function button_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'variant' => 'hero',
				'text'    => '',
				'title'   => 'מטופלים חדשים במרפאה?',
				'desc'    => 'באפשרותכם למלא מראש את שאלון הקבלה הדיגיטלי בצורה מסודרת ומאובטחת.',
			),
			$atts,
			'shoval_intake_button'
		);
		$url = esc_url( self::page_url() );

		if ( $atts['variant'] === 'box' ) {
			$text = $atts['text'] !== '' ? $atts['text'] : 'למילוי שאלון קבלה למטופל חדש';
			return '<div class="shoval-intake-box" style="background: #f0f9fa; border: 1px solid #b8e2e6; border-radius: 12px; padding: 20px; margin-bottom: 24px; text-align: center;" dir="rtl">'
				. '<h3 style="color: #142850; font-size: 19px; font-weight: 700; margin: 0 0 6px 0;">' . esc_html( $atts['title'] ) . '</h3>'
				. '<p style="color: #4a5568; font-size: 15px; margin: 0 0 14px 0;">' . esc_html( $atts['desc'] ) . '</p>'
				. '<a href="' . $url . '" style="display: inline-flex; align-items: center; gap: 8px; background-color: #00909e; color: #ffffff; padding: 10px 24px; border-radius: 6px; font-size: 15px; font-weight: 700; text-decoration: none;">'
				. '<span>' . esc_html( $text ) . '</span><span aria-hidden="true">&larr;</span></a>'
				. '</div>';
		}

		$text = $atts['text'] !== '' ? $atts['text'] : 'שאלון קבלה למטופל חדש';
		return '<div class="shoval-intake-hero" style="margin: 20px auto 10px auto; text-align: center; z-index: 10; position: relative;" dir="rtl">'
			. '<a href="' . $url . '" style="display: inline-flex; align-items: center; gap: 10px; background: linear-gradient(135deg, #00909e 0%, #142850 100%); color: #ffffff; padding: 14px 32px; border-radius: 30px; font-size: 17px; font-weight: 700; text-decoration: none; box-shadow: 0 4px 15px rgba(0, 144, 158, 0.4); transition: transform 0.2s ease, box-shadow 0.2s ease;">'
			. '<span>' . esc_html( $text ) . '</span><span aria-hidden="true" style="font-size: 20px;">&larr;</span></a>'
			. '</div>';
	}
}
