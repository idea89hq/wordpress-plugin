<?php
/**
 * Pages the WooCommerce catalogue and pushes it to /v1/catalog/upsert.
 *
 * Work is paged rather than done in one pass: a 500-product catalogue in a
 * single request is a PHP timeout waiting to happen. Each page job syncs 100
 * products and the caller enqueues the next, so progress is visible in
 * WooCommerce > Status > Scheduled Actions and no single request runs long.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Full and incremental catalogue sync.
 */
class Idea89_Catalog_Syncer {

	const BATCH_SIZE = 100;

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
	 * Product serializer.
	 *
	 * @var Idea89_Product_Serializer
	 */
	private $serializer;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Config             $config     Configuration reader.
	 * @param Idea89_Client             $client     API client.
	 * @param Idea89_Product_Serializer $serializer Product serializer.
	 */
	public function __construct( Idea89_Config $config, Idea89_Client $client, Idea89_Product_Serializer $serializer ) {
		$this->config     = $config;
		$this->client     = $client;
		$this->serializer = $serializer;
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
	 * Starts a full sync by enqueuing the first page.
	 *
	 * @return array{synced: int, failed: int}
	 */
	public function sync_all() {
		if ( ! $this->config->is_configured() ) {
			$this->log( 'sync_all skipped — no API key configured' );
			return array(
				'synced' => 0,
				'failed' => 0,
			);
		}

		// null args matches a queued page job regardless of page number: a
		// page job already sitting in the queue under this hook+group means
		// a "Sync now" click is a no-op instead of starting a second,
		// independent page-1→N chain alongside the one already running.
		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( Idea89_Scheduler::HOOK_SYNC_PAGE, null, Idea89_Scheduler::GROUP ) ) {
			$this->log( 'sync_all skipped — a catalogue sync is already queued' );
			return array(
				'synced' => 0,
				'failed' => 0,
			);
		}

		Idea89_Scheduler::enqueue( Idea89_Scheduler::HOOK_SYNC_PAGE, array( 'page' => 1 ) );
		return array(
			'synced' => 0,
			'failed' => 0,
		);
	}

	/**
	 * Syncs one page of products.
	 *
	 * @param int $page One-based page number.
	 * @return array{synced: int, failed: int, has_more: bool}
	 */
	public function sync_page( $page ) {
		$result = array(
			'synced'   => 0,
			'failed'   => 0,
			'has_more' => false,
		);

		if ( ! $this->config->is_configured() ) {
			$this->log( 'sync_page skipped — no API key configured' );
			return $result;
		}

		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => self::BATCH_SIZE,
				'page'    => max( 1, (int) $page ),
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		if ( ! is_array( $products ) || empty( $products ) ) {
			return $result;
		}

		$batch = array();
		foreach ( $products as $product ) {
			try {
				$batch[] = $this->serializer->serialize( $product );
			} catch ( Throwable $e ) {
				// Throwable, not Exception: in PHP 7+ a malformed product object
				// raises Error/TypeError, which does NOT extend Exception. That is
				// the realistic way one bad product would otherwise take the whole
				// batch down with it.
				//
				// Per-product failure, never per-batch: one unserialisable
				// product must not silently drop the other 99.
				++$result['failed'];
				$this->log(
					'serialize failed for product ' .
					( is_object( $product ) && method_exists( $product, 'get_id' ) ? $product->get_id() : '?' ) .
					': ' . $e->getMessage()
				);
			}
		}

		if ( ! empty( $batch ) ) {
			if ( $this->client->upsert_products( $batch ) ) {
				$result['synced'] += count( $batch );
			} else {
				$result['failed'] += count( $batch );
				$this->log( 'batch rejected on page ' . $page );
			}
		}

		$result['has_more'] = count( $products ) >= self::BATCH_SIZE;

		if ( ! $result['has_more'] ) {
			update_option( 'idea89_last_full_sync_at', time(), false );
		}

		return $result;
	}

	/**
	 * Syncs a single product, used by the save hooks.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public function sync_product( $product_id ) {
		if ( ! $this->config->is_configured() ) {
			return false;
		}

		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			$this->log( 'sync_product skipped — product not found: ' . $product_id );
			return false;
		}

		if ( 'publish' !== get_post_status( (int) $product_id ) ) {
			return $this->client->delete_products( array( (string) $product_id ) );
		}

		try {
			$serialized = $this->serializer->serialize( $product );
		} catch ( Throwable $e ) {
			// See sync_page(): Error/TypeError does not extend Exception, so
			// this must catch Throwable to actually isolate a bad product.
			$this->log( 'sync_product serialize failed for ' . $product_id . ': ' . $e->getMessage() );
			return false;
		}

		return $this->client->upsert_products( array( $serialized ) );
	}
}
