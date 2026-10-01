<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-checkout-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-guest-rate-limit.php';
require_once IDEA89_PLUGIN_DIR . 'includes/checkout/class-idea89-checkout-rest.php';

/**
 * Plain stand-ins for the WooCommerce surface Idea89_Checkout_Rest touches —
 * same "data in, data out" philosophy as Fake_WC_Product in bootstrap.php.
 * Prefixed Idea89_Fake_* (rather than the shorter Fake_WC_* already used by
 * MiniCheckoutTest.php and PromoSyncerTest.php) because PHPUnit loads every
 * test file into one process: a second `class Fake_WC_Cart` declaration here
 * would be a fatal redeclaration, not a harmless shadow.
 */
class Idea89_Fake_WC_Cart {
	public $contents_count = 0;
	public $subtotal       = 0.0;
	public $total          = 0.0;
	public $needs_shipping = false;
	public $empty          = true;
	public $packages       = array();
	public $totals_calculated = 0;

	public function get_cart_contents_count() {
		return $this->contents_count;
	}

	public function get_subtotal() {
		return $this->subtotal;
	}

	public function get_total( $context = 'view' ) {
		return $this->total;
	}

	public function needs_shipping() {
		return $this->needs_shipping;
	}

	public function is_empty() {
		return $this->empty;
	}

	public function get_shipping_packages() {
		return $this->packages;
	}

	public function calculate_totals() {
		++$this->totals_calculated;
	}
}

/**
 * Every field apply_address()/order_data() might set or read, declared as
 * plain, explicit methods rather than via __call. is_callable(array($obj,
 * 'anyName')) reports true for ANY name once __call is present on a class —
 * which would defeat the point of this fake, since both callers gate every
 * field behind is_callable() precisely so a field a real WC_Customer
 * doesn't support is skipped rather than fataling. Real WC_Customer has no
 * __call for these at all (they are ordinary declared methods), so this
 * fake matches that by declaring the exact set it supports and nothing
 * else, with no magic method present.
 */
class Idea89_Fake_WC_Customer {
	public $data  = array();
	public $saved = 0;

	public function save() {
		++$this->saved;
	}

	private function get( $field ) {
		return isset( $this->data[ $field ] ) ? $this->data[ $field ] : '';
	}

	private function set( $field, $value ) {
		$this->data[ $field ] = $value;
	}

	public function get_billing_first_name() {
		return $this->get( 'billing_first_name' ); }
	public function get_billing_last_name() {
		return $this->get( 'billing_last_name' ); }
	public function get_billing_address_1() {
		return $this->get( 'billing_address_1' ); }
	public function get_billing_address_2() {
		return $this->get( 'billing_address_2' ); }
	public function get_billing_city() {
		return $this->get( 'billing_city' ); }
	public function get_billing_state() {
		return $this->get( 'billing_state' ); }
	public function get_billing_postcode() {
		return $this->get( 'billing_postcode' ); }
	public function get_billing_country() {
		return $this->get( 'billing_country' ); }
	public function get_billing_phone() {
		return $this->get( 'billing_phone' ); }
	public function get_billing_email() {
		return $this->get( 'billing_email' ); }

	public function get_shipping_first_name() {
		return $this->get( 'shipping_first_name' ); }
	public function get_shipping_last_name() {
		return $this->get( 'shipping_last_name' ); }
	public function get_shipping_address_1() {
		return $this->get( 'shipping_address_1' ); }
	public function get_shipping_address_2() {
		return $this->get( 'shipping_address_2' ); }
	public function get_shipping_city() {
		return $this->get( 'shipping_city' ); }
	public function get_shipping_state() {
		return $this->get( 'shipping_state' ); }
	public function get_shipping_postcode() {
		return $this->get( 'shipping_postcode' ); }
	public function get_shipping_country() {
		return $this->get( 'shipping_country' ); }
	public function get_shipping_phone() {
		return $this->get( 'shipping_phone' ); }

	public function set_billing_first_name( $v ) {
		$this->set( 'billing_first_name', $v ); }
	public function set_billing_last_name( $v ) {
		$this->set( 'billing_last_name', $v ); }
	public function set_billing_address_1( $v ) {
		$this->set( 'billing_address_1', $v ); }
	public function set_billing_address_2( $v ) {
		$this->set( 'billing_address_2', $v ); }
	public function set_billing_city( $v ) {
		$this->set( 'billing_city', $v ); }
	public function set_billing_state( $v ) {
		$this->set( 'billing_state', $v ); }
	public function set_billing_postcode( $v ) {
		$this->set( 'billing_postcode', $v ); }
	public function set_billing_country( $v ) {
		$this->set( 'billing_country', $v ); }
	public function set_billing_phone( $v ) {
		$this->set( 'billing_phone', $v ); }
	public function set_billing_email( $v ) {
		$this->set( 'billing_email', $v ); }

	public function set_shipping_first_name( $v ) {
		$this->set( 'shipping_first_name', $v ); }
	public function set_shipping_last_name( $v ) {
		$this->set( 'shipping_last_name', $v ); }
	public function set_shipping_address_1( $v ) {
		$this->set( 'shipping_address_1', $v ); }
	public function set_shipping_address_2( $v ) {
		$this->set( 'shipping_address_2', $v ); }
	public function set_shipping_city( $v ) {
		$this->set( 'shipping_city', $v ); }
	public function set_shipping_state( $v ) {
		$this->set( 'shipping_state', $v ); }
	public function set_shipping_postcode( $v ) {
		$this->set( 'shipping_postcode', $v ); }
	public function set_shipping_country( $v ) {
		$this->set( 'shipping_country', $v ); }
	public function set_shipping_phone( $v ) {
		$this->set( 'shipping_phone', $v ); }
}

class Idea89_Fake_Shipping_Rate {
	private $method_id;
	private $instance_id;
	private $label;
	private $cost;

	public function __construct( $method_id, $instance_id, $label, $cost ) {
		$this->method_id   = $method_id;
		$this->instance_id = $instance_id;
		$this->label       = $label;
		$this->cost        = $cost;
	}

	public function get_method_id() {
		return $this->method_id;
	}

	public function get_instance_id() {
		return $this->instance_id;
	}

	public function get_label() {
		return $this->label;
	}

	public function get_cost() {
		return $this->cost;
	}
}

class Idea89_Fake_WC_Shipping {
	public $result = array();
	public $last_packages_arg;

	public function calculate_shipping( $packages ) {
		$this->last_packages_arg = $packages;
		return $this->result;
	}
}

class Idea89_Fake_WC_Session {
	public $data = array();

	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}

	public function get( $key, $default = null ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : $default;
	}
}

class Idea89_Fake_Gateway {
	public $id;
	private $title;
	public $process_payment_calls = array();
	/** @var mixed What process_payment() returns; null means the default success array. */
	public $payment_result = null;

	public function __construct( $id, $title ) {
		$this->id    = $id;
		$this->title = $title;
	}

	public function get_title() {
		return $this->title;
	}

	public function get_method_title() {
		return $this->title;
	}

	public function process_payment( $order_id ) {
		$this->process_payment_calls[] = $order_id;
		if ( null !== $this->payment_result ) {
			return $this->payment_result;
		}
		return array(
			'result'   => 'success',
			'redirect' => '',
		);
	}
}

class Idea89_Fake_Payment_Gateways {
	/** @var array<string, Idea89_Fake_Gateway> */
	public $all;
	/** @var array<string, Idea89_Fake_Gateway> */
	public $available;

	public function __construct( array $all, array $available ) {
		$this->all       = $all;
		$this->available = $available;
	}

	public function payment_gateways() {
		return $this->all;
	}

	public function get_available_payment_gateways() {
		return $this->available;
	}
}

class Idea89_Fake_WC_Checkout {
	public $create_order_calls = array();
	public $order_id_to_return = 501;
	public $error_to_return    = null;

	public function create_order( $data ) {
		$this->create_order_calls[] = $data;
		if ( null !== $this->error_to_return ) {
			return $this->error_to_return;
		}
		return $this->order_id_to_return;
	}
}

class Idea89_Fake_WC_Countries {
	public $allowed = array();

	public function get_allowed_countries() {
		return $this->allowed;
	}
}

class Idea89_Fake_WC_Order {
	private $data;

	public function __construct( array $data = array() ) {
		$this->data = array_merge(
			array(
				'order_number' => '2001',
				'total'        => '59.98',
				'currency'     => 'GBP',
				'status'       => 'processing',
			),
			$data
		);
	}

	public function get_status() {
		return $this->data['status'];
	}

	public function get_order_number() {
		return $this->data['order_number'];
	}

	public function get_total() {
		return $this->data['total'];
	}

	public function get_currency() {
		return $this->data['currency'];
	}
}

/**
 * The WC() global: only the properties/methods Idea89_Checkout_Rest reads.
 */
class Idea89_Fake_Wc_Global {
	public $cart;
	public $customer;
	public $session;
	public $countries;
	// Deliberately NO $payment_gateways property. Real WooCommerce's
	// WooCommerce class exposes payment_gateways() ONLY as a method,
	// reachable as a "property" solely via __get() magic — and it has no
	// __isset(), so `empty( WC()->payment_gateways )` (property syntax)
	// is ALWAYS true, real WC()->payment_gateways() call or not. This fake
	// omitting the property is what makes that whole class of bug visible
	// to these tests — a fake that supplied a truthy $payment_gateways
	// property here would hide it, which is exactly what happened before
	// this was found live (see Idea89_Checkout_Rest::allowed_gateways()'s
	// docblock).
	private $gateways_manager;
	private $shipping_manager;
	private $checkout_manager;

	public function payment_gateways() {
		return $this->gateways_manager;
	}

	public function set_payment_gateways_manager( $manager ) {
		$this->gateways_manager = $manager;
	}

	public function shipping() {
		return $this->shipping_manager;
	}

	public function set_shipping_manager( $manager ) {
		$this->shipping_manager = $manager;
	}

	public function checkout() {
		return $this->checkout_manager;
	}

	public function set_checkout_manager( $manager ) {
		$this->checkout_manager = $manager;
	}
}

class CheckoutRestTest extends TestCase {

	/** @var Idea89_Fake_Wc_Global */
	private $wc;

	/** @var Idea89_Fake_WC_Cart */
	private $cart;

	/** @var Idea89_Fake_WC_Customer */
	private $customer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_email' )->alias(
			function ( $value ) {
				return trim( (string) $value );
			}
		);
		Functions\when( 'is_email' )->alias(
			function ( $value ) {
				return false !== strpos( (string) $value, '@' );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'sanitize_key' )->alias(
			function ( $value ) {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) );
			}
		);
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'GBP' );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
		// I2: origin_gate() derives the site's own host from home_url() via
		// wp_parse_url() — same idiom the rest of the plugin already uses
		// (see Idea89_Client::domain_header()). request_with_body() below
		// sends a matching Origin by default so every pre-existing test
		// keeps passing without individually setting one.
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'https://shop.example.test' );
		// I2: rate limiters default to "never seen this IP before" unless a
		// test overrides get_transient() itself to simulate an exhausted
		// window.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$this->cart     = new Idea89_Fake_WC_Cart();
		$this->customer = new Idea89_Fake_WC_Customer();

		$this->wc            = new Idea89_Fake_Wc_Global();
		$this->wc->cart      = $this->cart;
		$this->wc->customer  = $this->customer;
		$this->wc->session   = new Idea89_Fake_WC_Session();
		$this->wc->countries = new Idea89_Fake_WC_Countries();
		$this->wc->set_shipping_manager( new Idea89_Fake_WC_Shipping() );
		$this->wc->set_checkout_manager( new Idea89_Fake_WC_Checkout() );
		$this->wc->set_payment_gateways_manager(
			new Idea89_Fake_Payment_Gateways(
				array(
					'cod'    => new Idea89_Fake_Gateway( 'cod', 'Cash on delivery' ),
					'bacs'   => new Idea89_Fake_Gateway( 'bacs', 'Direct bank transfer' ),
					'paypal' => new Idea89_Fake_Gateway( 'paypal', 'PayPal' ),
				),
				array(
					'cod'    => new Idea89_Fake_Gateway( 'cod', 'Cash on delivery' ),
					'bacs'   => new Idea89_Fake_Gateway( 'bacs', 'Direct bank transfer' ),
					'paypal' => new Idea89_Fake_Gateway( 'paypal', 'PayPal' ),
				)
			)
		);

		Functions\when( 'WC' )->justReturn( $this->wc );
		Functions\when( 'wc_get_order' )->justReturn( new Idea89_Fake_WC_Order() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $options
	 */
	private function with_options( array $options ) {
		$defaults = array(
			'idea89_enabled'       => true,
			'idea89_checkout_mode' => 'native',
		);
		$merged   = array_merge( $defaults, $options );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) use ( $merged ) {
				return array_key_exists( $name, $merged ) ? $merged[ $name ] : $default;
			}
		);
	}

	private function rest() {
		return new Idea89_Checkout_Rest( new Idea89_Config(), new Idea89_Checkout_Config() );
	}

	/**
	 * Carries a same-origin Origin header by default (matching the
	 * 'https://shop.example.test' home_url() stub in setUp()) so every
	 * pre-existing test satisfies I2's new origin_gate() without having to
	 * set one individually. Pass $origin = null for the "no header at all"
	 * case, or a foreign value to prove the mismatch is rejected too.
	 *
	 * @param array<string,mixed> $body   Decoded JSON body.
	 * @param string|null         $origin Origin header value, or null to omit it.
	 */
	private function request_with_body( array $body, $origin = 'https://shop.example.test' ) {
		$request = new WP_REST_Request();
		$request->set_json_params( $body );
		if ( null !== $origin ) {
			$request->set_header( 'Origin', $origin );
		}
		return $request;
	}

	/* ---------------- Auth: the single most important check ---------------- */

	public function test_get_nonce_check_rejects_a_missing_header() {
		$this->with_options( array() );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$result = $this->rest()->check_get_nonce( new WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_get_nonce_check_rejects_a_wrong_nonce() {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$request = new WP_REST_Request();
		$request->set_header( 'Nonce', 'not-a-real-nonce' );

		$result = $this->rest()->check_get_nonce( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_get_nonce_check_accepts_a_valid_nonce() {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$request = new WP_REST_Request();
		$request->set_header( 'Nonce', 'a-real-nonce' );

		$this->assertTrue( $this->rest()->check_get_nonce( $request ) );
	}

	public function test_post_nonce_check_rejects_a_body_with_no_form_key() {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$result = $this->rest()->check_post_nonce( $this->request_with_body( array( 'payment_method' => 'cod' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_post_nonce_check_accepts_a_valid_form_key() {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$result = $this->rest()->check_post_nonce( $this->request_with_body( array( 'form_key' => 'a-real-nonce' ) ) );

		$this->assertTrue( $result );
	}

	/* ---------------- I2: same-origin guard on the three POST routes ---------------- */

	public function test_address_rejects_a_missing_origin() {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$response = $this->rest()->handle_address( $this->request_with_body( array(), null ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'cross_origin_forbidden', $response->get_data()['error'] );
	}

	public function test_address_rejects_a_foreign_origin() {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$response = $this->rest()->handle_address( $this->request_with_body( array(), 'https://evil.example.test' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_address_accepts_a_same_origin_request() {
		$this->with_options( array() );
		// A same-origin request must still be able to reach past the guard —
		// the missing-postcode 400 below proves the request continued into
		// the handler rather than being rejected by origin_gate() itself.
		$response = $this->rest()->handle_address( $this->request_with_body( array() ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNotSame( 'cross_origin_forbidden', $response->get_data()['error'] );
	}

	public function test_method_rejects_a_missing_origin() {
		$response = $this->rest()->handle_method( $this->request_with_body( array( 'carrier' => 'flat_rate', 'method' => '5' ), null ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'cross_origin_forbidden', $response->get_data()['error'] );
	}

	public function test_place_rejects_a_missing_origin() {
		$response = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'cod' ), null ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'cross_origin_forbidden', $response->get_data()['error'] );
	}

	public function test_context_has_no_origin_check_and_stays_lenient_on_a_missing_one() {
		$this->with_options( array() );
		// Deliberately NOT gated, matching Magento's Context.php: a plain
		// GET can legitimately arrive with no Origin header at all.
		$response = $this->rest()->handle_context();

		$this->assertNotSame( 403, $response->get_status() );
	}

	/* ---------------- I2: rate limiting, /place tightest ---------------- */

	public function test_place_is_rate_limited_after_its_tight_ceiling() {
		$this->with_options( array() );
		Functions\when( 'get_transient' )->justReturn( Idea89_Checkout_Rest::RATE_LIMIT_PLACE_MAX );

		$response = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'cod' ) ) );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'rate_limited', $response->get_data()['error'] );
	}

	public function test_context_is_rate_limited_after_its_looser_ceiling() {
		$this->with_options( array() );
		Functions\when( 'get_transient' )->justReturn( Idea89_Checkout_Rest::RATE_LIMIT_OTHER_MAX );

		$response = $this->rest()->handle_context();

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'rate_limited', $response->get_data()['error'] );
	}

	public function test_place_and_context_use_independent_buckets() {
		$this->with_options( array() );
		// Exhausting /place's tight bucket must not touch /context's looser
		// one — they are two distinct Idea89_Guest_Rate_Limit instances with
		// distinct transient key prefixes, not one shared counter.
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return 0 === strpos( $key, Idea89_Checkout_Rest::RATE_LIMIT_PLACE_PREFIX )
					? Idea89_Checkout_Rest::RATE_LIMIT_PLACE_MAX
					: false;
			}
		);

		$placeResponse   = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'cod' ) ) );
		$contextResponse = $this->rest()->handle_context();

		$this->assertSame( 429, $placeResponse->get_status() );
		$this->assertNotSame( 429, $contextResponse->get_status() );
	}

	public function test_rate_limit_applies_before_the_gateway_allowlist_is_re_derived() {
		$this->with_options( array() );
		// A rate-limited caller must not learn anything about which
		// gateways are allowlisted.
		Functions\when( 'get_transient' )->justReturn( Idea89_Checkout_Rest::RATE_LIMIT_PLACE_MAX );

		$response = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'cod' ) ) );

		$this->assertArrayNotHasKey( 'methods', $response->get_data() );
	}

	/* ---------------- context() ---------------- */

	public function test_context_404s_when_native_mode_is_off() {
		$this->with_options( array( 'idea89_checkout_mode' => 'express' ) );

		$response = $this->rest()->handle_context();

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'not_available', $response->get_data()['error'] );
	}

	public function test_context_404s_when_the_assistant_is_disabled() {
		$this->with_options( array( 'idea89_enabled' => false ) );

		$response = $this->rest()->handle_context();

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_context_reports_the_cart_and_only_allowlisted_gateways() {
		$this->with_options( array( 'idea89_checkout_native_methods' => array( 'cod', 'bacs' ) ) );
		$this->cart->contents_count = 3;
		$this->cart->subtotal       = 45.5;
		$this->cart->total          = 49.99;
		$this->cart->needs_shipping = true;
		$this->wc->countries->allowed = array(
			'GB' => 'United Kingdom',
			'IE' => 'Ireland',
		);

		$response = $this->rest()->handle_context();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 3, $data['item_count'] );
		$this->assertSame( 'GBP', $data['currency'] );
		$this->assertSame( '45.50', $data['subtotal'] );
		$this->assertSame( '49.99', $data['grand_total'] );
		$this->assertTrue( $data['needs_shipping'] );
		// PayPal is available but not allowlisted — must not appear.
		$this->assertSame(
			array(
				array(
					'code'  => 'cod',
					'title' => 'Cash on delivery',
				),
				array(
					'code'  => 'bacs',
					'title' => 'Direct bank transfer',
				),
			),
			$data['methods']
		);
		$this->assertSame(
			array(
				array(
					'code' => 'GB',
					'name' => 'United Kingdom',
				),
				array(
					'code' => 'IE',
					'name' => 'Ireland',
				),
			),
			$data['allowed_countries']
		);
	}

	public function test_context_methods_are_empty_when_the_allowlist_is_empty() {
		$this->with_options( array( 'idea89_checkout_native_methods' => array() ) );

		$response = $this->rest()->handle_context();

		$this->assertSame( array(), $response->get_data()['methods'] );
	}

	/**
	 * C2 (Critical, pinned): the plan's headline Tier 3 guarantee is
	 * "behind an allowlist that DEFAULTS TO EMPTY", matching Magento's own
	 * Model/CheckoutConfig::getNativeMethods() ([] when unset) exactly. A
	 * merchant who selects "Native checkout (beta)" out of curiosity must
	 * get an endpoint that places NO orders at all, not three live
	 * offline gateways by surprise. This test previously asserted the
	 * inverted (wrong) behaviour — cod/bacs/cheque enabled by default —
	 * which this fix corrects.
	 */
	public function test_native_methods_default_to_empty_when_unset() {
		// with_options() never sets idea89_checkout_native_methods, so
		// get_option() falls through to Idea89_Checkout_Rest::DEFAULT_NATIVE_METHODS
		// — exactly the "option row never created" case a fresh install hits.
		$this->with_options( array() );
		$this->wc->set_payment_gateways_manager(
			new Idea89_Fake_Payment_Gateways(
				array(
					'cod'    => new Idea89_Fake_Gateway( 'cod', 'Cash on delivery' ),
					'bacs'   => new Idea89_Fake_Gateway( 'bacs', 'Direct bank transfer' ),
					'cheque' => new Idea89_Fake_Gateway( 'cheque', 'Cheque' ),
					'paypal' => new Idea89_Fake_Gateway( 'paypal', 'PayPal' ),
				),
				array(
					'cod'    => new Idea89_Fake_Gateway( 'cod', 'Cash on delivery' ),
					'bacs'   => new Idea89_Fake_Gateway( 'bacs', 'Direct bank transfer' ),
					'cheque' => new Idea89_Fake_Gateway( 'cheque', 'Cheque' ),
					'paypal' => new Idea89_Fake_Gateway( 'paypal', 'PayPal' ),
				)
			)
		);

		$this->assertSame( array(), Idea89_Checkout_Rest::DEFAULT_NATIVE_METHODS );

		$response = $this->rest()->handle_context();

		$this->assertSame( array(), $response->get_data()['methods'] );
	}

	/**
	 * C2 companion: an empty allowlist must not merely report zero
	 * methods from context() — place() must actually refuse every
	 * gateway, silently falling back to Tier 1 exactly as Magento's
	 * Facade::place() does on an empty allowlist. Proves the guarantee
	 * end to end, not just at the read side.
	 */
	public function test_place_refuses_every_gateway_when_the_allowlist_is_empty_by_default() {
		$this->with_options( array() );
		$this->wc->set_payment_gateways_manager(
			new Idea89_Fake_Payment_Gateways(
				array(
					'cod'  => new Idea89_Fake_Gateway( 'cod', 'Cash on delivery' ),
					'bacs' => new Idea89_Fake_Gateway( 'bacs', 'Direct bank transfer' ),
				),
				array(
					'cod'  => new Idea89_Fake_Gateway( 'cod', 'Cash on delivery' ),
					'bacs' => new Idea89_Fake_Gateway( 'bacs', 'Direct bank transfer' ),
				)
			)
		);

		$response = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'cod' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'checkout_error', $response->get_data()['error'] );
	}

	/* ---------------- address() ---------------- */

	public function test_address_rejects_a_missing_postcode() {
		$this->with_options( array() );

		$response = $this->rest()->handle_address(
			$this->request_with_body(
				array(
					'street'     => array( '1 Test St' ),
					'country_id' => 'GB',
					'postcode'   => '',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'checkout_error', $response->get_data()['error'] );
	}

	public function test_address_rejects_an_invalid_email() {
		$this->with_options( array() );

		$response = $this->rest()->handle_address(
			$this->request_with_body(
				array(
					'street'     => array( '1 Test St' ),
					'country_id' => 'GB',
					'postcode'   => 'SW1A 1AA',
					'email'      => 'not-an-email',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_address_applies_billing_and_shipping_and_returns_shipping_methods() {
		$this->with_options( array() );
		$this->cart->needs_shipping = true;
		$this->cart->subtotal       = 10.0;
		$this->cart->total          = 15.0;

		$rate = new Idea89_Fake_Shipping_Rate( 'flat_rate', '5', 'Flat rate', '5.00' );
		$this->wc->shipping()->result = array(
			0 => array( 'rates' => array( $rate ) ),
		);

		$response = $this->rest()->handle_address(
			$this->request_with_body(
				array(
					'email'      => 'shopper@example.test',
					'firstname'  => 'Ada',
					'lastname'   => 'Lovelace',
					'street'     => array( '1 Test St' ),
					'city'       => 'London',
					'postcode'   => 'SW1A 1AA',
					'country_id' => 'gb',
					'telephone'  => '0123456789',
				)
			)
		);
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'GB', $this->customer->data['billing_country'] );
		$this->assertSame( 'GB', $this->customer->data['shipping_country'] );
		$this->assertSame( 'Ada', $this->customer->data['billing_first_name'] );
		$this->assertSame( 1, $this->customer->saved );
		$this->assertSame(
			array(
				array(
					'carrier' => 'flat_rate',
					'method'  => '5',
					'title'   => 'Flat rate',
					'amount'  => '5.00',
				),
			),
			$data['shipping_methods']
		);
		$this->assertSame( '15.00', $data['totals']['grand_total'] );
		$this->assertSame( 1, $this->cart->totals_calculated );
	}

	public function test_address_skips_shipping_calculation_for_a_virtual_cart() {
		$this->with_options( array() );
		$this->cart->needs_shipping = false;

		$response = $this->rest()->handle_address(
			$this->request_with_body(
				array(
					'street'     => array( '1 Test St' ),
					'city'       => 'London',
					'postcode'   => 'SW1A 1AA',
					'country_id' => 'GB',
				)
			)
		);
		$data = $response->get_data();

		$this->assertSame( array(), $data['shipping_methods'] );
		$this->assertFalse( $data['needs_shipping'] );
	}

	/* ---------------- method() ---------------- */

	public function test_method_rejects_a_blank_carrier_or_method() {
		$this->with_options( array() );

		$response = $this->rest()->handle_method( $this->request_with_body( array( 'carrier' => '', 'method' => '5' ) ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_method_rejects_when_the_cart_does_not_need_shipping() {
		$this->with_options( array() );
		$this->cart->needs_shipping = false;

		$response = $this->rest()->handle_method( $this->request_with_body( array( 'carrier' => 'flat_rate', 'method' => '5' ) ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_method_sets_the_chosen_shipping_method_and_recalculates() {
		$this->with_options( array() );
		$this->cart->needs_shipping = true;
		$this->cart->total          = 20.0;

		$response = $this->rest()->handle_method( $this->request_with_body( array( 'carrier' => 'flat_rate', 'method' => '5' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 0 => 'flat_rate:5' ), $this->wc->session->get( 'chosen_shipping_methods' ) );
		$this->assertSame( 1, $this->cart->totals_calculated );
		$this->assertSame( '20.00', $response->get_data()['totals']['grand_total'] );
	}

	/* ---------------- place(): the gateway allowlist ---------------- */

	public function test_place_rejects_a_gateway_outside_the_allowlist_and_creates_no_order() {
		$this->with_options( array( 'idea89_checkout_native_methods' => array( 'cod', 'bacs' ) ) );
		$this->cart->empty = false;
		Functions\when( 'is_wp_error' )->justReturn( false );

		$response = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'paypal' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'checkout_error', $response->get_data()['error'] );
		$this->assertSame( array(), $this->wc->checkout()->create_order_calls );
	}

	public function test_place_rejects_an_empty_cart() {
		$this->with_options( array( 'idea89_checkout_native_methods' => array( 'cod' ) ) );
		$this->cart->empty = true;

		$response = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'cod' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), $this->wc->checkout()->create_order_calls );
	}

	public function test_place_rejects_a_guest_with_no_billing_email() {
		$this->with_options( array( 'idea89_checkout_native_methods' => array( 'cod' ) ) );
		$this->cart->empty = false;

		$response = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'cod' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), $this->wc->checkout()->create_order_calls );
	}

	public function test_place_creates_the_order_and_runs_the_allowlisted_gateway() {
		$this->with_options( array( 'idea89_checkout_native_methods' => array( 'cod', 'bacs' ) ) );
		$this->cart->empty          = false;
		$this->customer->data['billing_email']      = 'shopper@example.test';
		$this->customer->data['billing_first_name'] = 'Ada';
		$this->wc->checkout()->order_id_to_return = 777;

		$response = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'cod' ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2001', $data['order_id'] );
		$this->assertSame( '59.98', $data['total'] );
		$this->assertSame( 'GBP', $data['currency'] );
		$this->assertSame( 'processing', $data['status'] );

		$calls = $this->wc->checkout()->create_order_calls;
		$this->assertCount( 1, $calls );
		$this->assertSame( 'cod', $calls[0]['payment_method'] );
		$this->assertSame( 'Ada', $calls[0]['billing_first_name'] );

		$gateway = $this->wc->payment_gateways()->get_available_payment_gateways()['cod'];
		$this->assertSame( array( 777 ), $gateway->process_payment_calls );
	}

	public function test_place_returns_an_error_when_create_order_fails() {
		$this->with_options( array( 'idea89_checkout_native_methods' => array( 'cod' ) ) );
		$this->cart->empty                     = false;
		$this->customer->data['billing_email'] = 'shopper@example.test';
		$this->wc->checkout()->error_to_return = new WP_Error( 'checkout-error', 'Boom' );

		$response = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'cod' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$gateway = $this->wc->payment_gateways()->get_available_payment_gateways()['cod'];
		$this->assertSame( array(), $gateway->process_payment_calls );
	}

	public function test_place_does_not_claim_success_when_the_gateway_reports_failure() {
		$this->with_options( array( 'idea89_checkout_native_methods' => array( 'cod' ) ) );
		$this->cart->empty                     = false;
		$this->customer->data['billing_email'] = 'shopper@example.test';
		$gateway                 = $this->wc->payment_gateways()->get_available_payment_gateways()['cod'];
		$gateway->payment_result = array( 'result' => 'failure' );

		$response = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'cod' ) ) );
		$data     = $response->get_data();

		$this->assertSame( 402, $response->get_status() );
		$this->assertSame( 'payment_failed', $data['error'] );
		$this->assertNotEmpty( $data['message'] );
		$this->assertArrayNotHasKey( 'order_id', $data );
	}

	public function test_place_does_not_claim_success_when_the_gateway_returns_nothing() {
		$this->with_options( array( 'idea89_checkout_native_methods' => array( 'cod' ) ) );
		$this->cart->empty                     = false;
		$this->customer->data['billing_email'] = 'shopper@example.test';
		$gateway                 = $this->wc->payment_gateways()->get_available_payment_gateways()['cod'];
		$gateway->payment_result = false;

		$response = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'cod' ) ) );

		$this->assertSame( 402, $response->get_status() );
		$this->assertArrayNotHasKey( 'order_id', $response->get_data() );
	}

	public function test_place_does_not_claim_success_when_the_order_ends_up_failed() {
		$this->with_options( array( 'idea89_checkout_native_methods' => array( 'cod' ) ) );
		$this->cart->empty                     = false;
		$this->customer->data['billing_email'] = 'shopper@example.test';
		Functions\when( 'wc_get_order' )->justReturn( new Idea89_Fake_WC_Order( array( 'status' => 'failed' ) ) );

		$response = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'cod' ) ) );

		$this->assertSame( 402, $response->get_status() );
		$this->assertArrayNotHasKey( 'order_id', $response->get_data() );
	}

	public function test_place_reports_the_post_payment_status() {
		$this->with_options( array( 'idea89_checkout_native_methods' => array( 'bacs' ) ) );
		$this->cart->empty                     = false;
		$this->customer->data['billing_email'] = 'shopper@example.test';
		Functions\when( 'wc_get_order' )->justReturn( new Idea89_Fake_WC_Order( array( 'status' => 'on-hold' ) ) );

		$response = $this->rest()->handle_place( $this->request_with_body( array( 'payment_method' => 'bacs' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'on-hold', $response->get_data()['status'] );
	}
}
