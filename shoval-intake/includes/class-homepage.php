<?php
/**
 * Adds (or removes) the two questionnaire buttons on the Elementor homepage:
 *  - under the title "פסיכיאטר מומחה לילדים, נוער ולמבוגרים" (round gradient button)
 *  - above the contact form (the "מטופלים חדשים במרפאה?" box)
 *
 * Each button is an Elementor "Shortcode" widget, so it can also be moved or
 * removed by hand in the Elementor editor. Nothing else on the page changes.
 */

defined( 'ABSPATH' ) || exit;

class Shoval_Intake_Homepage {

	const BACKUP_OPTION = 'shoval_intake_homepage_backup';

	// Element IDs on the current homepage (post 10).
	const HERO_CONTAINER = '344347f';
	const HERO_AFTER     = 'be107b9';
	const FORM_CONTAINER = 'ffaf561';
	const FORM_WIDGET    = '6d5db82';

	public static function front_page_id() {
		return get_option( 'show_on_front' ) === 'page' ? (int) get_option( 'page_on_front' ) : 0;
	}

	private static function load( $page_id ) {
		$raw  = get_post_meta( $page_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $data ) ? $data : null;
	}

	private static function widget( $variant ) {
		return array(
			'id'         => substr( md5( uniqid( 'si', true ) ), 0, 7 ),
			'elType'     => 'widget',
			'settings'   => array( 'shortcode' => '[shoval_intake_button variant="' . $variant . '"]' ),
			'elements'   => array(),
			'widgetType' => 'shortcode',
		);
	}

	private static function is_ours( array $el ) {
		return isset( $el['widgetType'], $el['settings']['shortcode'] ) && $el['widgetType'] === 'shortcode'
			&& strpos( $el['settings']['shortcode'], '[shoval_intake_button' ) !== false;
	}

	/** @return int Number of our buttons on the page. */
	public static function count_buttons( $page_id = 0 ) {
		$page_id = $page_id ? $page_id : self::front_page_id();
		$data    = $page_id ? self::load( $page_id ) : null;
		$count   = 0;
		$walk    = function ( array $els ) use ( &$walk, &$count ) {
			foreach ( $els as $el ) {
				if ( self::is_ours( $el ) ) {
					$count++;
				}
				if ( ! empty( $el['elements'] ) ) {
					$walk( $el['elements'] );
				}
			}
		};
		if ( $data ) {
			$walk( $data );
		}
		return $count;
	}

	/**
	 * Inserts a widget next to the element with $target_id.
	 *
	 * @param string $where 'after' or 'before'.
	 */
	private static function insert_next_to( array &$els, $target_id, array $widget, $where ) {
		foreach ( $els as $i => &$el ) {
			if ( isset( $el['id'] ) && $el['id'] === $target_id ) {
				array_splice( $els, $where === 'after' ? $i + 1 : $i, 0, array( $widget ) );
				return true;
			}
			if ( ! empty( $el['elements'] ) && self::insert_next_to( $el['elements'], $target_id, $widget, $where ) ) {
				return true;
			}
		}
		return false;
	}

	/** Finds the first element matching $test (depth first). */
	private static function find( array $els, callable $test ) {
		foreach ( $els as $el ) {
			if ( $test( $el ) ) {
				return $el;
			}
			if ( ! empty( $el['elements'] ) ) {
				$found = self::find( $el['elements'], $test );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/** @return true|string True on success, or an error message. */
	public static function install() {
		$page_id = self::front_page_id();
		$data    = $page_id ? self::load( $page_id ) : null;
		if ( ! $data ) {
			return 'דף הבית אינו דף Elementor.';
		}
		if ( self::count_buttons( $page_id ) > 0 ) {
			return true;
		}
		$original = $data;

		// Hero button: right after the subtitle heading (or after the last heading of the first section).
		$hero_target = self::HERO_AFTER;
		if ( ! self::find( $data, function ( $el ) { return isset( $el['id'] ) && $el['id'] === Shoval_Intake_Homepage::HERO_AFTER; } ) ) {
			$hero_target = '';
			foreach ( isset( $data[0]['elements'] ) ? $data[0]['elements'] : array() as $el ) {
				if ( isset( $el['widgetType'] ) && $el['widgetType'] === 'heading' ) {
					$hero_target = $el['id'];
				}
			}
		}
		// Box: right before the contact form.
		$form = self::find( $data, function ( $el ) { return isset( $el['id'] ) && $el['id'] === Shoval_Intake_Homepage::FORM_WIDGET; } );
		if ( ! $form ) {
			$form = self::find( $data, function ( $el ) { return isset( $el['widgetType'] ) && $el['widgetType'] === 'form'; } );
		}

		$added = 0;
		if ( $hero_target !== '' && self::insert_next_to( $data, $hero_target, self::widget( 'hero' ), 'after' ) ) {
			$added++;
		}
		if ( $form && self::insert_next_to( $data, $form['id'], self::widget( 'box' ), 'before' ) ) {
			$added++;
		}
		if ( ! $added ) {
			return 'לא נמצא מקום מתאים לכפתורים בדף הבית. ניתן להוסיף אותם ידנית באלמנטור (ווידג\'ט Shortcode).';
		}

		update_option( self::BACKUP_OPTION, array( 'page_id' => $page_id, 'data' => wp_json_encode( $original ), 'time' => time() ), false );
		self::save( $page_id, $data );
		return true;
	}

	/** Removes our buttons (and nothing else). */
	public static function uninstall() {
		$page_id = self::front_page_id();
		$data    = $page_id ? self::load( $page_id ) : null;
		if ( ! $data ) {
			return true;
		}
		$strip = function ( array $els ) use ( &$strip ) {
			$out = array();
			foreach ( $els as $el ) {
				if ( self::is_ours( $el ) ) {
					continue;
				}
				if ( ! empty( $el['elements'] ) ) {
					$el['elements'] = $strip( $el['elements'] );
				}
				$out[] = $el;
			}
			return $out;
		};
		self::save( $page_id, $strip( $data ) );
		return true;
	}

	private static function save( $page_id, array $data ) {
		update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		self::flush_elementor_cache( $page_id );
	}

	public static function flush_elementor_cache( $page_id ) {
		delete_post_meta( $page_id, '_elementor_css' );
		delete_post_meta( $page_id, '_elementor_element_cache' );
		delete_post_meta( $page_id, '_elementor_page_assets' );
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		clean_post_cache( $page_id );
	}
}
