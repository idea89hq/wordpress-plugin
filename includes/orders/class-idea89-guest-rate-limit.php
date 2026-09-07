<?php
/**
 * Fixed-window rate limit, keyed on IP.
 *
 * Originally written for the guest order lookup (an email plus an order
 * number, which makes it the one place an attacker could probe for whether
 * a given order exists) — the limit there is deliberately tight, and the IP
 * is stored only as a salted hash so the throttle itself does not become a
 * log of who looked up what.
 *
 * The constructor arguments make the same class reusable for the
 * native-checkout REST routes (Idea89_Checkout_Rest, I2 in the final fix
 * wave review) with their own ceiling and window per route tier, rather
 * than inventing a second limiter idiom: every caller still gets the same
 * fixed-window transient mechanics and the same salted-hash key. The
 * defaults reproduce the original guest-lookup behaviour exactly, so the
 * zero-argument constructor already in use there is unaffected.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Throttles callers per IP.
 */
class Idea89_Guest_Rate_Limit {

	const MAX_ATTEMPTS = 4;
	const WINDOW       = HOUR_IN_SECONDS;
	const PREFIX       = 'idea89_gl_';

	/**
	 * Attempts allowed per window.
	 *
	 * @var int
	 */
	private $max_attempts;

	/**
	 * Window length in seconds.
	 *
	 * @var int
	 */
	private $window;

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param int|null    $max_attempts Attempts allowed per window. Defaults to MAX_ATTEMPTS.
	 * @param int|null    $window       Window length in seconds. Defaults to WINDOW.
	 * @param string|null $prefix       Transient key prefix, distinct per caller so
	 *                                  buckets never collide. Defaults to PREFIX.
	 */
	public function __construct( $max_attempts = null, $window = null, $prefix = null ) {
		$this->max_attempts = null === $max_attempts ? self::MAX_ATTEMPTS : (int) $max_attempts;
		$this->window       = null === $window ? self::WINDOW : (int) $window;
		$this->prefix       = null === $prefix ? self::PREFIX : (string) $prefix;
	}

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
			set_transient( $key, 1, $this->window );
			return true;
		}

		$count = (int) $count;

		if ( $count >= $this->max_attempts ) {
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

		return $left > 0 ? $left : $this->window;
	}

	/**
	 * Salted, truncated hash of the IP. Never stores the address itself.
	 *
	 * @param string $ip Client IP address.
	 * @return string
	 */
	private function key( $ip ) {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'idea89';

		return $this->prefix . substr( hash( 'sha256', $salt . '|' . (string) $ip ), 0, 32 );
	}
}
