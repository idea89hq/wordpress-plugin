<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-content-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-product-serializer.php';

class ProductSerializerTest extends TestCase {

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
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		// Default label/value resolution for variant attributes: mirrors
		// wc_attribute_label()'s real "pa_colour -> Colour" shape for a
		// taxonomy attribute, and leaves values untranslated (taxonomy_exists()
		// false) so tests that don't care about label/value resolution keep
		// seeing their raw input back. Individual tests override these to
		// exercise the term-name lookup.
		Functions\when( 'wc_attribute_label' )->alias(
			function ( $name ) {
				$stripped = 0 === strpos( $name, 'pa_' ) ? substr( $name, 3 ) : $name;
				return ucfirst( str_replace( '_', ' ', $stripped ) );
			}
		);
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function serializer() {
		return new Idea89_Product_Serializer();
	}

	public function test_maps_the_core_fields() {
		$out = $this->serializer()->serialize( new Fake_WC_Product() );

		$this->assertSame( '1', $out['external_id'] );
		$this->assertSame( 'SKU-1', $out['sku'] );
		$this->assertSame( 'Test Product', $out['name'] );
		$this->assertSame( 19.99, $out['price'] );
		$this->assertSame( 'GBP', $out['currency'] );
		$this->assertTrue( $out['in_stock'] );
		$this->assertSame( 5, $out['stock_qty'] );
		$this->assertSame( 'https://shop.example.test/p/1', $out['url'] );
	}

	public function test_description_combines_short_and_long_and_strips_tags() {
		$product = new Fake_WC_Product(
			array(
				'description'       => '<p>Long <b>body</b>.</p>',
				'short_description' => '<em>Teaser.</em>',
			)
		);
		$out = $this->serializer()->serialize( $product );

		$this->assertStringNotContainsString( '<', $out['description'] );
		$this->assertStringContainsString( 'Teaser.', $out['description'] );
		$this->assertStringContainsString( 'Long body.', $out['description'] );
	}

	public function test_variable_products_are_sent_as_configurable() {
		// The widget branches on product_type === 'configurable' to render the
		// variant picker. Sending Woo's own vocabulary would silently disable it.
		$this->assertSame( 'configurable', Idea89_Product_Serializer::map_product_type( 'variable' ) );
	}

	public function test_other_product_types_map_predictably() {
		$this->assertSame( 'simple', Idea89_Product_Serializer::map_product_type( 'simple' ) );
		$this->assertSame( 'simple', Idea89_Product_Serializer::map_product_type( '' ) );
		$this->assertSame( 'grouped', Idea89_Product_Serializer::map_product_type( 'grouped' ) );
		$this->assertSame( 'external', Idea89_Product_Serializer::map_product_type( 'external' ) );
	}

	public function test_strips_the_woo_attribute_prefix() {
		$this->assertSame( 'pa_colour', Idea89_Product_Serializer::strip_attribute_prefix( 'attribute_pa_colour' ) );
		$this->assertSame( 'Size', Idea89_Product_Serializer::strip_attribute_prefix( 'attribute_Size' ) );
		$this->assertSame( 'pa_colour', Idea89_Product_Serializer::strip_attribute_prefix( 'pa_colour' ) );
	}

	public function test_variants_carry_display_options_and_wire_attributes() {
		$product = new Fake_WC_Product(
			array(
				'type'                 => 'variable',
				'available_variations' => array(
					array(
						'variation_id' => 22,
						'sku'          => 'SKU-1-BLUE',
						'display_price' => 24.5,
						'is_in_stock'  => true,
						'attributes'   => array( 'attribute_pa_colour' => 'blue' ),
					),
				),
			)
		);
		$out = $this->serializer()->serialize( $product );

		$this->assertSame( 'configurable', $out['product_type'] );
		$this->assertCount( 1, $out['variants'] );

		$variant = $out['variants'][0];
		$this->assertSame( 'SKU-1-BLUE', $variant['sku'] );
		$this->assertTrue( $variant['in_stock'] );
		$this->assertSame( 24.5, $variant['price'] );
		// super_attributes is the WIRE form the widget reshapes into Woo's
		// Store API `variation` array — it MUST stay in Woo's raw
		// pa_colour => slug shape, never localised.
		$this->assertSame( array( 'pa_colour' => 'blue' ), $variant['super_attributes'] );
		// options is the DISPLAY form the variant picker renders: a human
		// label ("Colour", from the default wc_attribute_label() stub in
		// setUp()), not the raw taxonomy key.
		$this->assertSame( array( 'Colour' => 'blue' ), $variant['options'] );
	}

	/**
	 * Task 19, D2: the widget rendered the raw taxonomy slug as both the
	 * attribute label ("Pa_colour:") and the gated add-to-cart prompt
	 * ("Pick a pa_colour first") on every variable product. `options` must
	 * carry a human label (wc_attribute_label()) and, for taxonomy
	 * attributes, the term NAME rather than the slug
	 * get_available_variations() actually reports — while `super_attributes`
	 * keeps the exact raw pa_colour => clay shape the WooCommerce Store API
	 * expects, so add-to-cart is unaffected.
	 */
	public function test_options_carries_human_label_and_term_name_while_super_attributes_stays_raw() {
		Functions\when( 'wc_attribute_label' )->justReturn( 'Colour' );
		Functions\when( 'taxonomy_exists' )->alias(
			function ( $taxonomy ) {
				return 'pa_colour' === $taxonomy;
			}
		);
		Functions\when( 'get_term_by' )->alias(
			function ( $field, $value, $taxonomy ) {
				if ( 'slug' === $field && 'pa_colour' === $taxonomy && 'clay' === $value ) {
					return (object) array( 'name' => 'Clay' );
				}
				return false;
			}
		);

		$product = new Fake_WC_Product(
			array(
				'type'                 => 'variable',
				'available_variations' => array(
					array(
						'variation_id'   => 40,
						'sku'            => 'SKU-1-CLAY',
						'display_price'  => 36.0,
						'is_in_stock'    => true,
						'attributes'     => array( 'attribute_pa_colour' => 'clay' ),
					),
				),
			)
		);
		$out     = $this->serializer()->serialize( $product );
		$variant = $out['variants'][0];

		$this->assertSame( array( 'pa_colour' => 'clay' ), $variant['super_attributes'] );
		$this->assertSame( array( 'Colour' => 'Clay' ), $variant['options'] );
	}

	/**
	 * Custom (non-taxonomy) per-product attributes have no term to resolve —
	 * get_available_variations() already reports whatever the merchant typed
	 * as the value, so it must be forwarded unchanged.
	 */
	public function test_options_falls_back_to_the_raw_value_for_a_non_taxonomy_attribute() {
		Functions\when( 'wc_attribute_label' )->justReturn( 'Finish' );
		Functions\when( 'taxonomy_exists' )->justReturn( false );

		$product = new Fake_WC_Product(
			array(
				'type'                 => 'variable',
				'available_variations' => array(
					array(
						'variation_id'  => 41,
						'sku'           => 'SKU-1-MATTE',
						'display_price' => 12.0,
						'is_in_stock'   => true,
						'attributes'    => array( 'attribute_finish' => 'Matte' ),
					),
				),
			)
		);
		$out     = $this->serializer()->serialize( $product );
		$variant = $out['variants'][0];

		$this->assertSame( array( 'finish' => 'Matte' ), $variant['super_attributes'] );
		$this->assertSame( array( 'Finish' => 'Matte' ), $variant['options'] );
	}

	public function test_sale_fields() {
		$product = new Fake_WC_Product( array( 'on_sale' => true, 'sale_price' => '14.99' ) );
		$out     = $this->serializer()->serialize( $product );

		$this->assertTrue( $out['is_on_sale'] );
		$this->assertSame( 14.99, $out['sale_price'] );
	}

	public function test_absent_sale_price_is_null_not_zero() {
		$out = $this->serializer()->serialize( new Fake_WC_Product() );
		$this->assertNull( $out['sale_price'] );
		$this->assertFalse( $out['is_on_sale'] );
	}

	public function test_empty_price_becomes_null_rather_than_zero() {
		// A £0.00 product and a product with no price set are different things;
		// sending 0 would make free-item filters and price ranges wrong.
		$out = $this->serializer()->serialize( new Fake_WC_Product( array( 'price' => '' ) ) );
		$this->assertNull( $out['price'] );
	}

	public function test_category_names_are_lowercased_and_path_is_joined() {
		Functions\when( 'wp_get_post_terms' )->justReturn(
			array(
				(object) array( 'term_id' => 5, 'name' => 'Garden Tools', 'parent' => 0 ),
				(object) array( 'term_id' => 9, 'name' => 'Spades', 'parent' => 5 ),
			)
		);
		Functions\when( 'get_ancestors' )->alias(
			function ( $term_id ) {
				return 9 === $term_id ? array( 5 ) : array();
			}
		);
		$out = $this->serializer()->serialize( new Fake_WC_Product() );

		$this->assertSame( array( 'garden tools', 'spades' ), $out['category_names'] );
		$this->assertSame( 'Garden Tools > Spades', $out['category_path'] );
	}

	public function test_category_path_orders_parent_before_child_even_against_alphabetical_order() {
		// wp_get_post_terms() defaults to orderby=name, so the child "Apple" is
		// returned before its parent "Zoo Category". The path must still read
		// parent-first, proving the ordering comes from real ancestor depth and
		// not from names happening to alphabetise the right way round.
		Functions\when( 'wp_get_post_terms' )->justReturn(
			array(
				(object) array( 'term_id' => 2, 'name' => 'Apple', 'parent' => 1 ),
				(object) array( 'term_id' => 1, 'name' => 'Zoo Category', 'parent' => 0 ),
			)
		);
		Functions\when( 'get_ancestors' )->alias(
			function ( $term_id ) {
				return 2 === $term_id ? array( 1 ) : array();
			}
		);
		$out = $this->serializer()->serialize( new Fake_WC_Product() );

		$this->assertSame( 'Zoo Category > Apple', $out['category_path'] );
		$this->assertSame( array( 'apple', 'zoo category' ), $out['category_names'] );
	}

	public function test_ratings_and_review_count() {
		$out = $this->serializer()->serialize( new Fake_WC_Product() );
		$this->assertSame( 4.5, $out['avg_rating'] );
		$this->assertSame( 3, $out['review_count'] );
	}

	public function test_zero_rating_is_null_not_zero() {
		// Woo returns "0" for a product with no reviews. Sending 0 would tell the
		// assistant the product is rated one star out of five.
		$out = $this->serializer()->serialize(
			new Fake_WC_Product( array( 'average_rating' => '0', 'review_count' => 0 ) )
		);
		$this->assertNull( $out['avg_rating'] );
		$this->assertSame( 0, $out['review_count'] );
	}

	public function test_empty_variant_attributes_serialise_as_json_object_not_array() {
		// PHP encodes an empty array as JSON [] rather than {}; the API's
		// z.record() type rejects that, and one bad variation would fail the
		// whole upsert batch. A variation with no attribute map must still
		// wire up `"options":{}`, never `"options":[]`.
		$product = new Fake_WC_Product(
			array(
				'type'                 => 'variable',
				'available_variations' => array(
					array(
						'variation_id'  => 30,
						'sku'           => 'SKU-1-ANY',
						'display_price' => 9.99,
						'is_in_stock'   => true,
						'attributes'    => array(),
					),
				),
			)
		);
		$out  = $this->serializer()->serialize( $product );
		$json = wp_json_encode( $out );

		$this->assertStringContainsString( '"options":{}', $json );
		$this->assertStringNotContainsString( '"options":[]', $json );
		$this->assertStringContainsString( '"super_attributes":{}', $json );
		$this->assertStringNotContainsString( '"super_attributes":[]', $json );
	}

	public function test_variant_missing_attributes_key_also_serialises_as_object() {
		// Same as above, but the variation has no 'attributes' key at all
		// (rather than an explicit empty array) — the more common real-world
		// shape for a variation with no attached attributes.
		$product = new Fake_WC_Product(
			array(
				'type'                 => 'variable',
				'available_variations' => array(
					array(
						'variation_id'  => 31,
						'sku'           => 'SKU-1-NOATTR',
						'display_price' => 5.0,
						'is_in_stock'   => true,
					),
				),
			)
		);
		$out  = $this->serializer()->serialize( $product );
		$json = wp_json_encode( $out );

		$this->assertStringContainsString( '"options":{}', $json );
		$this->assertStringNotContainsString( '"options":[]', $json );
	}

	public function test_variant_price_is_null_when_absent_not_zero() {
		// Same null-vs-zero rule as the product-level price: a variation with
		// no display_price is unpriced, not free.
		$product = new Fake_WC_Product(
			array(
				'type'                 => 'variable',
				'available_variations' => array(
					array(
						'variation_id' => 32,
						'sku'          => 'SKU-1-NOPRICE',
						'is_in_stock'  => true,
						'attributes'   => array( 'attribute_pa_colour' => 'red' ),
					),
				),
			)
		);
		$out = $this->serializer()->serialize( $product );

		$this->assertNull( $out['variants'][0]['price'] );
	}

	// -----------------------------------------------------------------
	// Every field clamped to the API's Zod bounds. /v1/catalog/upsert
	// validates the whole batch in one pass, so any single out-of-bounds
	// value rejects all 100 products, not just its own.
	// -----------------------------------------------------------------

	public function test_a_blank_product_name_gets_a_generated_fallback() {
		// z.string().min(1) on `name`. CSV imports and programmatic creation
		// both produce products with no title, and one of them would 400 the
		// entire batch.
		$out = $this->serializer()->serialize( new Fake_WC_Product( array( 'name' => '   ' ) ) );

		$this->assertNotSame( '', trim( $out['name'] ) );
		$this->assertStringContainsString( '1', $out['name'] );
	}

	public function test_an_oversized_product_name_is_clamped_on_a_character_boundary() {
		$out = $this->serializer()->serialize(
			new Fake_WC_Product( array( 'name' => str_repeat( 'a', 499 ) . '££' ) )
		);

		$this->assertSame( 500, mb_strlen( $out['name'], 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $out['name'], 'UTF-8' ) );
	}

	public function test_review_snippets_are_clamped_without_breaking_utf8() {
		// The original byte-wise substr() is the bug that motivated all of
		// this: a cut through an accented character leaves invalid UTF-8,
		// wp_json_encode() returns false, and Idea89_Client::post() abandons
		// the request without an HTTP call — all 100 products, silently gone.
		Functions\when( 'get_comments' )->justReturn(
			array( (object) array( 'comment_content' => str_repeat( 'x', 499 ) . '£good' ) )
		);

		$out = $this->serializer()->serialize( new Fake_WC_Product() );

		$this->assertSame( 500, mb_strlen( $out['review_snippets'][0], 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $out['review_snippets'][0], 'UTF-8' ) );
		$this->assertNotFalse( wp_json_encode( $out ) );
	}

	public function test_category_names_are_clamped_to_sixty_four_characters() {
		Functions\when( 'wp_get_post_terms' )->justReturn(
			array( (object) array( 'term_id' => 1, 'name' => str_repeat( 'L', 90 ) ) )
		);

		$out = $this->serializer()->serialize( new Fake_WC_Product() );

		$this->assertSame( 64, mb_strlen( $out['category_names'][0], 'UTF-8' ) );
	}

	public function test_category_names_are_capped_at_twenty_entries() {
		$terms = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$terms[] = (object) array( 'term_id' => $i, 'name' => "Category $i" );
		}
		Functions\when( 'wp_get_post_terms' )->justReturn( $terms );

		$out = $this->serializer()->serialize( new Fake_WC_Product() );

		$this->assertCount( 20, $out['category_names'] );
	}

	public function test_an_empty_category_name_is_dropped() {
		// z.string().min(1) inside the array — one blank name 400s the batch.
		Functions\when( 'wp_get_post_terms' )->justReturn(
			array(
				(object) array( 'term_id' => 1, 'name' => '' ),
				(object) array( 'term_id' => 2, 'name' => 'Spades' ),
			)
		);

		$out = $this->serializer()->serialize( new Fake_WC_Product() );

		$this->assertSame( array( 'spades' ), $out['category_names'] );
	}

	public function test_variants_are_capped_at_two_hundred() {
		// Three attributes of ten options each is an ordinary WooCommerce
		// configuration and generates a thousand variations.
		$variations = array();
		for ( $i = 1; $i <= 300; $i++ ) {
			$variations[] = array(
				'variation_id'  => $i,
				'sku'           => "SKU-$i",
				'display_price' => 5.0,
				'is_in_stock'   => true,
				'attributes'    => array( 'attribute_pa_colour' => "c$i" ),
			);
		}
		$product = new Fake_WC_Product(
			array(
				'type'                 => 'variable',
				'available_variations' => $variations,
			)
		);

		$out = $this->serializer()->serialize( $product );

		$this->assertCount( 200, $out['variants'] );
	}

	public function test_a_protocol_relative_image_url_is_dropped_not_forwarded() {
		// CDN and image-offload plugins filter attachment URLs into this shape.
		// It is a non-empty string, so the old is_string() check forwarded it
		// and the API's .url() rejected the batch. The schema explicitly allows
		// "", so an unusable URL degrades instead of failing.
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '//cdn.example.test/img/1.jpg' );

		$out = $this->serializer()->serialize( new Fake_WC_Product() );

		$this->assertSame( '', $out['image_url'] );
	}

	public function test_a_protocol_relative_permalink_is_dropped_not_forwarded() {
		$out = $this->serializer()->serialize(
			new Fake_WC_Product( array( 'permalink' => '//cdn.example.test/p/1' ) )
		);

		$this->assertSame( '', $out['url'] );
	}

	public function test_a_false_permalink_becomes_an_empty_string() {
		// get_permalink() returns false on failure.
		$out = $this->serializer()->serialize( new Fake_WC_Product( array( 'permalink' => false ) ) );

		$this->assertSame( '', $out['url'] );
	}
}
