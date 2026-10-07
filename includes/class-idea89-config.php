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
	 * Optional catalog sync key, generated in the IDEA89 dashboard. When set
	 * it is sent as X-IDEA89-Sync-Key on every catalog write, so the API key
	 * alone (which also ships to the storefront widget) cannot change the
	 * catalog. Empty means the header is not sent.
	 *
	 * @return string
	 */
	public function get_sync_key() {
		return trim( (string) get_option( 'idea89_sync_key', '' ) );
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
		// The account holds the real value; this option row is a render cache
		// for when the API is unreachable. Reading remote-first keeps this in
		// step with the name the widget header actually shows.
		$remote = class_exists( 'Idea89_Remote_Config' ) ? Idea89_Remote_Config::assistant_name() : '';
		$name   = '' !== $remote ? $remote : (string) get_option( 'idea89_assistant_name', '' );

		return '' === trim( $name ) ? 'Shopping Assistant' : $name;
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
}
