<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-guest-rate-limit.php';

/**
 * Idea89_Guest_Rate_Limit went unpinned by any direct unit test even
 * though it was already shipped for guest order lookup — the final fix
 * wave (I2) makes it load-bearing for the native-checkout REST routes
 * too, so its fixed-window mechanics and the new constructor arguments
 * get their own coverage here.
 */
class GuestRateLimitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		define_auth_salt_once();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * In-memory stand-in for the transient store: an array keyed by
	 * transient name, holding [value, timeout]. Real enough to exercise
	 * the "preserve the original window" logic in remaining().
	 *
	 * @return array<string,array{0:mixed,1:int}>
	 */
	private function fake_transients() {
		$store = array();

		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( &$store ) {
				return isset( $store[ $key ] ) ? $store[ $key ][0] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$store ) {
				$store[ $key ] = array( $value, time() + $ttl );
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( &$store ) {
				if ( 0 === strpos( $name, '_transient_timeout_' ) ) {
					$key = substr( $name, strlen( '_transient_timeout_' ) );
					return isset( $store[ $key ] ) ? $store[ $key ][1] : $default;
				}
				return $default;
			}
		);

		return $store;
	}

	public function test_first_attempt_from_an_ip_is_always_allowed() {
		$this->fake_transients();
		$limiter = new Idea89_Guest_Rate_Limit();

		$this->assertTrue( $limiter->allow( '203.0.113.1' ) );
	}

	public function test_default_limits_match_the_original_guest_lookup_shape() {
		// Pinned so a future edit cannot silently change the guest order
		// lookup's throttle without a deliberate decision.
		$this->assertSame( 4, Idea89_Guest_Rate_Limit::MAX_ATTEMPTS );
		$this->assertSame( HOUR_IN_SECONDS, Idea89_Guest_Rate_Limit::WINDOW );
		$this->assertSame( 'idea89_gl_', Idea89_Guest_Rate_Limit::PREFIX );
	}

	public function test_allows_up_to_the_configured_ceiling_then_blocks() {
		$this->fake_transients();
		$limiter = new Idea89_Guest_Rate_Limit( 3, HOUR_IN_SECONDS, 'idea89_test_' );

		$this->assertTrue( $limiter->allow( '203.0.113.1' ) );
		$this->assertTrue( $limiter->allow( '203.0.113.1' ) );
		$this->assertTrue( $limiter->allow( '203.0.113.1' ) );
		$this->assertFalse( $limiter->allow( '203.0.113.1' ) );
	}

	public function test_two_different_ips_get_independent_buckets() {
		$this->fake_transients();
		$limiter = new Idea89_Guest_Rate_Limit( 1, HOUR_IN_SECONDS, 'idea89_test_' );

		$this->assertTrue( $limiter->allow( '203.0.113.1' ) );
		$this->assertFalse( $limiter->allow( '203.0.113.1' ) );
		// A second IP must not inherit the first one's exhausted bucket.
		$this->assertTrue( $limiter->allow( '203.0.113.2' ) );
	}

	public function test_two_instances_with_different_prefixes_never_collide() {
		$this->fake_transients();
		$tight = new Idea89_Guest_Rate_Limit( 1, HOUR_IN_SECONDS, 'idea89_tight_' );
		$loose = new Idea89_Guest_Rate_Limit( 5, HOUR_IN_SECONDS, 'idea89_loose_' );

		$this->assertTrue( $tight->allow( '203.0.113.1' ) );
		$this->assertFalse( $tight->allow( '203.0.113.1' ) );
		// Same IP, different limiter/prefix: must not be blocked by the
		// other bucket's exhaustion.
		$this->assertTrue( $loose->allow( '203.0.113.1' ) );
	}

	public function test_never_stores_the_raw_ip_in_the_transient_key() {
		$captured_keys = array();
		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( &$captured_keys ) {
				$captured_keys[] = $key;
				return false;
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );

		$limiter = new Idea89_Guest_Rate_Limit();
		$limiter->allow( '203.0.113.1' );

		$this->assertNotEmpty( $captured_keys );
		foreach ( $captured_keys as $key ) {
			$this->assertStringNotContainsString( '203.0.113.1', $key );
		}
	}
}

/**
 * AUTH_SALT is a real WordPress constant this class reads directly (not
 * via a function Brain Monkey can stub), so tests define it once, same as
 * a real wp-config.php would.
 *
 * @return void
 */
function define_auth_salt_once() {
	if ( ! defined( 'AUTH_SALT' ) ) {
		define( 'AUTH_SALT', 'test-salt-do-not-use-in-production' );
	}
}
