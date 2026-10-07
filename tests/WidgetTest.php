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

	/**
	 * Records the three WordPress enqueue calls instead of executing them,
	 * so each test can assert what WordPress was asked to print without
	 * printing anything.
	 *
	 * @return array<string,mixed> Recorded calls, by reference.
	 */
	private function &record_enqueues() {
		$calls = array( 'register' => null, 'inline' => null, 'enqueue' => null );

		Functions\when( 'wp_register_script' )->alias(
			function ( $handle, $src, $deps, $ver, $args ) use ( &$calls ) {
				$calls['register'] = compact( 'handle', 'src', 'deps', 'ver', 'args' );
				return true;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $data, $position = 'after' ) use ( &$calls ) {
				$calls['inline'] = compact( 'handle', 'data', 'position' );
				return true;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) use ( &$calls ) {
				$calls['enqueue'] = $handle;
			}
		);

		return $calls;
	}

	private function stub_store_environment() {
		Functions\when( 'get_rest_url' )->justReturn( 'https://shop.example.test/wp-json/wc/store/v1' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://shop.example.test/cart/' );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://shop.example.test/checkout/' );
	}

	public function test_enqueue_registers_the_loader_and_prints_the_config_before_it() {
		$this->with_options(
			array(
				'idea89_enabled'         => true,
				'idea89_api_key'         => 'sk_live_abc',
				'idea89_brand_color'     => '#2563eb',
				'idea89_widget_position' => 'bottom-left',
			)
		);
		$this->stub_store_environment();
		$calls = &$this->record_enqueues();

		$widget = new Idea89_Widget( new Idea89_Config() );
		$widget->enqueue();

		// The loader goes through wp_register_script, not a hand-written
		// <script> tag: async in the footer, no ?ver= (the API versions it).
		$this->assertSame( Idea89_Widget::HANDLE, $calls['register']['handle'] );
		$this->assertSame( 'https://api.idea89.com/widget/v1/sk_live_abc.js', $calls['register']['src'] );
		$this->assertNull( $calls['register']['ver'] );
		$this->assertTrue( $calls['register']['args']['in_footer'] );
		$this->assertSame( 'async', $calls['register']['args']['strategy'] );
		$this->assertSame( Idea89_Widget::HANDLE, $calls['enqueue'] );

		// The config globals are attached as an inline script BEFORE the
		// loader, or the loader boots before it can read window.__IDEA89_WC.
		$this->assertSame( Idea89_Widget::HANDLE, $calls['inline']['handle'] );
		$this->assertSame( 'before', $calls['inline']['position'] );
		$this->assertStringContainsString( "window.__IDEA89_PLATFORM = 'woocommerce'", $calls['inline']['data'] );
		$wc = $this->wc_global( $calls['inline']['data'] );
		$this->assertSame( 'https://shop.example.test/wp-json/wc/store/v1', $wc['storeApi'] );
		$this->assertSame( 'nonce123', $wc['nonce'] );
	}

	/**
	 * The decoded window.__IDEA89_WC object from a config script.
	 *
	 * @param string $js config_js() output.
	 * @return array<string,mixed>
	 */
	private function wc_global( $js ) {
		preg_match( '/window\.__IDEA89_WC = (\{.*?\});/s', $js, $m );
		$this->assertNotEmpty( $m, 'Could not find the __IDEA89_WC assignment in the config script.' );
		$wc = json_decode( $m[1], true );
		$this->assertIsArray( $wc );
		return $wc;
	}

	public function test_enqueue_does_nothing_when_the_widget_should_not_render() {
		$this->with_options( array( 'idea89_enabled' => false, 'idea89_api_key' => 'sk_live_abc' ) );
		$this->stub_store_environment();
		$calls = &$this->record_enqueues();

		$widget = new Idea89_Widget( new Idea89_Config() );
		$widget->enqueue();

		$this->assertNull( $calls['register'] );
		$this->assertNull( $calls['inline'] );
		$this->assertNull( $calls['enqueue'] );
	}

	public function test_loader_attributes_are_added_only_to_our_own_script_tag() {
		$this->with_options(
			array(
				'idea89_enabled'         => true,
				'idea89_api_key'         => 'sk_live_abc',
				'idea89_brand_color'     => '#2563eb',
				'idea89_widget_position' => 'bottom-left',
			)
		);

		$widget = new Idea89_Widget( new Idea89_Config() );

		// Core hands wp_script_attributes the tag's attributes with the
		// handle-derived id; that id is how we recognise our own tag.
		$ours = $widget->loader_attributes(
			array( 'src' => 'https://api.idea89.com/widget/v1/sk_live_abc.js', 'id' => Idea89_Widget::HANDLE . '-js' )
		);
		$this->assertSame( 'sk_live_abc', $ours['data-key'] );
		$this->assertSame( 'bottom-left', $ours['data-position'] );
		// The brand colour lives in the IDEA89 dashboard only: an old saved
		// value must never reach the storefront again.
		$this->assertArrayNotHasKey( 'data-color', $ours );

		// Somebody else's script is passed through untouched.
		$theirs = array( 'src' => 'https://example.test/other.js', 'id' => 'other-js' );
		$this->assertSame( $theirs, $widget->loader_attributes( $theirs ) );

		// So is an inline tag with no id at all.
		$this->assertSame( array( 'src' => 'x' ), $widget->loader_attributes( array( 'src' => 'x' ) ) );
	}

	public function test_no_brand_color_attribute_without_a_saved_value_either() {
		$this->with_options( array( 'idea89_enabled' => true, 'idea89_api_key' => 'sk_live_abc' ) );

		$widget = new Idea89_Widget( new Idea89_Config() );
		$attrs  = $widget->loader_attributes( array( 'id' => Idea89_Widget::HANDLE . '-js' ) );

		$this->assertArrayNotHasKey( 'data-color', $attrs );
		$this->assertSame( 'sk_live_abc', $attrs['data-key'] );
	}

	public function test_checkout_global_is_published_alongside_the_wc_global() {
		$this->with_options( array( 'idea89_enabled' => true, 'idea89_api_key' => 'sk_live_abc' ) );
		$this->stub_store_environment();

		$widget = new Idea89_Widget( new Idea89_Config() );
		$js     = $widget->config_js();

		// __IDEA89_WC keeps the three keys _addToCartWoo and _cartSummaryWoo
		// read (already shipped), plus basePath for the /idea89/ routes.
		$this->assertSame(
			array(
				'storeApi' => 'https://shop.example.test/wp-json/wc/store/v1',
				'nonce'    => 'nonce123',
				'cartUrl'  => 'https://shop.example.test/cart/',
				'basePath' => '',
			),
			$this->wc_global( $js )
		);

		$this->assertStringContainsString( 'window.__IDEA89_CHECKOUT = ', $js );

		preg_match( '/window\.__IDEA89_CHECKOUT = (\{.*?\});/', $js, $m );
		$this->assertNotEmpty( $m, 'Could not find the __IDEA89_CHECKOUT assignment in the config script.' );
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

		// __IDEA89_WC must still come before __IDEA89_CHECKOUT.
		$this->assertLessThan( strpos( $js, '__IDEA89_CHECKOUT' ), strpos( $js, '__IDEA89_WC' ) );
	}

	/**
	 * A value containing "</script>" must not be able to terminate the
	 * inline config block: wp_json_encode is asked to hex-encode < > & ' "
	 * so the JSON stays inert inside a script element.
	 */
	public function test_checkout_config_json_cannot_break_out_of_the_script_element() {
		$this->with_options( array( 'idea89_enabled' => true, 'idea89_api_key' => 'sk_live_abc' ) );
		$this->stub_store_environment();
		Functions\when( 'wp_create_nonce' )->justReturn( '</script><script>alert(1)</script>' );

		$widget = new Idea89_Widget( new Idea89_Config() );
		$js     = $widget->config_js();

		preg_match( '/window\.__IDEA89_CHECKOUT = (\{.*?\});/s', $js, $m );
		$this->assertNotEmpty( $m );
		$this->assertStringNotContainsString( '</script>', $m[1] );
		$this->assertStringContainsString( '\\u003C', $m[1], 'The < must be hex-encoded, not left as a literal.' );
		// ...and it still decodes to the original value on the JS side.
		$this->assertSame( '</script><script>alert(1)</script>', json_decode( $m[1], true )['formKey'] );
	}

	/**
	 * Same guarantee for __IDEA89_WC. Its three values are URLs and a nonce,
	 * all built by core, but they land inside a <script> element, so they
	 * are encoded the way __IDEA89_CHECKOUT already is: wp_json_encode()
	 * with the JSON_HEX_* flags, not esc_js(), which is for attribute
	 * context (onclick="...") and turns & < > into HTML entities that a
	 * script element does not decode.
	 */
	public function test_wc_global_cannot_break_out_of_the_script_element() {
		$this->with_options( array( 'idea89_enabled' => true, 'idea89_api_key' => 'sk_live_abc' ) );
		$this->stub_store_environment();
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://shop.example.test/cart/?a=1&b=</script><script>alert(1)</script>' );

		$widget = new Idea89_Widget( new Idea89_Config() );
		$js     = $widget->config_js();

		preg_match( '/window\.__IDEA89_WC = (\{.*?\});/s', $js, $m );
		$this->assertNotEmpty( $m, 'Could not find the __IDEA89_WC assignment in the config script.' );
		$this->assertStringNotContainsString( '</script>', $m[1] );
		$this->assertStringContainsString( '\\u003C', $m[1], 'The < must be hex-encoded, not left as a literal.' );

		// ...and every value still reads back unchanged on the JS side: no
		// HTML entities, no lost query string.
		$wc = $this->wc_global( $js );
		$this->assertSame( 'https://shop.example.test/cart/?a=1&b=</script><script>alert(1)</script>', $wc['cartUrl'] );
		$this->assertSame( 'https://shop.example.test/wp-json/wc/store/v1', $wc['storeApi'] );
		$this->assertSame( 'nonce123', $wc['nonce'] );
	}

	public function test_checkout_bar_flag_reflects_the_merchant_setting_when_disabled() {
		$this->with_options(
			array(
				'idea89_enabled'               => true,
				'idea89_api_key'               => 'sk_live_abc',
				'idea89_checkout_bar_enabled'  => false,
			)
		);
		$this->stub_store_environment();

		$widget = new Idea89_Widget( new Idea89_Config() );
		$js     = $widget->config_js();

		// __IDEA89_WC keeps its keys regardless of this setting —
		// _addToCartWoo and _cartSummaryWoo read them and are already shipped.
		$this->assertSame(
			array(
				'storeApi' => 'https://shop.example.test/wp-json/wc/store/v1',
				'nonce'    => 'nonce123',
				'cartUrl'  => 'https://shop.example.test/cart/',
				'basePath' => '',
			),
			$this->wc_global( $js )
		);

		preg_match( '/window\.__IDEA89_CHECKOUT = (\{.*?\});/', $js, $m );
		$this->assertNotEmpty( $m, 'Could not find the __IDEA89_CHECKOUT assignment in the config script.' );
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

		$widget = new Idea89_Widget( new Idea89_Config() );
		$js     = $widget->config_js();

		preg_match( '/window\.__IDEA89_CHECKOUT = (\{.*?\});/', $js, $m );
		$this->assertNotEmpty( $m, 'Could not find the __IDEA89_CHECKOUT assignment in the config script.' );
		$cfg = json_decode( $m[1], true );

		$this->assertSame( '/shop/?idea89-checkout=1', $cfg['miniCheckoutPath'] );
		// The widget's customer/me call and the order card prefix /idea89/
		// routes with this, so they reach WordPress on a subfolder site.
		$this->assertSame( '/shop', $this->wc_global( $js )['basePath'] );
	}
}
