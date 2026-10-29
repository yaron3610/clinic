<?php
/**
 * Plugin Name:       Shoval Intake – שאלון קבלה למטופל חדש
 * Description:       שאלון קבלה דיגיטלי למטופלים חדשים. כל שאלון נשמר באופן פרטי באתר, מופקת ממנו טיוטת סיכום קליני בפורמט Word, והיא נשלחת למייל המרפאה.
 * Version:           1.3.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            מרפאת פרופ' גל שובל
 * License:           Proprietary
 * Text Domain:       shoval-intake
 */

defined( 'ABSPATH' ) || exit;

define( 'SHOVAL_INTAKE_VERSION', '1.3.3' );
define( 'SHOVAL_INTAKE_FILE', __FILE__ );
define( 'SHOVAL_INTAKE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHOVAL_INTAKE_URL', plugin_dir_url( __FILE__ ) );

require_once SHOVAL_INTAKE_DIR . 'includes/class-settings.php';
require_once SHOVAL_INTAKE_DIR . 'includes/class-storage.php';
require_once SHOVAL_INTAKE_DIR . 'includes/class-letter.php';
require_once SHOVAL_INTAKE_DIR . 'includes/class-docx.php';
require_once SHOVAL_INTAKE_DIR . 'includes/class-email.php';
require_once SHOVAL_INTAKE_DIR . 'includes/class-processor.php';
require_once SHOVAL_INTAKE_DIR . 'includes/class-rest.php';
require_once SHOVAL_INTAKE_DIR . 'includes/class-frontend.php';
require_once SHOVAL_INTAKE_DIR . 'includes/class-admin.php';
require_once SHOVAL_INTAKE_DIR . 'includes/class-homepage.php';

final class Shoval_Intake_Plugin {

	public static function init() {
		Shoval_Intake_Storage::register();
		Shoval_Intake_Processor::register();
		Shoval_Intake_Rest::register();
		Shoval_Intake_Frontend::register();

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'protect_letter_assets' ) );
			Shoval_Intake_Settings::register();
			Shoval_Intake_Admin::register();
		}
	}

	public static function activate() {
		self::protect_letter_assets();
		Shoval_Intake_Storage::register_post_type();
		Shoval_Intake_Storage::ensure_private_dir();
		Shoval_Intake_Frontend::ensure_page();
		Shoval_Intake_Processor::schedule();
		flush_rewrite_rules();
	}

	/**
	 * Closes assets/letter/ (letterhead and stamp images) to the web. The zip leaves this .htaccess out,
	 * because WP File Manager refuses to extract dot-files, so the plugin writes it itself if it is missing.
	 */
	public static function protect_letter_assets() {
		$path = SHOVAL_INTAKE_DIR . 'assets/letter/.htaccess';
		if ( file_exists( $path ) || ! is_writable( dirname( $path ) ) ) {
			return;
		}
		file_put_contents( $path, "# Shoval Intake: letterhead and stamp images of the Word letter. The plugin reads them from disk; never serve them.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\nOptions -Indexes\n" );
	}

	public static function deactivate() {
		Shoval_Intake_Processor::unschedule();
		flush_rewrite_rules();
	}
}

register_activation_hook( __FILE__, array( 'Shoval_Intake_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Shoval_Intake_Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'Shoval_Intake_Plugin', 'init' ) );
