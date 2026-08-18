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
