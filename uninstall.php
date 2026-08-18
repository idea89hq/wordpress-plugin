<?php
/**
 * Removes every trace of the plugin on uninstall.
 *
 * @package Idea89
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$idea89_options = array(
	'idea89_enabled',
	'idea89_api_key',
	'idea89_api_url',
	'idea89_assistant_name',
	'idea89_store_context',
	'idea89_widget_position',
	'idea89_brand_color',
	'idea89_sync_products',
	'idea89_sync_categories',
	'idea89_sync_pages',
	'idea89_sync_store_info',
	'idea89_sync_post_types',
	'idea89_sync_faqs',
	'idea89_last_full_sync_at',
	'idea89_order_tracking_enabled',
	'idea89_order_tracking_show_button',
	'idea89_order_tracking_support_url',
	'idea89_order_tracking_support_label',
	'idea89_order_tracking_max_recent',
	'idea89_personalization_enabled',
	'idea89_personalization_secret',
	'idea89_locator_enabled',
	'idea89_locator_url_path',
	'idea89_locator_layout',
	'idea89_locator_page_title',
	'idea89_locator_meta_description',
	'idea89_locator_hero_eyebrow',
	'idea89_locator_hero_h1',
	'idea89_locator_hero_subhead',
	'idea89_locator_help_heading',
	'idea89_locator_help_body',
	'idea89_locator_help_cta_label',
	'idea89_locator_help_cta_url',
);

foreach ( $idea89_options as $idea89_option ) {
	delete_option( $idea89_option );
}

delete_transient( 'idea89_remote_cfg' );
delete_transient( 'idea89_locator_locations' );

// Guest-lookup throttles are per-IP and expire within the hour, so they are
// left to lapse rather than swept with a LIKE query over the options table.

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'idea89' );
}
