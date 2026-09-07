<?php
/**
 * Reads the dashboard-managed settings the storefront needs.
 *
 * Map provider, map key, brand colour and the store-locator plan gate all live
 * in the IDEA89 dashboard rather than in WordPress, so the merchant sets them
 * once for every storefront they run. They are published inside the widget
 * loader as `var cfg = {...};` and read back out here.
 *
 * Fails closed. A timeout, a 500 or an unparsable body all yield the fallback,
 * whose locatorEnabled is false: a billing-tier gate that opens when the
 * network hiccups is not a gate. The result is cached so a slow API cannot
 * slow every page view, and memoised so several consumers in one request share
 * a single call.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches and caches dashboard settings.
 */
class Idea89_Remote_Config {

	const CACHE_KEY = 'idea89_remote_cfg';
	const CACHE_TTL = 900;
	const TIMEOUT   = 3;

	/**
	 * Plugin settings.
	 *
	 * @var Idea89_Config
	 */
	private $config;

	/**
	 * Per-request memo.
	 *
	 * @var array<string,mixed>|null
	 */
	private $memo = null;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Config $config Plugin settings.
	 */
	public function __construct( Idea89_Config $config ) {
		$this->config = $config;
	}

	/**
	 * The safe defaults used whenever the real values cannot be had.
	 *
	 * @return array<string,mixed>
	 */
	public static function fallback() {
		return array(
			'provider'               => 'stadia',
			'key'                    => null,
			'country'                => null,
			'count'                  => 3,
			'brandColor'             => null,
			'storefinderLayout'      => 'fullwidth',
			'locatorEnabled'         => false,
			// Empty rather than 'full': an unreachable API has not told us
			// anything, so the settings screen should keep showing the local
			// cache instead of being moved to a mode nobody chose.
			'checkoutUi'             => '',
			'assistantName'          => '',
			// null, not false: the panel says "unknown" when the API could
			// not be asked rather than claiming the other half is off.
			'personalizationEnabled' => null,
		);
	}

	/**
	 * Returns the dashboard settings, cached.
	 *
	 * @return array<string,mixed>
	 */
	public function get() {
		if ( null !== $this->memo ) {
			return $this->memo;
		}

		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			$this->memo = $cached;
			return $this->memo;
		}

		$parsed = $this->fetch();

		// Cached either way: without this, a store on a plan without the
		// locator would re-request on every single page view.
		set_transient( self::CACHE_KEY, $parsed, self::CACHE_TTL );

		$this->memo = $parsed;

		return $this->memo;
	}

	/**
	 * Whether the store's plan includes the locator. False on any doubt.
	 *
	 * @return bool
	 */
	public function is_locator_plan_enabled() {
		$cfg = $this->get();

		return ! empty( $cfg['locatorEnabled'] );
	}

	/**
	 * The checkout display setting as IDEA89 holds it.
	 *
	 * Static so the settings screen can ask without wiring up an instance.
	 * Returns '' when the API could not be reached or is too old to send it,
	 * which the caller reads as "keep showing the local value".
	 *
	 * @return string
	 */
	public static function personalization_enabled() {
		$cfg = idea89_remote_config()->get();

		return array_key_exists( 'personalizationEnabled', $cfg ) ? $cfg['personalizationEnabled'] : null;
	}

	/**
	 * The assistant name as IDEA89 holds it.
	 *
	 * @return string
	 */
	public static function assistant_name() {
		$cfg = idea89_remote_config()->get();

		return isset( $cfg['assistantName'] ) && is_string( $cfg['assistantName'] ) ? $cfg['assistantName'] : '';
	}

	/**
	 * The checkout display setting as IDEA89 holds it.
	 *
	 * @return string
	 */
	public static function checkout_ui() {
		$cfg = idea89_remote_config()->get();

		return isset( $cfg['checkoutUi'] ) && is_string( $cfg['checkoutUi'] ) ? $cfg['checkoutUi'] : '';
	}

	/**
	 * Drops the cache, so a settings change is picked up without waiting.
	 *
	 * @return void
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Performs the request and parses the response.
	 *
	 * @return array<string,mixed>
	 */
	private function fetch() {
		$api_url = rtrim( (string) $this->config->get_api_url(), '/' );
		$api_key = (string) $this->config->get_api_key();

		if ( '' === $api_url || '' === $api_key ) {
			return self::fallback();
		}

		$response = wp_remote_get(
			$api_url . '/widget/v1/' . rawurlencode( $api_key ) . '.js',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Accept' => 'application/javascript' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return self::fallback();
		}

		return self::parse( (string) wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Extracts the cfg object from the loader script.
	 *
	 * Pure, so the parsing rules can be tested without a network call.
	 *
	 * @param string $body The loader JavaScript.
	 * @return array<string,mixed>
	 */
	public static function parse( $body ) {
		$fallback = self::fallback();

		if ( ! preg_match( '/var cfg = (\{[\s\S]*?\});/', (string) $body, $matches ) ) {
			return $fallback;
		}

		$cfg = json_decode( $matches[1], true );

		if ( ! is_array( $cfg ) ) {
			return $fallback;
		}

		$layout = isset( $cfg['storefinderLayout'] ) && is_string( $cfg['storefinderLayout'] )
			? $cfg['storefinderLayout']
			: '';

		return array(
			'provider'               => isset( $cfg['mapProvider'] ) && is_string( $cfg['mapProvider'] ) ? $cfg['mapProvider'] : $fallback['provider'],
			'key'                    => isset( $cfg['mapKey'] ) && is_string( $cfg['mapKey'] ) ? $cfg['mapKey'] : null,
			'country'                => isset( $cfg['defaultCountryCode'] ) && is_string( $cfg['defaultCountryCode'] ) ? $cfg['defaultCountryCode'] : null,
			'count'                  => isset( $cfg['nearestResultsCount'] ) && is_int( $cfg['nearestResultsCount'] ) ? $cfg['nearestResultsCount'] : $fallback['count'],
			'brandColor'             => isset( $cfg['brandColor'] ) && is_string( $cfg['brandColor'] ) ? $cfg['brandColor'] : null,
			'storefinderLayout'      => in_array( $layout, array( 'fullwidth', 'boxed' ), true ) ? $layout : $fallback['storefinderLayout'],
			'locatorEnabled'         => isset( $cfg['locatorEnabled'] ) && is_bool( $cfg['locatorEnabled'] ) ? $cfg['locatorEnabled'] : false,
			// Read back so the settings screen can show a change made in the
			// dashboard. An older API that does not send the key yields '',
			// which the caller treats as "no opinion".
			'checkoutUi'             => isset( $cfg['checkoutUi'] ) && 'inline' === $cfg['checkoutUi'] ? 'inline' : ( isset( $cfg['checkoutUi'] ) ? 'full' : '' ),
			'assistantName'          => isset( $cfg['assistantName'] ) && is_string( $cfg['assistantName'] ) ? $cfg['assistantName'] : '',
			'personalizationEnabled' => isset( $cfg['personalizationEnabled'] ) ? (bool) $cfg['personalizationEnabled'] : null,
		);
	}
}
