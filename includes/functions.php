<?php
/**
 * Shared service factories, used by Action Scheduler job handlers and
 * anywhere else that needs a configured client or syncer without wiring
 * one up by hand.
 *
 * Kept in its own file rather than alongside Idea89_Plugin: WordPress
 * coding standards (and our phpcs.xml.dist) require a file to contain
 * either an OO structure or function declarations, never both.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared configuration reader.
 *
 * @return Idea89_Config
 */
function idea89_config() {
	static $config = null;
	if ( null === $config ) {
		$config = new Idea89_Config();
	}
	return $config;
}

/**
 * Shared API client.
 *
 * @return Idea89_Client
 */
function idea89_client() {
	static $client = null;
	if ( null === $client ) {
		$client = new Idea89_Client( idea89_config() );
	}
	return $client;
}

/**
 * Shared catalogue syncer.
 *
 * @return Idea89_Catalog_Syncer
 */
function idea89_catalog_syncer() {
	static $syncer = null;
	if ( null === $syncer ) {
		$syncer = new Idea89_Catalog_Syncer( idea89_config(), idea89_client(), new Idea89_Product_Serializer() );
	}
	return $syncer;
}

/**
 * Shared stock syncer.
 *
 * @return Idea89_Stock_Syncer
 */
function idea89_stock_syncer() {
	static $syncer = null;
	if ( null === $syncer ) {
		$syncer = new Idea89_Stock_Syncer( idea89_config(), idea89_client() );
	}
	return $syncer;
}

/**
 * Shared promo syncer.
 *
 * @return Idea89_Promo_Syncer
 */
function idea89_promo_syncer() {
	static $syncer = null;
	if ( null === $syncer ) {
		$syncer = new Idea89_Promo_Syncer( idea89_config(), idea89_client() );
	}
	return $syncer;
}

/**
 * Shared content syncer.
 *
 * @return Idea89_Content_Syncer
 */
function idea89_content_syncer() {
	static $syncer = null;
	if ( null === $syncer ) {
		$syncer = new Idea89_Content_Syncer( idea89_config(), idea89_client() );
	}
	return $syncer;
}

/**
 * Shared FAQ syncer.
 *
 * @return Idea89_Faq_Syncer
 */
function idea89_faq_syncer() {
	static $syncer = null;
	if ( null === $syncer ) {
		$syncer = new Idea89_Faq_Syncer( idea89_config(), idea89_client(), new Idea89_Faq_Detector() );
	}
	return $syncer;
}

/**
 * Shared document syncer.
 *
 * @return Idea89_Document_Syncer
 */
function idea89_document_syncer() {
	static $syncer = null;
	if ( null === $syncer ) {
		$syncer = new Idea89_Document_Syncer( idea89_config(), idea89_client() );
	}
	return $syncer;
}

/**
 * Shared order-tracking settings accessor.
 *
 * @return Idea89_Order_Tracking_Config
 */
function idea89_order_tracking_config() {
	static $config = null;

	if ( null === $config ) {
		$config = new Idea89_Order_Tracking_Config();
	}

	return $config;
}

/**
 * Shared order endpoint router.
 *
 * @return Idea89_Order_Endpoints
 */
function idea89_order_endpoints() {
	static $endpoints = null;

	if ( null === $endpoints ) {
		$endpoints = new Idea89_Order_Endpoints(
			idea89_order_tracking_config(),
			new Idea89_Order_Sanitizer( new Idea89_Tracking_Url_Resolver() ),
			new Idea89_Guest_Rate_Limit(),
			idea89_personalization_config(),
			idea89_config()
		);
	}

	return $endpoints;
}

/**
 * Shared personalization settings accessor.
 *
 * @return Idea89_Personalization_Config
 */
function idea89_personalization_config() {
	static $config = null;

	if ( null === $config ) {
		$config = new Idea89_Personalization_Config();
	}

	return $config;
}

/**
 * Shared locator settings accessor.
 *
 * @return Idea89_Locator_Config
 */
function idea89_locator_config() {
	static $config = null;

	if ( null === $config ) {
		$config = new Idea89_Locator_Config();
	}

	return $config;
}

/**
 * Shared dashboard-settings reader.
 *
 * @return Idea89_Remote_Config
 */
function idea89_remote_config() {
	static $remote = null;

	if ( null === $remote ) {
		$remote = new Idea89_Remote_Config( idea89_config() );
	}

	return $remote;
}

/**
 * Shared store-finder page controller.
 *
 * @return Idea89_Locator_Page
 */
function idea89_locator_page() {
	static $page = null;

	if ( null === $page ) {
		$page = new Idea89_Locator_Page( idea89_locator_config(), idea89_remote_config(), idea89_config() );
	}

	return $page;
}
