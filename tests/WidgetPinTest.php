<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/frontend/class-idea89-widget-pin.php';

/**
 * Load with Subresource Integrity: the pin is only ever an exact pin on the
 * configured API host, it is cached for the time the API allows, and any
 * failure means no pin, so the widget falls back to the rolling script.
 */
class WidgetPinTest extends TestCase {

	const HASH      = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
	const PINNED    = 'https://api.idea89.com/widget/v1/p/' . self::HASH . '.js';
	const INTEGRITY = 'sha384-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

	/** @var array<string, array{value: mixed, ttl: int}> */
	private $transients = array();

	/** @var string[] */
	private $requests = array();

	/** @var mixed What wp_remote_get answers: array{code, body} or a WP_Error. */
	private $answer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transients = array();
		$this->requests   = array();
		$this->answer     = array( 'code' => 500, 'body' => '' );

		$this->with_options( array( 'idea89_api_key' => 'pk_live_1', 'idea89_widget_integrity' => true ) );
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return isset( $this->transients[ $key ] ) ? $this->transients[ $key ]['value'] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) {
				$this->transients[ $key ] = array( 'value' => $value, 'ttl' => $ttl );
				return true;
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) {
				$this->requests[] = $url;
				return $this->answer;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $r ) {
				return $r['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $r ) {
				return $r['body'];
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

	private function answer_with( array $json, $code = 200 ) {
		$this->answer = array( 'code' => $code, 'body' => json_encode( $json ) );
	}

	private function pin() {
		return new Idea89_Widget_Pin( new Idea89_Config() );
	}

	private function only_ttl() {
		$this->assertCount( 1, $this->transients );
		return array_values( $this->transients )[0]['ttl'];
	}

	public function test_a_valid_pin_is_returned_and_cached_for_the_max_age_the_api_sends() {
		$this->answer_with( array( 'src' => self::PINNED, 'integrity' => self::INTEGRITY, 'max_age' => 300 ) );

		$this->assertSame( array( 'src' => self::PINNED, 'integrity' => self::INTEGRITY ), $this->pin()->get() );
		$this->assertSame( array( 'https://api.idea89.com/widget/v1/pk_live_1/pin.json' ), $this->requests );
		$this->assertSame( 300, $this->only_ttl() );
	}

	public function test_max_age_is_clamped_to_between_one_minute_and_one_hour() {
		$this->answer_with( array( 'src' => self::PINNED, 'integrity' => self::INTEGRITY, 'max_age' => 86400 ) );
		$this->pin()->get();
		$this->assertSame( 3600, $this->only_ttl() );

		$this->transients = array();
		$this->answer_with( array( 'src' => self::PINNED, 'integrity' => self::INTEGRITY, 'max_age' => 1 ) );
		$this->pin()->get();
		$this->assertSame( 60, $this->only_ttl() );
	}

	public function test_a_second_request_is_served_from_the_cache_without_calling_the_api() {
		$this->answer_with( array( 'src' => self::PINNED, 'integrity' => self::INTEGRITY, 'max_age' => 300 ) );
		$this->pin()->get();
		$this->assertSame( array( 'src' => self::PINNED, 'integrity' => self::INTEGRITY ), $this->pin()->get() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_one_request_asks_only_once() {
		$this->answer_with( array( 'src' => self::PINNED, 'integrity' => self::INTEGRITY, 'max_age' => 300 ) );
		$pin = $this->pin();
		$pin->get();
		$this->transients = array();
		$pin->get();
		$this->assertCount( 1, $this->requests );
	}

	public function test_turned_off_means_no_pin_and_no_call() {
		$this->with_options( array( 'idea89_api_key' => 'pk_live_1', 'idea89_widget_integrity' => false ) );
		$this->answer_with( array( 'src' => self::PINNED, 'integrity' => self::INTEGRITY ) );

		$this->assertNull( $this->pin()->get() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_an_unreachable_api_gives_no_pin_and_is_remembered_for_a_minute() {
		$this->answer = new WP_Error( 'http_request_failed', 'timed out' );

		$this->assertNull( $this->pin()->get() );
		$this->assertSame( 60, $this->only_ttl() );

		$this->assertNull( $this->pin()->get() );
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_non_200_answer_gives_no_pin() {
		$this->answer_with( array( 'error' => 'pin_unavailable' ), 503 );
		$this->assertNull( $this->pin()->get() );
	}

	public function test_a_body_that_is_not_json_gives_no_pin() {
		$this->answer = array( 'code' => 200, 'body' => '<html>' );
		$this->assertNull( $this->pin()->get() );
	}

	#[DataProvider( 'not_a_pin' )]
	public function test_an_answer_that_is_not_exactly_a_pin_on_the_api_host_is_never_rendered( array $answer ) {
		$this->answer_with( $answer + array( 'max_age' => 300 ) );
		$this->assertNull( $this->pin()->get() );
	}

	public static function not_a_pin() {
		$host = 'https://api.idea89.com';
		$src  = self::PINNED;
		return array(
			'another host'       => array( array( 'src' => 'https://evil.example/widget/v1/p/' . self::HASH . '.js', 'integrity' => self::INTEGRITY ) ),
			'look-alike host'    => array( array( 'src' => $host . '.evil.example/widget/v1/p/' . self::HASH . '.js', 'integrity' => self::INTEGRITY ) ),
			'not a pin path'     => array( array( 'src' => $host . '/widget/v1/pk_live_1.js', 'integrity' => self::INTEGRITY ) ),
			'short hash'         => array( array( 'src' => $host . '/widget/v1/p/abc.js', 'integrity' => self::INTEGRITY ) ),
			'path tricks'        => array( array( 'src' => $src . '?x=<script>', 'integrity' => self::INTEGRITY ) ),
			'sha256 integrity'   => array( array( 'src' => $src, 'integrity' => 'sha256-' . str_repeat( 'A', 43 ) . '=' ) ),
			'quote in integrity' => array( array( 'src' => $src, 'integrity' => 'sha384-"' . str_repeat( 'A', 63 ) ) ),
			'missing integrity'  => array( array( 'src' => $src ) ),
		);
	}
}
