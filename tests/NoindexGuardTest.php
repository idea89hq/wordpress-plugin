<?php
/**
 * The noindex rule, proven on every lane that reads a post body.
 *
 * These live in one file on purpose. The rule used to be implemented in the
 * document syncer alone; the content lane and both FAQ sources quietly did not
 * have it, so a page the merchant hid from search engines was still quoted to
 * shoppers by the assistant. The check now has exactly one implementation
 * (Idea89_Content_Syncer::is_noindexed) and this file is where every caller of
 * it is held to the same standard.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-client.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-content-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-faq-detector.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-faq-syncer.php';

/** Records what was sent instead of making HTTP calls. */
class Spy_Lane_Client extends Idea89_Client {
	public $content       = array();
	public $deleted       = array();
	public $faqs          = array();
	public $pruned        = array();
	public $faq_return    = true;
	public $prune_return  = true;

	public function __construct() {}

	public function sync_content( array $items ) {
		$this->content[] = $items;
		return true;
	}

	public function delete_content( $type, array $external_ids ) {
		$this->deleted[] = array(
			'type'         => $type,
			'external_ids' => $external_ids,
		);
		return true;
	}

	public function sync_faqs( array $faqs ) {
		$this->faqs[] = $faqs;
		return $this->faq_return;
	}

	public function prune_faqs( array $questions ) {
		$this->pruned[] = $questions;
		return $this->prune_return;
	}
}

class NoindexGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'strip_shortcodes' )->returnArg( 1 );
		Functions\when( 'get_permalink' )->justReturn( 'https://shop.example.test/about/' );
		Functions\when( 'get_terms' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Options: configured, pages only. */
	private function content_options() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				switch ( $name ) {
					case 'idea89_api_key':
						return 'sk_test';
					case 'idea89_sync_pages':
					case 'idea89_sync_faqs':
						return true;
					case 'idea89_sync_categories':
					case 'idea89_sync_store_info':
						return false;
				}
				return $default;
			}
		);
	}

	/** get_post_meta() returning the supplied keys and '' for everything else. */
	private function meta_stub( array $meta ) {
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = false ) use ( $meta ) {
				return isset( $meta[ $key ] ) ? $meta[ $key ] : '';
			}
		);
	}

	private function page( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'ID'            => 7,
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_title'    => 'Delivery',
				'post_content'  => 'We deliver free over fifty pounds.',
				'post_password' => '',
			),
			$overrides
		);
	}

	// -----------------------------------------------------------------
	// Lane 1: content (pages pushed to /v1/catalog/content).
	// -----------------------------------------------------------------

	public function test_content_lane_sends_an_ordinary_page() {
		$this->content_options();
		$this->meta_stub( array() );
		Functions\when( 'get_posts' )->justReturn( array( $this->page() ) );

		$client = new Spy_Lane_Client();
		$syncer = new Idea89_Content_Syncer( new Idea89_Config(), $client );
		$syncer->sync_all();

		$this->assertCount( 1, $client->content );
		$this->assertSame( 'page_7', $client->content[0][0]['external_id'] );
	}

	public function test_content_lane_skips_a_page_yoast_marked_noindex() {
		$this->content_options();
		$this->meta_stub( array( '_yoast_wpseo_meta-robots-noindex' => '1' ) );
		Functions\when( 'get_posts' )->justReturn( array( $this->page() ) );

		$client = new Spy_Lane_Client();
		$syncer = new Idea89_Content_Syncer( new Idea89_Config(), $client );
		$syncer->sync_all();

		// Nothing eligible is left, so no request is made at all.
		$this->assertSame( array(), $client->content );
	}

	public function test_content_lane_skips_a_page_rank_math_marked_noindex_via_the_array_form() {
		$this->content_options();
		$this->meta_stub( array( 'rank_math_robots' => array( 'noindex', 'nofollow' ) ) );
		Functions\when( 'get_posts' )->justReturn( array( $this->page() ) );

		$client = new Spy_Lane_Client();
		$syncer = new Idea89_Content_Syncer( new Idea89_Config(), $client );
		$syncer->sync_all();

		$this->assertSame( array(), $client->content );
	}

	// -----------------------------------------------------------------
	// Lane 2: FAQ custom post types.
	// -----------------------------------------------------------------

	/** detect_sources(): one FAQ post type, no candidate HTML pages. */
	private function faq_cpt_sources( array $faq_posts ) {
		Functions\when( 'post_type_exists' )->alias(
			function ( $type ) {
				return 'ufaq' === $type;
			}
		);
		Functions\when( 'get_posts' )->alias(
			function ( $args ) use ( $faq_posts ) {
				return 'ufaq' === $args['post_type'] ? $faq_posts : array();
			}
		);
	}

	private function faq_post( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'ID'            => 21,
				'post_type'     => 'ufaq',
				'post_status'   => 'publish',
				'post_title'    => 'Do you deliver on Sundays?',
				'post_content'  => 'Yes, on Sundays before noon.',
				'post_password' => '',
			),
			$overrides
		);
	}

	private function faq_syncer( Spy_Lane_Client $client ) {
		return new Idea89_Faq_Syncer( new Idea89_Config(), $client, new Idea89_Faq_Detector() );
	}

	public function test_faq_cpt_lane_sends_an_ordinary_faq_post() {
		$this->content_options();
		$this->meta_stub( array() );
		$this->faq_cpt_sources( array( $this->faq_post() ) );

		$client = new Spy_Lane_Client();
		$this->faq_syncer( $client )->sync_all();

		$this->assertCount( 1, $client->faqs );
		$this->assertSame( 'Do you deliver on Sundays?', $client->faqs[0][0]['question'] );
	}

	public function test_faq_cpt_lane_skips_a_post_yoast_marked_noindex() {
		$this->content_options();
		$this->meta_stub( array( '_yoast_wpseo_meta-robots-noindex' => '1' ) );
		$this->faq_cpt_sources( array( $this->faq_post() ) );

		$client = new Spy_Lane_Client();
		$this->faq_syncer( $client )->sync_all();

		$this->assertSame( array(), $client->faqs );
	}

	public function test_faq_cpt_lane_skips_a_post_rank_math_marked_noindex_via_the_array_form() {
		$this->content_options();
		$this->meta_stub( array( 'rank_math_robots' => array( 'noindex' ) ) );
		$this->faq_cpt_sources( array( $this->faq_post() ) );

		$client = new Spy_Lane_Client();
		$this->faq_syncer( $client )->sync_all();

		$this->assertSame( array(), $client->faqs );
	}

	// -----------------------------------------------------------------
	// Lane 3: FAQ markup scraped from ordinary pages.
	// -----------------------------------------------------------------

	/** detect_sources(): no FAQ post types, one candidate HTML page. */
	private function faq_html_sources( $page ) {
		Functions\when( 'post_type_exists' )->justReturn( false );
		Functions\when( 'get_posts' )->justReturn( array( 7 ) );
		Functions\when( 'get_post' )->justReturn( $page );
	}

	private function faq_html_page( array $overrides = array() ) {
		return $this->page(
			array_merge(
				array(
					'post_content' => '<details><summary>Where do you ship?</summary>Anywhere in the UK.</details>',
				),
				$overrides
			)
		);
	}

	public function test_faq_html_lane_scrapes_an_ordinary_page() {
		$this->content_options();
		$this->meta_stub( array() );
		$this->faq_html_sources( $this->faq_html_page() );

		$client = new Spy_Lane_Client();
		$this->faq_syncer( $client )->sync_all();

		$this->assertCount( 1, $client->faqs );
		$this->assertSame( 'Where do you ship?', $client->faqs[0][0]['question'] );
	}

	public function test_faq_html_lane_skips_a_page_yoast_marked_noindex() {
		$this->content_options();
		$this->meta_stub( array( '_yoast_wpseo_meta-robots-noindex' => '1' ) );
		$this->faq_html_sources( $this->faq_html_page() );

		$client = new Spy_Lane_Client();
		$this->faq_syncer( $client )->sync_all();

		$this->assertSame( array(), $client->faqs );
	}

	public function test_faq_html_lane_skips_a_page_rank_math_marked_noindex_via_the_array_form() {
		$this->content_options();
		$this->meta_stub( array( 'rank_math_robots' => array( 'noindex' ) ) );
		$this->faq_html_sources( $this->faq_html_page() );

		$client = new Spy_Lane_Client();
		$this->faq_syncer( $client )->sync_all();

		$this->assertSame( array(), $client->faqs );
	}
}
