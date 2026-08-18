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
}
