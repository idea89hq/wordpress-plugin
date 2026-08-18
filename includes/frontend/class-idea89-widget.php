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
		?>
<script type="text/javascript">
window.__IDEA89_PLATFORM = 'woocommerce';
window.__IDEA89_WC = {
	storeApi: '<?php echo esc_js( $store_api ); ?>',
	nonce: '<?php echo esc_js( $nonce ); ?>',
	cartUrl: '<?php echo esc_js( $cart_url ); ?>'
};
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
}
