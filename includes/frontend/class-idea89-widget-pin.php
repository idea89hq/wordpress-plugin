<?php
/**
 * The widget script as a pinned, content-addressed URL plus its Subresource
 * Integrity hash, for stores that turn on "Load with Subresource Integrity".
 *
 * The rolling <api_url>/widget/v1/<api_key>.js changes with every IDEA89
 * release and every dashboard save, so it can never carry an integrity
 * attribute. <api_url>/widget/v1/<api_key>/pin.json returns a URL whose path
 * is the SHA-384 of the bytes it serves, and the sha384-… value for the tag:
 * those bytes can never change, so the browser check always holds.
 *
 * Caching: the answer is kept in a transient for the max_age the API sends
 * (clamped to 1–60 minutes); a failure is remembered for one minute so an
 * unreachable API costs one 2-second timeout a minute, not one per page.
 *
 * Failure mode: any error, or an answer that does not look exactly like a pin
 * on the configured API host, gives null and the widget loads the rolling URL
 * without integrity, as before. A pin must never make the widget vanish.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pinned widget script lookup.
 */
class Idea89_Widget_Pin {

	const CACHE_PREFIX = 'idea89_widget_pin_';
	const FAILURE_TTL  = 60;
	const MIN_TTL      = 60;
	const MAX_TTL      = 3600;
	const TIMEOUT      = 2;

	/**
	 * Configuration reader.
	 *
	 * @var Idea89_Config
	 */
	private $config;

	/**
	 * Request-scoped memo: null = not looked up yet, false = no pin.
	 *
	 * @var array|false|null
	 */
	private $memo = null;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Config $config Configuration reader.
	 */
	public function __construct( Idea89_Config $config ) {
		$this->config = $config;
	}

	/**
	 * The pinned src and integrity value, or null to load the rolling URL.
	 *
	 * @return array{src: string, integrity: string}|null
	 */
	public function get() {
		if ( null !== $this->memo ) {
			return $this->memo ? $this->memo : null;
		}

		$api_key = (string) $this->config->get_api_key();
		$api_url = (string) $this->config->get_api_url();
		if ( ! $this->config->is_widget_integrity_enabled() || '' === $api_key ) {
			$this->memo = false;
			return null;
		}

		$cache_key = self::CACHE_PREFIX . md5( $api_key . '|' . $api_url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$pin        = self::validate( $cached, $api_url );
			$this->memo = null === $pin ? false : $pin;
			return $pin;
		}

		list( $pin, $ttl ) = $this->fetch( $api_key, $api_url );
		set_transient( $cache_key, null === $pin ? array( 'none' => true ) : $pin, null === $pin ? self::FAILURE_TTL : $ttl );

		$this->memo = null === $pin ? false : $pin;
		return $pin;
	}

	/**
	 * Only a URL on the configured API host, under /widget/v1/p/, named by a
	 * 96-character sha384 hex digest, with a matching-shape sha384 integrity
	 * value, is ever rendered. Anything else is treated as no pin.
	 *
	 * Pure, so the rules can be tested without a network call.
	 *
	 * @param array  $data    Decoded pin.json answer (or a cached copy).
	 * @param string $api_url API base URL the pin must live under.
	 * @return array{src: string, integrity: string}|null
	 */
	public static function validate( $data, $api_url ) {
		$src       = isset( $data['src'] ) ? $data['src'] : null;
		$integrity = isset( $data['integrity'] ) ? $data['integrity'] : null;
		if ( ! is_string( $src ) || ! is_string( $integrity ) ) {
			return null;
		}

		$prefix = rtrim( (string) $api_url, '/' ) . '/widget/v1/p/';
		if ( 0 !== strpos( $src, $prefix ) ) {
			return null;
		}
		if ( 1 !== preg_match( '#^[a-f0-9]{96}\.js$#', substr( $src, strlen( $prefix ) ) ) ) {
			return null;
		}
		if ( 1 !== preg_match( '#^sha384-[A-Za-z0-9+/]{64}$#', $integrity ) ) {
			return null;
		}

		return array(
			'src'       => $src,
			'integrity' => $integrity,
		);
	}

	/**
	 * Asks the API for the current pin.
	 *
	 * @param string $api_key Store API key.
	 * @param string $api_url API base URL.
	 * @return array{0: array|null, 1: int} The pin (or null) and how long to keep it.
	 */
	private function fetch( $api_key, $api_url ) {
		$api_url = rtrim( $api_url, '/' );
		if ( '' === $api_url ) {
			return array( null, self::FAILURE_TTL );
		}

		$response = wp_remote_get(
			$api_url . '/widget/v1/' . rawurlencode( $api_key ) . '/pin.json',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array( null, self::FAILURE_TTL );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array( null, self::FAILURE_TTL );
		}

		$max_age = isset( $body['max_age'] ) && is_int( $body['max_age'] ) ? $body['max_age'] : self::MIN_TTL;

		return array( self::validate( $body, $api_url ), max( self::MIN_TTL, min( self::MAX_TTL, $max_age ) ) );
	}
}
