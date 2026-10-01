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
 * ruling. Not same-origin gated, same as Magento's own feed route,
 * because the caller is never the merchant's own storefront. Optionally
 * bearer-gated instead, mirroring magento-module/Model/Acp/Auth.php: when
 * the merchant sets the idea89_acp_secret option, a caller must send
 * `Authorization: Bearer <secret>` (compared with hash_equals) or gets a
 * 401; left blank, the feed stays public, which is what every integration
 * built before the secret existed relies on. An `API-Version` header, when
 * sent, must name a version this feed has been verified against (400
 * otherwise); absent means "current".
 *
 * GATED on the idea89_acp_enabled option, defaulting to FALSE. Magento's
 * equivalent (Model/CheckoutConfig::isAcpEnabled(), XML_ACP_ENABLED)
 * defaults to 0 under an explicit comment: this publishes catalog data to
 * third parties, and that is never a default a merchant should discover
 * after the fact. Route registration itself is what is gated (see
 * is_enabled()/register_routes() below), not merely check_permission() —
 * an unregistered route means the catalogue query is never reachable at
 * all while the feed is off, not just unauthenticated-but-callable. Once
 * on, the query is paged in the database (at most MAX_PAGE_SIZE products
 * loaded per request), never the whole catalogue.
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
 * "out_of_stock", never vanish). gated_page() below reads
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
	 * API-Version header values this feed accepts. Same list as
	 * magento-module/Model/Acp/Auth.php::SUPPORTED_API_VERSIONS.
	 */
	const SUPPORTED_API_VERSIONS = array( self::API_VERSION );

	/**
	 * Per-IP ceiling for the feed. Each request can load up to
	 * MAX_PAGE_SIZE products, so an unthrottled caller can still make the
	 * store do real work in a loop; 60 a minute is far above what a crawler
	 * walking the pages needs. Checked before the bearer secret, so the
	 * same ceiling also throttles guessing at it.
	 */
	const RATE_LIMIT_MAX    = 60;
	const RATE_LIMIT_WINDOW = MINUTE_IN_SECONDS;
	const RATE_LIMIT_PREFIX = 'idea89_acpf_';

	/**
	 * Per-IP throttle.
	 *
	 * @var Idea89_Guest_Rate_Limit
	 */
	private $rate_limit;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Guest_Rate_Limit|null $rate_limit Per-IP throttle. Built with the
	 *                                                 class defaults when omitted.
	 */
	public function __construct( ?Idea89_Guest_Rate_Limit $rate_limit = null ) {
		$this->rate_limit = null !== $rate_limit ? $rate_limit : new Idea89_Guest_Rate_Limit(
			self::RATE_LIMIT_MAX,
			self::RATE_LIMIT_WINDOW,
			self::RATE_LIMIT_PREFIX
		);
	}

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
	 * gated_page()'s wc_get_products() query is structurally unreachable
	 * while the feed is off, not merely unauthenticated.
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
	 * The optional shared bearer secret, or '' when the merchant left it
	 * blank (meaning: serve the feed publicly).
	 *
	 * @return string
	 */
	public static function secret() {
		return trim( (string) get_option( 'idea89_acp_secret', '' ) );
	}

	/**
	 * Open at the WordPress level on purpose. The ACP gates (enabled flag,
	 * bearer secret, API-Version pin) run in handle_feed() via authorize()
	 * instead, so their refusals carry the same `{error: code}` body the
	 * Magento feed returns rather than WordPress's own rest_forbidden
	 * shape. A named, documented callable rather than the literal
	 * '__return_true' string `wp plugin check` flags.
	 *
	 * @return true
	 */
	public function check_permission() {
		return true;
	}

	/**
	 * The ACP gate, in Magento's Auth::check() order: not_enabled first (the
	 * secret is never even read on a disabled store), then the bearer
	 * secret, then the API-Version pin. Returns the error code, or null when
	 * the request may proceed.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return string|null
	 */
	public function authorize( WP_REST_Request $request ) {
		if ( ! self::is_enabled() ) {
			// Defence in depth: register_routes() already skips the route
			// while the feed is off, so this is only reached if the option
			// changed after routes were registered for this request.
			return 'not_enabled';
		}

		$secret = self::secret();
		if ( '' !== $secret ) {
			$header = (string) $request->get_header( 'authorization' );
			$token  = 0 === strpos( $header, 'Bearer ' ) ? substr( $header, 7 ) : '';
			// hash_equals(), never ===: a shared secret must not leak timing
			// proportional to the matching prefix. An empty token can never
			// match because $secret is non-empty here.
			if ( ! hash_equals( $secret, $token ) ) {
				return 'unauthorized';
			}
		}

		$version = (string) $request->get_header( 'api-version' );
		if ( '' !== $version && ! in_array( $version, self::SUPPORTED_API_VERSIONS, true ) ) {
			return 'unsupported_api_version';
		}

		return null;
	}

	/**
	 * Serves one page of the feed.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_feed( WP_REST_Request $request ) {
		if ( ! $this->rate_limit->allow( $this->client_ip() ) ) {
			return $this->error_response( 'rate_limited', 429 );
		}

		$error = $this->authorize( $request );
		if ( null !== $error ) {
			return $this->error_response( $error, self::status_for( $error ) );
		}

		$page      = (int) $request->get_param( 'page' );
		$page_size = (int) $request->get_param( 'page_size' );
		if ( $page_size <= 0 ) {
			$page_size = self::DEFAULT_PAGE_SIZE;
		}

		$body = $this->build( $page, $page_size );

		$response = new WP_REST_Response( $body, 200 );
		// A public feed is the same document for every caller, so cacheable.
		// A bearer-gated one must not be: a shared cache would hand the
		// authorised body to the next, unauthenticated, requester.
		$response->header( 'Cache-Control', '' === self::secret() ? 'public, max-age=900' : 'private, no-store' );
		return $response;
	}

	/**
	 * Best-effort client IP for throttling only — same shape as
	 * Idea89_Order_Endpoints::client_ip().
	 *
	 * @return string
	 */
	private function client_ip() {
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '0.0.0.0';
	}

	/**
	 * HTTP status for an authorize() error code, same mapping as
	 * magento-module/Controller/Acp/Feed.php::statusFor().
	 *
	 * @param string $error Error code.
	 * @return int
	 */
	private static function status_for( $error ) {
		switch ( $error ) {
			case 'not_enabled':
				return 404;
			case 'unauthorized':
				return 401;
			default:
				return 400;
		}
	}

	/**
	 * An `{error: code}` refusal, never cacheable: a cached 404 or 401 would
	 * outlive the merchant switching the feed on or handing out the token.
	 *
	 * @param string $error  Error code.
	 * @param int    $status HTTP status.
	 * @return WP_REST_Response
	 */
	private function error_response( $error, $status ) {
		$response = new WP_REST_Response( array( 'error' => $error ), $status );
		$response->header( 'Cache-Control', 'no-store' );
		if ( 401 === $status ) {
			$response->header( 'WWW-Authenticate', 'Bearer' );
		}
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

		$gated = $this->gated_page( $page, $page_size );

		$products = array();
		foreach ( $gated['items'] as $item ) {
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
			'total'     => $gated['total'],
			'products'  => $products,
		);
	}

	/**
	 * One database page of published products, filtered to the
	 * catalogue-visible, price-resolvable ones, each paired with its
	 * already-resolved price so build() never recomputes it. See the class
	 * docblock for why catalogue visibility is read from
	 * get_catalog_visibility() rather than a product_visibility tax_query.
	 *
	 * Paged in the query (limit/page/paginate), so a request loads at most
	 * MAX_PAGE_SIZE products whatever the catalogue size. The visibility and
	 * price gates still run in PHP on that page, so a page can hold fewer
	 * than page_size products, and `total` is the database count of
	 * published products minus those skipped on THIS page: the same
	 * approach as magento-module's FeedBuilder, exact whenever nothing is
	 * skipped, otherwise an upper bound. Pages are fixed database windows,
	 * so walking page 1..N never skips or repeats a product.
	 *
	 * @param int $page      Page, already clamped to >= 1.
	 * @param int $page_size Page size, already clamped to 1..MAX_PAGE_SIZE.
	 * @return array{items: array<int, array{product: object, price: float}>, total: int}
	 */
	private function gated_page( $page, $page_size ) {
		$empty = array(
			'items' => array(),
			'total' => 0,
		);

		if ( ! function_exists( 'wc_get_products' ) ) {
			return $empty;
		}

		$result = wc_get_products(
			array(
				'status'   => 'publish',
				'limit'    => $page_size,
				'page'     => $page,
				'paginate' => true,
				'orderby'  => 'ID',
				'order'    => 'ASC',
			)
		);

		if ( ! is_object( $result ) || ! isset( $result->products ) || ! is_array( $result->products ) ) {
			return $empty;
		}

		$total   = isset( $result->total ) ? (int) $result->total : 0;
		$skipped = 0;
		$out     = array();
		foreach ( $result->products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_catalog_visibility' ) ) {
				++$skipped;
				continue;
			}

			if ( 'hidden' === $product->get_catalog_visibility() ) {
				++$skipped;
				continue;
			}

			$price = $this->resolved_price( $product );
			if ( null === $price ) {
				$this->log( 'omitting product with no resolvable price', $product );
				++$skipped;
				continue;
			}

			$out[] = array(
				'product' => $product,
				'price'   => $price,
			);
		}

		return array(
			'items' => $out,
			'total' => max( 0, $total - $skipped ),
		);
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
