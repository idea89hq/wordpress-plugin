<?php
/**
 * Prints the widget loader in the storefront footer.
 *
 * The inline config block MUST be emitted before the loader script. The widget
 * reads window.__IDEA89_WC when it boots, and without it the WooCommerce
 * add-to-cart branch falls back to the Magento path and fails.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storefront widget embed.
 */
class Idea89_Widget {

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
	 * Hooks the footer renderer.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_footer', array( $this, 'render' ), 20 );
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
	 * Prints the config block and the loader script.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! $this->should_render() ) {
			return;
		}

		$api_key     = $this->config->get_api_key();
		$loader_url  = self::build_loader_url( $this->config->get_api_url(), $api_key );
		$position    = $this->config->get_widget_position();
		$brand_color = $this->config->get_brand_color();

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
		?>
<script type="text/javascript">
window.__IDEA89_PLATFORM = 'woocommerce';
window.__IDEA89_WC = {
	storeApi: '<?php echo esc_js( $store_api ); ?>',
	nonce: '<?php echo esc_js( $nonce ); ?>',
	cartUrl: '<?php echo esc_js( $cart_url ); ?>'
};
window.__IDEA89_CHECKOUT = <?php echo wp_json_encode( $checkout_cfg ); ?>;
</script>
<script
	src="<?php echo esc_url( $loader_url ); ?>"
	data-key="<?php echo esc_attr( $api_key ); ?>"
	data-position="<?php echo esc_attr( $position ); ?>"
		<?php
		if ( '' !== $brand_color ) :
			?>
			data-color="<?php echo esc_attr( $brand_color ); ?>"<?php endif; ?>
	async
></script>
		<?php
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
