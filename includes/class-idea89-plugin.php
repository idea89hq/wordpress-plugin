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

		load_plugin_textdomain( 'idea89-assistant', false, dirname( plugin_basename( IDEA89_PLUGIN_FILE ) ) . '/languages' );

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
		require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-hooks.php';
		require_once IDEA89_PLUGIN_DIR . 'includes/functions.php';

		$scheduler = new Idea89_Scheduler();
		$scheduler->register();

		$hooks = new Idea89_Hooks();
		$hooks->register();

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
	 * Admin notice shown when requirements are not met.
	 *
	 * @return void
	 */
	public function render_requirements_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: minimum WooCommerce version */
				__( 'IDEA89 Assistant requires WooCommerce %s or newer. The plugin is inactive until WooCommerce is installed and updated.', 'idea89-assistant' ),
				self::MIN_WC_VERSION
			)
		);
		echo '</p></div>';
	}
}
