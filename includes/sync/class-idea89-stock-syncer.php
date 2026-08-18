<?php
/**
 * Lightweight stock-only sync.
 *
 * Stock changes on every order, so pushing a full product upsert each time
 * would re-embed the catalogue constantly. /v1/catalog/stock touches quantity
 * and availability only and never regenerates embeddings.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stock sync.
 */
class Idea89_Stock_Syncer {

	/**
	 * Configuration reader.
	 *
	 * @var Idea89_Config
	 */
	private $config;

	/**
	 * API client.
	 *
	 * @var Idea89_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Config $config Configuration reader.
	 * @param Idea89_Client $client API client.
	 */
	public function __construct( Idea89_Config $config, Idea89_Client $client ) {
		$this->config = $config;
		$this->client = $client;
	}

	/**
	 * Logs when WP_DEBUG is on.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'IDEA89: ' . $message );
		}
	}

	/**
	 * Pushes stock for one product.
	 *
	 * Variations report against their parent, because the catalogue is keyed on
	 * the parent product id.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return bool
	 */
	public function sync_product( $product_id ) {
		if ( ! $this->config->is_configured() ) {
			return false;
		}

		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return false;
		}

		if ( $product->is_type( 'variation' ) ) {
			$parent_id = (int) $product->get_parent_id();
			if ( ! $parent_id ) {
				// An orphaned variation has no parent to report against, and the
				// catalogue is keyed on the parent id — sending the variation's own
				// id would create stock for a product that does not exist there.
				$this->log( 'stock sync skipped — variation ' . $product->get_id() . ' has no parent' );
				return false;
			}
			$parent = wc_get_product( $parent_id );
			if ( ! $parent ) {
				$this->log( 'stock sync skipped — parent ' . $parent_id . ' not found for variation ' . $product->get_id() );
				return false;
			}
			$product = $parent;
		}

		return $this->client->upsert_stock(
			array(
				array(
					'external_id' => (string) $product->get_id(),
					'in_stock'    => (bool) $product->is_in_stock(),
					'stock_qty'   => null === $product->get_stock_quantity() ? null : (int) $product->get_stock_quantity(),
				),
			)
		);
	}
}
