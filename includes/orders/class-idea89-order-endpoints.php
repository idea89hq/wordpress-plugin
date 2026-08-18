<?php
/**
 * Serves the four order endpoints the chat widget calls.
 *
 * The widget hardcodes a base of /idea89, so these cannot live under
 * /wp-json. Requests are intercepted at parse_request, which runs after
 * WordPress has worked out the path relative to the site root and therefore
 * behaves correctly on subdirectory installs.
 *
 * Privacy model: the widget calls this same-origin with the shopper's own
 * cookies, so order data goes browser -> merchant and never touches IDEA89 or
 * any model provider. Nothing here contacts an external service.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes and answers /idea89/* order requests.
 */
class Idea89_Order_Endpoints {

	const MAX_LIVE_SKUS = 25;

	/**
	 * Settings accessor.
	 *
	 * @var Idea89_Order_Tracking_Config
	 */
	private $config;

	/**
	 * Order to payload mapper.
	 *
	 * @var Idea89_Order_Sanitizer
	 */
	private $sanitizer;

	/**
	 * Guest lookup throttle.
	 *
	 * @var Idea89_Guest_Rate_Limit
	 */
	private $rate_limit;

	/**
	 * Personalization settings.
	 *
	 * @var Idea89_Personalization_Config
	 */
	private $personalization;

	/**
	 * Plugin settings, for the API key used as the token's store reference.
	 *
	 * @var Idea89_Config
	 */
	private $plugin_config;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Order_Tracking_Config  $config          Settings accessor.
	 * @param Idea89_Order_Sanitizer        $sanitizer       Order mapper.
	 * @param Idea89_Guest_Rate_Limit       $rate_limit      Guest throttle.
	 * @param Idea89_Personalization_Config $personalization Personalization settings.
	 * @param Idea89_Config                 $plugin_config   Plugin settings.
	 */
	public function __construct(
		Idea89_Order_Tracking_Config $config,
		Idea89_Order_Sanitizer $sanitizer,
		Idea89_Guest_Rate_Limit $rate_limit,
		Idea89_Personalization_Config $personalization,
		Idea89_Config $plugin_config
	) {
		$this->config          = $config;
		$this->sanitizer       = $sanitizer;
		$this->rate_limit      = $rate_limit;
		$this->personalization = $personalization;
		$this->plugin_config   = $plugin_config;
	}

	/**
	 * Hooks the router in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'parse_request', array( $this, 'dispatch' ) );
	}

	/**
	 * Matches the request path and answers it, or returns to let WordPress
	 * carry on.
	 *
	 * @param WP $wp Current WordPress environment.
	 * @return void
	 */
	public function dispatch( $wp ) {
		$route = self::route_from_request( isset( $wp->request ) ? (string) $wp->request : '' );

		if ( null === $route ) {
			return;
		}

		// Gated per route, not in one blanket check: /customer/me also mints
		// the personalization identity token, so a store running
		// personalization with order tracking off still needs it to answer.
		switch ( $route ) {
			case 'customer/me':
				if ( ! $this->config->is_enabled() && ! $this->personalization->is_usable() ) {
					$this->send( array( 'error' => 'not_found' ), 404 );
				}
				$this->handle_me();
				break;
			case 'orders/recent':
				$this->require_order_tracking();
				$this->handle_recent();
				break;
			case 'orders/detail':
				$this->require_order_tracking();
				$this->handle_detail();
				break;
			case 'orders/lookup':
				$this->require_order_tracking();
				$this->handle_lookup();
				break;
			case 'products/live':
				$this->handle_live();
				break;
			default:
				$this->send( array( 'error' => 'not_found' ), 404 );
		}
	}

	/**
	 * Extracts the route below /idea89, or null when this is not ours.
	 *
	 * Pure so it can be tested without a WordPress request.
	 *
	 * @param string $request Path relative to the site root, no leading slash.
	 * @return string|null
	 */
	public static function route_from_request( $request ) {
		$path = trim( (string) $request, '/' );

		// Must be the whole first segment: a page called "idea89reviews"
		// belongs to the site, not to us.
		if ( 'idea89/' !== substr( $path, 0, 7 ) ) {
			return null;
		}

		$rest = trim( substr( $path, 7 ), '/' );

		if ( '' === $rest ) {
			return null;
		}

		$allowed = array( 'customer/me', 'orders/recent', 'orders/detail', 'orders/lookup', 'products/live' );

		return in_array( $rest, $allowed, true ) ? $rest : 'unknown';
	}

	/**
	 * Reports whether a shopper is logged in, without exposing their email.
	 *
	 * @return void
	 */
	private function handle_me() {
		$tracking_on = $this->config->is_enabled();
		$logged_in   = is_user_logged_in();
		$user        = $logged_in ? wp_get_current_user() : null;
		$customer_id = $logged_in ? (int) $user->ID : null;

		/**
		 * Filters the customer group id placed in the identity token.
		 *
		 * WooCommerce has no native customer groups, so this is 0 for guests
		 * and 1 for logged-in shoppers. B2B plugins that do model groups can
		 * substitute a real id here.
		 *
		 * @param int      $group_id    Default group id.
		 * @param int|null $customer_id Logged-in user id, or null.
		 */
		$group_id = (int) apply_filters( 'idea89_customer_group_id', $logged_in ? 1 : 0, $customer_id );

		// Minted whenever personalization is usable, independently of order
		// tracking: the two features are switched on separately.
		$token = null;

		if ( $this->personalization->is_usable() ) {
			$token = Idea89_Identity_Token::mint(
				$this->plugin_config->get_api_key(),
				$this->personalization->get_signing_secret(),
				$customer_id,
				$group_id,
				$logged_in
			);
		}

		$payload = array(
			'logged_in'       => $logged_in,
			'feature_enabled' => $tracking_on,
			'identity_token'  => $token,
		);

		if ( ! $logged_in ) {
			$this->send( $payload );
		}

		$payload['first_name'] = (string) $user->first_name;

		$email = strtolower( trim( (string) $user->user_email ) );

		if ( '' !== $email ) {
			// Truncated on purpose. The widget only needs to tell one session
			// from another; a full digest would be a stable, linkable handle
			// on the shopper across every store that ever sees it.
			$payload['email_hash'] = substr( hash( 'sha256', $email ), 0, 8 );
		}

		$this->send( $payload );
	}

	/**
	 * Sends a 404 unless order tracking is switched on.
	 *
	 * @return void
	 */
	private function require_order_tracking() {
		if ( ! $this->config->is_enabled() ) {
			$this->send( array( 'error' => 'not_found' ), 404 );
		}
	}

	/**
	 * Live price and stock for a set of SKUs.
	 *
	 * Server-to-server from IDEA89, authenticated with the shared secret, so
	 * the assistant can confirm a price before quoting it rather than relying
	 * on whatever the last catalogue sync captured. No customer data here.
	 *
	 * @return void
	 */
	private function handle_live() {
		if ( ! $this->personalization->is_usable() ) {
			$this->send( array( 'error' => 'not_found' ), 404 );
		}

		if ( 'POST' !== $this->request_method() ) {
			$this->send( array( 'error' => 'not_found' ), 404 );
		}

		$secret = $this->personalization->get_signing_secret();
		$header = isset( $_SERVER['HTTP_AUTHORIZATION'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) )
			: '';

		if ( ! hash_equals( 'Bearer ' . $secret, $header ) ) {
			$this->send( array( 'error' => 'unauthorized' ), 401 );
		}

		$body = json_decode( (string) file_get_contents( 'php://input' ), true );
		$skus = ( is_array( $body ) && isset( $body['skus'] ) && is_array( $body['skus'] ) )
			? array_slice( $body['skus'], 0, self::MAX_LIVE_SKUS )
			: array();

		$out = array();

		foreach ( $skus as $sku ) {
			$sku = sanitize_text_field( (string) $sku );

			if ( '' === $sku ) {
				continue;
			}

			$id = wc_get_product_id_by_sku( $sku );

			if ( ! $id ) {
				continue;
			}

			$product = wc_get_product( $id );

			if ( ! $product ) {
				continue;
			}

			$out[] = array(
				'sku'      => (string) $product->get_sku(),
				'price'    => (float) wc_get_price_to_display( $product ),
				'qty'      => null === $product->get_stock_quantity() ? 0.0 : (float) $product->get_stock_quantity(),
				'in_stock' => (bool) $product->is_in_stock(),
				'status'   => 'publish' === $product->get_status() ? 1 : 2,
				'url'      => (string) $product->get_permalink(),
			);
		}

		$this->send( array( 'products' => $out ) );
	}

	/**
	 * The request method, uppercased.
	 *
	 * @return string
	 */
	private function request_method() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return '';
		}

		return strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}

	/**
	 * Lists the logged-in shopper's most recent orders.
	 *
	 * @return void
	 */
	private function handle_recent() {
		if ( ! is_user_logged_in() ) {
			$this->send( array( 'error' => 'not_logged_in' ), 401 );
		}

		$max = $this->config->get_max_recent_orders();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, scoped to the current user's own orders.
		$requested = isset( $_GET['limit'] ) ? (int) $_GET['limit'] : $max;
		$limit     = max( 1, min( $max, $requested ) );

		$orders = wc_get_orders(
			array(
				'customer_id' => get_current_user_id(),
				'limit'       => $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'status'      => $this->listable_statuses(),
			)
		);

		$payload = array();

		foreach ( $orders as $order ) {
			$payload[] = $this->sanitizer->sanitize( $order, false );
		}

		$this->send( array( 'orders' => $payload ) );
	}

	/**
	 * Returns one of the logged-in shopper's orders in full.
	 *
	 * @return void
	 */
	private function handle_detail() {
		if ( ! is_user_logged_in() ) {
			$this->send( array( 'error' => 'not_logged_in' ), 401 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, ownership enforced below.
		$number = isset( $_GET['increment_id'] ) ? sanitize_text_field( wp_unslash( $_GET['increment_id'] ) ) : '';
		$order  = $this->find_order( $number );

		// One response for "no such order" and "not yours", so the endpoint
		// cannot be used to discover which order numbers exist.
		if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() ) {
			$this->send( array( 'error' => 'not_found' ), 404 );
		}

		$this->send( array( 'order' => $this->sanitizer->sanitize( $order, true ) ) );
	}

	/**
	 * Guest lookup by order number plus the email on the order.
	 *
	 * @return void
	 */
	private function handle_lookup() {
		if ( 'POST' !== $this->request_method() ) {
			$this->send( array( 'error' => 'not_found' ), 404 );
		}

		if ( ! $this->rate_limit->allow( $this->client_ip() ) ) {
			$this->send( array( 'error' => 'rate_limited' ), 429 );
		}

		$body = json_decode( (string) file_get_contents( 'php://input' ), true );

		if ( ! is_array( $body ) ) {
			$this->send( array( 'error' => 'not_found' ), 404 );
		}

		$number = isset( $body['increment_id'] ) ? sanitize_text_field( (string) $body['increment_id'] ) : '';
		$email  = isset( $body['email'] ) ? sanitize_email( (string) $body['email'] ) : '';

		if ( '' === $number || '' === $email ) {
			$this->send( array( 'error' => 'not_found' ), 404 );
		}

		$order = $this->find_order( $number );

		// Identical failure for every reason, compared in constant time. The
		// email is never logged, here or anywhere in this class.
		if ( ! $order || ! hash_equals(
			hash( 'sha256', strtolower( trim( (string) $order->get_billing_email() ) ) ),
			hash( 'sha256', strtolower( trim( $email ) ) )
		) ) {
			$this->send( array( 'error' => 'not_found' ), 404 );
		}

		$this->send( array( 'order' => $this->sanitizer->sanitize( $order, true ) ) );
	}

	/**
	 * Finds an order by the number a shopper would recognise.
	 *
	 * The displayed number is not always the post ID: sequential-order-number
	 * plugins override it. Try the ID, confirm the displayed number matches,
	 * then fall back to the meta key those plugins use.
	 *
	 * @param string $number Order number as the shopper typed it.
	 * @return WC_Order|null
	 */
	private function find_order( $number ) {
		$number = trim( (string) $number );

		if ( '' === $number ) {
			return null;
		}

		/**
		 * Filters order-number resolution, for stores whose numbering this
		 * class does not recognise. Return a WC_Order or null.
		 *
		 * @param WC_Order|null $order  Resolved order, initially null.
		 * @param string        $number Order number as entered.
		 */
		$filtered = apply_filters( 'idea89_resolve_order_number', null, $number );

		if ( $filtered instanceof WC_Order ) {
			return $filtered;
		}

		$candidate = ltrim( $number, '#' );

		if ( ctype_digit( $candidate ) ) {
			$order = wc_get_order( (int) $candidate );

			if ( $order instanceof WC_Order && (string) $order->get_order_number() === $number ) {
				return $order;
			}

			if ( $order instanceof WC_Order && (string) $order->get_id() === $candidate ) {
				return $order;
			}
		}

		$matches = wc_get_orders(
			array(
				'limit'      => 1,
				'status'     => $this->listable_statuses(),
				'meta_key'   => '_order_number', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed lookup of one order.
				'meta_value' => $number,         // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Exact match, not a scan.
			)
		);

		if ( is_array( $matches ) && isset( $matches[0] ) && $matches[0] instanceof WC_Order ) {
			return $matches[0];
		}

		return null;
	}

	/**
	 * Statuses a shopper may see. Drafts and trashed orders are excluded.
	 *
	 * @return string[]
	 */
	private function listable_statuses() {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();
		$statuses = array_map(
			function ( $status ) {
				return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
			},
			$statuses
		);

		return array_values( array_diff( $statuses, array( 'checkout-draft', 'trash' ) ) );
	}

	/**
	 * Best-effort client IP for throttling only.
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
	 * Sends JSON and stops. Never cached: responses are per-session.
	 *
	 * @param array<string,mixed> $payload Response body.
	 * @param int                 $status  HTTP status code.
	 * @return void
	 */
	private function send( $payload, $status = 200 ) {
		nocache_headers();
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $payload );
		exit;
	}
}
