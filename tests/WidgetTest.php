<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/frontend/class-idea89-widget.php';

class WidgetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Not stubbed in the original brief: should_render() checks is_admin()
		// first (the widget must never render in wp-admin), and without
		// WordPress loaded that function does not exist.
		Functions\when( 'is_admin' )->justReturn( false );
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
