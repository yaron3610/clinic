<?php
/**
 * Plugin settings (report e-mails, Dx./Rx. lists, Gmail and Brevo sending, retention) and the settings screen.
 *
 * These can also be defined as constants in wp-config.php, which take
 * precedence over the value saved in the database:
 *   SHOVAL_INTAKE_GMAIL_USER, SHOVAL_INTAKE_GMAIL_APP_PASSWORD,
 *   SHOVAL_INTAKE_BREVO_API_KEY, SHOVAL_INTAKE_BREVO_FROM,
 *   SHOVAL_INTAKE_REPORT_EMAIL (one address, or several separated by commas)
 */

defined( 'ABSPATH' ) || exit;

class Shoval_Intake_Settings {

	const OPTION = 'shoval_intake_settings';
	const PAGE   = 'shoval-intake-settings';

	private static $constants = array(
		'gmail_user'         => 'SHOVAL_INTAKE_GMAIL_USER',
		'gmail_app_password' => 'SHOVAL_INTAKE_GMAIL_APP_PASSWORD',
		'brevo_api_key'      => 'SHOVAL_INTAKE_BREVO_API_KEY',
		'brevo_from'         => 'SHOVAL_INTAKE_BREVO_FROM',
		'report_email'       => 'SHOVAL_INTAKE_REPORT_EMAIL',
	);

	private static $secrets = array( 'gmail_app_password', 'brevo_api_key' );

	public static function defaults() {
		return array(
			'gmail_user'         => '',
			'gmail_app_password' => '',
			'brevo_api_key'      => '',
			'brevo_from'         => '',
			'report_email'       => get_option( 'admin_email' ),
			'attach_uploads'     => 1,
			'retention_days'     => 90,
			// The clinic's lists ("אבחנות" and "תרופות"): every line is written in every letter.
			'dx_list'        => implode(
				"\n",
				array(
					'Autism spectrum disorder (ASD) | F84.0',
					'Social communications disorder (SCD) | F80.82',
					'ADHD (dysregulation disorder) | F90.9',
					'Anxiety disorder | F41.9',
					'Depressive disorder | F32',
					'Mixed anxiety and depression disorder | F32',
					'Emotional dysregulation | F60.3',
					'OCD | F42',
					'Post-traumatic stress disorder (PTSD) | F43.1',
					'Language developmental delay | R62.5',
					'Enuresis | F98.0',
					'Learning disabilities | F81.9',
					'Other specified problems related to psychosocial circumstances | Z65.8',
				)
			),
			'rx_list'        => "0\nT. Prizma 20 mg/d\nT. Ritalin LA 20 mg/d\nT. Risperdal 0.5 mg/d",
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

	/** The addresses that receive the reports (the setting may hold several, separated by commas). */
	public static function report_emails() {
		$list = preg_split( '/[\s,;]+/', (string) self::get( 'report_email' ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( array_filter( array_map( 'sanitize_email', $list ), 'is_email' ) ) );
	}

	/**
	 * The Dx. lines of the letter, in order: "name | ICD-10", or the name followed by the code
	 * (as in the clinic's "אבחנות" file).
	 *
	 * @return array[] [ [ 'name' => ..., 'icd10' => ... ] ]
	 */
	public static function diagnoses() {
		$out = array();
		foreach ( preg_split( '/\R/u', (string) self::get( 'dx_list' ) ) as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			$bar = strrpos( $line, '|' );
			if ( $bar !== false ) {
				$name = trim( substr( $line, 0, $bar ) );
				$code = trim( substr( $line, $bar + 1 ) );
			} elseif ( preg_match( '/^(.*\S)\s+([A-Z]?\d{2}(?:\.\d+)?)$/iu', $line, $m ) ) {
				$name = $m[1];
				$code = strtoupper( $m[2] );
			} else {
				$name = $line;
				$code = '';
			}
			$out[] = array( 'name' => $name, 'icd10' => $code );
		}
		return $out;
	}

	/** The Rx. lines of the letter, in order (including "0"). */
	public static function medications() {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/u', (string) self::get( 'rx_list' ) ) ), 'strlen' ) );
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
		// Only current settings are kept (older versions also stored a Gemini key, which is dropped here).
		$out = array_intersect_key( $old, self::defaults() );

		foreach ( self::$secrets as $key ) {
			$value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( ! empty( $input[ $key . '_clear' ] ) ) {
				$out[ $key ] = '';
			} elseif ( $value !== '' ) {
				$out[ $key ] = sanitize_text_field( $value );
			}
		}

		$out['attach_uploads'] = empty( $input['attach_uploads'] ) ? 0 : 1;
		$out['retention_days'] = isset( $input['retention_days'] ) ? min( 3650, absint( $input['retention_days'] ) ) : $old['retention_days'];

		foreach ( array( 'dx_list', 'rx_list' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_textarea_field( (string) $input[ $key ] ), 0, 5000 );
			}
		}

		if ( isset( $input['report_email'] ) ) {
			$valid   = array();
			$invalid = array();
			foreach ( preg_split( '/[\s,;]+/', wp_unslash( (string) $input['report_email'] ), -1, PREG_SPLIT_NO_EMPTY ) as $raw ) {
				$email = sanitize_email( $raw );
				if ( is_email( $email ) ) {
					$valid += array( strtolower( $email ) => $email );
				} else {
					$invalid[] = $raw;
				}
			}
			$out['report_email'] = implode( ', ', $valid );
			if ( $invalid ) {
				add_settings_error( self::OPTION, 'report_email', 'הכתובות האלה אינן תקינות ולא נשמרו: ' . esc_html( implode( ', ', $invalid ) ) );
			}
		}

		if ( isset( $input['gmail_user'] ) ) {
			$raw   = trim( wp_unslash( (string) $input['gmail_user'] ) );
			$email = sanitize_email( $raw );
			if ( $email || $raw === '' ) {
				$out['gmail_user'] = $email;
			} else {
				add_settings_error( self::OPTION, 'gmail_user', 'כתובת המייל "' . esc_html( $raw ) . '" אינה תקינה ולא נשמרה.' );
			}
		}

		foreach ( array( 'brevo_from' ) as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$raw = trim( wp_unslash( (string) $input[ $key ] ) );
			// The sender may be written as "Clinic name <address@domain>".
			if ( preg_match( '/^(.*)<([^>]+)>$/u', $raw, $m ) && is_email( trim( $m[2] ) ) ) {
				$label       = trim( sanitize_text_field( str_replace( array( '"', '<', '>' ), '', $m[1] ) ) );
				$out[ $key ] = ( $label !== '' ? $label . ' ' : '' ) . '<' . sanitize_email( trim( $m[2] ) ) . '>';
			} else {
				$email = sanitize_email( $raw );
				if ( $email || $raw === '' ) {
					$out[ $key ] = $email;
				} else {
					add_settings_error( self::OPTION, $key, 'כתובת המייל "' . esc_html( $raw ) . '" אינה תקינה ולא נשמרה.' );
				}
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
						<th scope="row"><label for="si-report-email">מיילים לקבלת הסיכומים</label></th>
						<td><?php self::text_field( 'report_email', $s ); ?>
							<p class="description">כל שאלון חדש נשלח לכתובות האלה, עם טיוטת הסיכום (Word) והקבצים שצורפו. אפשר לרשום כמה כתובות, מופרדות בפסיק.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="si-dx-list">אבחנות (Dx.)</label></th>
						<td><?php self::textarea_field( 'dx_list', $s, 14 ); ?>
							<p class="description">כל השורות נכתבות בכל מכתב, בסדר הזה. שורה לכל אבחנה: שם האבחנה | קוד ICD-10.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="si-rx-list">תרופות (Rx.)</label></th>
						<td><?php self::textarea_field( 'rx_list', $s, 5 ); ?>
							<p class="description">כל השורות נכתבות בכל מכתב, בסדר הזה (כולל 0). שורה לכל תרופה.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="si-gmail-user">שליחה דרך Gmail – כתובת</label></th>
						<td><?php self::text_field( 'gmail_user', $s ); ?>
							<p class="description">המיילים נשלחים מהכתובת הזו, דרך השרתים של Google (עד כ-500 מיילים ביום), בלי הגדרות DNS. אם Gmail נכשל – דרך Brevo (אם הוגדר), ואחריו דרך שרת המייל של האתר.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="si-gmail-app-password">Gmail – סיסמת אפליקציה</label></th>
						<td><?php self::secret_field( 'gmail_app_password', $s ); ?>
							<p class="description">לא הסיסמה הרגילה של Gmail: בחשבון Google ← אבטחה ← אימות דו-שלבי (חייב להיות פעיל) ← סיסמאות לאפליקציות. 16 אותיות.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="si-brevo-key">Brevo API key</label></th>
						<td><?php self::secret_field( 'brevo_api_key', $s ); ?>
							<p class="description">גיבוי ל-Gmail (מפתח עם הרשאת Transactional email, מתחיל ב-xkeysib-). אם Gmail ו-Brevo לא מוגדרים או נכשלים, המייל נשלח דרך שרת המייל של האתר (wp_mail).</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="si-brevo-from">כתובת השולח ב-Brevo</label></th>
						<td><?php self::text_field( 'brevo_from', $s ); ?>
							<p class="description">כתובת שאושרה ב-Brevo (Senders, אישור בקוד שנשלח אליה), למשל: מרפאת פרופ גל שובל &lt;shovalclinic@gmail.com&gt;</p></td>
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
			$key === 'brevo_from' ? 'auto' : 'ltr'
		);
	}

	private static function textarea_field( $key, $s, $rows ) {
		printf(
			'<textarea id="%1$s" name="%2$s[%3$s]" rows="%4$d" class="large-text code" dir="ltr">%5$s</textarea>',
			esc_attr( 'si-' . str_replace( '_', '-', $key ) ),
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			(int) $rows,
			esc_textarea( $s[ $key ] )
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
			echo '<div class="notice notice-success"><p>מייל הבדיקה נשלח אל ' . esc_html( implode( ', ', self::report_emails() ) ) . ( $via ? ' (דרך ' . esc_html( $via ) . ')' : '' ) . '.</p></div>';
			return;
		}
		$error = get_transient( 'shoval_intake_test_error_' . get_current_user_id() );
		echo '<div class="notice notice-error"><p>שליחת מייל הבדיקה נכשלה: ' . esc_html( (string) $error ) . '</p></div>';
	}
}
