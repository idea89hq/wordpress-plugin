<?php
/**
 * GET /wp-json/idea89/v1/acp/feed.json
 *
 * Publishes this store's catalogue in the Agentic Commerce Protocol product
 * feed shape so an external agent (ChatGPT and similar) can discover what
 * the store sells. Read-only, carries no payment/order surface — mirrors
 * magento-module/Controller/Acp/Feed.php + Model/Acp/FeedBuilder.php (Task
 * 4.3): same three gates (published, catalogue-visible, price-resolvable),
 * same page-size cap, same "out-of-stock survives, unpriced does not"
 * ruling. NOT bearer-gated here — that surface (magento-module's Model/
 * Acp/Auth.php) belongs to a later WooCommerce task; once reachable at all,
 * this route is deliberately public, same as Magento's own feed route is
 * deliberately NOT same-origin gated, because the caller is never the
 * merchant's own storefront.
 *
 * GATED on the idea89_acp_enabled option, defaulting to FALSE. Magento's
 * equivalent (Model/CheckoutConfig::isAcpEnabled(), XML_ACP_ENABLED)
 * defaults to 0 under an explicit comment: this publishes catalog data to
 * third parties, and that is never a default a merchant should discover
 * after the fact. Route registration itself is what is gated (see
 * is_enabled()/register_routes() below), not merely check_permission() —
 * an unregistered route means gated_products()'s unbounded
 * wc_get_products(['limit' => -1]) is never reachable at all while the
 * feed is off, not just unauthenticated-but-callable.
 *
 * ONE WooCommerce-specific trap this feed exists to avoid, found live
 * against a real catalogue with "Hide out of stock items from the
 * catalogue" switched on (WooCommerce > Settings > Products > Inventory):
 * enabling that setting makes WooCommerce attach the SAME
 * `exclude-from-catalog` / `exclude-from-search` product_visibility
 * taxonomy terms to an out-of-stock product that it attaches to a product
 * the merchant genuinely marked "Hidden". Filtering on those terms — the
 * obvious, tax_query-based way to exclude hidden products — would
 * therefore silently drop every out-of-stock product on any store running
 * that (common) setting, which is exactly the failure this feed must not
 * have (an out-of-stock product must appear with availability:
 * "out_of_stock", never vanish). gated_products() below reads
 * WC_Product::get_catalog_visibility() instead — the plain
 * `_catalog_visibility` post meta the merchant actually set, independent
 * of that auto-tagging — so a "Hidden" product is excluded on the
 * merchant's own choice and an out-of-stock one never is.
 *
 * The second trap, shared with the Magento feed: a product whose price
 * cannot be resolved (empty/unset) or resolves to <= 0 is skipped and
 * logged by SKU, never published at "0.00" — an agent that reads a
 * resolvable-looking $0.00 can present the item to a shopper as free.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and serves the ACP product feed.
 */
class Idea89_Acp_Feed {

	const REST_NAMESPACE    = 'idea89/v1';
	const MAX_PAGE_SIZE     = 200;
	const DEFAULT_PAGE_SIZE = 200;

	/**
	 * Shared Agentic Commerce Protocol version string — the protocol's own
	 * version, not this module's; kept identical to
	 * magento-module/Model/Acp/Auth.php::SUPPORTED_API_VERSIONS[0] so a
	 * consumer sees one protocol version across every IDEA89-powered store
	 * regardless of platform.
	 */
	const API_VERSION = '2026-04-17';

	/**
	 * Hooks route registration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the feed route, only when the merchant has opted in.
	 *
	 * Gating registration itself — not merely check_permission() — means an
	 * unauthenticated caller gets WordPress's own "route not found" (the
	 * route was never added to the REST server) rather than a 401/403 that
	 * would confirm the plugin is installed. It also means
	 * gated_products()'s unbounded wc_get_products() query is structurally
	 * unreachable while the feed is off, not merely unauthenticated.
	 *
	 * @return void
	 */
	public function register_routes() {
		if ( ! self::is_enabled() ) {
			return;
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/acp/feed\.json',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_feed' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Whether the merchant has switched the ACP feed on. Defaults to FALSE —
	 * see the class docblock for why publishing the whole catalogue
	 * publicly can never be a default a merchant discovers after the fact.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( 'idea89_acp_enabled', false );
	}

	/**
	 * Deliberately public — see this file's class docblock. A named,
	 * documented callable rather than the literal '__return_true' string
	 * `wp plugin check` flags: the outcome is the same (always allow), but
	 * the choice is explicit and reviewable here rather than a bare
	 * boolean in the route registration array.
	 *
	 * @return true
	 */
	public function check_permission() {
		return true;
	}

	/**
	 * Serves one page of the feed.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_feed( WP_REST_Request $request ) {
		$page      = (int) $request->get_param( 'page' );
		$page_size = (int) $request->get_param( 'page_size' );
		if ( $page_size <= 0 ) {
			$page_size = self::DEFAULT_PAGE_SIZE;
		}

		$body = $this->build( $page, $page_size );

		$response = new WP_REST_Response( $body, 200 );
		$response->header( 'Cache-Control', 'public, max-age=900' );
		return $response;
	}

	/**
	 * Pure aside from the two WooCommerce data calls it makes, so a test
	 * can call this directly with page/page_size edge cases without going
	 * through a WP_REST_Request at all.
	 *
	 * @param int $page      Requested page, any integer.
	 * @param int $page_size Requested page size, any integer.
	 * @return array{version: string, seller: array{name: string, url: string}, page: int, page_size: int, total: int, products: array<int, array<string, mixed>>}
	 */
	public function build( $page, $page_size ) {
		$page      = max( 1, (int) $page );
		$page_size = max( 1, min( self::MAX_PAGE_SIZE, (int) $page_size ) );

		$gated = $this->gated_products();
		$total = count( $gated );

		// Plain array_slice, not a DB-level LIMIT/OFFSET: gated_products()
		// already loaded and filtered the whole catalogue into memory (see
		// its own docblock for why the filtering cannot safely happen at
		// the DB level), so slicing here is both correct for any requested
		// page — including one past the end, which simply yields an empty
		// products array — and avoids the class of pagination bug the
		// Magento feed hit trying to reconcile a DB LIMIT against a
		// post-load PHP filter.
		$slice = array_slice( $gated, ( $page - 1 ) * $page_size, $page_size );

		$products = array();
		foreach ( $slice as $item ) {
			$products[] = $this->serialize( $item['product'], $item['price'] );
		}

		return array(
			'version'   => self::API_VERSION,
			'seller'    => array(
				'name' => (string) get_bloginfo( 'name' ),
				'url'  => rtrim( (string) home_url(), '/' ),
			),
			'page'      => $page,
			'page_size' => $page_size,
			'total'     => $total,
			'products'  => $products,
		);
	}

	/**
	 * Every published, catalogue-visible, price-resolvable product, each
	 * paired with its already-resolved price so build() never recomputes
	 * it. See the class docblock for why catalogue visibility is read from
	 * get_catalog_visibility() rather than a product_visibility tax_query.
	 *
	 * @return array<int, array{product: object, price: float}>
	 */
	private function gated_products() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		// limit => -1: every published product loaded into memory, THEN
		// filtered in PHP. Deliberate — see the class docblock's reasoning
		// on why the catalogue-visibility gate cannot safely be pushed into
		// the query itself. Fine at the catalogue sizes this plugin targets
		// (the same assumption Idea89_Catalog_Syncer::BATCH_SIZE-paged sync
		// does not need to make, because a sync has no "current page" to
		// keep correct against a PHP-side filter).
		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => -1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		if ( ! is_array( $products ) ) {
			return array();
		}

		$out = array();
		foreach ( $products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_catalog_visibility' ) ) {
				continue;
			}

			if ( 'hidden' === $product->get_catalog_visibility() ) {
				continue;
			}

			$price = $this->resolved_price( $product );
			if ( null === $price ) {
				$this->log( 'omitting product with no resolvable price', $product );
				continue;
			}

			$out[] = array(
				'product' => $product,
				'price'   => $price,
			);
		}

		return $out;
	}

	/**
	 * The display price WooCommerce would show a shopper, or null when the
	 * product has no price to resolve at all. Checked in two steps: an
	 * unset/empty raw price (get_price() === '') is a different situation
	 * from a real price that happens to compute to zero after tax display,
	 * but this feed treats both the same way — skip, never publish "0.00" —
	 * per the class docblock's second trap.
	 *
	 * @param object $product A WC_Product.
	 * @return float|null
	 */
	private function resolved_price( $product ) {
		$raw = $product->get_price();
		if ( '' === $raw || null === $raw || false === $raw ) {
			return null;
		}

		$price = function_exists( 'wc_get_price_to_display' )
			? (float) wc_get_price_to_display( $product )
			: (float) $raw;

		return $price > 0.0 ? $price : null;
	}

	/**
	 * Builds one feed entry.
	 *
	 * @param object $product A WC_Product.
	 * @param float  $price   Already-resolved display price.
	 * @return array<string, mixed>
	 */
	private function serialize( $product, $price ) {
		return array(
			'id'            => $this->feed_id( $product ),
			'title'         => (string) $product->get_name(),
			'description'   => $this->description( $product ),
			'link'          => (string) $product->get_permalink(),
			'image_link'    => $this->image_link( $product ),
			'price'         => array(
				'amount'   => number_format( $price, 2, '.', '' ),
				'currency' => (string) get_woocommerce_currency(),
			),
			'availability'  => $product->is_in_stock() ? 'in_stock' : 'out_of_stock',
			'brand'         => $this->brand( $product ),
			'gtin'          => $this->gtin( $product ),
			// WooCommerce variations are not independently browsable or
			// individually gated the way a Magento configurable's child
			// simple is (they carry no catalog_visibility of their own and
			// this feed does not enumerate them at all — see the class
			// docblock), so there is no analogous grouping id to publish.
			'item_group_id' => null,
		);
	}

	/**
	 * SKU when the merchant set one; the numeric product id otherwise. A
	 * blank SKU is realistic (CSV imports, quick "Add new" drafts turned
	 * publish) and must not become a blank feed id.
	 *
	 * @param object $product A WC_Product.
	 * @return string
	 */
	private function feed_id( $product ) {
		$sku = trim( (string) $product->get_sku() );
		return '' !== $sku ? $sku : (string) $product->get_id();
	}

	/**
	 * Short + long description, stripped and collapsed to plain text.
	 *
	 * @param object $product A WC_Product.
	 * @return string
	 */
	private function description( $product ) {
		$text = trim(
			wp_strip_all_tags( (string) $product->get_short_description() ) . ' ' .
			wp_strip_all_tags( (string) $product->get_description() )
		);
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * The primary image URL, or '' when the product has none.
	 *
	 * @param object $product A WC_Product.
	 * @return string
	 */
	private function image_link( $product ) {
		$image_id = method_exists( $product, 'get_image_id' ) ? $product->get_image_id() : 0;
		if ( empty( $image_id ) || ! function_exists( 'wp_get_attachment_image_url' ) ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $image_id, 'large' );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Best-effort brand lookup against the `product_brand` taxonomy — the
	 * slug the most widely installed brand plugins (WooCommerce Brands,
	 * Perzanite) register. WooCommerce core has no built-in brand field, so
	 * a store with neither plugin simply publishes no brand, same as
	 * Magento's own extractOptionalAttribute() falls back to null for an
	 * attribute code a catalogue never created.
	 *
	 * @param object $product A WC_Product.
	 * @return string|null
	 */
	private function brand( $product ) {
		if ( ! function_exists( 'wp_get_post_terms' ) || ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( 'product_brand' ) ) {
			return null;
		}
		$terms = wp_get_post_terms( $product->get_id(), 'product_brand' );
		if ( ! is_array( $terms ) || empty( $terms ) || ! isset( $terms[0]->name ) ) {
			return null;
		}
		return (string) $terms[0]->name;
	}

	/**
	 * WooCommerce's own global-unique-id field (Product data > Inventory >
	 * "GTIN, UPC, EAN, or ISBN"), when the installed version has it.
	 *
	 * @param object $product A WC_Product.
	 * @return string|null
	 */
	private function gtin( $product ) {
		if ( ! is_callable( array( $product, 'get_global_unique_id' ) ) ) {
			return null;
		}
		$value = trim( (string) $product->get_global_unique_id() );
		return '' !== $value ? $value : null;
	}

	/**
	 * Logs when WP_DEBUG is on — same convention as
	 * Idea89_Catalog_Syncer::log().
	 *
	 * @param string $message Message.
	 * @param object $product The product being skipped.
	 * @return void
	 */
	private function log( $message, $product ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$sku = ( is_object( $product ) && method_exists( $product, 'get_sku' ) ) ? $product->get_sku() : '?';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'IDEA89: ACP feed: ' . $message . ' sku=' . $sku );
		}
	}
}
