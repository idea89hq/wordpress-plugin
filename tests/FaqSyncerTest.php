<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-client.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-content-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-faq-detector.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-faq-syncer.php';

/** Records what was sent instead of making HTTP calls. */
class Spy_Faq_Client extends Idea89_Client {
	public $faqs         = array();
	public $pruned       = array();
	public $faq_return   = true;
	public $prune_return = true;

	public function __construct() {}

	public function sync_faqs( array $faqs ) {
		$this->faqs[] = $faqs;
		return $this->faq_return;
	}

	public function prune_faqs( array $questions ) {
		$this->pruned[] = $questions;
		return $this->prune_return;
	}
}

class FaqSyncerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_chunk_pairs_respects_the_batch_limit() {
		$pairs = array_map(
			function ( $i ) {
				return array( 'question' => "Q$i?", 'answer' => "A$i." );
			},
			range( 1, 250 )
		);

		$batches = Idea89_Faq_Syncer::chunk_pairs( $pairs, 100 );

		$this->assertCount( 3, $batches );
		$this->assertCount( 100, $batches[0] );
		$this->assertCount( 100, $batches[1] );
		$this->assertCount( 50, $batches[2] );
	}

	public function test_chunk_pairs_on_an_empty_list_yields_no_batches() {
		$this->assertSame( array(), Idea89_Faq_Syncer::chunk_pairs( array(), 100 ) );
	}

	public function test_detect_sources_reports_registered_faq_post_types() {
		Functions\when( 'post_type_exists' )->alias(
			function ( $type ) {
				return 'ufaq' === $type;
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_posts' )->justReturn( array() );

		$syncer  = new Idea89_Faq_Syncer( new Idea89_Config(), new Idea89_Client( new Idea89_Config() ), new Idea89_Faq_Detector() );
		$sources = $syncer->detect_sources();

		$this->assertSame( array( 'ufaq' ), $sources['post_types'] );
	}

	public function test_detect_sources_is_empty_when_no_faq_plugin_is_present() {
		Functions\when( 'post_type_exists' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_posts' )->justReturn( array() );

		$syncer  = new Idea89_Faq_Syncer( new Idea89_Config(), new Idea89_Client( new Idea89_Config() ), new Idea89_Faq_Detector() );
		$sources = $syncer->detect_sources();

		$this->assertSame( array(), $sources['post_types'] );
	}

	// -----------------------------------------------------------------
	// Pruning is DEFERRED and must not fire. store_faqs has four writers —
	// this lane, the dashboard's manual add and bulk import, and the
	// self-serve onboarding scraper — and no record of which one authored a
	// row. "Delete everything not in my list" therefore means "delete every
	// FAQ the merchant typed by hand and every FAQ onboarding scraped", and
	// installing the plugin would do it within the hour, unprompted:
	// idea89_sync_faqs defaults to on and the daily reconcile is scheduled an
	// hour after activation.
	//
	// Idea89_Client::prune_faqs() and its endpoint stay implemented and
	// tested. These tests pin the fact that nothing calls them yet.
	// -----------------------------------------------------------------

	/** Configured store with FAQ sync on. */
	private function faq_options() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				if ( 'idea89_api_key' === $name ) {
					return 'sk_test';
				}
				if ( 'idea89_sync_faqs' === $name ) {
					return true;
				}
				return $default;
			}
		);
	}

	/** One FAQ post type returning $posts; no candidate HTML pages. */
	private function faq_sources( $posts ) {
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'strip_shortcodes' )->returnArg( 1 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'post_type_exists' )->alias(
			function ( $type ) {
				return 'ufaq' === $type;
			}
		);
		Functions\when( 'get_posts' )->alias(
			function ( $args ) use ( $posts ) {
				return 'ufaq' === $args['post_type'] ? $posts : array();
			}
		);
	}

	/** $count FAQ posts, numbered from 1. */
	private function faq_posts( $count ) {
		$posts = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$posts[] = (object) array(
				'ID'            => 100 + $i,
				'post_type'     => 'ufaq',
				'post_status'   => 'publish',
				'post_title'    => "Question $i?",
				'post_content'  => "Answer $i.",
				'post_password' => '',
			);
		}
		return $posts;
	}

	private function syncer( Spy_Faq_Client $client ) {
		return new Idea89_Faq_Syncer( new Idea89_Config(), $client, new Idea89_Faq_Detector() );
	}

	public function test_a_complete_successful_sync_still_never_prunes() {
		// The dangerous case: everything enumerated, every batch accepted. This
		// is precisely when a prune would fire, and precisely when it would
		// delete every merchant- and onboarding-authored FAQ in the store.
		$this->faq_options();
		$this->faq_sources( $this->faq_posts( 2 ) );

		$client = new Spy_Faq_Client();
		$this->assertTrue( $this->syncer( $client )->sync_all() );

		$this->assertCount( 1, $client->faqs, 'the FAQs it found are still synced' );
		$this->assertSame( array(), $client->pruned );
	}

	public function test_no_input_shape_causes_a_prune() {
		// One assertion held across every route through sync_all(): a full
		// enumeration, a truncated post-type query, a truncated page scan, a
		// failed query, a rejected batch, and no FAQs at all.
		$this->faq_options();

		$cases = array(
			'complete'            => $this->faq_posts( 2 ),
			'post type truncated' => $this->faq_posts( Idea89_Faq_Syncer::MAX_POSTS_PER_TYPE ),
			'query failed'        => null,
			'nothing detected'    => array(),
		);

		foreach ( $cases as $label => $posts ) {
			$this->faq_sources( $posts );

			$client = new Spy_Faq_Client();
			$this->syncer( $client )->sync_all();

			$this->assertSame( array(), $client->pruned, $label );
		}

		// ...and with a rejected batch.
		$this->faq_sources( $this->faq_posts( 2 ) );
		$client             = new Spy_Faq_Client();
		$client->faq_return = false;
		$this->assertFalse( $this->syncer( $client )->sync_all() );
		$this->assertSame( array(), $client->pruned, 'rejected batch' );
	}

	public function test_a_rejected_batch_still_reports_failure() {
		$this->faq_options();
		$this->faq_sources( $this->faq_posts( 2 ) );

		$client             = new Spy_Faq_Client();
		$client->faq_return = false;

		$this->assertFalse( $this->syncer( $client )->sync_all() );
	}

	public function test_detecting_nothing_syncs_nothing_and_succeeds() {
		$this->faq_options();
		$this->faq_sources( array() );

		$client = new Spy_Faq_Client();
		$this->assertTrue( $this->syncer( $client )->sync_all() );

		$this->assertSame( array(), $client->faqs );
	}

	public function test_a_truncated_enumeration_still_syncs_what_it_saw() {
		// Truncation stops the (deferred) prune, never the sync itself.
		$this->faq_options();
		$this->faq_sources( $this->faq_posts( Idea89_Faq_Syncer::MAX_POSTS_PER_TYPE ) );

		$client = new Spy_Faq_Client();
		$this->syncer( $client )->sync_all();

		$this->assertNotEmpty( $client->faqs );
	}

	/**
	 * The prune client method and its endpoint are deliberately kept — they are
	 * correct and become safe the moment store_faqs carries provenance. This
	 * pins that nothing in the plugin wires them up in the meantime.
	 */
	public function test_nothing_in_the_plugin_calls_prune_faqs() {
		$sources = '';
		$dir     = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( IDEA89_PLUGIN_DIR . 'includes' )
		);
		foreach ( $dir as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$sources .= file_get_contents( $file->getPathname() ) . "\n";
			}
		}

		// The definition in Idea89_Client is expected; a call is not.
		preg_match_all( '/->prune_faqs\s*\(/', $sources, $calls );

		$this->assertSame(
			array(),
			$calls[0],
			'prune_faqs() is called somewhere in the plugin. It deletes every FAQ for the '
			. 'store that is not in the list it sends, and store_faqs has four writers with '
			. 'no record of which one authored a row — so it would delete the merchant\'s own '
			. 'FAQs and everything self-serve onboarding scraped. It must stay uncalled until '
			. 'store_faqs has a source column.'
		);
	}
}
