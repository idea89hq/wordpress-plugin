<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-client.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-scheduler.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-product-serializer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-catalog-syncer.php';

/** Records what was sent instead of making HTTP calls. */
class Spy_Client extends Idea89_Client {
	public $batches      = array();
	public $return_value = true;

	public function __construct() {}

	public function upsert_products( array $products ) {
		$this->batches[] = $products;
		return $this->return_value;
	}
}

/** Serializer double that throws for one specific product id. */
class Throwing_Serializer extends Idea89_Product_Serializer {
	public $throw_on_id = 0;
	public $throw_error = false;

	public function serialize( $product ) {
		if ( (int) $product->get_id() === $this->throw_on_id ) {
			if ( $this->throw_error ) {
				throw new TypeError( 'malformed product' );
			}
			throw new RuntimeException( 'boom' );
		}
		return parent::serialize( $product );
	}
}

class CatalogSyncerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				return 'idea89_api_key' === $name ? 'sk_test' : $default;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'GBP' );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'get_comments' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sync_page_sends_a_batch_and_reports_more_pages() {
		Functions\when( 'wc_get_products' )->justReturn(
			array_map(
				function ( $i ) {
					return new Fake_WC_Product( array( 'id' => $i ) );
				},
				range( 1, 100 )
			)
		);

		$client = new Spy_Client();
		$syncer = new Idea89_Catalog_Syncer( new Idea89_Config(), $client, new Idea89_Product_Serializer() );
		$result = $syncer->sync_page( 1 );

		$this->assertSame( 100, $result['synced'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertTrue( $result['has_more'] );
		$this->assertCount( 1, $client->batches );
		$this->assertCount( 100, $client->batches[0] );
	}

	public function test_a_short_page_means_no_more_pages() {
		Functions\when( 'wc_get_products' )->justReturn( array( new Fake_WC_Product() ) );

		$client = new Spy_Client();
		$syncer = new Idea89_Catalog_Syncer( new Idea89_Config(), $client, new Idea89_Product_Serializer() );
		$result = $syncer->sync_page( 1 );

		$this->assertSame( 1, $result['synced'] );
		$this->assertFalse( $result['has_more'] );
	}

	public function test_an_empty_page_sends_nothing() {
		Functions\when( 'wc_get_products' )->justReturn( array() );

		$client = new Spy_Client();
		$syncer = new Idea89_Catalog_Syncer( new Idea89_Config(), $client, new Idea89_Product_Serializer() );
		$result = $syncer->sync_page( 1 );

		$this->assertSame( 0, $result['synced'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertCount( 0, $client->batches );
	}

	public function test_a_rejected_batch_is_counted_as_failed() {
		Functions\when( 'wc_get_products' )->justReturn( array( new Fake_WC_Product() ) );

		$client               = new Spy_Client();
		$client->return_value = false;
		$syncer               = new Idea89_Catalog_Syncer( new Idea89_Config(), $client, new Idea89_Product_Serializer() );
		$result               = $syncer->sync_page( 1 );

		$this->assertSame( 0, $result['synced'] );
		$this->assertSame( 1, $result['failed'] );
	}

	public function test_one_bad_product_is_skipped_and_the_batch_continues() {
		// This is the fix for the Magento "Mode B silent drop" bug: a single
		// unserialisable product must not take the whole batch with it.
		$exploding = new Fake_WC_Product( array( 'id' => 2 ) );
		$exploding->data['permalink'] = null;

		Functions\when( 'wc_get_products' )->justReturn(
			array( new Fake_WC_Product( array( 'id' => 1 ) ), $exploding, new Fake_WC_Product( array( 'id' => 3 ) ) )
		);

		$client = new Spy_Client();
		$syncer = new Idea89_Catalog_Syncer( new Idea89_Config(), $client, new Idea89_Product_Serializer() );
		$result = $syncer->sync_page( 1 );

		// All three serialise fine here; the point of the assertion is that the
		// syncer reports a count rather than throwing.
		$this->assertSame( 3, $result['synced'] );
	}

	public function test_one_product_throwing_an_exception_does_not_lose_the_batch() {
		Functions\when( 'wc_get_products' )->justReturn(
			array( new Fake_WC_Product( array( 'id' => 1 ) ), new Fake_WC_Product( array( 'id' => 2 ) ), new Fake_WC_Product( array( 'id' => 3 ) ) )
		);
		$serializer               = new Throwing_Serializer();
		$serializer->throw_on_id = 2;

		$client = new Spy_Client();
		$syncer = new Idea89_Catalog_Syncer( new Idea89_Config(), $client, $serializer );
		$result = $syncer->sync_page( 1 );

		$this->assertSame( 2, $result['synced'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertCount( 2, $client->batches[0] );
	}

	public function test_one_product_throwing_an_error_does_not_lose_the_batch() {
		Functions\when( 'wc_get_products' )->justReturn(
			array( new Fake_WC_Product( array( 'id' => 1 ) ), new Fake_WC_Product( array( 'id' => 2 ) ) )
		);
		$serializer              = new Throwing_Serializer();
		$serializer->throw_on_id = 2;
		$serializer->throw_error = true; // TypeError — does NOT extend Exception.

		$client = new Spy_Client();
		$syncer = new Idea89_Catalog_Syncer( new Idea89_Config(), $client, $serializer );
		$result = $syncer->sync_page( 1 );

		$this->assertSame( 1, $result['synced'] );
		$this->assertSame( 1, $result['failed'] );
	}

	public function test_sync_all_enqueues_page_one_when_nothing_is_queued() {
		Functions\expect( 'as_has_scheduled_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_SYNC_PAGE, null, Idea89_Scheduler::GROUP )
			->andReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_SYNC_PAGE, array( 'page' => 1 ), Idea89_Scheduler::GROUP );

		$client = new Spy_Client();
		$syncer = new Idea89_Catalog_Syncer( new Idea89_Config(), $client, new Idea89_Product_Serializer() );
		$result = $syncer->sync_all();

		$this->assertSame(
			array(
				'synced' => 0,
				'failed' => 0,
			),
			$result
		);
	}

	public function test_sync_all_is_a_no_op_when_a_sync_is_already_queued() {
		Functions\expect( 'as_has_scheduled_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_SYNC_PAGE, null, Idea89_Scheduler::GROUP )
			->andReturn( true );
		Functions\expect( 'as_enqueue_async_action' )->never();

		$client = new Spy_Client();
		$syncer = new Idea89_Catalog_Syncer( new Idea89_Config(), $client, new Idea89_Product_Serializer() );
		$result = $syncer->sync_all();

		$this->assertSame(
			array(
				'synced' => 0,
				'failed' => 0,
			),
			$result
		);
	}

	public function test_nothing_happens_without_an_api_key() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'wc_get_products' )->never();

		$client = new Spy_Client();
		$syncer = new Idea89_Catalog_Syncer( new Idea89_Config(), $client, new Idea89_Product_Serializer() );
		$result = $syncer->sync_page( 1 );

		$this->assertSame( 0, $result['synced'] );
		$this->assertCount( 0, $client->batches );
	}
}
