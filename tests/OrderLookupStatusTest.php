<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-tracking-url-resolver.php';
require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-order-sanitizer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-order-tracking-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-order-endpoints.php';

if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * Minimal WC_Order: find_order() checks instanceof, the id, the
	 * displayed number and the status.
	 */
	class WC_Order {
		private $id;
		private $status;

		public function __construct( $id = 0, $status = 'processing' ) {
			$this->id     = $id;
			$this->status = $status;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_order_number() {
			return (string) $this->id;
		}

		public function get_status() {
			return $this->status;
		}
	}
}

/**
 * The id path of find_order() must not reach orders a shopper never placed:
 * checkout drafts, auto-drafts and trash.
 */
class OrderLookupStatusTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		Functions\when( 'wc_get_order_statuses' )->justReturn(
			array(
				'wc-pending'        => 'Pending payment',
				'wc-processing'     => 'Processing',
				'wc-on-hold'        => 'On hold',
				'wc-completed'      => 'Completed',
				'wc-cancelled'      => 'Cancelled',
				'wc-refunded'       => 'Refunded',
				'wc-failed'         => 'Failed',
				'wc-checkout-draft' => 'Draft',
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function find( $number ) {
		$ref      = new ReflectionClass( 'Idea89_Order_Endpoints' );
		$endpoint = $ref->newInstanceWithoutConstructor();
		$method   = $ref->getMethod( 'find_order' );
		$method->setAccessible( true );
		return $method->invoke( $endpoint, $number );
	}

	public function test_a_placed_order_is_found_by_id() {
		Functions\when( 'wc_get_order' )->justReturn( new WC_Order( 42, 'processing' ) );

		$this->assertInstanceOf( WC_Order::class, $this->find( '42' ) );
	}

	public function test_an_unplaced_order_is_not_reachable_by_id() {
		foreach ( array( 'checkout-draft', 'trash', 'auto-draft', 'draft' ) as $status ) {
			Functions\when( 'wc_get_order' )->justReturn( new WC_Order( 42, $status ) );

			$this->assertNull( $this->find( '42' ), $status );
		}
	}
}
