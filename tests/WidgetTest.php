<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-checkout-config.php';
// render() only reads Idea89_Mini_Checkout::QUERY_VAR, a class constant, but
// that reference still has to resolve to a loaded class.
require_once IDEA89_PLUGIN_DIR . 'includes/checkout/class-idea89-checkout-bridge.php';
require_once IDEA89_PLUGIN_DIR . 'includes/checkout/class-idea89-mini-checkout.php';
require_once IDEA89_PLUGIN_DIR . 'includes/frontend/class-idea89-widget.php';

class WidgetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Not stubbed in the original brief: should_render() checks is_admin()
		// first (the widget must never render in wp-admin), and without
		// WordPress loaded that function does not exist.
		Functions\when( 'is_admin' )->justReturn( false );
		// render() now also builds window.__IDEA89_CHECKOUT via
		// Idea89_Checkout_Config, which calls these three.
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://shop.example.test/checkout/' );
		// Root install by default; individual tests override this for the
		// subfolder case (C4: miniCheckoutPath must carry the site's base path).
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'https://shop.example.test' . $path;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function with_options( array $options ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) use ( $options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
	}

	public function test_loader_url_is_built_from_the_api_url_and_key() {
		$this->assertSame(
			'https://api.idea89.com/widget/v1/sk_live_abc.js',
			Idea89_Widget::build_loader_url( 'https://api.idea89.com', 'sk_live_abc' )
		);
	}

	public function test_loader_url_tolerates_a_trailing_slash() {
		$this->assertSame(
			'https://api.idea89.com/widget/v1/sk_live_abc.js',
			Idea89_Widget::build_loader_url( 'https://api.idea89.com/', 'sk_live_abc' )
		);
	}

	public function test_does_not_render_when_disabled() {
		$this->with_options( array( 'idea89_enabled' => false, 'idea89_api_key' => 'sk_live_abc' ) );
		$widget = new Idea89_Widget( new Idea89_Config() );
		$this->assertFalse( $widget->should_render() );
	}

	public function test_does_not_render_without_a_key() {
		$this->with_options( array( 'idea89_enabled' => true, 'idea89_api_key' => '' ) );
		$widget = new Idea89_Widget( new Idea89_Config() );
		$this->assertFalse( $widget->should_render() );
	}

	public function test_renders_when_enabled_and_configured() {
		$this->with_options( array( 'idea89_enabled' => true, 'idea89_api_key' => 'sk_live_abc' ) );
		$widget = new Idea89_Widget( new Idea89_Config() );
		$this->assertTrue( $widget->should_render() );
	}

	public function test_does_not_render_in_wp_admin() {
		// Otherwise enabled and configured — is_admin() alone must block it.
		$this->with_options( array( 'idea89_enabled' => true, 'idea89_api_key' => 'sk_live_abc' ) );
		Functions\when( 'is_admin' )->justReturn( true );
		$widget = new Idea89_Widget( new Idea89_Config() );
		$this->assertFalse( $widget->should_render() );
	}

	public function test_markup_declares_the_platform_and_store_api_config() {
		$this->with_options(
			array(
				'idea89_enabled'         => true,
				'idea89_api_key'         => 'sk_live_abc',
				'idea89_brand_color'     => '#2563eb',
				'idea89_widget_position' => 'bottom-left',
			)
		);
		Functions\when( 'get_rest_url' )->justReturn( 'https://shop.example.test/wp-json/wc/store/v1' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://shop.example.test/cart/' );
		Functions\when( 'esc_js' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );

		$widget = new Idea89_Widget( new Idea89_Config() );

		ob_start();
		$widget->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( "window.__IDEA89_PLATFORM = 'woocommerce'", $html );
		$this->assertStringContainsString( 'wp-json/wc/store/v1', $html );
		$this->assertStringContainsString( 'nonce123', $html );
		$this->assertStringContainsString( 'https://api.idea89.com/widget/v1/sk_live_abc.js', $html );
		$this->assertStringContainsString( 'data-position="bottom-left"', $html );
		$this->assertStringContainsString( 'data-color="#2563eb"', $html );

		// The config block must come first, or the loader boots before it can
		// read window.__IDEA89_WC.
		$this->assertLessThan(
			strpos( $html, 'widget/v1/sk_live_abc.js' ),
			strpos( $html, '__IDEA89_WC' )
		);
	}

	public function test_checkout_global_is_published_alongside_the_wc_global() {
		$this->with_options( array( 'idea89_enabled' => true, 'idea89_api_key' => 'sk_live_abc' ) );
		Functions\when( 'get_rest_url' )->justReturn( 'https://shop.example.test/wp-json/wc/store/v1' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://shop.example.test/cart/' );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://shop.example.test/checkout/' );
		Functions\when( 'esc_js' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );

		$widget = new Idea89_Widget( new Idea89_Config() );

		ob_start();
		$widget->render();
		$html = ob_get_clean();

		// __IDEA89_WC must stay byte-identical: _addToCartWoo depends on it
		// and is already shipped.
		$this->assertStringContainsString(
			"window.__IDEA89_WC = {\n\tstoreApi: 'https://shop.example.test/wp-json/wc/store/v1',\n\tnonce: 'nonce123',\n\tcartUrl: 'https://shop.example.test/cart/'\n};",
			$html
		);

		$this->assertStringContainsString( 'window.__IDEA89_CHECKOUT = ', $html );

		preg_match( '/window\.__IDEA89_CHECKOUT = (\{.*?\});/', $html, $m );
		$this->assertNotEmpty( $m, 'Could not find the __IDEA89_CHECKOUT assignment in the rendered markup.' );
		$cfg = json_decode( $m[1], true );

		$this->assertSame( 'woocommerce', $cfg['platform'] );
		$this->assertSame( 'express', $cfg['checkoutMode'], 'Express is the shipped default.' );
		$this->assertSame( '/cart/', $cfg['cartPath'] );
		$this->assertSame( '/checkout/', $cfg['checkoutPath'] );
		$this->assertSame( 'nonce123', $cfg['formKey'], 'No Woo equivalent of a form key, so this carries the Store API nonce.' );
		$this->assertSame( '/?idea89-checkout=1', $cfg['miniCheckoutPath'] );
		// Same key/type ('checkoutBar', boolean) the Magento 2 module
		// publishes (Block/Widget::getClientBootstrapJs) — the widget reads
		// one platform-neutral field. Unset in with_options() above, so this
		// also proves the default (matching Magento's) is enabled.
		$this->assertTrue( $cfg['checkoutBar'] );

		// __IDEA89_WC must still come before the loader script.
		$this->assertLessThan(
			strpos( $html, 'widget/v1/sk_live_abc.js' ),
			strpos( $html, '__IDEA89_CHECKOUT' )
		);
	}

	public function test_checkout_bar_flag_reflects_the_merchant_setting_when_disabled() {
		$this->with_options(
			array(
				'idea89_enabled'               => true,
				'idea89_api_key'               => 'sk_live_abc',
				'idea89_checkout_bar_enabled'  => false,
			)
		);
		Functions\when( 'get_rest_url' )->justReturn( 'https://shop.example.test/wp-json/wc/store/v1' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://shop.example.test/cart/' );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://shop.example.test/checkout/' );
		Functions\when( 'esc_js' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );

		$widget = new Idea89_Widget( new Idea89_Config() );

		ob_start();
		$widget->render();
		$html = ob_get_clean();

		// __IDEA89_WC must stay byte-identical regardless of this setting —
		// _addToCartWoo depends on it and is already shipped.
		$this->assertStringContainsString(
			"window.__IDEA89_WC = {\n\tstoreApi: 'https://shop.example.test/wp-json/wc/store/v1',\n\tnonce: 'nonce123',\n\tcartUrl: 'https://shop.example.test/cart/'\n};",
			$html
		);

		preg_match( '/window\.__IDEA89_CHECKOUT = (\{.*?\});/', $html, $m );
		$this->assertNotEmpty( $m, 'Could not find the __IDEA89_CHECKOUT assignment in the rendered markup.' );
		$cfg = json_decode( $m[1], true );
		$this->assertFalse( $cfg['checkoutBar'] );
	}

	/**
	 * C4: a WordPress install living in a subfolder (https://host/shop/) is
	 * a first-class account type on this product ((store_domain, site_path)).
	 * Before this fix, miniCheckoutPath was the bare '/?idea89-checkout=1'
	 * with no site path at all — the widget's ckUrl() resolves that against
	 * window.location.origin straight to the site ROOT, missing the /shop/
	 * segment WordPress needs to route the request. cartPath/checkoutPath
	 * were already subfolder-safe (derived through wp_parse_url()); this
	 * proves miniCheckoutPath now is too.
	 */
	public function test_mini_checkout_path_carries_the_site_base_path_on_a_subfolder_install() {
		$this->with_options( array( 'idea89_enabled' => true, 'idea89_api_key' => 'sk_live_abc' ) );
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'https://shop.example.test/shop' . $path;
			}
		);
		Functions\when( 'get_rest_url' )->justReturn( 'https://shop.example.test/shop/wp-json/wc/store/v1' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://shop.example.test/shop/cart/' );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://shop.example.test/shop/checkout/' );
		Functions\when( 'esc_js' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );

		$widget = new Idea89_Widget( new Idea89_Config() );

		ob_start();
		$widget->render();
		$html = ob_get_clean();

		preg_match( '/window\.__IDEA89_CHECKOUT = (\{.*?\});/', $html, $m );
		$this->assertNotEmpty( $m, 'Could not find the __IDEA89_CHECKOUT assignment in the rendered markup.' );
		$cfg = json_decode( $m[1], true );

		$this->assertSame( '/shop/?idea89-checkout=1', $cfg['miniCheckoutPath'] );
	}

	public function test_brand_color_attribute_is_omitted_when_unset() {
		$this->with_options( array( 'idea89_enabled' => true, 'idea89_api_key' => 'sk_live_abc' ) );
		Functions\when( 'get_rest_url' )->justReturn( 'https://shop.example.test/wp-json/wc/store/v1' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://shop.example.test/cart/' );
		Functions\when( 'esc_js' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );

		$widget = new Idea89_Widget( new Idea89_Config() );

		ob_start();
		$widget->render();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'data-color', $html );
	}
}
