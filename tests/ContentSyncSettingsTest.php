<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-client.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-content-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-document-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-faq-detector.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-faq-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/functions.php';
require_once IDEA89_PLUGIN_DIR . 'includes/admin/class-idea89-admin-settings.php';

class ContentSyncSettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
			}
		);
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page', 'case_study', 'product' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// sanitize_post_types() — the security-critical intersect.
	// -----------------------------------------------------------------

	public function test_keeps_only_selectable_post_types() {
		$out = Idea89_Admin_Settings::sanitize_post_types( array( 'post', 'case_study' ) );
		$this->assertSame( array( 'post', 'case_study' ), $out );
	}

	public function test_drops_products_even_if_submitted() {
		$out = Idea89_Admin_Settings::sanitize_post_types( array( 'post', 'product' ) );
		$this->assertSame( array( 'post' ), $out );
	}

	public function test_drops_unregistered_types() {
		$out = Idea89_Admin_Settings::sanitize_post_types( array( 'post', 'made_up_type' ) );
		$this->assertSame( array( 'post' ), $out );
	}

	public function test_a_non_array_submission_yields_an_empty_selection() {
		$this->assertSame( array(), Idea89_Admin_Settings::sanitize_post_types( 'post' ) );
		$this->assertSame( array(), Idea89_Admin_Settings::sanitize_post_types( null ) );
	}

	// -----------------------------------------------------------------
	// The checkbox trap (Task 11): an unchecked box must be able to turn a
	// setting OFF, not just leave it stuck on whatever it last was. Browsers
	// omit unchecked checkboxes from the POST body entirely, and the
	// Settings API only calls update_option() (and therefore the sanitize
	// callback) for keys actually present in $_POST — so without a hidden
	// fallback, an unchecked box can never persist "0".
	// -----------------------------------------------------------------

	/**
	 * Proves the four new boolean fields are wired through render_field()'s
	 * 'checkbox' branch (the exact mechanism that fixed idea89_enabled),
	 * and that the hidden-0 sibling this relies on is actually emitted for
	 * each of them, ahead of the real checkbox, sharing its option name.
	 *
	 * Markup order matters here, not just presence: browsers submit
	 * same-named fields in document order, and PHP's superglobal parsing
	 * keeps the *last* value for a repeated key. So when the box is
	 * checked, "0" then "1" are submitted and "1" wins; when it is
	 * unchecked, only the hidden "0" survives and the option is turned off.
	 * Reversing the order would silently defeat the fix.
	 */
	public function test_unchecked_new_checkboxes_persist_a_hidden_zero() {
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'checked' )->justReturn( '' );

		foreach ( array( 'idea89_sync_categories', 'idea89_sync_pages', 'idea89_sync_store_info', 'idea89_sync_faqs' ) as $name ) {
			// Simulates the option currently being ON, and the merchant
			// unticking the box: get_option() still reports the old truthy
			// value because render_field() renders from the last-saved
			// state, not from a hypothetical new one.
			Functions\when( 'get_option' )->justReturn( true );

			$admin = new Idea89_Admin_Settings();

			ob_start();
			$admin->render_field( array( 'name' => $name, 'type' => 'checkbox' ) );
			$html = ob_get_clean();

			$hidden_pos = strpos( $html, '<input type="hidden" name="' . $name . '" value="0" />' );
			$box_pos    = strpos( $html, '<input type="checkbox" name="' . $name . '" value="1"' );

			$this->assertNotFalse( $hidden_pos, "hidden 0 fallback missing for $name" );
			$this->assertNotFalse( $box_pos, "checkbox missing for $name" );
			$this->assertLessThan( $box_pos, $hidden_pos, "hidden 0 must precede the checkbox for $name, or an unchecked box cannot win the duplicate-key parse" );
		}
	}

	/**
	 * The other half of the same proof: when the option is actually off,
	 * rendering must not mark the box checked, confirming render_field()
	 * reads real state for these new names rather than always defaulting on.
	 */
	public function test_checkbox_is_unmarked_when_the_option_is_off() {
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'checked' )->alias(
			function ( $a, $b, $echo ) {
				return ( $a === $b ) ? 'checked="checked"' : '';
			}
		);

		$admin = new Idea89_Admin_Settings();

		ob_start();
		$admin->render_field( array( 'name' => 'idea89_sync_faqs', 'type' => 'checkbox' ) );
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'checked="checked"', $html );
	}

	/**
	 * The post-type list is an array field, not a single boolean, so it
	 * cannot reuse render_field()'s hidden-0 trick verbatim — but it has
	 * exactly the same absent-from-POST failure mode: if a merchant
	 * deselects every custom post type, no idea89_sync_post_types[] key is
	 * submitted at all, sanitize_post_types() never runs, and the last
	 * saved selection can never be cleared to none. A hidden empty-value
	 * sibling closes the same gap for the array case.
	 */
	public function test_post_types_field_includes_a_hidden_fallback_so_clearing_all_persists() {
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'get_post_type_object' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( array() );

		$admin = new Idea89_Admin_Settings();

		ob_start();
		$admin->render_post_types_field();
		$html = ob_get_clean();

		$hidden_pos = strpos( $html, '<input type="hidden" name="idea89_sync_post_types[]" value="" />' );
		$this->assertNotFalse( $hidden_pos, 'hidden fallback for idea89_sync_post_types[] is missing' );

		$first_checkbox_pos = strpos( $html, '<input type="checkbox" name="idea89_sync_post_types[]"' );
		if ( false !== $first_checkbox_pos ) {
			$this->assertLessThan( $first_checkbox_pos, $hidden_pos );
		}
	}

	// -----------------------------------------------------------------
	// Fresh-install default (Task 19, D4): before this fix, render_field()
	// computed its value with get_option( $name, '' ) — an explicit ''
	// default — while Idea89_Content_Syncer::sync_all() and
	// Idea89_Faq_Syncer::sync_all() already read the same four options as
	// get_option( $name, true ). A fresh install (no row in wp_options at
	// all) therefore rendered every content-lane checkbox unchecked even
	// though the syncers themselves treated a missing row as "on" — and the
	// first time a merchant saved the settings screen for any reason (e.g.
	// just to paste in the API key), that wrong unchecked display got
	// persisted as an explicit "0" via the hidden-field trick, permanently
	// disabling content sync. render_field() must now agree with the
	// syncers for these four names.
	// -----------------------------------------------------------------

	public function test_fresh_install_shows_the_four_content_lanes_ticked() {
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'checked' )->alias(
			function ( $a, $b, $echo ) {
				return ( (bool) $a === (bool) $b ) ? 'checked="checked"' : '';
			}
		);
		// Simulates a fresh install: no row exists for any of these options,
		// so get_option() falls through to whatever default the caller
		// supplied — exactly like the real WordPress function.
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return $default;
			}
		);

		$admin = new Idea89_Admin_Settings();

		foreach ( array( 'idea89_sync_categories', 'idea89_sync_pages', 'idea89_sync_store_info', 'idea89_sync_faqs' ) as $name ) {
			ob_start();
			$admin->render_field( array( 'name' => $name, 'type' => 'checkbox' ) );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'checked="checked"', $html, "$name should render ticked on a fresh install" );
		}
	}

	/**
	 * The other half of the same fix: an existing merchant who deliberately
	 * turned a lane off must stay off. A stored "0" — a row that genuinely
	 * exists — must win over the new true-by-default fallback, the same way
	 * it already does for idea89_enabled.
	 */
	public function test_an_explicitly_stored_zero_still_wins_over_the_new_default() {
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'checked' )->alias(
			function ( $a, $b, $echo ) {
				return ( (bool) $a === (bool) $b ) ? 'checked="checked"' : '';
			}
		);
		// Simulates a real, previously-saved row holding the falsy string
		// "0" — get_option() must return the STORED value, never the
		// passed-in default, once a row actually exists.
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return '0';
			}
		);

		$admin = new Idea89_Admin_Settings();

		foreach ( array( 'idea89_sync_categories', 'idea89_sync_pages', 'idea89_sync_store_info', 'idea89_sync_faqs' ) as $name ) {
			ob_start();
			$admin->render_field( array( 'name' => $name, 'type' => 'checkbox' ) );
			$html = ob_get_clean();

			$this->assertStringNotContainsString( 'checked="checked"', $html, "$name should stay off once a merchant has explicitly turned it off" );
		}
	}

	// -----------------------------------------------------------------
	// Honesty requirement 1: FAQ detection is deliberately narrow (native
	// <details>, FAQPage JSON-LD, known FAQ post types) and does not scan
	// theme-specific div accordions. A merchant who gets zero detections
	// must be told plainly, and told what WAS checked — not left assuming
	// the store simply has no FAQs.
	// -----------------------------------------------------------------

	public function test_intro_admits_the_gap_plainly_when_nothing_is_detected() {
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'post_type_exists' )->justReturn( false );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( array() );

		$admin = new Idea89_Admin_Settings();

		ob_start();
		$admin->render_content_intro();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'No FAQs detected yet.', $html );
		// Must say what WAS checked, so the merchant can act rather than
		// simply conclude their store has no FAQs.
		$this->assertStringContainsString( 'FAQ plugin post types', $html );
		$this->assertStringContainsString( 'FAQ schema markup', $html );
		$this->assertStringContainsString( 'accordion', $html );
		// Must not silently pretend accordion-only FAQs were considered.
		$this->assertStringContainsString( 'theme-specific accordion markup built from plain divs', $html );
	}

	public function test_intro_still_flags_the_gap_when_something_is_detected() {
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias(
			function ( $single, $plural, $count ) {
				return 1 === (int) $count ? $single : $plural;
			}
		);
		Functions\when( 'post_type_exists' )->alias(
			function ( $type ) {
				return 'ufaq' === $type;
			}
		);
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( array() );

		$admin = new Idea89_Admin_Settings();

		ob_start();
		$admin->render_content_intro();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'FAQ sources detected', $html );
		$this->assertStringContainsString( 'ufaq', $html );
		// Even a successful detection is not proof of full coverage.
		$this->assertStringContainsString( 'theme-specific accordion markup built from plain divs', $html );
	}

	// -----------------------------------------------------------------
	// Honesty requirement 2: unchecking a content type stops future syncing
	// but does not retroactively remove what is already indexed.
	// -----------------------------------------------------------------

	public function test_intro_warns_that_deselecting_a_type_does_not_prune_the_index() {
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'post_type_exists' )->justReturn( false );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( array() );

		$admin = new Idea89_Admin_Settings();

		ob_start();
		$admin->render_content_intro();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'only stops future syncing', $html );
		$this->assertStringContainsString( 'not automatically removed', $html );
	}

	// -----------------------------------------------------------------
	// detect_sources() must never fatal wp-admin, even with no FAQ plugin
	// installed and no matching pages — the settings screen must still
	// render.
	// -----------------------------------------------------------------

	public function test_content_intro_renders_fine_on_a_site_with_no_faq_plugin() {
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'post_type_exists' )->justReturn( false );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( array() );

		$admin = new Idea89_Admin_Settings();

		ob_start();
		$admin->render_content_intro();
		$html = ob_get_clean();

		$this->assertNotSame( '', $html );
	}
}
