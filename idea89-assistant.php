<?php
/**
 * Plugin Name:       IDEA89 AI Shopping Assistant
 * Plugin URI:        https://idea89.com
 * Description:       AI shopping assistant for WooCommerce. Answers product and policy questions, recommends products from your catalogue, and adds them to the basket.
 * Version:           1.0.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            4K Technologies Ltd
 * Author URI:        https://idea89.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       idea89-assistant
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.0
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

define( 'IDEA89_VERSION', '1.0.1' );
define( 'IDEA89_PLUGIN_FILE', __FILE__ );
define( 'IDEA89_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'IDEA89_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-plugin.php';

/**
 * Declares HPOS compatibility so order reads work on both storage backends.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

Idea89_Plugin::instance()->init();

register_activation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'idea89' );
		}
	}
);
