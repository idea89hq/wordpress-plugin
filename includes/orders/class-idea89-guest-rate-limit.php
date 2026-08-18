<?php
/**
 * Fixed-window rate limit for the guest order lookup.
 *
 * The guest endpoint takes an email plus an order number, which makes it the
 * one place an attacker could probe for whether a given order exists. The
 * limit is deliberately tight, and the IP is stored only as a salted hash so
 * the throttle itself does not become a log of who looked up what.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Throttles guest lookups per IP.
 */
class Idea89_Guest_Rate_Limit {

	const MAX_ATTEMPTS = 4;
	const WINDOW       = HOUR_IN_SECONDS;
	const PREFIX       = 'idea89_gl_';

	/**
	 * Records an attempt and reports whether it is allowed.
	 *
	 * @param string $ip Client IP address.
	 * @return bool True when the caller may proceed.
	 */
	public function allow( $ip ) {
		$key   = $this->key( $ip );
		$count = get_transient( $key );

		if ( false === $count ) {
			set_transient( $key, 1, self::WINDOW );
			return true;
		}

		$count = (int) $count;

		if ( $count >= self::MAX_ATTEMPTS ) {
			return false;
		}

		// Preserve the original window: re-setting with a full TTL here would
		// let a caller extend their own window indefinitely by keeping busy.
		set_transient( $key, $count + 1, $this->remaining( $key ) );

		return true;
	}

	/**
	 * Seconds left in the current window, falling back to a full window.
	 *
	 * @param string $key Transient key.
	 * @return int
	 */
	private function remaining( $key ) {
		$timeout = (int) get_option( '_transient_timeout_' . $key, 0 );
		$left    = $timeout - time();

		return $left > 0 ? $left : self::WINDOW;
	}

	/**
	 * Salted, truncated hash of the IP. Never stores the address itself.
	 *
	 * @param string $ip Client IP address.
	 * @return string
	 */
	private function key( $ip ) {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'idea89';

		return self::PREFIX . substr( hash( 'sha256', $salt . '|' . (string) $ip ), 0, 32 );
	}
}
