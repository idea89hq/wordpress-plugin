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
			'external_id'        => (string) $product_id,
			'sku'                => (string) $product->get_sku(),
			// Through the same guard every other lane uses. The API requires a
			// non-empty name (1-500 chars) and a blank one is realistic — CSV
			// imports and programmatic creation both produce products with no
			// title — so it gets a generated fallback instead of 400ing all 100.
			'name'               => Idea89_Content_Syncer::safe_title( $product->get_name(), 'Product ' . $product_id ),
			'description'        => Idea89_Content_Syncer::truncate( $description, self::MAX_DESCRIPTION ),
			'price'              => $this->price_or_null( $product->get_price() ),
			'currency'           => get_woocommerce_currency(),
			'in_stock'           => (bool) $product->is_in_stock(),
			'stock_qty'          => null === $product->get_stock_quantity() ? null : (int) $product->get_stock_quantity(),
			// safe_url(), not a bare cast: get_permalink() returns false on
			// failure, and a CDN or image-offload plugin filtering these can hand
			// back a protocol-relative "//cdn.example/..." that fails the API's
			// .url(). Both schema fields explicitly allow "", so an unusable URL
			// degrades to empty rather than failing the batch.
			'url'                => (string) Idea89_Content_Syncer::safe_url( $product->get_permalink() ),
			'image_url'          => $this->image_url( $product ),
			'category_path'      => $categories['path'],
			'category_names'     => $categories['names'],
			'attributes'         => $this->attributes( $product ),
			// Schema 2 (plugin 1.3.0): labels, types and flags, the price's tax
			// basis, the short description on its own and category paths. An
			// IDEA89 API that predates schema 2 ignores these keys.
			'attribute_list'     => $this->attribute_list( $product ),
			'short_description'  => $this->short_description( $product ),
			'price_includes_tax' => function_exists( 'wc_prices_include_tax' ) ? (bool) wc_prices_include_tax() : null,
			'tax_rate'           => $this->tax_rate( $product ),
			'category_paths'     => $categories['paths'],
			'product_type'       => self::map_product_type( $product->get_type() ),
			'variants'           => $this->variants( $product ),
			'avg_rating'         => $rating > 0 ? $rating : null,
			'review_count'       => (int) $product->get_review_count(),
			'review_snippets'    => $this->review_snippets( $product_id ),
			'is_featured'        => (bool) $product->is_featured(),
			'is_new'             => $this->is_new( $product ),
			'bestseller_rank'    => null,
			'sale_price'         => $product->is_on_sale() ? $this->price_or_null( $product->get_sale_price() ) : null,
			'is_on_sale'         => (bool) $product->is_on_sale(),
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
				'paths' => array(),
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

		// Schema 2: each category as its own path of names, root first.
		$paths = array();
		foreach ( $terms as $term ) {
			if ( ! isset( $term->name, $term->term_id ) ) {
				continue;
			}
			$chain = array();
			foreach ( array_reverse( (array) get_ancestors( $term->term_id, 'product_cat' ) ) as $ancestor_id ) {
				$ancestor = function_exists( 'get_term' ) ? get_term( $ancestor_id, 'product_cat' ) : null;
				if ( is_object( $ancestor ) && isset( $ancestor->name ) ) {
					$chain[] = $ancestor->name;
				}
			}
			$chain[] = $term->name;
			$paths[] = implode( ' > ', $chain );
		}

		return array(
			'names' => array_slice( array_values( array_unique( $names ) ), 0, self::MAX_CATEGORY_NAMES ),
			'path'  => implode( ' > ', $path ),
			'paths' => array_slice( array_values( array_unique( $paths ) ), 0, 50 ),
		);
	}

	/**
	 * Flattens product attributes to a name => value map.
	 *
	 * A global (taxonomy, `pa_*`) attribute's get_options() holds TERM IDS,
	 * not names: sending them gave the assistant "12, 15" for a colour. Their
	 * values are the term names, read with wc_get_product_terms(). A local
	 * attribute's options are the text the merchant typed.
	 *
	 * @param object $product Product.
	 * @return array<string, string>
	 */
	private function attributes( $product ) {
		$out = array();
		foreach ( $this->attribute_values( $product ) as $name => $entry ) {
			$out[ $name ] = implode( ', ', $entry['values'] );
		}
		return $out;
	}

	/**
	 * Each product attribute with its display values, keyed by attribute name.
	 *
	 * @param object $product Product.
	 * @return array<string, array{attribute: object|null, values: string[]}>
	 */
	private function attribute_values( $product ) {
		$out        = array();
		$attributes = $product->get_attributes();
		if ( ! is_array( $attributes ) ) {
			return $out;
		}

		foreach ( $attributes as $name => $attribute ) {
			if ( is_object( $attribute ) && method_exists( $attribute, 'get_options' ) ) {
				$taxonomy = method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy();
				if ( $taxonomy && function_exists( 'wc_get_product_terms' ) ) {
					$terms  = wc_get_product_terms( (int) $product->get_id(), (string) $attribute->get_name(), array( 'fields' => 'names' ) );
					$values = is_array( $terms ) ? $terms : array();
				} else {
					$options = $attribute->get_options();
					$values  = is_array( $options ) ? $options : array( $options );
				}
				$values = array_values(
					array_filter(
						array_map( 'strval', $values ),
						static function ( $v ) {
							return '' !== trim( $v );
						}
					)
				);
				if ( ! empty( $values ) ) {
					$out[ (string) $name ] = array(
						'attribute' => $attribute,
						'values'    => $values,
					);
				}
			} elseif ( is_scalar( $attribute ) && '' !== (string) $attribute ) {
				$out[ (string) $name ] = array(
					'attribute' => null,
					'values'    => array( (string) $attribute ),
				);
			}
		}

		return $out;
	}

	/**
	 * Schema-2 attribute list: every product attribute with its label (as the
	 * Attributes screen or the product names it), its values as term names
	 * or the text typed, a type, and its flags: "Visible on the product page"
	 * and, for a global attribute, filterable (global attributes are what the
	 * layered-navigation filters use). The product's weight and dimensions
	 * are added with the store's units when set.
	 *
	 * @param object $product Product.
	 * @return array<int, array<string, mixed>>
	 */
	private function attribute_list( $product ) {
		$out = array();
		foreach ( $this->attribute_values( $product ) as $name => $entry ) {
			$attribute = $entry['attribute'];
			$taxonomy  = is_object( $attribute ) && method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy();
			$values    = $entry['values'];
			$out[]     = array(
				'code'       => (string) $name,
				'label'      => $this->attribute_label( is_object( $attribute ) && method_exists( $attribute, 'get_name' ) ? (string) $attribute->get_name() : (string) $name, $product ),
				'value'      => count( $values ) > 1 ? $values : $values[0],
				'type'       => count( $values ) > 1 ? 'multiselect' : ( $taxonomy ? 'select' : 'text' ),
				'filterable' => $taxonomy,
				'searchable' => null,
				'visible'    => is_object( $attribute ) && method_exists( $attribute, 'get_visible' ) ? (bool) $attribute->get_visible() : true,
			);
		}
		foreach ( $this->measures( $product ) as $measure ) {
			$out[] = $measure;
		}
		return $out;
	}

	/**
	 * Weight and dimensions with the store's units, as attributes.
	 *
	 * @param object $product Product or variation.
	 * @return array<int, array<string, mixed>>
	 */
	private function measures( $product ) {
		$out    = array();
		$weight = method_exists( $product, 'get_weight' ) ? (string) $product->get_weight() : '';
		if ( '' !== $weight && is_numeric( $weight ) ) {
			$unit  = (string) get_option( 'woocommerce_weight_unit', '' );
			$out[] = array(
				'code'       => 'weight',
				'label'      => 'Weight',
				'value'      => $weight,
				'type'       => 'number',
				'unit'       => '' !== $unit ? $unit : null,
				'filterable' => false,
				'searchable' => null,
				'visible'    => true,
			);
		}
		foreach ( array(
			'length' => 'Length',
			'width'  => 'Width',
			'height' => 'Height',
		) as $key => $label ) {
			$getter = 'get_' . $key;
			$value  = method_exists( $product, $getter ) ? (string) $product->$getter() : '';
			if ( '' !== $value && is_numeric( $value ) ) {
				$unit  = (string) get_option( 'woocommerce_dimension_unit', '' );
				$out[] = array(
					'code'       => $key,
					'label'      => $label,
					'value'      => $value,
					'type'       => 'number',
					'unit'       => '' !== $unit ? $unit : null,
					'filterable' => false,
					'searchable' => null,
					'visible'    => true,
				);
			}
		}
		return $out;
	}

	/**
	 * The short description on its own, tags stripped, or null.
	 *
	 * @param object $product Product.
	 * @return string|null
	 */
	private function short_description( $product ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $product->get_short_description() ) ) );
		return '' === $text ? null : Idea89_Content_Syncer::truncate( $text, 5000 );
	}

	/**
	 * The tax rate (percent) of the product's tax class at the shop's base
	 * location, or null when WooCommerce cannot say.
	 *
	 * @param object $product Product.
	 * @return float|null
	 */
	private function tax_rate( $product ) {
		if ( ! class_exists( 'WC_Tax' ) || ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
			return null;
		}
		$class = method_exists( $product, 'get_tax_class' ) ? (string) $product->get_tax_class() : '';
		$rates = WC_Tax::get_base_tax_rates( $class );
		if ( ! is_array( $rates ) ) {
			return null;
		}
		$total = 0.0;
		foreach ( $rates as $rate ) {
			$total += isset( $rate['rate'] ) ? (float) $rate['rate'] : 0.0;
		}
		return $total;
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

			// The variation's own product, for its stock quantity and its
			// price on the same tax basis as the parent's (display_price
			// follows the shop's tax DISPLAY setting, the parent's price does not).
			$variation_product = isset( $variation['variation_id'] ) && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $variation['variation_id'] ) : false;
			$raw_price         = is_object( $variation_product ) && method_exists( $variation_product, 'get_price' ) ? $variation_product->get_price() : null;
			$item              = array(
				'sku'              => isset( $variation['sku'] ) ? (string) $variation['sku'] : '',
				'in_stock'         => ! empty( $variation['is_in_stock'] ),
				'price'            => $this->price_or_null( null !== $raw_price && '' !== $raw_price ? $raw_price : ( isset( $variation['display_price'] ) ? $variation['display_price'] : null ) ),
				'options'          => $display_out,
				'super_attributes' => $wire_out,
			);
			if ( is_object( $variation_product ) ) {
				$qty               = method_exists( $variation_product, 'get_stock_quantity' ) ? $variation_product->get_stock_quantity() : null;
				$item['stock_qty'] = null === $qty ? null : (int) $qty;
				if ( method_exists( $variation_product, 'get_name' ) ) {
					$item['name'] = (string) $variation_product->get_name();
				}
				// The variation's own values: its options, and weight or
				// dimensions where it sets its own.
				$own = array();
				foreach ( (array) $display as $label => $value ) {
					if ( '' !== (string) $value ) {
						$own[] = array(
							'code'       => sanitize_title( (string) $label ),
							'label'      => (string) $label,
							'value'      => (string) $value,
							'type'       => 'select',
							'filterable' => null,
							'searchable' => null,
							'visible'    => true,
						);
					}
				}
				foreach ( $this->measures( $variation_product ) as $measure ) {
					$own[] = $measure;
				}
				if ( ! empty( $own ) ) {
					$item['attribute_list'] = $own;
				}
			}
			$out[] = $item;
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
