<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';

class ConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_api_url_falls_back_to_production() {
		Functions\when( 'get_option' )->justReturn( '' );
		$config = new Idea89_Config();
		$this->assertSame( 'https://api.idea89.com', $config->get_api_url() );
	}

	public function test_api_url_trailing_slash_is_stripped() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				return 'idea89_api_url' === $name ? 'https://staging.idea89.com/' : $default;
			}
		);
		$config = new Idea89_Config();
		$this->assertSame( 'https://staging.idea89.com', $config->get_api_url() );
	}

	public function test_is_configured_requires_a_key() {
		Functions\when( 'get_option' )->justReturn( '' );
		$config = new Idea89_Config();
		$this->assertFalse( $config->is_configured() );
	}

	public function test_is_configured_true_with_a_key() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				return 'idea89_api_key' === $name ? 'sk_live_abc' : $default;
			}
		);
		$config = new Idea89_Config();
		$this->assertTrue( $config->is_configured() );
	}

	public function test_widget_position_defaults_and_rejects_junk() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				return 'idea89_widget_position' === $name ? 'upside-down' : $default;
			}
		);
		$config = new Idea89_Config();
		$this->assertSame( 'bottom-right', $config->get_widget_position() );
	}

	public function test_brand_color_rejects_non_hex() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				return 'idea89_brand_color' === $name ? 'javascript:alert(1)' : $default;
			}
		);
		$config = new Idea89_Config();
		$this->assertSame( '', $config->get_brand_color() );
	}

	public function test_brand_color_accepts_hex() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				return 'idea89_brand_color' === $name ? '#2563eb' : $default;
			}
		);
		$config = new Idea89_Config();
		$this->assertSame( '#2563eb', $config->get_brand_color() );
	}
}
