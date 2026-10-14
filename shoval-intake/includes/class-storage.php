<?php
/**
 * Private storage for intake submissions.
 *
 * Each submission is a private "shoval_intake" post (visible only to
 * administrators) plus a folder with a random name under
 * wp-content/uploads/shoval-intake-private/, which is closed to the web.
 */

defined( 'ABSPATH' ) || exit;

class Shoval_Intake_Storage {

	const POST_TYPE = 'shoval_intake';
	const DIR       = 'shoval-intake-private';

	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		// Submissions are always private, so drop WordPress's "Private:" title prefix and list label.
		add_filter( 'private_title_format', array( __CLASS__, 'plain_title_format' ), 10, 2 );
		add_filter( 'display_post_states', array( __CLASS__, 'hide_private_state' ), 10, 2 );
	}

	public static function plain_title_format( $format, $post ) {
		return $post && get_post_type( $post ) === self::POST_TYPE ? '%s' : $format;
	}

	public static function hide_private_state( $states, $post ) {
		if ( $post->post_type === self::POST_TYPE ) {
			unset( $states['private'] );
		}
		return $states;
	}

	public static function register_post_type() {
		$cap = 'manage_options';
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => 'שאלוני קבלה',
					'singular_name'      => 'שאלון קבלה',
					'menu_name'          => 'שאלוני קבלה',
					'all_items'          => 'כל השאלונים',
					'edit_item'          => 'שאלון קבלה',
					'view_item'          => 'צפייה בשאלון',
					'search_items'       => 'חיפוש שאלונים',
					'not_found'          => 'עדיין לא התקבלו שאלונים.',
					'not_found_in_trash' => 'אין שאלונים בפח.',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-clipboard',
				'supports'            => false,
				'rewrite'             => false,
				'query_var'           => false,
				'map_meta_cap'        => false,
				'capabilities'        => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'edit_private_posts'     => $cap,
					'edit_published_posts'   => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'delete_posts'           => $cap,
					'delete_private_posts'   => $cap,
					'delete_others_posts'    => $cap,
					'delete_published_posts' => $cap,
					'create_posts'           => 'do_not_allow',
				),
			)
		);
	}

	public static function base_dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . self::DIR;
	}

	/** Creates the private folder and the files that close it to the web. */
	public static function ensure_private_dir() {
		$base = self::base_dir();
		if ( ! wp_mkdir_p( $base ) ) {
			return false;
		}
		$guards = array(
			'.htaccess'  => "# Shoval Intake: private patient files. Never serve them.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $guards as $name => $content ) {
			$path = $base . '/' . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $content );
			}
		}
		return true;
	}

	public static function dir_for( $post_id ) {
		$name = (string) get_post_meta( $post_id, '_si_dir', true );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $name ) ) {
			return '';
		}
		return self::base_dir() . '/' . $name;
	}

	/** Absolute path of a stored file, or '' if the name is not a plain file name. */
	public static function file_path( $post_id, $stored_name ) {
		$dir = self::dir_for( $post_id );
		if ( $dir === '' || $stored_name === '' || $stored_name !== basename( $stored_name ) || strpos( $stored_name, '..' ) !== false ) {
			return '';
		}
		return $dir . '/' . $stored_name;
	}

	public static function random_name( $ext = '' ) {
		$name = bin2hex( random_bytes( 12 ) );
		return $ext !== '' ? $name . '.' . $ext : $name;
	}

	/**
	 * Saves a validated submission.
	 *
	 * @param array $data  Sanitized form fields.
	 * @param array $files Validated uploads: [ 'name', 'tmp_name', 'size', 'type', 'ext' ].
	 * @return int|WP_Error Post ID.
	 */
	public static function create( array $data, array $files ) {
		if ( ! self::ensure_private_dir() ) {
			return new WP_Error( 'si_storage', 'storage folder is not writable' );
		}

		$dir_name = bin2hex( random_bytes( 16 ) );
		$dir      = self::base_dir() . '/' . $dir_name;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'si_storage', 'could not create submission folder' );
		}
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );

		$stored = array();
		foreach ( $files as $file ) {
			$target = self::random_name( $file['ext'] );
			$moved  = is_uploaded_file( $file['tmp_name'] )
				? move_uploaded_file( $file['tmp_name'], $dir . '/' . $target )
				: rename( $file['tmp_name'], $dir . '/' . $target );
			if ( $moved ) {
				$stored[] = array(
					'name' => $file['name'],
					'file' => $target,
					'size' => (int) $file['size'],
					'type' => $file['type'],
				);
			}
		}

		$full_name = trim( $data['firstName'] . ' ' . $data['lastName'] );
		$post_id   = wp_insert_post(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'post_title'     => $full_name,
				'post_name'      => 'intake-' . self::random_name(),
				'post_author'    => 0,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			self::delete_dir( $dir );
			return $post_id;
		}

		update_post_meta( $post_id, '_si_dir', $dir_name );
		update_post_meta( $post_id, '_si_data', wp_slash( wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ) );
		update_post_meta( $post_id, '_si_files', wp_slash( wp_json_encode( $stored, JSON_UNESCAPED_UNICODE ) ) );
		update_post_meta( $post_id, '_si_received_at', time() );
		update_post_meta( $post_id, '_si_attempts', 0 );
		self::set_status( $post_id, 'received' );

		return $post_id;
	}

	public static function get_data( $post_id ) {
		$data = json_decode( (string) get_post_meta( $post_id, '_si_data', true ), true );
		return is_array( $data ) ? $data : array();
	}

	public static function get_files( $post_id ) {
		$files = json_decode( (string) get_post_meta( $post_id, '_si_files', true ), true );
		return is_array( $files ) ? $files : array();
	}

	public static function get_summary( $post_id ) {
		$summary = json_decode( (string) get_post_meta( $post_id, '_si_summary', true ), true );
		return is_array( $summary ) ? $summary : null;
	}

	public static function status( $post_id ) {
		return (string) get_post_meta( $post_id, '_si_status', true );
	}

	public static function set_status( $post_id, $status, $error = '' ) {
		update_post_meta( $post_id, '_si_status', $status );
		if ( $error !== '' ) {
			update_post_meta( $post_id, '_si_error', wp_slash( $error ) );
		} else {
			delete_post_meta( $post_id, '_si_error' );
		}
	}

	/** Writes a generated file (e.g. the Word summary) into the submission folder. */
	public static function put_file( $post_id, $stored_name, $bytes ) {
		$path = self::file_path( $post_id, $stored_name );
		if ( $path === '' ) {
			return false;
		}
		return file_put_contents( $path, $bytes ) !== false;
	}

	public static function on_delete( $post_id ) {
		if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
			return;
		}
		$dir = self::dir_for( $post_id );
		if ( $dir !== '' ) {
			self::delete_dir( $dir );
		}
	}

	private static function delete_dir( $dir ) {
		$base = wp_normalize_path( self::base_dir() ) . '/';
		$dir  = wp_normalize_path( $dir );
		if ( strpos( $dir, $base ) !== 0 || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( $name !== '.' && $name !== '..' && is_file( $dir . '/' . $name ) ) {
				unlink( $dir . '/' . $name );
			}
		}
		rmdir( $dir );
	}

	/** Daily: removes submissions older than the retention period. */
	public static function cleanup_expired() {
		$days = (int) Shoval_Intake_Settings::get( 'retention_days' );
		if ( $days <= 0 ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'date_query'     => array(
					array(
						'column' => 'post_date_gmt',
						'before' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
}
