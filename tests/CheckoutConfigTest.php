<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-checkout-config.php';

class CheckoutConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $options
	 */
	private function with_options( array $options ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) use ( $options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
	}

	/* ---------------- Mode ladder ---------------- */

	public function test_mode_defaults_to_express_when_unset() {
		// Tier 1 is the shipped default. This fallback covers the window
		// before the merchant has ever saved the settings screen.
		$this->with_options( array() );
		$config = new Idea89_Checkout_Config();
		$this->assertSame( Idea89_Checkout_Config::MODE_EXPRESS, $config->get_mode() );
		$this->assertTrue( $config->is_at_least_express() );
	}

	public function test_unknown_mode_collapses_to_the_shipped_default() {
		// A hand-edited row, or a value left behind by a downgrade, must
		// never leave the widget in an undefined branch.
		$this->with_options( array( 'idea89_checkout_mode' => 'teleport' ) );
		$config = new Idea89_Checkout_Config();
		$this->assertSame( Idea89_Checkout_Config::MODE_EXPRESS, $config->get_mode() );
	}

	public function test_off_is_still_reachable_when_explicitly_set() {
		// A merchant who wants the pre-1.2.0 behaviour must be able to get it.
		$this->with_options( array( 'idea89_checkout_mode' => 'off' ) );
		$config = new Idea89_Checkout_Config();
		$this->assertSame( Idea89_Checkout_Config::MODE_OFF, $config->get_mode() );
		$this->assertFalse( $config->is_at_least_express() );
	}

	public function test_embedded_is_exclusive_on_the_ladder() {
		$this->with_options( array( 'idea89_checkout_mode' => 'embedded' ) );
		$config = new Idea89_Checkout_Config();
		$this->assertTrue( $config->is_embedded() );
		$this->assertFalse( $config->is_express() );
		$this->assertFalse( $config->is_native() );
		$this->assertTrue( $config->is_at_least_express() );
	}

	public function test_native_is_exclusive_on_the_ladder() {
		$this->with_options( array( 'idea89_checkout_mode' => 'native' ) );
		$config = new Idea89_Checkout_Config();
		$this->assertTrue( $config->is_native() );
		$this->assertFalse( $config->is_express() );
		$this->assertFalse( $config->is_embedded() );
		$this->assertTrue( $config->is_at_least_express() );
	}

	/* ---------------- Pinned checkout bar ---------------- */

	public function test_checkout_bar_defaults_to_enabled_when_unset() {
		// Matches the Magento 2 module's XML_CHECKOUT_BAR default of 1
		// (etc/config.xml) — this fallback covers the window before the
		// merchant has ever saved the settings screen.
		$this->with_options( array() );
		$config = new Idea89_Checkout_Config();
		$this->assertTrue( $config->is_checkout_bar_enabled() );
	}

	public function test_checkout_bar_can_be_explicitly_disabled() {
		$this->with_options( array( 'idea89_checkout_bar_enabled' => false ) );
		$config = new Idea89_Checkout_Config();
		$this->assertFalse( $config->is_checkout_bar_enabled() );
	}

	public function test_checkout_bar_is_independent_of_the_ladder() {
		// Even on Off, the bar's click still routes through the widget's own
		// _goCheckout(), which falls back to a plain cart navigation, so it
		// is not gated on the mode.
		$this->with_options(
			array(
				'idea89_checkout_mode'         => 'off',
				'idea89_checkout_bar_enabled'  => true,
			)
		);
		$config = new Idea89_Checkout_Config();
		$this->assertSame( Idea89_Checkout_Config::MODE_OFF, $config->get_mode() );
		$this->assertTrue( $config->is_checkout_bar_enabled() );
	}

	/* ---------------- Paths ---------------- */

	public function test_cart_path_is_derived_from_wc_get_cart_url() {
		$this->with_options( array() );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://shop.example.test/cart/' );
		$config = new Idea89_Checkout_Config();
		$this->assertSame( '/cart/', $config->get_cart_path() );
	}

	public function test_checkout_path_is_derived_from_wc_get_checkout_url() {
		$this->with_options( array() );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://shop.example.test/checkout/' );
		$config = new Idea89_Checkout_Config();
		$this->assertSame( '/checkout/', $config->get_checkout_path() );
	}

	/**
	 * A Woo store can live in a subdirectory. The widget resolves the path
	 * against window.location.origin, so the FULL path (including the
	 * subdirectory) must survive, not just the trailing slug.
	 */
	public function test_paths_survive_a_subdirectory_install() {
		$this->with_options( array() );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://example.test/shop/cart/' );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://example.test/shop/checkout/' );
		$config = new Idea89_Checkout_Config();
		$this->assertSame( '/shop/cart/', $config->get_cart_path() );
		$this->assertSame( '/shop/checkout/', $config->get_checkout_path() );
	}

	public function test_paths_are_normalised_to_leading_and_trailing_slash() {
		$this->with_options( array() );
		// A theme or extension could return a URL with no trailing slash.
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://shop.example.test/cart' );
		$config = new Idea89_Checkout_Config();
		$this->assertSame( '/cart/', $config->get_cart_path() );
	}

	public function test_paths_fall_back_to_a_sane_default_when_unparsable() {
		// wc_get_cart_url() missing, empty, or an unparsable value must never
		// bubble a null or empty string out to the widget.
		$this->with_options( array() );
		Functions\when( 'wc_get_cart_url' )->justReturn( '' );
		Functions\when( 'wc_get_checkout_url' )->justReturn( '' );
		$config = new Idea89_Checkout_Config();
		$this->assertSame( '/cart/', $config->get_cart_path() );
		$this->assertSame( '/checkout/', $config->get_checkout_path() );
	}

	/* ---------------- Checkout display (shared with the dashboard) ---------------- */

	public function test_checkout_ui_defaults_to_full() {
		// The shipped presentation, and what every store behaved as before
		// this setting existed. Nothing changes on upgrade.
		$this->with_options( array() );
		$config = new Idea89_Checkout_Config();
		$this->assertSame( Idea89_Checkout_Config::UI_FULL, $config->get_checkout_ui() );
	}

	public function test_checkout_ui_reads_inline() {
		$this->with_options( array( 'idea89_checkout_ui' => 'inline' ) );
		$config = new Idea89_Checkout_Config();
		$this->assertSame( Idea89_Checkout_Config::UI_INLINE, $config->get_checkout_ui() );
	}

	public function test_an_unrecognised_checkout_ui_collapses_to_full() {
		// A stray value must not silently change what every shopper sees, and
		// must never be handed to a select that cannot render it.
		$this->with_options( array( 'idea89_checkout_ui' => 'popup' ) );
		$config = new Idea89_Checkout_Config();
		$this->assertSame( Idea89_Checkout_Config::UI_FULL, $config->get_checkout_ui() );
	}

	public function test_a_non_string_checkout_ui_collapses_to_full() {
		// get_option can return whatever is in the row, including a value put
		// there by another plugin or a bad import.
		$this->with_options( array( 'idea89_checkout_ui' => array( 'inline' ) ) );
		$config = new Idea89_Checkout_Config();
		$this->assertSame( Idea89_Checkout_Config::UI_FULL, $config->get_checkout_ui() );
	}
}
