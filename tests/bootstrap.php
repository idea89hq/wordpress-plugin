<?php
/**
 * PHPUnit bootstrap. Brain Monkey stubs WordPress functions, so no WordPress
 * install is required to run these tests.
 */

require_once __DIR__ . '/../vendor/autoload.php';

define( 'IDEA89_TESTING', true );
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'IDEA89_VERSION', '1.0.0' );
define( 'IDEA89_PLUGIN_FILE', __DIR__ . '/../idea89-assistant.php' );
define( 'IDEA89_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'IDEA89_PLUGIN_URL', 'https://example.test/wp-content/plugins/idea89-assistant/' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

/**
 * Minimal stand-ins for the WooCommerce product API surface the serializer
 * touches. Not a mock framework: a plain object whose getters return whatever
 * the test set, so a test reads as data in, data out.
 */
class Fake_WC_Product {
	public $data = array();

	public function __construct( array $data = array() ) {
		$this->data = array_merge(
			array(
				'id'                    => 1,
				'type'                  => 'simple',
				'sku'                   => 'SKU-1',
				'name'                  => 'Test Product',
				'description'           => 'A long description.',
				'short_description'     => 'Short.',
				'price'                 => '19.99',
				'sale_price'            => '',
				'on_sale'               => false,
				'in_stock'              => true,
				'stock_quantity'        => 5,
				'permalink'             => 'https://shop.example.test/p/1',
				'image_id'              => 11,
				'average_rating'        => '4.5',
				'review_count'          => 3,
				'featured'              => false,
				'date_created'          => '2026-08-01',
				'total_sales'           => 12,
				'attributes'            => array(),
				'available_variations'  => array(),
				'parent_id'             => 0,
			),
			$data
		);
	}

	public function get_id() { return $this->data['id']; }
	public function get_type() { return $this->data['type']; }
	public function is_type( $type ) { return $this->data['type'] === $type; }
	public function get_parent_id() { return $this->data['parent_id']; }
	public function get_sku() { return $this->data['sku']; }
	public function get_name() { return $this->data['name']; }
	public function get_description() { return $this->data['description']; }
	public function get_short_description() { return $this->data['short_description']; }
	public function get_price() { return $this->data['price']; }
	public function get_sale_price() { return $this->data['sale_price']; }
	public function is_on_sale() { return $this->data['on_sale']; }
	public function is_in_stock() { return $this->data['in_stock']; }
	public function get_stock_quantity() { return $this->data['stock_quantity']; }
	public function get_permalink() { return $this->data['permalink']; }
	public function get_image_id() { return $this->data['image_id']; }
	public function get_average_rating() { return $this->data['average_rating']; }
	public function get_review_count() { return $this->data['review_count']; }
	public function is_featured() { return $this->data['featured']; }
	public function get_date_created() { return $this->data['date_created']; }
	public function get_total_sales() { return $this->data['total_sales']; }
	public function get_attributes() { return $this->data['attributes']; }
	public function get_available_variations() { return $this->data['available_variations']; }
}
