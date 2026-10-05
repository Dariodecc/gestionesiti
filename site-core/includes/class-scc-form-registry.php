<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * In-memory registry of form definitions, rebuilt every request from the
 * 'scc_register_forms' action (see site-core.php). Themes/plugins call
 * SCC_Form_Registry::register() from a callback hooked to that action.
 *
 * $config shape:
 * [
 *   'label'  => 'Contact form',                 // shown in admin screens
 *   'fields' => [
 *     'name'  => [ 'required' => true,  'type' => 'text'  ],
 *     'email' => [ 'required' => true,  'type' => 'email' ],
 *     'msg'   => [ 'required' => false, 'type' => 'textarea' ],
 *   ],
 * ]
 */
class SCC_Form_Registry {

	private static $forms = array();

	public static function register( $slug, array $config ) {
		$slug = sanitize_key( $slug );
		if ( empty( $slug ) ) {
			return false;
		}
		if ( empty( $config['fields'] ) || ! is_array( $config['fields'] ) ) {
			$config['fields'] = array();
		}
		if ( empty( $config['label'] ) ) {
			$config['label'] = $slug;
		}
		self::$forms[ $slug ] = $config;
		return true;
	}

	public static function get( $slug ) {
		$slug = sanitize_key( $slug );
		return isset( self::$forms[ $slug ] ) ? self::$forms[ $slug ] : null;
	}

	public static function get_all() {
		return self::$forms;
	}

	public static function exists( $slug ) {
		return isset( self::$forms[ sanitize_key( $slug ) ] );
	}
}

