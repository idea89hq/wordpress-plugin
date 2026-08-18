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
);

foreach ( $idea89_options as $idea89_option ) {
	delete_option( $idea89_option );
}

delete_transient( 'idea89_remote_cfg' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'idea89' );
}
