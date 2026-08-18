<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/admin/class-idea89-admin-settings.php';

class AdminSettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		// Not stubbed in the original brief: sanitize_api_url() calls
		// wp_parse_url() to check the scheme before esc_url_raw() runs, and
		// without WordPress loaded that function does not exist.
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_api_url_keeps_https_and_drops_the_trailing_slash() {
		$this->assertSame(
			'https://api.idea89.com',
			Idea89_Admin_Settings::sanitize_api_url( 'https://api.idea89.com/' )
		);
	}

	public function test_api_url_rejects_non_http_schemes() {
		// A javascript: or file: URL here would be fetched server-side.
		$this->assertSame( '', Idea89_Admin_Settings::sanitize_api_url( 'javascript:alert(1)' ) );
		$this->assertSame( '', Idea89_Admin_Settings::sanitize_api_url( 'file:///etc/passwd' ) );
	}

	public function test_empty_api_url_is_allowed_and_falls_back_at_read_time() {
		$this->assertSame( '', Idea89_Admin_Settings::sanitize_api_url( '' ) );
	}

	public function test_brand_color_accepts_six_digit_hex_only() {
		$this->assertSame( '#2563eb', Idea89_Admin_Settings::sanitize_brand_color( '#2563eb' ) );
		$this->assertSame( '#2563EB', Idea89_Admin_Settings::sanitize_brand_color( ' #2563EB ' ) );
		$this->assertSame( '', Idea89_Admin_Settings::sanitize_brand_color( 'red' ) );
		$this->assertSame( '', Idea89_Admin_Settings::sanitize_brand_color( '#fff' ) );
		$this->assertSame( '', Idea89_Admin_Settings::sanitize_brand_color( '"><script>' ) );
	}

	public function test_position_falls_back_to_the_default() {
		$this->assertSame( 'bottom-left', Idea89_Admin_Settings::sanitize_position( 'bottom-left' ) );
		$this->assertSame( 'bottom-right', Idea89_Admin_Settings::sanitize_position( 'nonsense' ) );
	}
}
