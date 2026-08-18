<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-tracking-url-resolver.php';
require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-order-sanitizer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-order-tracking-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-order-endpoints.php';

class OrderTrackingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/* ---------------- Tracking URL resolver ---------------- */

	public function test_resolves_known_carriers() {
		$r = new Idea89_Tracking_Url_Resolver();

		$this->assertSame( 'https://www.ups.com/track?tracknum=1Z999', $r->resolve( 'ups', '1Z999' ) );
		$this->assertStringContainsString( 'fedex.com', (string) $r->resolve( 'fedex', 'X1' ) );
		$this->assertStringContainsString( 'royalmail.com', (string) $r->resolve( 'royalmail', 'RM1' ) );
	}

	/**
	 * Shipment plugins prefix and suffix carrier codes freely. All of these
	 * name the same carrier and must resolve identically.
	 */
	public function test_normalises_prefixed_and_suffixed_carrier_codes() {
		$r        = new Idea89_Tracking_Url_Resolver();
		$expected = $r->resolve( 'ups', 'N1' );

		foreach ( array( 'UPS', ' ups ', 'wc_ups', 'custom_ups', 'ups_ground', 'ups-express' ) as $code ) {
			$this->assertSame( $expected, $r->resolve( $code, 'N1' ), "Failed for '{$code}'" );
		}
	}

	public function test_royal_mail_aliases_collapse() {
		$r        = new Idea89_Tracking_Url_Resolver();
		$expected = $r->resolve( 'royal-mail', 'RM9' );

		foreach ( array( 'royalmail', 'rm', 'Royal-Mail', 'royal_mail' ) as $code ) {
			$this->assertSame( $expected, $r->resolve( $code, 'RM9' ), "Failed for '{$code}'" );
		}
	}

	/**
	 * Guessing a URL for an unknown carrier would send shoppers to a 404 on
	 * someone else's site, so an unknown carrier must yield null.
	 */
	public function test_unknown_carrier_and_empty_input_yield_null() {
		$r = new Idea89_Tracking_Url_Resolver();

		$this->assertNull( $r->resolve( 'some-local-courier', 'N1' ) );
		$this->assertNull( $r->resolve( '', 'N1' ) );
		$this->assertNull( $r->resolve( 'ups', '' ) );
		$this->assertNull( $r->resolve( 'ups', '   ' ) );
	}

	public function test_tracking_number_is_url_encoded() {
		$r = new Idea89_Tracking_Url_Resolver();

		$this->assertSame( 'https://www.ups.com/track?tracknum=a%20b%26c', $r->resolve( 'ups', 'a b&c' ) );
	}

	/* ---------------- Status mapping ---------------- */

	public function test_maps_woocommerce_statuses_to_widget_statuses() {
		$cases = array(
			'pending'           => 'pending',
			'wc-pending'        => 'pending',
			'checkout-draft'    => 'pending',
			'on-hold'           => 'holding',
			'processing'        => 'processing',
			'completed'         => 'complete',
			'wc-completed'      => 'complete',
			'cancelled'         => 'cancelled',
			'refunded'          => 'refunded',
			'failed'            => 'cancelled',
			'shipped'           => 'shipped',
			'partially-shipped' => 'shipped',
			'delivered'         => 'delivered',
		);

		foreach ( $cases as $wc => $expected ) {
			$this->assertSame( $expected, Idea89_Order_Sanitizer::map_status( $wc ), "Failed for '{$wc}'" );
		}
	}

	/**
	 * A store with a custom status must still render, so anything unmapped
	 * falls back to the neutral in-progress pill rather than an empty string.
	 */
	public function test_unknown_status_falls_back_to_processing() {
		$this->assertSame( 'processing', Idea89_Order_Sanitizer::map_status( 'awaiting-pick' ) );
		$this->assertSame( 'processing', Idea89_Order_Sanitizer::map_status( '' ) );
	}

	/* ---------------- Route matching ---------------- */

	public function test_matches_only_our_routes() {
		$this->assertSame( 'customer/me', Idea89_Order_Endpoints::route_from_request( 'idea89/customer/me' ) );
		$this->assertSame( 'orders/recent', Idea89_Order_Endpoints::route_from_request( '/idea89/orders/recent/' ) );
		$this->assertSame( 'orders/detail', Idea89_Order_Endpoints::route_from_request( 'idea89/orders/detail' ) );
		$this->assertSame( 'orders/lookup', Idea89_Order_Endpoints::route_from_request( 'idea89/orders/lookup' ) );
	}

	/**
	 * The router runs on every front-end request, so anything that is not
	 * ours must return null and let WordPress carry on. A page whose slug
	 * merely starts with "idea89" belongs to the site.
	 */
	public function test_ignores_everything_else() {
		foreach ( array( '', '/', 'shop', 'idea89', 'idea89reviews', 'idea89reviews/page', 'about/idea89' ) as $path ) {
			$this->assertNull(
				Idea89_Order_Endpoints::route_from_request( $path ),
				"Should not have matched '{$path}'"
			);
		}
	}

	public function test_unknown_subroute_is_claimed_but_not_dispatched() {
		$this->assertSame( 'unknown', Idea89_Order_Endpoints::route_from_request( 'idea89/orders/all' ) );
	}

	/* ---------------- Config clamping ---------------- */

	public function test_max_recent_orders_is_clamped() {
		$make = function ( $value ) {
			Functions\when( 'get_option' )->alias(
				function ( $name, $default = null ) use ( $value ) {
					return 'idea89_order_tracking_max_recent' === $name ? $value : $default;
				}
			);
			return ( new Idea89_Order_Tracking_Config() )->get_max_recent_orders();
		};

		$this->assertSame( 1, $make( 0 ) );
		$this->assertSame( 1, $make( -5 ) );
		$this->assertSame( 3, $make( 3 ) );
		$this->assertSame( 10, $make( 10 ) );
		$this->assertSame( 10, $make( 99 ) );
	}

	/**
	 * The endpoints read customer orders, so they must stay shut until the
	 * merchant opts in rather than switching on during an upgrade.
	 */
	public function test_order_tracking_is_off_by_default() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = null ) {
				return $default;
			}
		);

		$this->assertFalse( ( new Idea89_Order_Tracking_Config() )->is_enabled() );
	}
}
