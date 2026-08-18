<?php
/**
 * Turns a WooCommerce product into the JSON shape POST /v1/catalog/upsert
 * expects. The contract is shared with the Magento modules and is defined by
 * the Zod schema in api/src/routes/catalog.ts.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serialises products for the catalog sync.
 */
class Idea89_Product_Serializer {

	const NEW_PRODUCT_DAYS    = 30;
	const MAX_REVIEW_SNIPPETS = 3;

	/**
	 * Caps that mirror the API's Zod schema in api/src/routes/catalog.ts.
	 *
	 * The batch is validated in one pass, so a single over-long field or
	 * over-full array 400s all 100 products in the request, not just its own.
	 * Clamping here means one odd product costs its own detail, never the batch.
	 */
	const MAX_REVIEW_SNIPPET_CHARS = 500;
	const MAX_DESCRIPTION          = 10000;
	const MAX_CATEGORY_NAME        = 64;
	const MAX_CATEGORY_NAMES       = 20;
	const MAX_VARIANTS             = 200;

	/**
	 * Maps a WooCommerce product type to the vocabulary the widget understands.
	 *
	 * 'variable' becomes 'configurable' deliberately: that is the existing
	 * string the widget checks before rendering its variant picker. Sending
	 * Woo's own word would silently disable variant selection.
	 *
	 * @param string $wc_type WooCommerce product type.
	 * @return string
	 */
	public static function map_product_type( $wc_type ) {
		switch ( (string) $wc_type ) {
			case 'variable':
				return 'configurable';
			case 'grouped':
				return 'grouped';
			case 'external':
			case 'affiliate':
				return 'external';
			case 'simple':
			default:
				return 'simple';
		}
	}

	/**
	 * Removes WooCommerce's `attribute_` prefix from a variation attribute key.
	 *
	 * @param string $key Raw key, e.g. attribute_pa_colour.
	 * @return string
	 */
	public static function strip_attribute_prefix( $key ) {
		$key = (string) $key;
		return 0 === strpos( $key, 'attribute_' ) ? substr( $key, strlen( 'attribute_' ) ) : $key;
	}

	/**
	 * Casts a Woo price string to a float, or null when it is not set.
	 *
	 * An unset price and a £0.00 price are different things; collapsing both to
	 * 0 would corrupt price ranges and free-item filters.
	 *
	 * @param mixed $value Raw price.
	 * @return float|null
	 */
	private function price_or_null( $value ) {
		if ( '' === $value || null === $value || false === $value ) {
			return null;
		}
		return (float) $value;
	}

	/**
	 * Serialises one product.
	 *
	 * Every string and array here is clamped to the API's Zod bounds before it
	 * leaves. The catalogue lane is the one place a single unusual product can
	 * take 99 healthy ones down with it, because /v1/catalog/upsert validates
	 * the whole batch in one pass and rejects all of it on the first failure.
	 *
	 * @param object $product A WC_Product (or a test double with the same getters).
	 * @return array
	 */
	public function serialize( $product ) {
		$product_id = (int) $product->get_id();

		$description = trim(
			wp_strip_all_tags( (string) $product->get_short_description() ) . ' ' .
			wp_strip_all_tags( (string) $product->get_description() )
		);
		$description = preg_replace( '/\s+/', ' ', $description );

		$categories = $this->categories( $product_id );
		$rating     = (float) $product->get_average_rating();

		return array(
			'external_id'     => (string) $product_id,
			'sku'             => (string) $product->get_sku(),
			// Through the same guard every other lane uses. The API requires a
			// non-empty name (1-500 chars) and a blank one is realistic — CSV
			// imports and programmatic creation both produce products with no
			// title — so it gets a generated fallback instead of 400ing all 100.
			'name'            => Idea89_Content_Syncer::safe_title( $product->get_name(), 'Product ' . $product_id ),
			'description'     => Idea89_Content_Syncer::truncate( $description, self::MAX_DESCRIPTION ),
			'price'           => $this->price_or_null( $product->get_price() ),
			'currency'        => get_woocommerce_currency(),
			'in_stock'        => (bool) $product->is_in_stock(),
			'stock_qty'       => null === $product->get_stock_quantity() ? null : (int) $product->get_stock_quantity(),
			// safe_url(), not a bare cast: get_permalink() returns false on
			// failure, and a CDN or image-offload plugin filtering these can hand
			// back a protocol-relative "//cdn.example/..." that fails the API's
			// .url(). Both schema fields explicitly allow "", so an unusable URL
			// degrades to empty rather than failing the batch.
			'url'             => (string) Idea89_Content_Syncer::safe_url( $product->get_permalink() ),
			'image_url'       => $this->image_url( $product ),
			'category_path'   => $categories['path'],
			'category_names'  => $categories['names'],
			'attributes'      => $this->attributes( $product ),
			'product_type'    => self::map_product_type( $product->get_type() ),
			'variants'        => $this->variants( $product ),
			'avg_rating'      => $rating > 0 ? $rating : null,
			'review_count'    => (int) $product->get_review_count(),
			'review_snippets' => $this->review_snippets( $product_id ),
			'is_featured'     => (bool) $product->is_featured(),
			'is_new'          => $this->is_new( $product ),
			'bestseller_rank' => null,
			'sale_price'      => $product->is_on_sale() ? $this->price_or_null( $product->get_sale_price() ) : null,
			'is_on_sale'      => (bool) $product->is_on_sale(),
		);
	}

	/**
	 * Primary image URL, or an empty string when the product has no image.
	 *
	 * @param object $product Product.
	 * @return string
	 */
	private function image_url( $product ) {
		$image_id = $product->get_image_id();
		if ( empty( $image_id ) ) {
			return '';
		}
		// safe_url() rather than a string check: image CDN and offload plugins
		// filter attachment URLs, and a protocol-relative "//cdn.example/x.jpg"
		// is a string but is not a URL the API's .url() will accept.
		return (string) Idea89_Content_Syncer::safe_url( wp_get_attachment_image_url( $image_id, 'large' ) );
	}

	/**
	 * Category names (lowercased) and a human-readable ancestry path.
	 *
	 * `wp_get_post_terms()` returns terms in `orderby=name` order by default,
	 * which is not category hierarchy order. The path must read parent before
	 * child, so terms are re-sorted by ancestor depth before the path is built;
	 * `names` is unaffected by that ordering.
	 *
	 * @param int $product_id Product ID.
	 * @return array{names: string[], path: string}
	 */
	private function categories( $product_id ) {
		$terms = wp_get_post_terms( $product_id, 'product_cat' );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return array(
				'names' => array(),
				'path'  => '',
			);
		}

		// The API bounds these: z.array(z.string().min(1).max(64)).max(20). A
		// deep or verbose taxonomy is ordinary — one 70-character category name
		// or a product filed under 25 categories would otherwise 400 the whole
		// batch. Empty names are dropped for the same reason (.min(1)).
		$names = array();
		foreach ( $terms as $term ) {
			if ( ! isset( $term->name ) ) {
				continue;
			}
			$name = Idea89_Content_Syncer::truncate( strtolower( $term->name ), self::MAX_CATEGORY_NAME );
			if ( '' === trim( $name ) ) {
				continue;
			}
			$names[] = $name;
		}

		// usort() is not guaranteed stable before PHP 8.0, so same-depth terms
		// (e.g. two top-level categories) are decorated with their original
		// index and compared on it as a tiebreaker, keeping the sort
		// deterministic on PHP 7.4.
		$indexed = array();
		foreach ( array_values( $terms ) as $index => $term ) {
			$indexed[] = array( $index, $term );
		}
		usort(
			$indexed,
			function ( $a, $b ) {
				$depth_a = isset( $a[1]->term_id ) ? count( get_ancestors( $a[1]->term_id, 'product_cat' ) ) : 0;
				$depth_b = isset( $b[1]->term_id ) ? count( get_ancestors( $b[1]->term_id, 'product_cat' ) ) : 0;
				return $depth_a === $depth_b ? $a[0] <=> $b[0] : $depth_a <=> $depth_b;
			}
		);

		$path = array();
		foreach ( $indexed as $item ) {
			$term = $item[1];
			if ( ! isset( $term->name ) ) {
				continue;
			}
			$path[] = $term->name;
		}

		return array(
			'names' => array_slice( array_values( array_unique( $names ) ), 0, self::MAX_CATEGORY_NAMES ),
			'path'  => implode( ' > ', $path ),
		);
	}

	/**
	 * Flattens product attributes to a name => value map.
	 *
	 * @param object $product Product.
	 * @return array<string, string>
	 */
	private function attributes( $product ) {
		$out        = array();
		$attributes = $product->get_attributes();
		if ( ! is_array( $attributes ) ) {
			return $out;
		}

		foreach ( $attributes as $name => $attribute ) {
			if ( is_object( $attribute ) && method_exists( $attribute, 'get_options' ) ) {
				$options               = $attribute->get_options();
				$out[ (string) $name ] = is_array( $options ) ? implode( ', ', $options ) : (string) $options;
			} elseif ( is_scalar( $attribute ) ) {
				$out[ (string) $name ] = (string) $attribute;
			}
		}

		return $out;
	}

	/**
	 * Builds the variants array for variable products.
	 *
	 * `options` is the DISPLAY form the widget's variant picker renders — human
	 * attribute labels (e.g. "Colour", not "pa_colour") and, for taxonomy
	 * attributes, the term name rather than its slug (e.g. "Clay", not
	 * "clay"). `super_attributes` is the WIRE form the widget reshapes into
	 * Woo's Store API `variation` array and MUST stay in Woo's raw
	 * `pa_colour => clay` shape — the Store API resolves the human display
	 * itself, so localising this one would double-translate or break the
	 * lookup entirely. The two are built from the same source data and the
	 * widget matches a selection purely against `options`, so keeping the
	 * label/value substitution confined to `options` cannot desync the two.
	 *
	 * @param object $product Product.
	 * @return array
	 */
	private function variants( $product ) {
		if ( 'variable' !== $product->get_type() ) {
			return array();
		}

		$variations = $product->get_available_variations();
		if ( ! is_array( $variations ) ) {
			return array();
		}

		$out = array();
		foreach ( $variations as $variation ) {
			$wire    = array();
			$display = array();
			if ( isset( $variation['attributes'] ) && is_array( $variation['attributes'] ) ) {
				foreach ( $variation['attributes'] as $key => $value ) {
					$attr_key  = self::strip_attribute_prefix( $key );
					$raw_value = (string) $value;

					$wire[ $attr_key ]                                        = $raw_value;
					$display[ $this->attribute_label( $attr_key, $product ) ] = $this->attribute_value_label( $attr_key, $raw_value );
				}
			}

			// PHP encodes an empty array as JSON [] rather than {}, which the
			// API's z.record() type rejects — and one bad variation would fail
			// the entire batch. Emit an empty object instead.
			$wire_out    = empty( $wire ) ? new stdClass() : $wire;
			$display_out = empty( $display ) ? new stdClass() : $display;

			$out[] = array(
				'sku'              => isset( $variation['sku'] ) ? (string) $variation['sku'] : '',
				'in_stock'         => ! empty( $variation['is_in_stock'] ),
				'price'            => $this->price_or_null( isset( $variation['display_price'] ) ? $variation['display_price'] : null ),
				'options'          => $display_out,
				'super_attributes' => $wire_out,
			);
		}

		// The API caps variants at 200. A product with three attributes of ten
		// options each generates a thousand variations, which is a normal
		// WooCommerce configuration and would otherwise 400 the whole batch.
		return array_slice( $out, 0, self::MAX_VARIANTS );
	}

	/**
	 * Human label for a variation attribute key, e.g. `pa_colour` -> `Colour`.
	 *
	 * WooCommerce's own wc_attribute_label() already covers both cases: a
	 * global (taxonomy) attribute's label comes from the Attributes admin
	 * screen; a custom per-product attribute's label is whatever the
	 * merchant typed on the product itself, which is why $product is passed
	 * through.
	 *
	 * @param string $attr_key Raw (unprefixed) attribute key.
	 * @param object $product  Parent product.
	 * @return string
	 */
	private function attribute_label( $attr_key, $product ) {
		if ( ! function_exists( 'wc_attribute_label' ) ) {
			return $attr_key;
		}
		$label = wc_attribute_label( $attr_key, $product );
		return ( is_string( $label ) && '' !== $label ) ? $label : $attr_key;
	}

	/**
	 * Human-readable value for a variation attribute.
	 *
	 * WooCommerce's get_available_variations() reports taxonomy attribute
	 * values (colour, size, ...) as term SLUGS — "clay", not "Clay". Custom
	 * (non-taxonomy) attribute values are stored as the merchant typed them
	 * and need no translation. This mirrors
	 * WC_Product_Variation::get_attribute()'s own slug -> term-name
	 * resolution so the two never disagree.
	 *
	 * @param string $attr_key Raw (unprefixed) attribute key, e.g. pa_colour.
	 * @param string $value    Raw stored value.
	 * @return string
	 */
	private function attribute_value_label( $attr_key, $value ) {
		if ( '' === $value || ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( $attr_key ) ) {
			return $value;
		}
		$term = function_exists( 'get_term_by' ) ? get_term_by( 'slug', $value, $attr_key ) : false;
		if ( $term && is_object( $term ) && isset( $term->name ) && ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $term ) ) ) {
			return (string) $term->name;
		}
		return $value;
	}

	/**
	 * Up to three recent approved review excerpts.
	 *
	 * @param int $product_id Product ID.
	 * @return string[]
	 */
	private function review_snippets( $product_id ) {
		$comments = get_comments(
			array(
				'post_id' => $product_id,
				'status'  => 'approve',
				'type'    => 'review',
				'number'  => self::MAX_REVIEW_SNIPPETS,
			)
		);
		if ( ! is_array( $comments ) ) {
			return array();
		}

		$out = array();
		foreach ( $comments as $comment ) {
			if ( empty( $comment->comment_content ) ) {
				continue;
			}
			// truncate(), not substr(): a byte cut through an accented character
			// in a review leaves invalid UTF-8, wp_json_encode() then returns
			// false, and Idea89_Client::post() drops the entire 100-product
			// request without making an HTTP call at all.
			$out[] = Idea89_Content_Syncer::truncate(
				wp_strip_all_tags( $comment->comment_content ),
				self::MAX_REVIEW_SNIPPET_CHARS
			);
		}
		return $out;
	}

	/**
	 * True when the product was published inside the recency window.
	 *
	 * @param object $product Product.
	 * @return bool
	 */
	private function is_new( $product ) {
		$created = $product->get_date_created();
		if ( empty( $created ) ) {
			return false;
		}
		$timestamp = is_object( $created ) && method_exists( $created, 'getTimestamp' )
			? $created->getTimestamp()
			: strtotime( (string) $created );

		if ( ! $timestamp ) {
			return false;
		}
		return ( time() - $timestamp ) < ( self::NEW_PRODUCT_DAYS * DAY_IN_SECONDS );
	}
}
