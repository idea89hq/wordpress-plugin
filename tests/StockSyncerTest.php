<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-client.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-stock-syncer.php';

/** Records what was sent instead of making HTTP calls. */
class Spy_Stock_Client extends Idea89_Client {
	public $calls        = array();
	public $return_value = true;

	public function __construct() {}

	public function upsert_stock( array $items ) {
		$this->calls[] = $items;
		return $this->return_value;
	}
}

class StockSyncerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				return 'idea89_api_key' === $name ? 'sk_test' : $default;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_simple_product_reports_its_own_id() {
		$product = new Fake_WC_Product(
			array(
				'id'             => 42,
				'type'           => 'simple',
				'in_stock'       => true,
				'stock_quantity' => 5,
			)
		);
		Functions\when( 'wc_get_product' )->justReturn( $product );

		$client = new Spy_Stock_Client();
		$syncer = new Idea89_Stock_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_product( 42 );

		$this->assertTrue( $result );
		$this->assertCount( 1, $client->calls );
		$this->assertSame(
			array(
				'external_id' => '42',
				'in_stock'    => true,
				'stock_qty'   => 5,
			),
			$client->calls[0][0]
		);
	}

	public function test_a_variation_with_a_valid_parent_reports_the_parent_id() {
		$variation = new Fake_WC_Product(
			array(
				'id'        => 55,
				'type'      => 'variation',
				'parent_id' => 42,
			)
		);
		$parent = new Fake_WC_Product(
			array(
				'id'             => 42,
				'type'           => 'variable',
				'in_stock'       => true,
				'stock_quantity' => 9,
			)
		);

		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) use ( $variation, $parent ) {
				if ( 55 === (int) $id ) {
					return $variation;
				}
				if ( 42 === (int) $id ) {
					return $parent;
				}
				return false;
			}
		);

		$client = new Spy_Stock_Client();
		$syncer = new Idea89_Stock_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_product( 55 );

		$this->assertTrue( $result );
		$this->assertCount( 1, $client->calls );
		// The catalogue is keyed on the parent id — the variation's own id
		// (55) must never appear as the external_id sent to the API.
		$this->assertSame( '42', $client->calls[0][0]['external_id'] );
	}

	public function test_an_orphaned_variation_is_a_no_op_and_makes_no_client_call() {
		// get_parent_id() === 0: an orphaned variation, which happens after a
		// botched import or a partially-deleted variable product. Sending the
		// variation's own id would create stock for a phantom product the
		// catalogue has no record of.
		$variation = new Fake_WC_Product(
			array(
				'id'        => 55,
				'type'      => 'variation',
				'parent_id' => 0,
			)
		);
		Functions\when( 'wc_get_product' )->justReturn( $variation );

		$client = new Spy_Stock_Client();
		$syncer = new Idea89_Stock_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_product( 55 );

		$this->assertFalse( $result );
		$this->assertCount( 0, $client->calls );
	}

	public function test_a_variation_whose_parent_no_longer_exists_is_a_no_op() {
		$variation = new Fake_WC_Product(
			array(
				'id'        => 55,
				'type'      => 'variation',
				'parent_id' => 42,
			)
		);

		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) use ( $variation ) {
				if ( 55 === (int) $id ) {
					return $variation;
				}
				return false; // Parent 42 no longer exists.
			}
		);

		$client = new Spy_Stock_Client();
		$syncer = new Idea89_Stock_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_product( 55 );

		$this->assertFalse( $result );
		$this->assertCount( 0, $client->calls );
	}

	public function test_an_unconfigured_store_makes_no_client_call() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'wc_get_product' )->never();

		$client = new Spy_Stock_Client();
		$syncer = new Idea89_Stock_Syncer( new Idea89_Config(), $client );
		$result = $syncer->sync_product( 42 );

		$this->assertFalse( $result );
		$this->assertCount( 0, $client->calls );
	}
}
