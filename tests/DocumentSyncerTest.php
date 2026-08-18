<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-client.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-content-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-document-syncer.php';

/** Records what was sent instead of making HTTP calls. */
class Spy_Document_Client extends Idea89_Client {
	public $indexed             = array();
	public $deleted             = array();
	public $index_return_value  = true;
	public $delete_return_value = true;

	public function __construct() {}

	public function index_documents( array $documents ) {
		$this->indexed[] = $documents;
		return $this->index_return_value;
	}

	public function delete_documents( $doc_type, array $external_ids ) {
		$this->deleted[] = array(
			'doc_type'     => $doc_type,
			'external_ids' => $external_ids,
		);
		return $this->delete_return_value;
	}
}

class DocumentSyncerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function post( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'ID'            => 4,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => 'Choosing a spade',
				'post_content'  => 'Some genuinely useful advice about spades.',
				'post_password' => '',
			),
			$overrides
		);
	}

	/** get_option() stub: API key configured, with a given sync_post_types selection. */
	private function configured_with_types( array $types ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) use ( $types ) {
				if ( 'idea89_api_key' === $name ) {
					return 'sk_test';
				}
				if ( 'idea89_sync_post_types' === $name ) {
					return $types;
				}
				return $default;
			}
		);
	}

	/**
	 * get_post_meta() stub that returns a value per exact meta key, defaulting
	 * to '' for anything not listed. Unlike a blanket justReturn(), this can
	 * only pass when the code reads the specific key under test — a stub that
	 * matches any key proves nothing about which key the code actually reads.
	 */
	private function meta_stub( array $meta ) {
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = false ) use ( $meta ) {
				return array_key_exists( $key, $meta ) ? $meta[ $key ] : '';
			}
		);
	}

	/** Stubs the WP rendering functions serialize()/extract_body() touch, as a content passthrough. */
	private function stub_content_rendering() {
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'strip_shortcodes' )->alias(
			function ( $text ) {
				return $text;
			}
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.example.test/spade/' );
	}

	// -----------------------------------------------------------------
	// should_index()
	// -----------------------------------------------------------------

	public function test_indexes_a_published_public_post() {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertTrue( Idea89_Document_Syncer::should_index( $this->post() ) );
	}

	public function test_skips_drafts_and_private_posts() {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertFalse( Idea89_Document_Syncer::should_index( $this->post( array( 'post_status' => 'draft' ) ) ) );
		$this->assertFalse( Idea89_Document_Syncer::should_index( $this->post( array( 'post_status' => 'private' ) ) ) );
		$this->assertFalse( Idea89_Document_Syncer::should_index( $this->post( array( 'post_status' => 'trash' ) ) ) );
	}

	public function test_skips_password_protected_posts() {
		// Content behind a password is not public, so it must not reach the
		// assistant, which would happily quote it to anyone.
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertFalse( Idea89_Document_Syncer::should_index( $this->post( array( 'post_password' => 'secret' ) ) ) );
	}

	public function test_skips_a_post_yoast_has_marked_noindex() {
		$this->meta_stub( array( '_yoast_wpseo_meta-robots-noindex' => '1' ) );
		$this->assertFalse( Idea89_Document_Syncer::should_index( $this->post() ) );
	}

	public function test_skips_a_post_rank_math_has_marked_noindex_via_the_array_form() {
		// Rank Math's real schema: `rank_math_robots` stores a serialized array,
		// e.g. array('noindex') — not a discrete `rank_math_robots_noindex`
		// flag. A blanket justReturn('1') stub can't catch a wrong meta key;
		// meta_stub() only returns a value for the exact key under test.
		$this->meta_stub( array( 'rank_math_robots' => array( 'noindex' ) ) );
		$this->assertFalse( Idea89_Document_Syncer::should_index( $this->post() ) );
	}

	public function test_skips_a_post_rank_math_has_marked_noindex_via_the_discrete_flag() {
		// Some Rank Math install/version has been seen using a discrete flag
		// instead of the array form. Both shapes must be honoured.
		$this->meta_stub( array( 'rank_math_robots_noindex' => '1' ) );
		$this->assertFalse( Idea89_Document_Syncer::should_index( $this->post() ) );
	}

	public function test_indexes_a_post_whose_rank_math_robots_array_omits_noindex() {
		// Rank Math can set other directives (e.g. 'nofollow') without
		// noindex. The array's mere presence must not over-block indexing.
		$this->meta_stub( array( 'rank_math_robots' => array( 'nofollow' ) ) );
		$this->assertTrue( Idea89_Document_Syncer::should_index( $this->post() ) );
	}

	public function test_indexes_a_post_with_neither_seo_plugins_meta_key_present() {
		$this->meta_stub( array() );
		$this->assertTrue( Idea89_Document_Syncer::should_index( $this->post() ) );
	}

	public function test_skips_a_post_with_no_meaningful_body() {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->assertFalse( Idea89_Document_Syncer::should_index( $this->post( array( 'post_content' => '   ' ) ) ) );
	}

	// -----------------------------------------------------------------
	// available_post_types() / synced_post_types()
	// -----------------------------------------------------------------

	public function test_available_post_types_excludes_products_and_attachments() {
		// Products travel the catalogue lane; indexing them again as documents
		// would duplicate the whole catalogue into the retrieval corpus.
		$out = Idea89_Document_Syncer::available_post_types(
			array( 'post', 'page', 'product', 'attachment', 'case_study' )
		);

		$this->assertContains( 'post', $out );
		$this->assertContains( 'page', $out );
		$this->assertContains( 'case_study', $out );
		$this->assertNotContains( 'product', $out );
		$this->assertNotContains( 'attachment', $out );
	}

	public function test_synced_post_types_defaults_to_posts_only() {
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page', 'product' ) );

		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), new Idea89_Client( new Idea89_Config() ) );

		$this->assertSame( array( 'post' ), $syncer->synced_post_types() );
	}

	public function test_synced_post_types_honours_the_saved_selection() {
		Functions\when( 'get_option' )->justReturn( array( 'post', 'case_study' ) );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page', 'case_study', 'product' ) );

		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), new Idea89_Client( new Idea89_Config() ) );

		$this->assertSame( array( 'post', 'case_study' ), $syncer->synced_post_types() );
	}

	public function test_saved_selection_cannot_smuggle_in_products() {
		Functions\when( 'get_option' )->justReturn( array( 'post', 'product' ) );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'product' ) );

		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), new Idea89_Client( new Idea89_Config() ) );

		$this->assertSame( array( 'post' ), $syncer->synced_post_types() );
	}

	// -----------------------------------------------------------------
	// sync_post() — routing, withdrawal, and the real-defect fixes: a
	// get_permalink() failure or a blank title must not 400 the batch, and a
	// throwing the_content filter must not fatal the job.
	// -----------------------------------------------------------------

	public function test_sync_post_is_a_no_op_for_a_post_type_not_selected_for_sync() {
		$this->configured_with_types( array( 'post' ) );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );
		Functions\when( 'get_post' )->justReturn( $this->post( array( 'post_type' => 'page' ) ) );

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_post( 4 );

		$this->assertTrue( $result );
		$this->assertCount( 0, $client->indexed );
		$this->assertCount( 0, $client->deleted );
	}

	public function test_sync_post_never_indexes_a_product_even_when_called_directly() {
		// Defence in depth: even a tampered/stale saved selection containing
		// 'product' must not let a product travel the document lane.
		$this->configured_with_types( array( 'post', 'product' ) );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'product' ) );
		Functions\when( 'get_post' )->justReturn(
			$this->post(
				array(
					'ID'        => 9,
					'post_type' => 'product',
				)
			)
		);

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_post( 9 );

		$this->assertTrue( $result );
		$this->assertCount( 0, $client->indexed );
		$this->assertCount( 0, $client->deleted );
	}

	public function test_sync_post_withdraws_a_trashed_post_instead_of_skipping_it() {
		$this->configured_with_types( array( 'post' ) );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post' )->justReturn( $this->post( array( 'post_status' => 'trash' ) ) );

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_post( 4 );

		$this->assertTrue( $result );
		$this->assertCount( 0, $client->indexed );
		$this->assertCount( 1, $client->deleted );
		$this->assertSame( 'post', $client->deleted[0]['doc_type'] );
		$this->assertSame( array( '4' ), $client->deleted[0]['external_ids'] );
	}

	public function test_sync_post_withdraws_a_password_protected_post() {
		$this->configured_with_types( array( 'post' ) );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post' )->justReturn( $this->post( array( 'post_password' => 'secret' ) ) );

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_post( 4 );

		$this->assertCount( 0, $client->indexed );
		$this->assertCount( 1, $client->deleted );
	}

	public function test_sync_post_indexes_an_eligible_post_and_drops_a_false_permalink() {
		// get_permalink() returns false on failure. Forwarding that through
		// would ship a boolean in the `url` field, which the API rejects
		// (it accepts a string, null, or '' — never false), 400ing the
		// whole batch of up to 50 documents, not just this one.
		$this->configured_with_types( array( 'post' ) );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post' )->justReturn( $this->post() );
		$this->stub_content_rendering();
		Functions\when( 'get_permalink' )->justReturn( false );

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_post( 4 );

		$this->assertTrue( $result );
		$this->assertCount( 1, $client->indexed );
		$document = $client->indexed[0][0];
		$this->assertSame( 'post', $document['doc_type'] );
		$this->assertSame( '4', $document['external_id'] );
		$this->assertSame( 'Choosing a spade', $document['title'] );
		$this->assertStringContainsString( 'genuinely useful advice', $document['body'] );
		$this->assertArrayNotHasKey( 'url', $document );
	}

	public function test_sync_post_falls_back_to_a_generated_title_when_blank() {
		// A merchant can save a post with the title field cleared. The API
		// requires a non-empty title (1-500 chars); shipping "" would 400 the
		// whole batch, not just this document.
		$this->configured_with_types( array( 'post' ) );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post' )->justReturn( $this->post( array( 'post_title' => '' ) ) );
		$this->stub_content_rendering();

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$syncer->sync_post( 4 );

		$this->assertCount( 1, $client->indexed );
		$this->assertNotSame( '', $client->indexed[0][0]['title'] );
	}

	public function test_sync_post_survives_a_the_content_filter_that_throws() {
		// A badly-behaved third-party shortcode callback must not fatal the
		// whole scheduler job — same fix Task 16 applied to Idea89_Faq_Syncer.
		// Catches Throwable, not Exception: a bad callback can raise Error,
		// which does not extend Exception.
		$this->configured_with_types( array( 'post' ) );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post' )->justReturn( $this->post() );
		$this->stub_content_rendering();
		Functions\when( 'apply_filters' )->alias(
			function () {
				throw new Error( 'a badly-behaved shortcode blew up' );
			}
		);

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_post( 4 );

		$this->assertTrue( $result );
		$this->assertCount( 1, $client->indexed );
		// Falls back to the raw, un-filtered content rather than losing the post.
		$this->assertStringContainsString( 'genuinely useful advice', $client->indexed[0][0]['body'] );
	}

	// -----------------------------------------------------------------
	// sync_all()
	// -----------------------------------------------------------------

	public function test_sync_all_is_a_no_op_when_no_post_types_are_selected() {
		$this->configured_with_types( array() );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );
		Functions\expect( 'get_posts' )->never();

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_all();

		$this->assertTrue( $result );
		$this->assertCount( 0, $client->indexed );
	}

	public function test_sync_all_never_fetches_products_even_if_the_saved_selection_smuggled_them_in() {
		$this->configured_with_types( array( 'product' ) );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'product' ) );
		Functions\expect( 'get_posts' )->never();

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_all();

		$this->assertTrue( $result );
		$this->assertCount( 0, $client->indexed );
	}

	public function test_sync_all_batches_documents_at_the_batch_size_ceiling() {
		$this->configured_with_types( array( 'post' ) );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		$this->stub_content_rendering();

		$posts = array_map(
			function ( $i ) {
				return $this->post( array( 'ID' => $i ) );
			},
			range( 1, 60 )
		);
		Functions\when( 'get_posts' )->justReturn( $posts );

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_all();

		$this->assertTrue( $result );
		$this->assertCount( 2, $client->indexed );
		$this->assertCount( 50, $client->indexed[0] );
		$this->assertCount( 10, $client->indexed[1] );
	}

	public function test_sync_all_makes_no_call_without_an_api_key() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'get_posts' )->never();

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_all();

		$this->assertFalse( $result );
		$this->assertCount( 0, $client->indexed );
	}

	// -----------------------------------------------------------------
	// delete_post()
	// -----------------------------------------------------------------

	public function test_delete_post_sends_the_post_type_and_id_as_strings() {
		Functions\when( 'get_option' )->justReturn( 'sk_test' );
		Functions\when( 'get_post_type' )->justReturn( 'post' );

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$result = $syncer->delete_post( 4 );

		$this->assertTrue( $result );
		$this->assertCount( 1, $client->deleted );
		$this->assertSame( 'post', $client->deleted[0]['doc_type'] );
		$this->assertSame( array( '4' ), $client->deleted[0]['external_ids'] );
	}

	public function test_delete_post_is_a_no_op_when_the_post_type_cannot_be_resolved() {
		// A force-delete: by the time this runs the row may already be gone,
		// so get_post_type() returning falsy must not throw or call the API
		// with a bogus doc_type.
		Functions\when( 'get_option' )->justReturn( 'sk_test' );
		Functions\when( 'get_post_type' )->justReturn( false );

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$result = $syncer->delete_post( 4 );

		$this->assertFalse( $result );
		$this->assertCount( 0, $client->deleted );
	}

	public function test_delete_post_makes_no_call_without_an_api_key() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'get_post_type' )->never();

		$client = new Spy_Document_Client();
		$syncer = new Idea89_Document_Syncer( new Idea89_Config(), $client );
		$result = $syncer->delete_post( 4 );

		$this->assertFalse( $result );
	}
}
