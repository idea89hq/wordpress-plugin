<?php
/**
 * Typed accessors for the store locator settings.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitises and defaults the locator options.
 */
class Idea89_Locator_Config {

	const DEFAULT_URL_PATH = 'store-finder';

	/**
	 * Slugs that would shadow a WooCommerce or WordPress surface.
	 *
	 * A merchant who types "cart" into the field should lose the locator, not
	 * their basket, so a reserved slug falls back to the default.
	 *
	 * @var string[]
	 */
	private static $reserved = array(
		'cart',
		'checkout',
		'my-account',
		'shop',
		'wp-admin',
		'wp-login',
		'wp-json',
		'wp-content',
		'wp-includes',
		'feed',
		'comments',
		'search',
		'author',
		'category',
		'tag',
		'idea89',
	);

	/**
	 * Option name => default value.
	 *
	 * @var array<string,string>
	 */
	private static $defaults = array(
		'idea89_locator_page_title'       => 'Find a store',
		'idea89_locator_meta_description' => 'Find your nearest store or showroom. Search by postcode or browse the map to plan your visit.',
		'idea89_locator_hero_eyebrow'     => 'Showrooms',
		'idea89_locator_hero_h1'          => 'Find a store near you',
		'idea89_locator_hero_subhead'     => 'Walk in, talk to a specialist, and try things before you buy. Use your postcode, share your location, or browse the map.',
		'idea89_locator_help_heading'     => "Can't find a store near you?",
		'idea89_locator_help_body'        => 'Our team can point you to the nearest stockist, arrange a click & collect, or guide you through ordering online.',
		'idea89_locator_help_cta_label'   => 'Get in touch',
		'idea89_locator_help_cta_url'     => '/contact',
	);

	/**
	 * Whether the merchant has switched the locator on.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) get_option( 'idea89_locator_enabled', false );
	}

	/**
	 * The page slug, sanitised and never a reserved one.
	 *
	 * @return string
	 */
	public function get_url_path() {
		return self::sanitize_slug( (string) get_option( 'idea89_locator_url_path', self::DEFAULT_URL_PATH ) );
	}

	/**
	 * Sanitises a slug, falling back to the default.
	 *
	 * Letters, digits, hyphens and underscores. Underscores matter: stripping
	 * them would store "store_finder" but route "storefinder", and the page
	 * would 404 with the setting apparently correct.
	 *
	 * @param string $raw Raw slug.
	 * @return string
	 */
	public static function sanitize_slug( $raw ) {
		$slug = strtolower( trim( (string) $raw, "/ \t\n\r\0\x0B" ) );
		$slug = preg_replace( '/[^a-z0-9_\-]/', '', $slug );

		if ( null === $slug || '' === $slug ) {
			return self::DEFAULT_URL_PATH;
		}

		if ( in_array( $slug, self::$reserved, true ) ) {
			return self::DEFAULT_URL_PATH;
		}

		return $slug;
	}

	/**
	 * Layout override: 'fullwidth', 'boxed', or '' to defer to the dashboard.
	 *
	 * @return string
	 */
	public function get_layout_override() {
		$value = (string) get_option( 'idea89_locator_layout', '' );

		return in_array( $value, array( 'fullwidth', 'boxed' ), true ) ? $value : '';
	}

	/**
	 * A text setting, falling back to its default when blank.
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	public function get_text( $option ) {
		$default = isset( self::$defaults[ $option ] ) ? self::$defaults[ $option ] : '';
		$value   = trim( (string) get_option( $option, $default ) );

		return '' === $value ? $default : $value;
	}

	/**
	 * The default for one option, for the settings screen to render.
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	public static function default_for( $option ) {
		return isset( self::$defaults[ $option ] ) ? self::$defaults[ $option ] : '';
	}

	/**
	 * Every option this section owns, with its default.
	 *
	 * @return array<string,string>
	 */
	public static function text_defaults() {
		return self::$defaults;
	}
}
