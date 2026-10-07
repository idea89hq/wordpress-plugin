<?php
/**
 * Enqueues the widget loader in the storefront footer.
 *
 * The inline config block MUST be emitted before the loader script. The widget
 * reads window.__IDEA89_WC when it boots, and without it the WooCommerce
 * add-to-cart branch falls back to the Magento path and fails. That ordering
 * is guaranteed by attaching the config with wp_add_inline_script( ..., 'before' )
 * to the loader's own handle, rather than by printing two tags in sequence.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storefront widget embed.
 */
class Idea89_Widget {

	/**
	 * Script handle for the loader. Core derives the tag's id from it
	 * ("{handle}-js"), which is how loader_attributes() recognises our tag.
	 */
	const HANDLE = 'idea89-widget';

	/**
	 * Configuration reader.
	 *
	 * @var Idea89_Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Config $config Configuration reader.
	 */
	public function __construct( Idea89_Config $config ) {
		$this->config = $config;
	}

	/**
	 * Hooks the enqueue and the attribute filter for the loader tag.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'wp_script_attributes', array( $this, 'loader_attributes' ) );
	}

	/**
	 * Builds the loader script URL.
	 *
	 * @param string $api_url API base URL.
	 * @param string $api_key Store API key.
	 * @return string
	 */
	public static function build_loader_url( $api_url, $api_key ) {
		return rtrim( (string) $api_url, '/' ) . '/widget/v1/' . rawurlencode( (string) $api_key ) . '.js';
	}

	/**
	 * True when the widget should appear on this request.
	 *
	 * @return bool
	 */
	public function should_render() {
		if ( is_admin() ) {
			return false;
		}
		return $this->config->is_enabled() && $this->config->is_configured();
	}

	/**
	 * Registers the loader script with the config block attached before it.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->should_render() ) {
			return;
		}

		$loader_url = self::build_loader_url( $this->config->get_api_url(), $this->config->get_api_key() );

		// Version is null on purpose: the API versions the loader itself and
		// appending ?ver= would fight its own cache headers. Async matches
		// the tag the loader has always shipped with; 'before' is the one
		// inline position that keeps an async script async.
		wp_register_script(
			self::HANDLE,
			$loader_url,
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the API versions the loader itself.
			array(
				'in_footer' => true,
				'strategy'  => 'async',
			)
		);
		wp_add_inline_script( self::HANDLE, $this->config_js(), 'before' );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Adds the loader's data-* attributes to its own <script> tag, and no
	 * other. Core escapes every value when it prints the tag.
	 *
	 * @param array<string,mixed> $attributes Tag attributes core is about to print.
	 * @return array<string,mixed>
	 */
	public function loader_attributes( $attributes ) {
		if ( ! isset( $attributes['id'] ) || self::HANDLE . '-js' !== $attributes['id'] ) {
			return $attributes;
		}

		$attributes['data-key']      = $this->config->get_api_key();
		$attributes['data-position'] = $this->config->get_widget_position();

		return $attributes;
	}

	/**
	 * The JavaScript that publishes the storefront globals the widget reads
	 * on boot. Returned rather than printed so wp_add_inline_script() can
	 * place it, and so tests can read it without output buffering.
	 *
	 * @return string
	 */
	public function config_js() {
		$store_api = function_exists( 'get_rest_url' ) ? get_rest_url( null, 'wc/store/v1' ) : '';
		$nonce     = wp_create_nonce( 'wc_store_api' );
		$cart_url  = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';

		// Platform-neutral checkout capability contract, published alongside
		// (never instead of) __IDEA89_WC above — see checkoutBootJs() in
		// api/src/widget/checkout/index.ts for the reader. formKey has no
		// WooCommerce equivalent (Woo uses nonces, not a form key), so it
		// carries the Store API nonce: every POST the checkout chunk makes
		// goes through the Store API, which is what that nonce is for.
		$checkout_config = new Idea89_Checkout_Config();
		$checkout_cfg    = array(
			'platform'         => 'woocommerce',
			'checkoutMode'     => $checkout_config->get_mode(),
			'cartPath'         => $checkout_config->get_cart_path(),
			'checkoutPath'     => $checkout_config->get_checkout_path(),
			// GET /?idea89-checkout=1 — Idea89_Mini_Checkout::QUERY_VAR is the
			// single source of truth for the query var name (Task 6.3). The
			// widget's ckUrl() resolves this against window.location.origin
			// and already tolerates a path that carries a query string.
			//
			// Prefixed with the site's own base path (mini_checkout_base_path()),
			// same class of derivation cartPath/checkoutPath already use via
			// Idea89_Checkout_Config::url_to_path() — miniCheckoutPath has no
			// WooCommerce URL of its own to reduce (it is our query var, not a
			// Woo page), so this reduces home_url() instead. Without the prefix,
			// a subfolder install (https://host/shop/) would publish the bare
			// '/?idea89-checkout=1', which the widget's ckUrl() resolves against
			// window.location.origin straight to the site ROOT — missing the
			// /shop/ segment WordPress needs to route the request at all.
			'miniCheckoutPath' => $this->mini_checkout_base_path() . '/?' . Idea89_Mini_Checkout::QUERY_VAR . '=1',
			// Woo has no form key; every POST this chunk makes goes through
			// the Store API, so its nonce is what belongs here.
			'formKey'          => $nonce,
			// Same key/type as the Magento 2 module publishes
			// (Block/Widget::getClientBootstrapJs) — the widget reads one
			// platform-neutral ckCfg.checkoutBar boolean either way.
			'checkoutBar'      => $checkout_config->is_checkout_bar_enabled(),
		);

		// Both objects land inside a <script> element, so both go through
		// wp_json_encode() with the JSON_HEX_* flags: < > & ' " become
		// \u003C etc., which a JS object literal reads back as the original
		// characters, so no value can carry a "</script>" that terminates
		// the inline block. Not esc_js(): that is for attribute context
		// (onclick="...") and turns & < > into HTML entities, which a script
		// element does not decode, so a URL with a query string would reach
		// the widget corrupted.
		$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

		// __IDEA89_WC keeps the exact keys the shipped widget's
		// _addToCartWoo and _cartSummaryWoo read: storeApi, nonce, cartUrl.
		$wc_json       = wp_json_encode(
			array(
				'storeApi' => $store_api,
				'nonce'    => $nonce,
				'cartUrl'  => $cart_url,
				// '' for a root install, '/shop' for a subfolder one. The chat
				// widget and the order-tracking card prefix /idea89/... with it,
				// so a subfolder site's customer/me and order routes resolve.
				'basePath' => $this->mini_checkout_base_path(),
			),
			$flags
		);
		$checkout_json = wp_json_encode( $checkout_cfg, $flags );

		return "window.__IDEA89_PLATFORM = 'woocommerce';\n"
			. 'window.__IDEA89_WC = ' . $wc_json . ";\n"
			. 'window.__IDEA89_CHECKOUT = ' . $checkout_json . ';';
	}

	/**
	 * The site's base path with no trailing slash: '' for a root install,
	 * '/shop' for a subfolder one. Same wp_parse_url(home_url())
	 * derivation Idea89_Client::site_path_header() already uses to report
	 * this site's identity to the API, applied here so a locally-published
	 * path (miniCheckoutPath) resolves correctly for a subfolder install
	 * too, not only the API-facing header.
	 *
	 * @return string
	 */
	private function mini_checkout_base_path() {
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return '';
		}
		return rtrim( $path, '/' );
	}
}
