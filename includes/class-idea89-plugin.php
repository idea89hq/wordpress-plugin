<?php
/**
 * Plugin bootstrap: requirement checks and hook registration.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires every subsystem into WordPress.
 */
class Idea89_Plugin {

	const MIN_WC_VERSION = '8.0.0';

	/**
	 * Admin screens allowed to show the "WooCommerce missing" notice.
	 *
	 * The plugin's own pages are never registered when requirements fail, so
	 * the dashboard and the plugins list are the only screens a merchant can
	 * act from.
	 *
	 * @var string[]
	 */
	const NOTICE_SCREENS = array( 'dashboard', 'dashboard-network', 'plugins', 'plugins-network' );

	/**
	 * Singleton instance.
	 *
	 * @var Idea89_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return Idea89_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * True when the installed WooCommerce version meets our floor.
	 *
	 * Extracted as a pure static so it is testable without WordPress loaded.
	 *
	 * @param string|null $wc_version Installed WooCommerce version, or null if absent.
	 * @return bool
	 */
	public static function requirements_met( $wc_version ) {
		if ( empty( $wc_version ) ) {
			return false;
		}
		return version_compare( $wc_version, self::MIN_WC_VERSION, '>=' );
	}

	/**
	 * Registers hooks. Called once from the main plugin file.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
	}

	/**
	 * Deactivates with a notice when WooCommerce is missing or too old,
	 * rather than fatalling on a missing WC_Product later.
	 *
	 * @return void
	 */
	public function on_plugins_loaded() {
		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : null;

		if ( ! self::requirements_met( $wc_version ) ) {
			add_action( 'admin_notices', array( $this, 'render_requirements_notice' ) );
			return;
		}

		// No load_plugin_textdomain() call: WordPress has loaded translations
		// for wordpress.org-hosted plugins automatically since 4.6, and calling
		// it this early triggers the _load_textdomain_just_in_time notice on 6.7+.

		require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-client.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-scheduler.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-product-serializer.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-catalog-syncer.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-stock-syncer.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-promo-syncer.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-content-syncer.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-faq-detector.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-faq-syncer.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-document-syncer.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-order-tracking-config.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-tracking-url-resolver.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-order-sanitizer.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-guest-rate-limit.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/locator/class-idea89-remote-config.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/locator/class-idea89-locator-config.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/locator/class-idea89-locator-page.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/personalization/class-idea89-personalization-config.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/personalization/class-idea89-identity-token.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-order-endpoints.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-hooks.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/functions.php';

		$scheduler = new Idea89_Scheduler();
		$scheduler->register();

		$hooks = new Idea89_Hooks();
		$hooks->register();

		idea89_order_endpoints()->register();
		idea89_locator_page()->register();

		// The dashboard settings cache is keyed to nothing but the store, so
		// changing which store this site points at must drop it immediately
		// rather than leaving up to 15 minutes of another tenant's config.
		add_action( 'update_option_idea89_api_key', array( 'Idea89_Remote_Config', 'flush' ) );
		add_action( 'update_option_idea89_api_url', array( 'Idea89_Remote_Config', 'flush' ) );

		require_once IDEA89_PLUGIN_DIR . 'includes/frontend/class-idea89-widget.php';

		$widget = new Idea89_Widget( idea89_config() );
		$widget->register();

		if ( is_admin() ) {
			require_once IDEA89_PLUGIN_DIR . 'includes/admin/class-idea89-admin-settings.php';
			require_once IDEA89_PLUGIN_DIR . 'includes/admin/class-idea89-admin-ajax.php';

			$settings = new Idea89_Admin_Settings();
			$settings->register();

			$ajax = new Idea89_Admin_Ajax();
			$ajax->register();
		}
	}

	/**
	 * Decides whether the "WooCommerce missing" notice belongs on this screen.
	 *
	 * WordPress.org guideline 11 forbids nagging on every admin page, so the
	 * notice is limited to the screens a merchant can act from: the dashboard
	 * and the plugins list. The plugin's own pages never register while
	 * requirements fail, so they cannot appear here.
	 *
	 * @param string $screen_id Current admin screen id, '' when unknown.
	 * @return bool
	 */
	public static function should_show_requirements_notice( $screen_id ) {
		$screen_id = (string) $screen_id;

		if ( '' === $screen_id ) {
			return false;
		}

		return in_array( $screen_id, self::NOTICE_SCREENS, true );
	}

	/**
	 * Admin notice shown when requirements are not met.
	 *
	 * @return void
	 */
	public function render_requirements_notice() {
		$screen_id = '';

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();

			if ( $screen instanceof \WP_Screen ) {
				$screen_id = (string) $screen->id;
			}
		}

		if ( ! self::should_show_requirements_notice( $screen_id ) ) {
			return;
		}

		echo '<div class="notice notice-error is-dismissible"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: minimum WooCommerce version */
				__( 'IDEA89 Assistant requires WooCommerce %s or newer. The plugin is inactive until WooCommerce is installed and updated.', 'idea89-ai-shopping-assistant' ),
				self::MIN_WC_VERSION
			)
		);
		echo '</p></div>';
	}
}
