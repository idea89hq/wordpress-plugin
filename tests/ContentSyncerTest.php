<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-content-syncer.php';

class ContentSyncerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'strip_shortcodes' )->alias(
			function ( $text ) {
				return preg_replace( '/\[[^\]]*\]/', '', $text );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_extract_body_strips_tags_and_shortcodes() {
		$raw = '<p>Free delivery over &pound;50.</p>[contact_form id="4"]<script>evil()</script>';
		$out = Idea89_Content_Syncer::extract_body( $raw );

		$this->assertStringNotContainsString( '<', $out );
		$this->assertStringNotContainsString( '[contact_form', $out );
		$this->assertStringContainsString( 'Free delivery over', $out );
	}

	public function test_extract_body_collapses_whitespace() {
		$this->assertSame( 'One two three.', Idea89_Content_Syncer::extract_body( "One\n\n  two\t three." ) );
	}

	public function test_extract_body_truncates_to_the_api_limit() {
		$out = Idea89_Content_Syncer::extract_body( str_repeat( 'word ', 5000 ) );
		$this->assertLessThanOrEqual( 10000, strlen( $out ) );
	}

	public function test_store_info_body_names_the_facts_it_has() {
		$out = Idea89_Content_Syncer::build_store_info(
			array(
				'name'     => 'Example Shop',
				'currency' => 'GBP',
				'email'    => 'help@example.test',
				'url'      => 'https://example.test',
			)
		);

		$this->assertSame( 'store_info', $out['type'] );
		$this->assertSame( 'store', $out['external_id'] );
		$this->assertStringContainsString( 'Example Shop', $out['body'] );
		$this->assertStringContainsString( 'GBP', $out['body'] );
		$this->assertStringContainsString( 'help@example.test', $out['body'] );
	}

	public function test_store_info_omits_facts_it_does_not_have() {
		// Inventing a contact address the merchant never set would put a wrong
		// answer in the assistant's mouth.
		$out = Idea89_Content_Syncer::build_store_info(
			array( 'name' => 'Example Shop', 'currency' => 'GBP', 'email' => '', 'url' => '' )
		);

		$this->assertStringNotContainsString( 'Contact', $out['body'] );
	}

	public function test_store_info_omits_the_url_key_when_there_is_no_url() {
		// The API's `url` field is optional-if-absent but not nullable: shipping
		// "url": null instead of leaving the key out would 400 the whole batch.
		$out = Idea89_Content_Syncer::build_store_info(
			array( 'name' => 'Example Shop', 'currency' => '', 'email' => '', 'url' => '' )
		);

		$this->assertArrayNotHasKey( 'url', $out );
	}

	public function test_store_info_falls_back_to_a_generated_title() {
		// A merchant can clear the WordPress site title. The API requires a
		// non-empty title, so an empty one gets a fallback rather than 400ing
		// the whole batch.
		$out = Idea89_Content_Syncer::build_store_info( array( 'name' => '' ) );

		$this->assertSame( 'Store', $out['title'] );
	}

	public function test_safe_title_falls_back_when_blank() {
		$this->assertSame( 'Page', Idea89_Content_Syncer::safe_title( '   ', 'Page' ) );
		$this->assertSame( 'Page', Idea89_Content_Syncer::safe_title( '', 'Page' ) );
	}

	public function test_safe_title_truncates_to_the_api_limit() {
		$out = Idea89_Content_Syncer::safe_title( str_repeat( 'x', 600 ), 'Page' );
		$this->assertSame( 500, strlen( $out ) );
	}

	public function test_safe_url_passes_through_a_real_string() {
		$this->assertSame( 'https://shop.example.test/about/', Idea89_Content_Syncer::safe_url( 'https://shop.example.test/about/' ) );
	}

	public function test_safe_url_drops_a_wp_error() {
		// get_term_link() returns a WP_Error on failure. Forwarding that through
		// wp_json_encode() would ship a malformed `url` field and 400 the batch.
		$this->assertNull( Idea89_Content_Syncer::safe_url( new WP_Error( 'invalid_term', 'no term' ) ) );
	}

	public function test_safe_url_drops_false() {
		// get_permalink() returns false on failure.
		$this->assertNull( Idea89_Content_Syncer::safe_url( false ) );
	}

	public function test_safe_url_drops_an_empty_string() {
		$this->assertNull( Idea89_Content_Syncer::safe_url( '' ) );
	}

	public function test_safe_url_drops_a_protocol_relative_url() {
		// A CDN or image-offload plugin filtering permalinks and attachment URLs
		// routinely returns this shape. It is a non-empty string, so the old
		// check passed it straight through to the API, where Zod's .url()
		// rejected it and 400d the whole batch.
		$this->assertNull( Idea89_Content_Syncer::safe_url( '//cdn.example.test/x.jpg' ) );
	}

	public function test_safe_url_drops_a_scheme_relative_path() {
		$this->assertNull( Idea89_Content_Syncer::safe_url( '/about/' ) );
	}

	public function test_safe_url_drops_a_non_http_scheme() {
		$this->assertNull( Idea89_Content_Syncer::safe_url( 'javascript:alert(1)' ) );
		$this->assertNull( Idea89_Content_Syncer::safe_url( 'data:text/html,hi' ) );
		$this->assertNull( Idea89_Content_Syncer::safe_url( 'ftp://files.example.test/x' ) );
	}

	public function test_safe_url_keeps_plain_http() {
		$this->assertSame( 'http://shop.example.test/about/', Idea89_Content_Syncer::safe_url( 'http://shop.example.test/about/' ) );
	}

	public function test_truncate_cuts_on_character_boundaries_not_bytes() {
		// "£" is two bytes in UTF-8. Cutting at byte 5 of "1234£" lands inside
		// it and leaves invalid UTF-8, which makes wp_json_encode() return false
		// and Idea89_Client::post() abandon the request without an HTTP call —
		// so one clipped field silently drops the entire batch.
		$text = '1234£6789';
		$out  = Idea89_Content_Syncer::truncate( $text, 5 );

		$this->assertSame( '1234£', $out );
		$this->assertSame( 5, mb_strlen( $out, 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ) );
		$this->assertNotFalse( json_encode( $out ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_truncate_leaves_short_text_alone() {
		$this->assertSame( 'short', Idea89_Content_Syncer::truncate( 'short', 500 ) );
	}

	public function test_extract_body_truncation_never_leaves_invalid_utf8() {
		$body = str_repeat( 'a', 9999 ) . '£££';
		$out  = Idea89_Content_Syncer::extract_body( $body );

		$this->assertSame( 10000, mb_strlen( $out, 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ) );
	}

	public function test_safe_title_truncation_never_leaves_invalid_utf8() {
		$out = Idea89_Content_Syncer::safe_title( str_repeat( 'a', 499 ) . '££', 'Page' );

		$this->assertSame( 500, mb_strlen( $out, 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ) );
	}

	public function test_is_noindexed_detects_the_yoast_flag() {
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = false ) {
				return '_yoast_wpseo_meta-robots-noindex' === $key ? '1' : '';
			}
		);

		$this->assertTrue( Idea89_Content_Syncer::is_noindexed( 12 ) );
	}

	public function test_is_noindexed_detects_the_rank_math_array_form() {
		// Rank Math stores directives as an array, e.g. array('noindex').
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = false ) {
				return 'rank_math_robots' === $key ? array( 'noindex', 'nofollow' ) : '';
			}
		);

		$this->assertTrue( Idea89_Content_Syncer::is_noindexed( 12 ) );
	}

	public function test_is_noindexed_detects_the_rank_math_discrete_flag() {
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = false ) {
				return 'rank_math_robots_noindex' === $key ? '1' : '';
			}
		);

		$this->assertTrue( Idea89_Content_Syncer::is_noindexed( 12 ) );
	}

	public function test_is_noindexed_is_false_for_an_ordinary_post() {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$this->assertFalse( Idea89_Content_Syncer::is_noindexed( 12 ) );
	}

	public function test_is_noindexed_ignores_a_rank_math_array_without_noindex() {
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = false ) {
				return 'rank_math_robots' === $key ? array( 'nofollow' ) : '';
			}
		);

		$this->assertFalse( Idea89_Content_Syncer::is_noindexed( 12 ) );
	}

	public function test_page_is_eligible_accepts_a_published_public_page() {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$page = (object) array(
			'ID'            => 3,
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_password' => '',
		);

		$this->assertTrue( Idea89_Content_Syncer::page_is_eligible( $page ) );
	}

	public function test_page_is_eligible_rejects_hidden_pages() {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$cases = array(
			'draft'             => array( 'post_status' => 'draft' ),
			'private'           => array( 'post_status' => 'private' ),
			'trashed'           => array( 'post_status' => 'trash' ),
			'pending'           => array( 'post_status' => 'pending' ),
			'passworded'        => array( 'post_password' => 'hunter2' ),
			'not a page at all' => array( 'post_type' => 'post' ),
		);

		foreach ( $cases as $label => $overrides ) {
			$page = (object) array_merge(
				array(
					'ID'            => 3,
					'post_type'     => 'page',
					'post_status'   => 'publish',
					'post_password' => '',
				),
				$overrides
			);

			$this->assertFalse( Idea89_Content_Syncer::page_is_eligible( $page ), $label );
		}
	}

	public function test_page_is_eligible_rejects_a_noindexed_page() {
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = false ) {
				return '_yoast_wpseo_meta-robots-noindex' === $key ? '1' : '';
			}
		);

		$page = (object) array(
			'ID'            => 3,
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_password' => '',
		);

		$this->assertFalse( Idea89_Content_Syncer::page_is_eligible( $page ) );
	}

	public function test_page_external_id_matches_what_the_syncer_sends() {
		$this->assertSame( 'page_42', Idea89_Content_Syncer::page_external_id( 42 ) );
	}
}
