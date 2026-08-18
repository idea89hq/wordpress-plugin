<?php

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-plugin.php';

class PluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_instance_is_a_singleton() {
		$this->assertSame( Idea89_Plugin::instance(), Idea89_Plugin::instance() );
	}

	public function test_requirements_met_when_woocommerce_version_is_high_enough() {
		$this->assertTrue( Idea89_Plugin::requirements_met( '11.0.1' ) );
		$this->assertTrue( Idea89_Plugin::requirements_met( '8.0.0' ) );
	}

	public function test_requirements_not_met_below_the_floor() {
		$this->assertFalse( Idea89_Plugin::requirements_met( '7.9.9' ) );
		$this->assertFalse( Idea89_Plugin::requirements_met( null ) );
		$this->assertFalse( Idea89_Plugin::requirements_met( '' ) );
	}

	public function test_requirements_notice_shows_on_actionable_screens() {
		$this->assertTrue( Idea89_Plugin::should_show_requirements_notice( 'dashboard' ) );
		$this->assertTrue( Idea89_Plugin::should_show_requirements_notice( 'plugins' ) );
		$this->assertTrue( Idea89_Plugin::should_show_requirements_notice( 'plugins-network' ) );
	}

	/**
	 * wordpress.org guideline 11: no sitewide nagging. The notice must stay off
	 * screens the merchant cannot act from, however many admin pages exist.
	 */
	public function test_requirements_notice_is_not_sitewide() {
		$elsewhere = array( 'edit-post', 'post', 'upload', 'options-general', 'users', 'themes', 'edit-page', 'woocommerce_page_wc-settings', '' );

		foreach ( $elsewhere as $screen_id ) {
			$this->assertFalse(
				Idea89_Plugin::should_show_requirements_notice( $screen_id ),
				"Notice must not render on screen '{$screen_id}'"
			);
		}
	}

	/**
	 * wordpress.org rejects a plugin whose Plugin URI and Author URI match:
	 * one describes the plugin, the other describes who wrote it. Both were
	 * https://idea89.com on 1.0.1 and the submission was refused for it.
	 */
	public function test_plugin_and_author_uris_differ() {
		$header = file_get_contents( IDEA89_PLUGIN_DIR . 'idea89-assistant.php' );

		preg_match( '/^\s*\*\s*Plugin URI:\s*(\S+)/mi', $header, $plugin_uri );
		preg_match( '/^\s*\*\s*Author URI:\s*(\S+)/mi', $header, $author_uri );

		$this->assertNotEmpty( $plugin_uri[1] ?? '', 'Plugin URI header is missing.' );
		$this->assertNotEmpty( $author_uri[1] ?? '', 'Author URI header is missing.' );
		$this->assertNotSame(
			$plugin_uri[1],
			$author_uri[1],
			'Plugin URI and Author URI must be different pages.'
		);
	}

	/**
	 * Guideline 11 also requires sitewide notices be dismissible. Ours is
	 * scoped, but it carries is-dismissible so it can always be closed.
	 */
	public function test_requirements_notice_markup_is_dismissible() {
		$source = file_get_contents( IDEA89_PLUGIN_DIR . 'includes/class-idea89-plugin.php' );

		$this->assertStringContainsString(
			'notice notice-error is-dismissible',
			$source,
			'The requirements notice must be dismissible.'
		);
	}
}
