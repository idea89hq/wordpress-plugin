<?php
/**
 * Typed accessors over the plugin's options.
 *
 * WordPress has no encryption at rest, so the API key lives in wp_options with
 * autoload disabled, is gated behind manage_options, and is never exposed to
 * REST or to any front-end script. This is stated plainly in the README rather
 * than dressed up with reversible obfuscation that only looks like security.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and validates plugin configuration.
 */
class Idea89_Config {

	const DEFAULT_API_URL  = 'https://api.idea89.com';
	const DEFAULT_POSITION = 'bottom-right';

	/**
	 * Allowed widget positions. Anything else falls back to the default.
	 *
	 * @var string[]
	 */
	private static $positions = array( 'bottom-right', 'bottom-left' );

	/**
	 * Whether the merchant has switched the widget on.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) get_option( 'idea89_enabled', false );
	}

	/**
	 * The store's IDEA89 API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		return (string) get_option( 'idea89_api_key', '' );
	}

	/**
	 * API base URL with any trailing slash removed.
	 *
	 * @return string
	 */
	public function get_api_url() {
		$url = (string) get_option( 'idea89_api_url', '' );
		if ( '' === trim( $url ) ) {
			$url = self::DEFAULT_API_URL;
		}
		return rtrim( trim( $url ), '/' );
	}

	/**
	 * True when there is a key to authenticate with.
	 *
	 * Every sync path checks this first, so an unconfigured site makes no
	 * external HTTP calls at all.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== trim( $this->get_api_key() );
	}

	/**
	 * Display name for the assistant.
	 *
	 * @return string
	 */
	public function get_assistant_name() {
		$name = (string) get_option( 'idea89_assistant_name', '' );
		return '' === trim( $name ) ? 'Shopping Assistant' : $name;
	}

	/**
	 * Free-text store context sent to the assistant.
	 *
	 * @return string
	 */
	public function get_store_context() {
		return (string) get_option( 'idea89_store_context', '' );
	}

	/**
	 * Widget corner, validated against the allow-list.
	 *
	 * @return string
	 */
	public function get_widget_position() {
		$position = (string) get_option( 'idea89_widget_position', self::DEFAULT_POSITION );
		return in_array( $position, self::$positions, true ) ? $position : self::DEFAULT_POSITION;
	}

	/**
	 * Brand colour, or an empty string when it is not a valid hex colour.
	 *
	 * Validated here as well as on save: the value is interpolated into a
	 * data attribute on the storefront, so a non-hex value must never survive.
	 *
	 * @return string
	 */
	public function get_brand_color() {
		$color = trim( (string) get_option( 'idea89_brand_color', '' ) );
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ? $color : '';
	}
}
