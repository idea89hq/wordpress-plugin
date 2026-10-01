<?php
/**
 * Renders WooCommerce's own checkout with the site chrome stripped, for the
 * chat widget's assistant panel to frame.
 *
 * GET /?idea89-checkout=1 serves the merchant's REAL checkout — the same
 * page WooCommerce would otherwise render at wc_get_checkout_url(), same
 * payment gateways, same shipping rules, same third-party extensions — with
 * no theme header, footer or navigation around it. The chat widget frames
 * this same-origin, so the shopper keeps their session and their cart.
 *
 * IDEA89 renders nothing payment-related here and never receives card data.
 * The merchant's PCI posture is unchanged from a normal checkout visit.
 *
 * WooCommerce splits between two completely different checkout renderers —
 * the classic `[woocommerce_checkout]` shortcode and the newer Checkout
 * block — and there is no reliable way to assume which one a given store
 * uses. detect_checkout_type() probes the actual checkout page's content
 * for a block or the shortcode before rendering anything; see its docblock.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Query-var handler, checkout-type probe, and thank-you-page success hook.
 */
class Idea89_Mini_Checkout {

	/** GET /?idea89-checkout=1 is the framed checkout entry point. */
	const QUERY_VAR = 'idea89-checkout';

	/**
	 * Plugin settings.
	 *
	 * @var Idea89_Config
	 */
	private $config;

	/**
	 * Checkout experience settings.
	 *
	 * @var Idea89_Checkout_Config
	 */
	private $checkout_config;

	/**
	 * The postMessage bridge.
	 *
	 * @var Idea89_Checkout_Bridge
	 */
	private $bridge;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Config          $config          Plugin settings.
	 * @param Idea89_Checkout_Config $checkout_config Checkout experience settings.
	 * @param Idea89_Checkout_Bridge $bridge          The postMessage bridge.
	 */
	public function __construct( Idea89_Config $config, Idea89_Checkout_Config $checkout_config, Idea89_Checkout_Bridge $bridge ) {
		$this->config          = $config;
		$this->checkout_config = $checkout_config;
		$this->bridge          = $bridge;
	}

	/**
	 * Hooks the query var, its handler, and the thank-you success bridge.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_query_var' ) );
		// Late priority so every theme and WooCommerce itself has already
		// registered whatever template_redirect hooks it needs before we
		// decide whether to short-circuit the request.
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 999 );
		add_action( 'woocommerce_thankyou', array( $this, 'render_success_bridge' ), 5 );
	}

	/**
	 * Registers the query_vars filter. Split from register_query_var()'s
	 * caller (add_action) only in that it must run on init, before
	 * parse_request applies the query_vars filter — WordPress applies
	 * filters registered later than that point too late for this request.
	 *
	 * @return void
	 */
	public function register_query_var() {
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
	}

	/**
	 * Adds our query var to WordPress's recognised list.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Hooked to template_redirect. Does nothing at all — not even a function
	 * call into render() — when the query var is absent, so ordinary
	 * WooCommerce and WordPress requests are completely untouched by this
	 * class being registered.
	 *
	 * Ends the request itself (prints then exits) once it decides to render,
	 * exactly like Idea89_Order_Endpoints::send(). That exit is kept OUT of
	 * render() below so render() stays callable, and its output capturable,
	 * from a test without terminating the test process.
	 *
	 * @return void
	 */
	public function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Sent before the first byte of output, and kept out of render()
		// itself: this is the one raw header() call in this class, and
		// PHPUnit's function-mocking layer (Patchwork) cannot safely
		// redefine PHP's native header() without extra project config, so
		// render() — the method every test below calls directly — must never
		// reach it. Applies to every outcome alike, error page or real
		// checkout: both carry the same postMessage contract back to
		// whichever page embedded them, so both are exactly as sensitive to
		// being cached or framed cross-origin.
		nocache_headers();
		header( 'X-Frame-Options: SAMEORIGIN' );

		$this->render();
		exit;
	}

	/**
	 * Prints the full response: either the bridge-error page or the
	 * complete stripped-checkout document. Never exits, never redirects —
	 * every failure mode prints the same-origin bridge-error page so the
	 * framing widget can read a machine-readable reason and fall back
	 * immediately, per the message contract shared with Magento 2 and
	 * Magento 1.
	 *
	 * Printed, not buffered: the document is the theme-free equivalent of
	 * a page template, so it is written the way a template is — literal
	 * markup, wp_head() and wp_footer() printing themselves, core printing
	 * every script element — rather than assembled into one string that is
	 * echoed at the end.
	 *
	 * Guard order is deliberate and matters for which code a shopper sees:
	 * feature/mode first (an unused route should not leak checkout-shape
	 * information), then the cart (an empty cart is never worth rendering a
	 * whole checkout for), then the checkout-type probe (Task 6.4) last,
	 * since it is the most expensive check and the only one that needs a
	 * real cart to matter.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! $this->config->is_enabled() || ! $this->checkout_config->is_embedded() ) {
			$this->bridge->print_error_page( 'not_available' );
			return;
		}

		if ( ! $this->has_a_usable_cart() ) {
			$this->bridge->print_error_page( 'empty_cart' );
			return;
		}

		$type = self::detect_checkout_type();

		if ( 'unknown' === $type ) {
			$this->bridge->print_error_page( 'unsupported_checkout' );
			return;
		}

		$this->print_document( $type );
	}

	/**
	 * True when WooCommerce's cart is loaded and has at least one item.
	 *
	 * @return bool
	 */
	private function has_a_usable_cart() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		$wc = WC();

		if ( ! $wc || empty( $wc->cart ) ) {
			return false;
		}

		return ! $wc->cart->is_empty();
	}

	/**
	 * Prints the stripped document: wp_head() and wp_footer() are both
	 * REQUIRED, or WooCommerce's own scripts and styles never enqueue and
	 * the checkout renders unstyled and dead — every theme and WooCommerce
	 * itself expects both to run on every front-end request.
	 *
	 * `idea89-mini-checkout` is a detection marker: the widget's own
	 * compatibility check (and this class's tests) can confirm the stripped
	 * page rendered rather than, say, a theme 404.
	 *
	 * @param string $type 'block' or 'shortcode', from detect_checkout_type().
	 * @return void
	 */
	private function print_document( $type ) {
		echo '<!doctype html><html><head>';
		wp_head();
		echo '</head><body class="idea89-mini-checkout"><div id="idea89-mini-checkout">';

		if ( 'block' === $type ) {
			$this->print_block_checkout();
		} else {
			$this->print_shortcode_checkout();
		}

		echo '</div>';
		wp_print_inline_script_tag( $this->bridge->handshake_js() );
		wp_footer();
		echo '</body></html>';
	}

	/**
	 * Prints the Checkout block path: do_blocks() on the checkout page's
	 * own content, exactly as WordPress would when serving that page
	 * normally.
	 *
	 * @return void
	 */
	private function print_block_checkout() {
		$post = self::checkout_post();

		// do_blocks() returns the WooCommerce Checkout block's own rendered
		// markup — the same HTML the_content() prints for the checkout page
		// when the theme serves it. It is a checkout form: wp_kses_post()
		// would strip the elements and inline scripts the form needs, so it
		// is printed as WooCommerce rendered it, exactly as core does.
		echo do_blocks( $post instanceof WP_Post ? $post->post_content : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered block HTML, see above.
	}

	/**
	 * Prints the classic shortcode path: WooCommerce's own rendered
	 * checkout form, as the theme would print it for the checkout page.
	 *
	 * @return void
	 */
	private function print_shortcode_checkout() {
		echo do_shortcode( '[woocommerce_checkout]' );
	}

	/**
	 * Probes which of WooCommerce's two checkout renderers this store uses,
	 * on the page returned by wc_get_page_id( 'checkout' ).
	 *
	 * WooCommerce stores split between the classic `[woocommerce_checkout]`
	 * shortcode and the newer Checkout block, and the two render through
	 * completely different paths — there is no version, setting or capability
	 * flag that reliably tells them apart without looking at the actual page
	 * content. Detection order matters: a store mid-migration could have
	 * added the block to a page that still carries the shortcode text
	 * somewhere (e.g. in an unrelated content block), so the block check runs
	 * first and wins.
	 *
	 * @return string 'block', 'shortcode', or 'unknown'.
	 */
	public static function detect_checkout_type() {
		$post = self::checkout_post();

		if ( ! $post instanceof WP_Post ) {
			return 'unknown';
		}

		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $post ) ) {
			return 'block';
		}

		if ( function_exists( 'has_shortcode' ) && has_shortcode( (string) $post->post_content, 'woocommerce_checkout' ) ) {
			return 'shortcode';
		}

		return 'unknown';
	}

	/**
	 * The WP_Post behind wc_get_page_id( 'checkout' ), or null when the
	 * store has no checkout page configured at all.
	 *
	 * @return WP_Post|null
	 */
	private static function checkout_post() {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return null;
		}

		$page_id = (int) wc_get_page_id( 'checkout' );

		if ( $page_id < 1 ) {
			return null;
		}

		$post = get_post( $page_id );

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Hooked to woocommerce_thankyou. This page is reached by EVERY
	 * WooCommerce shopper on EVERY order, framed or not — there is no
	 * server-side signal that tells framed and unframed apart, so this
	 * method must never assume it is safe to act just because it fired.
	 *
	 * The window.parent === window guard that actually makes this safe is
	 * baked into the emitted script itself (Idea89_Checkout_Bridge::
	 * success_js()) as its first statement, not decided here. What IS
	 * decided here is whether $order_id is a genuinely completed, loadable
	 * order — the same belt-and-braces pattern as Magento 2's
	 * Bridge::getSuccessPayloadJson(), which checks getIncrementId() is
	 * non-empty on top of its own mode check. Order state, not session
	 * state, is what is trusted: WooCommerce's session carries the same
	 * "last order" trap Magento's does (a repeat purchase can leave a
	 * previous order's id reachable), which is exactly why this reads
	 * $order_id from the hook's own argument instead of any session lookup.
	 *
	 * @param int $order_id The order WooCommerce just placed.
	 * @return void
	 */
	public function render_success_bridge( $order_id ) {
		if ( ! $this->config->is_enabled() || ! $this->checkout_config->is_embedded() ) {
			return;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// success_js() checks the order's status itself: a failed, cancelled
		// or pending order posts a non-success envelope, never "success".
		wp_print_inline_script_tag( $this->bridge->success_js( $order ) );
	}
}
