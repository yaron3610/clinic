<?php
/**
 * Plugin settings (API keys, report e-mail, retention) and the settings screen.
 *
 * Every secret can also be defined as a constant in wp-config.php, which takes
 * precedence over the value saved in the database:
 *   SHOVAL_INTAKE_GEMINI_API_KEY, SHOVAL_INTAKE_RESEND_API_KEY,
 *   SHOVAL_INTAKE_RESEND_FROM, SHOVAL_INTAKE_REPORT_EMAIL
 */

defined( 'ABSPATH' ) || exit;

class Shoval_Intake_Settings {

	const OPTION = 'shoval_intake_settings';
	const PAGE   = 'shoval-intake-settings';

	private static $constants = array(
		'gemini_api_key' => 'SHOVAL_INTAKE_GEMINI_API_KEY',
		'resend_api_key' => 'SHOVAL_INTAKE_RESEND_API_KEY',
		'resend_from'    => 'SHOVAL_INTAKE_RESEND_FROM',
		'report_email'   => 'SHOVAL_INTAKE_REPORT_EMAIL',
	);

	private static $secrets = array( 'gemini_api_key', 'resend_api_key' );

	public static function defaults() {
		return array(
			'ai_enabled'     => 1,
			'gemini_api_key' => '',
			'gemini_models'  => 'gemini-3.1-flash-lite, gemini-flash-latest',
			'resend_api_key' => '',
			'resend_from'    => 'onboarding@resend.dev',
			'report_email'   => get_option( 'admin_email' ),
			'attach_uploads' => 1,
			'retention_days' => 90,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		if ( self::from_constant( $key ) ) {
			return constant( self::$constants[ $key ] );
		}
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function from_constant( $key ) {
		return isset( self::$constants[ $key ] ) && defined( self::$constants[ $key ] ) && constant( self::$constants[ $key ] ) !== '';
	}

	/** Gemini model names to try, in order. */
	public static function gemini_models() {
		$models = array_filter( array_map( 'trim', explode( ',', (string) self::get( 'gemini_models' ) ) ) );
		return array_values( array_filter( $models, function ( $m ) {
			return (bool) preg_match( '/^[a-z0-9.\-]+$/i', $m );
		} ) );
	}

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_post_shoval_intake_test_email', array( __CLASS__, 'handle_test_email' ) );
		add_action( 'admin_post_shoval_intake_homepage_buttons', array( __CLASS__, 'handle_homepage_buttons' ) );
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Shoval_Intake_Storage::POST_TYPE,
			'הגדרות שאלון קבלה',
			'הגדרות',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function register_setting() {
		register_setting(
			'shoval_intake',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $input ) {
		$old   = self::all();
		$input = is_array( $input ) ? $input : array();
		$out   = $old;

		foreach ( self::$secrets as $key ) {
			$value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( ! empty( $input[ $key . '_clear' ] ) ) {
				$out[ $key ] = '';
			} elseif ( $value !== '' ) {
				$out[ $key ] = sanitize_text_field( $value );
			}
		}

		$out['ai_enabled']     = empty( $input['ai_enabled'] ) ? 0 : 1;
		$out['attach_uploads'] = empty( $input['attach_uploads'] ) ? 0 : 1;
		$out['gemini_models']  = isset( $input['gemini_models'] ) ? sanitize_text_field( $input['gemini_models'] ) : $old['gemini_models'];
		$out['retention_days'] = isset( $input['retention_days'] ) ? min( 3650, absint( $input['retention_days'] ) ) : $old['retention_days'];

		foreach ( array( 'report_email', 'resend_from' ) as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$raw = trim( wp_unslash( (string) $input[ $key ] ) );
			// The sender may be written as "Clinic name <address@domain>".
			if ( $key === 'resend_from' && preg_match( '/^(.*)<([^>]+)>$/u', $raw, $m ) && is_email( trim( $m[2] ) ) ) {
				$label       = trim( sanitize_text_field( str_replace( array( '"', '<', '>' ), '', $m[1] ) ) );
				$out[ $key ] = ( $label !== '' ? $label . ' ' : '' ) . '<' . sanitize_email( trim( $m[2] ) ) . '>';
				continue;
			}
			$email = sanitize_email( $raw );
			if ( $email || $raw === '' ) {
				$out[ $key ] = $email;
			} else {
				add_settings_error( self::OPTION, $key, 'כתובת המייל "' . esc_html( $raw ) . '" אינה תקינה ולא נשמרה.' );
			}
		}

		return $out;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::all();
		?>
		<div class="wrap" dir="rtl">
			<h1>הגדרות שאלון קבלה</h1>
			<?php settings_errors( self::OPTION ); ?>
			<?php self::render_test_notice(); ?>

			<p>
				דף השאלון:
				<a href="<?php echo esc_url( Shoval_Intake_Frontend::page_url() ); ?>" target="_blank"><?php echo esc_html( rawurldecode( Shoval_Intake_Frontend::page_url() ) ); ?></a>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'shoval_intake' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="si-report-email">מייל לקבלת הסיכומים</label></th>
						<td><?php self::text_field( 'report_email', $s, 'email' ); ?>
							<p class="description">לכתובת זו נשלח כל שאלון חדש, עם טיוטת הסיכום (Word) והקבצים שצורפו.</p></td>
					</tr>
					<tr>
						<th scope="row">עיבוד בבינה מלאכותית</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[ai_enabled]" value="1" <?php checked( $s['ai_enabled'] ); ?>>
							להשתמש ב-Google Gemini להצעת אבחנות, התרשמות ותוכנית טיפולית</label>
							<p class="description">ל-Gemini נשלחים רק נתונים קליניים (מין, גיל, סיבת פנייה, תרופות ומסמכים) &ndash; ללא שם, ת.ז., טלפון, מייל או עיר. ללא AI הטיוטה נבנית מכללים קבועים.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="si-gemini-key">Gemini API key</label></th>
						<td><?php self::secret_field( 'gemini_api_key', $s ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="si-gemini-models">מודלי Gemini</label></th>
						<td><?php self::text_field( 'gemini_models', $s ); ?>
							<p class="description">לפי סדר עדיפות, מופרדים בפסיק.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="si-resend-key">Resend API key</label></th>
						<td><?php self::secret_field( 'resend_api_key', $s ); ?>
							<p class="description">אם לא מוגדר, או אם השליחה דרך Resend נכשלת, המייל נשלח דרך שרת המייל של האתר (wp_mail).</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="si-resend-from">כתובת השולח ב-Resend</label></th>
						<td><?php self::text_field( 'resend_from', $s ); ?>
							<p class="description">הכתובת onboarding@resend.dev שולחת רק למייל של בעל חשבון ה-Resend. לשליחה לכל כתובת יש לאמת דומיין ב-Resend.</p></td>
					</tr>
					<tr>
						<th scope="row">צירוף קבצים</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[attach_uploads]" value="1" <?php checked( $s['attach_uploads'] ); ?>>
							לצרף למייל את הקבצים שהמטופל העלה</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="si-retention">שמירת שאלונים באתר</label></th>
						<td><input type="number" min="0" max="3650" id="si-retention" name="<?php echo esc_attr( self::OPTION ); ?>[retention_days]" value="<?php echo esc_attr( $s['retention_days'] ); ?>" class="small-text"> ימים
							<p class="description">שאלונים ישנים יותר נמחקים אוטומטית מהאתר, יחד עם הקבצים שלהם. 0 = לא למחוק.</p></td>
					</tr>
				</table>
				<?php submit_button( 'שמירת הגדרות' ); ?>
			</form>

			<hr>
			<h2>כפתורים בדף הבית</h2>
			<?php $si_buttons = Shoval_Intake_Homepage::count_buttons(); ?>
			<p>
				<?php if ( $si_buttons ) : ?>
					בדף הבית מופיעים <?php echo (int) $si_buttons; ?> כפתורים לשאלון (מתחת לכותרת הראשית ומעל טופס יצירת הקשר).
				<?php else : ?>
					הכפתורים לשאלון עדיין לא נוספו לדף הבית. לחיצה תוסיף כפתור מתחת לכותרת הראשית ותיבה "מטופלים חדשים במרפאה?" מעל טופס יצירת הקשר. שאר הדף לא משתנה.
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shoval_intake_homepage_buttons">
				<input type="hidden" name="do" value="<?php echo $si_buttons ? 'remove' : 'add'; ?>">
				<?php wp_nonce_field( 'shoval_intake_homepage_buttons' ); ?>
				<?php submit_button( $si_buttons ? 'הסרת הכפתורים מדף הבית' : 'הוספת הכפתורים לדף הבית', $si_buttons ? 'secondary' : 'primary', 'submit', false ); ?>
			</form>

			<hr>
			<h2>בדיקת שליחה</h2>
			<p>שולח למייל לקבלת הסיכומים סיכום לדוגמה של מטופל בדוי, כדי לוודא שהמייל וקובץ ה-Word מגיעים.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shoval_intake_test_email">
				<?php wp_nonce_field( 'shoval_intake_test_email' ); ?>
				<?php submit_button( 'שליחת מייל בדיקה', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private static function text_field( $key, $s, $type = 'text' ) {
		$id = 'si-' . str_replace( '_', '-', $key );
		if ( self::from_constant( $key ) ) {
			echo '<code>' . esc_html( (string) self::get( $key ) ) . '</code> <span class="description">(מוגדר ב-wp-config.php)</span>';
			return;
		}
		printf(
			'<input type="%1$s" id="%2$s" name="%3$s[%4$s]" value="%5$s" class="regular-text" dir="%6$s">',
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			esc_attr( $s[ $key ] ),
			$key === 'resend_from' ? 'auto' : 'ltr'
		);
	}

	private static function secret_field( $key, $s ) {
		$id = 'si-' . str_replace( array( '_api_key', '_' ), array( '-key', '-' ), $key );
		if ( self::from_constant( $key ) ) {
			echo '<span class="description">מוגדר ב-wp-config.php</span>';
			return;
		}
		$has = $s[ $key ] !== '';
		printf(
			'<input type="password" id="%1$s" name="%2$s[%3$s]" value="" class="regular-text" dir="ltr" autocomplete="off" placeholder="%4$s">',
			esc_attr( $id ),
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			esc_attr( $has ? '•••••••• (שמור – השאירו ריק כדי לא לשנות)' : '' )
		);
		if ( $has ) {
			printf(
				' <label><input type="checkbox" name="%1$s[%2$s_clear]" value="1"> מחיקה</label>',
				esc_attr( self::OPTION ),
				esc_attr( $key )
			);
		}
	}

	public static function handle_test_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'אין הרשאה.' );
		}
		check_admin_referer( 'shoval_intake_test_email' );

		$result = Shoval_Intake_Processor::send_test_email();
		$args   = array(
			'post_type' => Shoval_Intake_Storage::POST_TYPE,
			'page'      => self::PAGE,
			'si_test'   => $result['ok'] ? 'ok' : 'fail',
			'si_via'    => isset( $result['via'] ) ? $result['via'] : '',
		);
		if ( ! $result['ok'] ) {
			set_transient( 'shoval_intake_test_error_' . get_current_user_id(), (string) $result['error'], 300 );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function handle_homepage_buttons() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'אין הרשאה.' );
		}
		check_admin_referer( 'shoval_intake_homepage_buttons' );
		$remove = isset( $_POST['do'] ) && $_POST['do'] === 'remove';
		$result = $remove ? Shoval_Intake_Homepage::uninstall() : Shoval_Intake_Homepage::install();
		if ( $result !== true ) {
			set_transient( 'shoval_intake_test_error_' . get_current_user_id(), (string) $result, 300 );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'  => Shoval_Intake_Storage::POST_TYPE,
					'page'       => self::PAGE,
					'si_buttons' => $result === true ? ( $remove ? 'removed' : 'added' ) : 'fail',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	private static function render_test_notice() {
		if ( ! empty( $_GET['si_buttons'] ) ) {
			$state = sanitize_key( wp_unslash( $_GET['si_buttons'] ) );
			if ( $state === 'added' ) {
				echo '<div class="notice notice-success"><p>הכפתורים נוספו לדף הבית. <a href="' . esc_url( home_url( '/' ) ) . '" target="_blank">לצפייה בדף הבית</a> (אם יש תוסף מטמון באתר – יש לנקות אותו).</p></div>';
			} elseif ( $state === 'removed' ) {
				echo '<div class="notice notice-success"><p>הכפתורים הוסרו מדף הבית.</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html( (string) get_transient( 'shoval_intake_test_error_' . get_current_user_id() ) ) . '</p></div>';
			}
		}
		if ( empty( $_GET['si_test'] ) ) {
			return;
		}
		$via = isset( $_GET['si_via'] ) ? sanitize_key( wp_unslash( $_GET['si_via'] ) ) : '';
		if ( $_GET['si_test'] === 'ok' ) {
			echo '<div class="notice notice-success"><p>מייל הבדיקה נשלח אל ' . esc_html( (string) self::get( 'report_email' ) ) . ( $via ? ' (דרך ' . esc_html( $via ) . ')' : '' ) . '.</p></div>';
			return;
		}
		$error = get_transient( 'shoval_intake_test_error_' . get_current_user_id() );
		echo '<div class="notice notice-error"><p>שליחת מייל הבדיקה נכשלה: ' . esc_html( (string) $error ) . '</p></div>';
	}
}
