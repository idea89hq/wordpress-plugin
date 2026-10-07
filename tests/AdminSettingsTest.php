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

	public function test_position_falls_back_to_the_default() {
		$this->assertSame( 'bottom-left', Idea89_Admin_Settings::sanitize_position( 'bottom-left' ) );
		$this->assertSame( 'bottom-right', Idea89_Admin_Settings::sanitize_position( 'nonsense' ) );
	}

	/* ------------- Checkout display: shared with the IDEA89 dashboard ------------- */

	/**
	 * Stubs what sanitize_checkout_ui() reads, and returns a settings object
	 * whose one network seam is overridden.
	 *
	 * push_checkout_ui() is overridden rather than idea89_client() stubbed
	 * because that function is defined in functions.php, which loads before
	 * Patchwork, so Brain Monkey cannot redefine it.
	 *
	 * @param string $stored  What the option row currently holds.
	 * @param bool   $push_ok What the push returns.
	 * @param array  $errors  Collects add_settings_error() codes, by reference.
	 * @return object
	 */
	private function checkout_ui_settings( $stored, $push_ok, &$errors ) {
		$errors = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) use ( $stored ) {
				return 'idea89_checkout_ui' === $name ? $stored : $default;
			}
		);
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'add_settings_error' )->alias(
			function ( $setting, $code ) use ( &$errors ) {
				$errors[] = $code;
			}
		);

		return new class( $push_ok ) extends Idea89_Admin_Settings {
			public $ok;
			public $calls = 0;
			public function __construct( $ok ) {
				$this->ok = $ok;
			}
			protected function push_checkout_ui( $value ) {
				++$this->calls;
				return $this->ok;
			}
		};
	}

	public function test_a_successful_push_keeps_the_new_value() {
		$settings = $this->checkout_ui_settings( 'full', true, $errors );

		$this->assertSame( 'inline', $settings->sanitize_checkout_ui( 'inline' ) );
		$this->assertSame( 1, $settings->calls );
		$this->assertSame( array(), $errors );
	}

	public function test_a_failed_push_reverts_the_save_and_says_why() {
		// The behaviour this design exists for. Storing the new value locally
		// when IDEA89 never learned about it would leave the dashboard and
		// this screen showing different answers for the same setting, and the
		// merchant believing they had changed something they had not.
		$settings = $this->checkout_ui_settings( 'full', false, $errors );

		$this->assertSame( 'full', $settings->sanitize_checkout_ui( 'inline' ) );
		$this->assertSame( 1, $settings->calls );
		$this->assertSame( array( 'idea89_checkout_ui_unreachable' ), $errors );
	}

	public function test_an_unrecognised_value_never_reaches_the_api() {
		$settings = $this->checkout_ui_settings( 'full', true, $errors );

		$this->assertSame( 'full', $settings->sanitize_checkout_ui( 'popup' ) );
		$this->assertSame( 0, $settings->calls );
		$this->assertSame( array( 'idea89_checkout_ui_invalid' ), $errors );
	}

	public function test_an_unchanged_value_does_not_call_the_api_at_all() {
		// WordPress runs the sanitize callback on every save of the page, not
		// only when this field was touched. Without the guard, editing an
		// unrelated setting would fail whenever the API happened to be slow.
		$settings = $this->checkout_ui_settings( 'inline', true, $errors );

		$this->assertSame( 'inline', $settings->sanitize_checkout_ui( 'inline' ) );
		$this->assertSame( 0, $settings->calls );
		$this->assertSame( array(), $errors );
	}

	public function test_a_non_string_submission_is_refused() {
		$settings = $this->checkout_ui_settings( 'full', true, $errors );

		$this->assertSame( 'full', $settings->sanitize_checkout_ui( array( 'inline' ) ) );
		$this->assertSame( 0, $settings->calls );
	}

	/* ------------- Assistant name: shared with the IDEA89 dashboard ------------- */

	/**
	 * Same shape as checkout_ui_settings(): stub what the callback reads and
	 * override the one seam that reaches the network.
	 *
	 * @param string $stored  What the option row currently holds.
	 * @param bool   $push_ok What the push returns.
	 * @param array  $errors  Collects add_settings_error() codes, by reference.
	 * @return object
	 */
	private function assistant_name_settings( $stored, $push_ok, &$errors ) {
		$errors = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) use ( $stored ) {
				return 'idea89_assistant_name' === $name ? $stored : $default;
			}
		);
		Functions\when( 'add_settings_error' )->alias(
			function ( $setting, $code ) use ( &$errors ) {
				$errors[] = $code;
			}
		);

		return new class( $push_ok ) extends Idea89_Admin_Settings {
			public $ok;
			public $calls = 0;
			public function __construct( $ok ) {
				$this->ok = $ok;
			}
			protected function push_assistant_name( $value ) {
				++$this->calls;
				return $this->ok;
			}
		};
	}

	public function test_a_successful_push_keeps_the_new_name() {
		$settings = $this->assistant_name_settings( 'Mira', true, $errors );

		$this->assertSame( 'Robin', $settings->sanitize_assistant_name( 'Robin' ) );
		$this->assertSame( 1, $settings->calls );
		$this->assertSame( array(), $errors );
	}

	public function test_a_failed_push_reverts_the_name_and_says_why() {
		// The behaviour the whole design exists for. Keeping the new name
		// locally when IDEA89 never learned about it is how the header and the
		// assistant's own answer end up disagreeing.
		$settings = $this->assistant_name_settings( 'Mira', false, $errors );

		$this->assertSame( 'Mira', $settings->sanitize_assistant_name( 'Robin' ) );
		$this->assertSame( 1, $settings->calls );
		$this->assertSame( array( 'idea89_assistant_name_unreachable' ), $errors );
	}

	public function test_an_empty_name_is_refused_without_calling_the_api() {
		// Saving blank would leave nothing above the conversation.
		$settings = $this->assistant_name_settings( 'Mira', true, $errors );

		$this->assertSame( 'Mira', $settings->sanitize_assistant_name( '   ' ) );
		$this->assertSame( 0, $settings->calls );
		$this->assertSame( array( 'idea89_assistant_name_empty' ), $errors );
	}

	public function test_a_name_longer_than_the_dashboard_allows_is_refused() {
		// The dashboard validates 1..100. Storing 101 here would give the
		// merchant a name the dashboard then refuses while already showing it.
		$settings = $this->assistant_name_settings( 'Mira', true, $errors );

		$this->assertSame( 'Mira', $settings->sanitize_assistant_name( str_repeat( 'x', 101 ) ) );
		$this->assertSame( 0, $settings->calls );
		$this->assertSame( array( 'idea89_assistant_name_too_long' ), $errors );
	}

	public function test_exactly_one_hundred_characters_is_allowed() {
		$settings = $this->assistant_name_settings( 'Mira', true, $errors );
		$name = str_repeat( 'x', 100 );

		$this->assertSame( $name, $settings->sanitize_assistant_name( $name ) );
		$this->assertSame( 1, $settings->calls );
	}

	public function test_an_unchanged_name_does_not_call_the_api() {
		// WordPress runs the callback on every save of the page.
		$settings = $this->assistant_name_settings( 'Mira', true, $errors );

		$this->assertSame( 'Mira', $settings->sanitize_assistant_name( 'Mira' ) );
		$this->assertSame( 0, $settings->calls );
	}

	public function test_a_name_is_trimmed_before_it_is_compared() {
		// Otherwise a stray space counts as a change, fires a pointless push,
		// and stores a name that renders differently from the dashboard's.
		$settings = $this->assistant_name_settings( 'Mira', true, $errors );

		$this->assertSame( 'Mira', $settings->sanitize_assistant_name( '  Mira  ' ) );
		$this->assertSame( 0, $settings->calls );
	}

	/* ------------- Store context: one-time handover to the dashboard ------------- */

	/**
	 * Stubs the option, transient and delete calls the handover makes, and
	 * overrides the network seam.
	 *
	 * @param string $stored  What idea89_store_context holds.
	 * @param bool   $push_ok What the push returns.
	 * @param array  $log     Collects side effects, by reference.
	 * @param bool   $waiting Whether a retry transient is set.
	 * @return object
	 */
	private function handover_settings( $stored, $push_ok, &$log, $waiting = false ) {
		$log = array();
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) use ( $stored ) {
				return 'idea89_store_context' === $name ? $stored : $default;
			}
		);
		Functions\when( 'get_transient' )->justReturn( $waiting ? 1 : false );
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $ttl ) use ( &$log ) {
				$log[] = 'retry:' . $name . ':' . $ttl;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$log ) {
				$log[] = 'delete:' . $name;
			}
		);

		return new class( $push_ok, $log ) extends Idea89_Admin_Settings {
			public $ok;
			public $sent = array();
			public function __construct( $ok ) {
				$this->ok = $ok;
			}
			protected function push_store_context_seed( $text ) {
				$this->sent[] = $text;
				return $this->ok;
			}
		};
	}

	public function test_saved_store_context_is_handed_over_once_then_deleted() {
		$settings = $this->handover_settings( "  We sell handmade soap.\n", true, $log );
		$settings->hand_over_store_context();
		$this->assertSame( array( 'We sell handmade soap.' ), $settings->sent );
		$this->assertSame( array( 'delete:idea89_store_context' ), $log );
	}

	public function test_a_failed_handover_keeps_the_text_and_waits_before_retrying() {
		$settings = $this->handover_settings( 'We sell handmade soap.', false, $log );
		$settings->hand_over_store_context();
		$this->assertSame( array( 'retry:idea89_store_context_retry:43200' ), $log );
	}

	public function test_nothing_is_sent_when_there_is_no_text_or_a_retry_is_pending() {
		$empty = $this->handover_settings( '   ', true, $log );
		$empty->hand_over_store_context();
		$this->assertSame( array(), $empty->sent );

		$waiting = $this->handover_settings( 'We sell soap.', true, $log, true );
		$waiting->hand_over_store_context();
		$this->assertSame( array(), $waiting->sent );
		$this->assertSame( array(), $log );
	}

	public function test_text_over_the_dashboard_limit_is_cut_on_a_character_boundary() {
		$settings = $this->handover_settings( str_repeat( 'é', 3000 ), true, $log );
		$settings->hand_over_store_context();
		$this->assertLessThanOrEqual( 4096, strlen( $settings->sent[0] ) );
		$this->assertTrue( mb_check_encoding( $settings->sent[0], 'UTF-8' ) );
	}

	/**
	 * A settings object whose brand colour handover is observable.
	 *
	 * @param string     $saved   Saved option value.
	 * @param bool       $push_ok What IDEA89 answers.
	 * @param array|null $log     Receives option/transient writes.
	 * @param bool       $waiting Whether a retry is pending.
	 */
	private function colour_handover( $saved, $push_ok, &$log, $waiting = false ) {
		$log = array();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) use ( $saved ) {
				return 'idea89_brand_color' === $name ? $saved : $default;
			}
		);
		Functions\when( 'get_transient' )->justReturn( $waiting ? 1 : false );
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $ttl ) use ( &$log ) {
				$log[] = 'retry:' . $name . ':' . $ttl;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$log ) {
				$log[] = 'delete:' . $name;
			}
		);

		return new class( $push_ok ) extends Idea89_Admin_Settings {
			public $ok;
			public $sent = array();
			public function __construct( $ok ) {
				$this->ok = $ok;
			}
			protected function push_brand_color_seed( $color ) {
				$this->sent[] = $color;
				return $this->ok;
			}
		};
	}

	public function test_saved_brand_color_is_handed_over_once_then_deleted() {
		$settings = $this->colour_handover( ' #2563eb ', true, $log );
		$settings->hand_over_brand_color();
		$this->assertSame( array( '#2563eb' ), $settings->sent );
		$this->assertSame( array( 'delete:idea89_brand_color' ), $log );
	}

	public function test_a_failed_brand_color_handover_keeps_it_and_waits_before_retrying() {
		$settings = $this->colour_handover( '#2563eb', false, $log );
		$settings->hand_over_brand_color();
		$this->assertSame( array( 'retry:idea89_brand_color_retry:43200' ), $log );
	}

	public function test_no_brand_color_is_sent_when_unset_invalid_or_a_retry_is_pending() {
		$empty = $this->colour_handover( '', true, $log );
		$empty->hand_over_brand_color();
		$this->assertSame( array(), $empty->sent );
		$this->assertSame( array(), $log );

		$bad = $this->colour_handover( 'javascript:alert(1)', true, $log );
		$bad->hand_over_brand_color();
		$this->assertSame( array(), $bad->sent );
		$this->assertSame( array( 'delete:idea89_brand_color' ), $log );

		$waiting = $this->colour_handover( '#2563eb', true, $log, true );
		$waiting->hand_over_brand_color();
		$this->assertSame( array(), $waiting->sent );
		$this->assertSame( array(), $log );
	}
}
