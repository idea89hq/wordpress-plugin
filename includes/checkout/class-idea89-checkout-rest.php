<?php
/**
 * The WooCommerce arm of Tier 3 (native) checkout: four `wp-json` routes
 * behind the exact same façade contract the Magento 2 module's
 * Model/Checkout/Facade.php defines and the widget's step machine
 * (api/src/widget/checkout/index.ts, `_ckBase()`/`_ckGet()`/`_ckPost()`)
 * already calls unconditionally once `ckPlatform === 'woocommerce'`.
 *
 * GET  /wp-json/idea89/v1/checkout/context
 * POST /wp-json/idea89/v1/checkout/address
 * POST /wp-json/idea89/v1/checkout/method
 * POST /wp-json/idea89/v1/checkout/place
 *
 * Two guarantees this class exists to hold, mirroring Facade.php's own
 * docblock exactly (same two guarantees, same reasons):
 *
 *   1. Every read and write touches only WC()->cart / WC()->customer /
 *      WC()->shipping() / WC()->checkout() — the shopper's own session
 *      state. No method here accepts a cart or customer id from the
 *      request body; that would be a second, weaker credential than the
 *      session cookie the shopper already has.
 *   2. A payment gateway id is never trusted from the caller. place()
 *      re-derives the allowed set from the idea89_checkout_native_methods
 *      option intersected with WooCommerce's own currently-available
 *      gateways, and refuses anything outside it — including when the
 *      merchant has allowlisted nothing at all, which silently produces
 *      an empty `methods` array from context(), never an error.
 *
 * AUTH: WordPress REST routes require a real, non-`__return_true`
 * permission_callback or `wp plugin check` (rightly) flags them. The
 * widget carries exactly one credential for this surface: the WooCommerce
 * Store API nonce, published as both window.__IDEA89_WC.nonce and
 * window.__IDEA89_CHECKOUT.formKey (class-idea89-widget.php) — there is no
 * WooCommerce equivalent of Magento's form key, so the same Store API
 * nonce (action `wc_store_api`) stands in for it everywhere the widget's
 * shared transport helpers (_ckGet/_ckPost) send one. _ckPost carries it
 * as `form_key` in the JSON body (that field name is Magento's; the widget
 * is platform-neutral and never renames it per platform — see _ckPost's
 * own docblock). _ckGet carries it as a `Nonce` header, the same header
 * name _cartSummaryWoo() already uses against WooCommerce's own Store API
 * `/cart` route, so context() is checked exactly the same way the other
 * three are: every one of the four routes 403s with no nonce.
 *
 * NOT an HTTP round-trip to the Store API: WC()->cart etc. are already in
 * process for this request (WooCommerce boots them on REST requests the
 * same way it does for its own Store API routes), so calling out to
 * `wc/store/v1/*` over HTTP from here would be slower, harder to debug,
 * and would risk losing the very session this class depends on.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and answers the four native-checkout REST routes.
 */
class Idea89_Checkout_Rest {

	const REST_NAMESPACE = 'idea89/v1';

	/**
	 * Empty by default, matching the Magento tier's own guarantee exactly:
	 * Model/CheckoutConfig::getNativeMethods() returns [] when unset, and
	 * place() then refuses every gateway. The plan's headline Tier 3
	 * guarantee is "behind an allowlist that DEFAULTS TO EMPTY" — a
	 * merchant who selects Native checkout out of curiosity must get an
	 * endpoint that places no orders at all until they explicitly
	 * allowlist a gateway, not three live offline gateways by surprise.
	 */
	const DEFAULT_NATIVE_METHODS = array();

	/**
	 * I2: rate limits for the four native-checkout routes. /place spends the
	 * shopper's money and creates support tickets on a mistake, so it gets
	 * the tight ceiling; the other three (context/address/method) share the
	 * looser one. Built on the plugin's existing Idea89_Guest_Rate_Limit
	 * idiom (already shipped for guest order lookup) rather than a second
	 * limiter.
	 *
	 * These are NOT at parity with Magento's CheckoutRateLimit, despite
	 * sharing the numbers 8 and 30. Magento limits two dimensions per
	 * bucket: 8 per session AND 24 per IP for /place, 30 per session AND
	 * 90 per IP for the rest. Idea89_Guest_Rate_Limit keys on IP alone, so
	 * these two constants land on the IP dimension, where Magento allows 24
	 * and 90. The practical effect is a ceiling roughly three times tighter
	 * for anyone sharing an address: office NAT, carrier CGNAT, one
	 * household. Eight /place attempts per ten minutes is shared across
	 * every shopper behind that address, not granted to each.
	 *
	 * It errs toward refusing real checkouts rather than allowing abuse,
	 * which is the safe direction to be wrong in, but it is a divergence
	 * rather than the parity this comment used to claim. Closing it means
	 * either giving the limiter a session dimension or raising these to
	 * Magento's per-IP numbers.
	 */
	const RATE_LIMIT_PLACE_MAX    = 8;
	const RATE_LIMIT_OTHER_MAX    = 30;
	const RATE_LIMIT_WINDOW       = 10 * MINUTE_IN_SECONDS;
	const RATE_LIMIT_PLACE_PREFIX = 'idea89_ckp_';
	const RATE_LIMIT_OTHER_PREFIX = 'idea89_cko_';

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
	 * Tight bucket for /place.
	 *
	 * @var Idea89_Guest_Rate_Limit
	 */
	private $rate_limit_place;

	/**
	 * Loose bucket shared by /context, /address and /method.
	 *
	 * @var Idea89_Guest_Rate_Limit
	 */
	private $rate_limit_other;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Config                $config          Plugin settings.
	 * @param Idea89_Checkout_Config       $checkout_config Checkout experience settings.
	 * @param Idea89_Guest_Rate_Limit|null $rate_limit_place Tight /place bucket. Built with
	 *                                                       the class defaults when omitted.
	 * @param Idea89_Guest_Rate_Limit|null $rate_limit_other Loose bucket for the other three
	 *                                                       routes. Built with the class
	 *                                                       defaults when omitted.
	 */
	public function __construct(
		Idea89_Config $config,
		Idea89_Checkout_Config $checkout_config,
		?Idea89_Guest_Rate_Limit $rate_limit_place = null,
		?Idea89_Guest_Rate_Limit $rate_limit_other = null
	) {
		$this->config           = $config;
		$this->checkout_config  = $checkout_config;
		$this->rate_limit_place = null !== $rate_limit_place ? $rate_limit_place : new Idea89_Guest_Rate_Limit(
			self::RATE_LIMIT_PLACE_MAX,
			self::RATE_LIMIT_WINDOW,
			self::RATE_LIMIT_PLACE_PREFIX
		);
		$this->rate_limit_other = null !== $rate_limit_other ? $rate_limit_other : new Idea89_Guest_Rate_Limit(
			self::RATE_LIMIT_OTHER_MAX,
			self::RATE_LIMIT_WINDOW,
			self::RATE_LIMIT_OTHER_PREFIX
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
	 * Registers the four routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/checkout/context',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_context' ),
				'permission_callback' => array( $this, 'check_get_nonce' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/checkout/address',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_address' ),
				'permission_callback' => array( $this, 'check_post_nonce' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/checkout/method',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_method' ),
				'permission_callback' => array( $this, 'check_post_nonce' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/checkout/place',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_place' ),
				'permission_callback' => array( $this, 'check_post_nonce' ),
			)
		);
	}

	// ---------------------------------------------------------------- //
	// Auth
	// ---------------------------------------------------------------- //

	/**
	 * GET arm: the nonce travels as a `Nonce` header — see this file's
	 * docblock and _ckGet()'s own comment in api/src/widget/checkout/index.ts
	 * for why a header rather than a query string (the widget already uses
	 * this exact header name against WooCommerce's own Store API `/cart`
	 * route in _cartSummaryWoo()).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return true|WP_Error
	 */
	public function check_get_nonce( WP_REST_Request $request ) {
		return $this->verify_nonce( (string) $request->get_header( 'nonce' ) );
	}

	/**
	 * POST arm: the nonce travels in the JSON body as `form_key` — the
	 * field name _ckPost() already sends on every POST call, carrying the
	 * Store API nonce on this platform (see class-idea89-widget.php's
	 * `formKey` comment). Read directly from the decoded body rather than
	 * $request->get_param() so this does not depend on WordPress having
	 * already merged JSON body params into the request's param bag, which
	 * varies by content-type handling.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return true|WP_Error
	 */
	public function check_post_nonce( WP_REST_Request $request ) {
		$body  = $request->get_json_params();
		$nonce = ( is_array( $body ) && isset( $body['form_key'] ) && is_string( $body['form_key'] ) )
			? $body['form_key']
			: '';
		return $this->verify_nonce( $nonce );
	}

	/**
	 * Timing-safe by way of wp_verify_nonce() itself (core hashes and
	 * compares, never a raw string match). An empty nonce is rejected
	 * before ever reaching wp_verify_nonce() — WordPress's own function
	 * returns false for '' too, but failing fast here means a missing
	 * header/field never even makes that call.
	 *
	 * @param string $nonce Candidate nonce.
	 * @return true|WP_Error
	 */
	private function verify_nonce( $nonce ) {
		$nonce = trim( (string) $nonce );

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wc_store_api' ) ) {
			return new WP_Error(
				'idea89_invalid_nonce',
				__( 'Your session has expired. Refresh the page and try again.', 'idea89-ai-shopping-assistant' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	// ---------------------------------------------------------------- //
	// Routes
	// ---------------------------------------------------------------- //

	/**
	 * GET /checkout/context
	 *
	 * @return WP_REST_Response
	 */
	public function handle_context() {
		$gate = $this->feature_gate();
		if ( null !== $gate ) {
			return $gate;
		}

		$gate = $this->rate_limit_other_gate();
		if ( null !== $gate ) {
			return $gate;
		}

		$cart = $this->cart();
		if ( null === $cart ) {
			return $this->error_response( 'checkout_error', __( 'Your basket is not available right now.', 'idea89-ai-shopping-assistant' ), 400 );
		}

		$methods = array();
		foreach ( $this->allowed_gateways() as $id => $gateway ) {
			$methods[] = array(
				'code'  => (string) $id,
				'title' => (string) $gateway->get_title(),
			);
		}

		return new WP_REST_Response(
			array(
				'logged_in'         => is_user_logged_in(),
				'item_count'        => (int) $cart->get_cart_contents_count(),
				'currency'          => get_woocommerce_currency(),
				'subtotal'          => $this->format_amount( $cart->get_subtotal() ),
				'grand_total'       => $this->format_amount( $cart->get_total( 'edit' ) ),
				'methods'           => $methods,
				'needs_shipping'    => (bool) $cart->needs_shipping(),
				'allowed_countries' => $this->allowed_countries(),
			),
			200
		);
	}

	/**
	 * POST /checkout/address
	 *
	 * Body: {email, firstname, lastname, street (array or string), city,
	 *        postcode, country_id, telephone, region?, region_id?, form_key}
	 * — the exact shape _ckRenderAddress() in the widget sends, unchanged
	 * from the Magento contract.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_address( WP_REST_Request $request ) {
		$gate = $this->origin_gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}

		$gate = $this->feature_gate();
		if ( null !== $gate ) {
			return $gate;
		}

		$gate = $this->rate_limit_other_gate();
		if ( null !== $gate ) {
			return $gate;
		}

		$body = $this->body( $request );

		// Validated BEFORE WC()->customer is touched — same ordering
		// Facade::setAddress() uses and for the same reason: a shopper who
		// typed half an address must not leave a half-set address behind.
		$missing = $this->missing_address_field( $body );
		if ( null !== $missing ) {
			return $this->error_response( 'checkout_error', __( 'Enter a delivery postcode, country and street address.', 'idea89-ai-shopping-assistant' ), 400 );
		}

		$email = isset( $body['email'] ) ? sanitize_email( (string) $body['email'] ) : '';
		if ( isset( $body['email'] ) && '' !== trim( (string) $body['email'] ) && ( '' === $email || ! is_email( $email ) ) ) {
			return $this->error_response( 'checkout_error', __( 'Enter a valid email address.', 'idea89-ai-shopping-assistant' ), 400 );
		}

		$cart = $this->cart();
		if ( null === $cart || ! function_exists( 'WC' ) || empty( WC()->customer ) ) {
			return $this->error_response( 'checkout_error', __( 'Your basket is not available right now.', 'idea89-ai-shopping-assistant' ), 400 );
		}

		try {
			$this->apply_address( WC()->customer, $body, $email );
			WC()->customer->save();

			$needs_shipping   = (bool) $cart->needs_shipping();
			$shipping_methods = $needs_shipping ? $this->calculate_shipping_methods( $cart ) : array();

			$cart->calculate_totals();

			return new WP_REST_Response(
				array(
					'shipping_methods' => $shipping_methods,
					'totals'           => $this->totals( $cart ),
					'needs_shipping'   => $needs_shipping,
				),
				200
			);
		} catch ( \Throwable $e ) {
			return $this->error_response( 'checkout_error', __( 'We could not save that address. Check the details and try again.', 'idea89-ai-shopping-assistant' ), 400 );
		}
	}

	/**
	 * POST /checkout/method
	 *
	 * Body: {carrier, method, form_key} — carrier/method split from a
	 * WooCommerce shipping rate id (`{method_id}:{instance_id}`), exactly
	 * as calculate_shipping_methods() below reports it in address()'s
	 * response, so the two always agree on the split point.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_method( WP_REST_Request $request ) {
		$gate = $this->origin_gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}

		$gate = $this->feature_gate();
		if ( null !== $gate ) {
			return $gate;
		}

		$gate = $this->rate_limit_other_gate();
		if ( null !== $gate ) {
			return $gate;
		}

		$body    = $this->body( $request );
		$carrier = isset( $body['carrier'] ) ? sanitize_text_field( (string) $body['carrier'] ) : '';
		$method  = isset( $body['method'] ) ? sanitize_text_field( (string) $body['method'] ) : '';

		if ( '' === trim( $carrier ) || '' === trim( $method ) ) {
			return $this->error_response( 'checkout_error', __( 'Choose a delivery method.', 'idea89-ai-shopping-assistant' ), 400 );
		}

		$cart = $this->cart();
		if ( null === $cart ) {
			return $this->error_response( 'checkout_error', __( 'Your basket is not available right now.', 'idea89-ai-shopping-assistant' ), 400 );
		}

		if ( ! $cart->needs_shipping() ) {
			return $this->error_response( 'checkout_error', __( 'This order does not need a delivery method.', 'idea89-ai-shopping-assistant' ), 400 );
		}

		try {
			$rate_id = $carrier . ':' . $method;
			// Keyed by package index 0: a plain WooCommerce cart ships as a
			// single package unless a multi-vendor/split-shipment plugin is
			// active, matching what calculate_shipping_methods() below
			// already assumes when it walks $packages by numeric key.
			WC()->session->set( 'chosen_shipping_methods', array( 0 => $rate_id ) );
			WC()->shipping()->calculate_shipping( $cart->get_shipping_packages() );
			$cart->calculate_totals();

			return new WP_REST_Response( array( 'totals' => $this->totals( $cart ) ), 200 );
		} catch ( \Throwable $e ) {
			return $this->error_response( 'checkout_error', __( 'We could not update the delivery method. Try again.', 'idea89-ai-shopping-assistant' ), 400 );
		}
	}

	/**
	 * POST /checkout/place
	 *
	 * Body: {payment_method, form_key}.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_place( WP_REST_Request $request ) {
		$gate = $this->origin_gate( $request );
		if ( null !== $gate ) {
			return $gate;
		}

		$gate = $this->feature_gate();
		if ( null !== $gate ) {
			return $gate;
		}

		// Tightest bucket of the four — this is the one call that spends the
		// shopper's money and creates support tickets on a mistake.
		$gate = $this->rate_limit_place_gate();
		if ( null !== $gate ) {
			return $gate;
		}

		$body           = $this->body( $request );
		$payment_method = isset( $body['payment_method'] ) ? sanitize_key( (string) $body['payment_method'] ) : '';

		// Re-check server side. Never trust the code the request sent — an
		// empty allowlist here means an empty $allowed array, and this
		// rejects every method, exactly as it should. Same rule as
		// Facade::place().
		$allowed = $this->allowed_gateways();
		if ( '' === $payment_method || ! isset( $allowed[ $payment_method ] ) ) {
			return $this->error_response( 'checkout_error', __( 'That payment method is not available.', 'idea89-ai-shopping-assistant' ), 400 );
		}

		$cart = $this->cart();
		if ( null === $cart ) {
			return $this->error_response( 'checkout_error', __( 'Your basket is not available right now.', 'idea89-ai-shopping-assistant' ), 400 );
		}

		if ( $cart->is_empty() ) {
			return $this->error_response( 'checkout_error', __( 'Your cart is empty.', 'idea89-ai-shopping-assistant' ), 400 );
		}

		$customer = ( function_exists( 'WC' ) && ! empty( WC()->customer ) ) ? WC()->customer : null;
		$email    = $customer ? trim( (string) $customer->get_billing_email() ) : '';

		if ( ! is_user_logged_in() && '' === $email ) {
			return $this->error_response( 'checkout_error', __( 'Enter your email address before placing the order.', 'idea89-ai-shopping-assistant' ), 400 );
		}

		try {
			$cart->calculate_totals();

			$order_id = WC()->checkout()->create_order( $this->order_data( $customer, $payment_method ) );
			if ( is_wp_error( $order_id ) ) {
				return $this->error_response( 'checkout_error', __( 'We could not place your order. Please try again.', 'idea89-ai-shopping-assistant' ), 400 );
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return $this->error_response( 'internal_error', __( 'We could not place your order. Please try again.', 'idea89-ai-shopping-assistant' ), 500 );
			}

			// Delegate to the real gateway's own process_payment(): for every
			// allowlisted gateway (cod/bacs/cheque, all core WooCommerce
			// offline methods) this is what transitions the order status,
			// reduces stock via WooCommerce's own core hooks, and empties the
			// cart — the same sequence a normal checkout submission runs, not
			// a re-implementation of it.
			$gateway = $allowed[ $payment_method ];
			$gateway->process_payment( $order_id );

			return new WP_REST_Response(
				array(
					'order_id' => (string) $order->get_order_number(),
					'total'    => $this->format_amount( $order->get_total() ),
					'currency' => (string) $order->get_currency(),
				),
				200
			);
		} catch ( \Throwable $e ) {
			return $this->error_response( 'checkout_error', __( 'We could not place your order. Please try again.', 'idea89-ai-shopping-assistant' ), 400 );
		}
	}

	// ---------------------------------------------------------------- //
	// Helpers
	// ---------------------------------------------------------------- //

	/**
	 * 404, not 403: an unused route should not advertise its own
	 * existence — same reasoning as Controller/Checkout/Context.php.
	 *
	 * @return WP_REST_Response|null Null when the route may proceed.
	 */
	private function feature_gate() {
		if ( ! $this->config->is_enabled() || ! $this->checkout_config->is_native() ) {
			return $this->error_response( 'not_available', '', 404 );
		}
		return null;
	}

	/**
	 * I2: same-origin guard for the three state-changing routes (address,
	 * method, place), matching Magento's Controller/Checkout/Place.php
	 * (and Method.php, Address.php) shape exactly — including the
	 * DEVIATION from a GET-only same-origin check: a missing Origin header
	 * is REJECTED here, not tolerated. Browsers reliably attach Origin on
	 * a state-changing POST (same-origin fetch()es included, every major
	 * engine since ~2020), so a missing Origin on POST is itself
	 * suspicious rather than an ordinary gap to allow, and costs nothing
	 * to reject — the widget always sends it. Deliberately NOT applied to
	 * the GET /context route, matching Magento's Context.php, which stays
	 * lenient on a missing Origin there.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|null Null when the request may proceed.
	 */
	private function origin_gate( WP_REST_Request $request ) {
		$origin = trim( (string) $request->get_header( 'origin' ) );

		if ( '' === $origin ) {
			return $this->error_response( 'cross_origin_forbidden', '', 403 );
		}

		$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
		$site_host   = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		if ( ! is_string( $origin_host ) || $origin_host !== $site_host ) {
			return $this->error_response( 'cross_origin_forbidden', '', 403 );
		}

		return null;
	}

	/**
	 * I2: applies the tight /place bucket. Same idiom as
	 * Idea89_Order_Endpoints::handle_lookup() — {error: rate_limited}, 429,
	 * no Retry-After header (the existing limiter's shape; not invented
	 * here).
	 *
	 * @return WP_REST_Response|null Null when the request may proceed.
	 */
	private function rate_limit_place_gate() {
		if ( ! $this->rate_limit_place->allow( $this->client_ip() ) ) {
			return $this->error_response( 'rate_limited', '', 429 );
		}
		return null;
	}

	/**
	 * I2: applies the loose bucket shared by context/address/method.
	 *
	 * @return WP_REST_Response|null Null when the request may proceed.
	 */
	private function rate_limit_other_gate() {
		if ( ! $this->rate_limit_other->allow( $this->client_ip() ) ) {
			return $this->error_response( 'rate_limited', '', 429 );
		}
		return null;
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
	 * The live WooCommerce cart, or null when it is not available at all
	 * (WooCommerce not fully booted for this request).
	 *
	 * @return WC_Cart|null
	 */
	private function cart() {
		$this->ensure_cart_loaded();
		if ( ! function_exists( 'WC' ) || ! WC() || empty( WC()->cart ) ) {
			return null;
		}
		return WC()->cart;
	}

	/**
	 * WooCommerce only auto-initialises WC()->cart / ->customer / ->session
	 * for a "frontend" request — WC::is_request('frontend') explicitly
	 * excludes any REST API request (`! $this->is_rest_api_request()`), so
	 * on a plain register_rest_route callback all three stay null unless
	 * something loads them first. Found live: every one of these four
	 * routes 400'd with "basket not available" even with a valid nonce and
	 * an established WooCommerce session, until this was added.
	 *
	 * WooCommerce's OWN Store API hits the exact same gap and papers over
	 * it the exact same way — see
	 * Automattic\WooCommerce\StoreApi\Utilities\CartController::load_cart(),
	 * which calls this same core function for this same reason before any
	 * Store API route touches the cart. Idempotent (wc_load_cart() itself
	 * guards on did_action('woocommerce_load_cart_from_session')), so
	 * calling it from cart() on every request that reaches it is cheap and
	 * cannot double-initialise anything.
	 *
	 * @return void
	 */
	private function ensure_cart_loaded() {
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
	}

	/**
	 * Decoded JSON body, or an empty array when it is missing or malformed.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array<string,mixed>
	 */
	private function body( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : array();
	}

	/**
	 * Finds the first blank required address field, if any.
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @return string|null The name of the first missing/blank required field, or null.
	 */
	private function missing_address_field( array $body ) {
		foreach ( array( 'postcode', 'country_id', 'street' ) as $field ) {
			$value = isset( $body[ $field ] ) ? $body[ $field ] : null;
			$blank = is_array( $value )
				? array() === array_filter(
					$value,
					static function ( $line ) {
						return '' !== trim( (string) $line );
					}
				)
				: '' === trim( (string) $value );
			if ( $blank ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Sets billing AND shipping to the same single address — the widget's
	 * form collects one address, not two, same as Facade::buildAddress()
	 * setting both the quote's shipping and billing address from one
	 * payload.
	 *
	 * @param WC_Customer         $customer WooCommerce's live customer object.
	 * @param array<string,mixed> $body     Decoded request body.
	 * @param string              $email    Already-validated email, or ''.
	 * @return void
	 */
	private function apply_address( $customer, array $body, $email ) {
		$street = isset( $body['street'] ) ? $body['street'] : '';
		$lines  = is_array( $street )
			? array_values(
				array_filter(
					$street,
					static function ( $line ) {
						return '' !== trim( (string) $line );
					}
				)
			)
			: array_values(
				array_filter(
					explode( "\n", (string) $street ),
					static function ( $line ) {
						return '' !== trim( $line );
					}
				)
			);

		$fields = array(
			'first_name' => isset( $body['firstname'] ) ? sanitize_text_field( (string) $body['firstname'] ) : '',
			'last_name'  => isset( $body['lastname'] ) ? sanitize_text_field( (string) $body['lastname'] ) : '',
			'address_1'  => isset( $lines[0] ) ? sanitize_text_field( (string) $lines[0] ) : '',
			'address_2'  => isset( $lines[1] ) ? sanitize_text_field( (string) $lines[1] ) : '',
			'city'       => isset( $body['city'] ) ? sanitize_text_field( (string) $body['city'] ) : '',
			'postcode'   => isset( $body['postcode'] ) ? sanitize_text_field( (string) $body['postcode'] ) : '',
			'country'    => isset( $body['country_id'] ) ? strtoupper( sanitize_text_field( (string) $body['country_id'] ) ) : '',
			'state'      => isset( $body['region'] ) ? sanitize_text_field( (string) $body['region'] ) : '',
			'phone'      => isset( $body['telephone'] ) ? sanitize_text_field( (string) $body['telephone'] ) : '',
		);

		foreach ( $fields as $key => $value ) {
			// is_callable(), not a bare method call: real WC_Customer defines
			// every one of these, but a store's own customer-object filter
			// could swap in a leaner stand-in, same defensive idiom
			// WC_Checkout::create_order() itself uses for the same reason.
			foreach ( array( 'billing_' . $key, 'shipping_' . $key ) as $prefixed ) {
				$setter = 'set_' . $prefixed;
				if ( is_callable( array( $customer, $setter ) ) ) {
					$customer->$setter( $value );
				}
			}
		}

		if ( '' !== $email && is_callable( array( $customer, 'set_billing_email' ) ) ) {
			$customer->set_billing_email( $email );
		}
	}

	/**
	 * Priced shipping methods for the cart's current package(s).
	 *
	 * @param WC_Cart $cart The live cart.
	 * @return array<int, array{carrier: string, method: string, title: string, amount: string}>
	 */
	private function calculate_shipping_methods( $cart ) {
		$out      = array();
		$packages = $cart->get_shipping_packages();
		$results  = WC()->shipping()->calculate_shipping( $packages );

		if ( ! is_array( $results ) ) {
			return $out;
		}

		foreach ( $results as $package ) {
			if ( empty( $package['rates'] ) || ! is_array( $package['rates'] ) ) {
				continue;
			}
			foreach ( $package['rates'] as $rate ) {
				$out[] = array(
					'carrier' => (string) $rate->get_method_id(),
					'method'  => (string) $rate->get_instance_id(),
					'title'   => (string) $rate->get_label(),
					'amount'  => $this->format_amount( $rate->get_cost() ),
				);
			}
		}

		return $out;
	}

	/**
	 * Payment gateway codes the merchant has explicitly allowed AND that
	 * are currently active/available in WooCommerce, keyed by id. Empty on
	 * both an empty allowlist and a merchant who allowlisted a gateway
	 * they've since disabled — either way, place() then refuses every
	 * method, same as Facade::allowedMethods().
	 *
	 * DEVIATION FROM AN EARLIER DRAFT, found live: this guard used to read
	 * `empty( WC()->payment_gateways )`. WooCommerce's WooCommerce class has
	 * no __isset(), only __get() — and PHP's empty()/isset() on an
	 * inaccessible object property consults __isset() FIRST; with none
	 * defined, both assume the property does not exist and return without
	 * ever calling __get(). `WC()->payment_gateways` (property syntax) is
	 * therefore ALWAYS reported empty regardless of state, and that guard
	 * silently zeroed out every gateway on every request — verified live: it
	 * returned an empty methods array even with COD and BACS both enabled
	 * and confirmed available via the real `WC()->payment_gateways()`
	 * (method call) a line below. Checking WC() itself is enough; the method
	 * call below is what actually reaches WooCommerce's gateway manager.
	 *
	 * @return array<string, WC_Payment_Gateway>
	 */
	private function allowed_gateways() {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return array();
		}

		$allowlist = array_flip( $this->native_method_ids() );
		$available = WC()->payment_gateways()->get_available_payment_gateways();
		if ( ! is_array( $available ) ) {
			return array();
		}

		$out = array();
		foreach ( $available as $id => $gateway ) {
			if ( isset( $allowlist[ $id ] ) ) {
				$out[ $id ] = $gateway;
			}
		}
		return $out;
	}

	/**
	 * The merchant's own allowlist, empty when unset — same guarantee as
	 * the Magento tier's Model/CheckoutConfig::getNativeMethods(). place()
	 * then refuses every gateway, exactly as an empty allowlist should.
	 *
	 * @return string[]
	 */
	private function native_method_ids() {
		$raw = get_option( 'idea89_checkout_native_methods', self::DEFAULT_NATIVE_METHODS );
		if ( ! is_array( $raw ) ) {
			return self::DEFAULT_NATIVE_METHODS;
		}
		$clean = array_map( 'sanitize_key', $raw );
		return array_values(
			array_filter(
				$clean,
				static function ( $id ) {
					return '' !== $id;
				}
			)
		);
	}

	/**
	 * The store's own shippable-country allowlist — WooCommerce's
	 * get_allowed_countries() has already resolved whatever the merchant
	 * configured ("Sell to all countries", a specific list, or "all except")
	 * to a concrete code => localised-name map, so this passes it straight
	 * through rather than re-deriving anything. Never a hardcoded list.
	 *
	 * @return array<int, array{code: string, name: string}>
	 */
	private function allowed_countries() {
		if ( ! function_exists( 'WC' ) || ! WC() || empty( WC()->countries ) ) {
			return array();
		}
		$countries = WC()->countries->get_allowed_countries();
		if ( ! is_array( $countries ) ) {
			return array();
		}
		$out = array();
		foreach ( $countries as $code => $name ) {
			$out[] = array(
				'code' => (string) $code,
				'name' => (string) $name,
			);
		}
		return $out;
	}

	/**
	 * Builds the $data array WC_Checkout::create_order() expects: any key
	 * matching a callable WC_Order::set_{$key}() lands there directly (see
	 * WooCommerce core's own create_order() loop), so billing and shipping
	 * fields already applied to WC()->customer during address() are read
	 * back from it here rather than re-collected — the order and the
	 * customer must describe the same address.
	 *
	 * @param WC_Customer|null $customer The live customer object, or null.
	 * @param string           $payment_method Allow-listed gateway id.
	 * @return array<string,mixed>
	 */
	private function order_data( $customer, $payment_method ) {
		$data = array( 'payment_method' => $payment_method );
		if ( ! $customer ) {
			return $data;
		}

		foreach ( array( 'billing', 'shipping' ) as $group ) {
			foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' ) as $field ) {
				$getter = 'get_' . $group . '_' . $field;
				if ( is_callable( array( $customer, $getter ) ) ) {
					$data[ $group . '_' . $field ] = $customer->$getter();
				}
			}
		}

		return $data;
	}

	/**
	 * The two totals every step response carries.
	 *
	 * @param WC_Cart $cart The live cart.
	 * @return array{subtotal: string, grand_total: string}
	 */
	private function totals( $cart ) {
		return array(
			'subtotal'    => $this->format_amount( $cart->get_subtotal() ),
			'grand_total' => $this->format_amount( $cart->get_total( 'edit' ) ),
		);
	}

	/**
	 * Same two-decimal-place formatting Facade.php uses for every
	 * currency, zero-decimal ones included — this is an inherited shape
	 * (the widget's step machine renders whatever string comes back), not
	 * a WooCommerce-specific choice, so it is not corrected here.
	 *
	 * @param mixed $value Raw amount.
	 * @return string
	 */
	private function format_amount( $value ) {
		return number_format( (float) $value, 2, '.', '' );
	}

	/**
	 * Builds a JSON error response.
	 *
	 * @param string $error   Machine-readable error code.
	 * @param string $message Shopper-safe message, or '' for none.
	 * @param int    $status  HTTP status code.
	 * @return WP_REST_Response
	 */
	private function error_response( $error, $message, $status ) {
		$payload = array( 'error' => $error );
		if ( '' !== $message ) {
			$payload['message'] = $message;
		}
		return new WP_REST_Response( $payload, $status );
	}
}
