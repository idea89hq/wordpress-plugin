<?php
/**
 * Attributes as WooCommerce stores them: global (taxonomy, pa_*) attributes
 * hold term IDs, local ones the text the merchant typed. Invented products.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-content-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-product-serializer.php';

/** Mirrors WC_Product_Attribute: get_options() returns term IDs for a taxonomy attribute. */
class Fake_WC_Product_Attribute {
	private $name;
	private $options;
	private $taxonomy;
	private $visible;
	private $variation;
	public function __construct( $name, array $options, $taxonomy, $visible = true, $variation = false ) {
		$this->name      = $name;
		$this->options   = $options;
		$this->taxonomy  = $taxonomy;
		$this->visible   = $visible;
		$this->variation = $variation;
	}
	public function get_name() { return $this->name; }
	public function get_options() { return $this->options; }
	public function is_taxonomy() { return $this->taxonomy; }
	public function get_visible() { return $this->visible; }
	public function get_variation() { return $this->variation; }
}

class ProductSerializerAttributesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'GBP' );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://shop.example.test/img/1.jpg' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'get_comments' )->justReturn( array() );
		Functions\when( 'wc_get_product' )->justReturn( false );
		Functions\when( 'wc_attribute_label' )->alias(
			function ( $name ) {
				return 'pa_colour' === $name ? 'Colour' : ( 'pa_material' === $name ? 'Material' : $name );
			}
		);
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'is_wp_error' )->justReturn( false );
		// Schema-2 lookups (plugin 1.3.0).
		Functions\when( 'wc_prices_include_tax' )->justReturn( false );
		Functions\when( 'wc_tax_enabled' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_term' )->justReturn( null );
		Functions\when( 'sanitize_title' )->alias( function ( $t ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $t ) ); } );
		// Term IDs 12 and 15 are the colour terms "Sage" and "Rust"; 30 is "Oak".
		Functions\when( 'wc_get_product_terms' )->alias(
			function ( $product_id, $taxonomy, $args = array() ) {
				$names = array( 'pa_colour' => array( 'Sage', 'Rust' ), 'pa_material' => array( 'Oak' ) );
				return $names[ $taxonomy ] ?? array();
			}
		);
		Functions\when( 'wc_prices_include_tax' )->justReturn( true );
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function product() {
		return new Fake_WC_Product(
			array(
				'attributes' => array(
					'pa_colour'   => new Fake_WC_Product_Attribute( 'pa_colour', array( 12, 15 ), true, true, true ),
					'pa_material' => new Fake_WC_Product_Attribute( 'pa_material', array( 30 ), true ),
					'care'        => new Fake_WC_Product_Attribute( 'Care', array( 'Wipe clean' ), false, false ),
				),
			)
		);
	}

	public function test_global_attributes_are_sent_as_term_names_not_term_ids() {
		$out = ( new Idea89_Product_Serializer() )->serialize( $this->product() );
		$this->assertSame( 'Sage, Rust', $out['attributes']['pa_colour'] );
		$this->assertSame( 'Oak', $out['attributes']['pa_material'] );
		$this->assertSame( 'Wipe clean', $out['attributes']['care'] );
	}
	public function test_the_attribute_list_carries_labels_types_and_flags() {
		$out  = ( new Idea89_Product_Serializer() )->serialize( $this->product() );
		$list = array_column( $out['attribute_list'], null, 'code' );
		$this->assertSame(
			array( 'code' => 'pa_colour', 'label' => 'Colour', 'value' => array( 'Sage', 'Rust' ), 'type' => 'multiselect', 'filterable' => true, 'visible' => true ),
			array_intersect_key( $list['pa_colour'], array_flip( array( 'code', 'label', 'value', 'type', 'filterable', 'visible' ) ) )
		);
		$this->assertSame( 'select', $list['pa_material']['type'] );
		$this->assertSame( array( 'code' => 'care', 'label' => 'Care', 'value' => 'Wipe clean', 'type' => 'text', 'filterable' => false, 'visible' => false ),
			array_intersect_key( $list['care'], array_flip( array( 'code', 'label', 'value', 'type', 'filterable', 'visible' ) ) ) );
	}

	public function test_tax_basis_short_description_and_category_paths() {
		Functions\when( 'wp_get_post_terms' )->justReturn( array( (object) array( 'term_id' => 9, 'name' => 'Benches' ) ) );
		Functions\when( 'get_ancestors' )->justReturn( array( 5, 2 ) );
		Functions\when( 'get_term' )->alias(
			function ( $id ) {
				$names = array( 2 => 'Garden', 5 => 'Furniture' );
				return (object) array( 'name' => $names[ $id ] );
			}
		);
		$out = ( new Idea89_Product_Serializer() )->serialize( $this->product() );
		$this->assertTrue( $out['price_includes_tax'] );
		$this->assertSame( 'Short.', $out['short_description'] );
		$this->assertSame( array( 'Garden > Furniture > Benches' ), $out['category_paths'] );
		$this->assertNull( $out['tax_rate'] );
	}

	public function test_variations_carry_stock_quantity_their_own_values_and_the_parents_price_basis() {
		$variation = new class() {
			public function get_price() { return '20.00'; }
			public function get_stock_quantity() { return 4; }
			public function get_name() { return 'Throw - Sage'; }
			public function get_weight() { return '1.2'; }
		};
		Functions\when( 'wc_get_product' )->justReturn( $variation );
		Functions\when( 'get_option' )->justReturn( 'kg' );
		$product = new Fake_WC_Product(
			array(
				'type'                 => 'variable',
				'available_variations' => array(
					array(
						'variation_id'  => 22,
						'sku'           => 'T-SAGE',
						'display_price' => 24.0,
						'is_in_stock'   => true,
						'attributes'    => array( 'attribute_pa_colour' => 'sage' ),
					),
				),
			)
		);
		$v = ( new Idea89_Product_Serializer() )->serialize( $product )['variants'][0];
		$this->assertSame( 20.0, $v['price'] );
		$this->assertSame( 4, $v['stock_qty'] );
		$this->assertSame( 'Throw - Sage', $v['name'] );
		$this->assertSame( array( 'Colour', 'Weight' ), array_column( $v['attribute_list'], 'label' ) );
		$this->assertSame( 'kg', $v['attribute_list'][1]['unit'] );
	}
}
