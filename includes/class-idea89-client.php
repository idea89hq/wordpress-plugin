<?php
/**
 * HTTP client for the IDEA89 SaaS API.
 *
 * Endpoint paths, header names and timeouts match the Magento modules exactly,
 * so the API sees no difference in callers.
 *
 * Every method swallows its failures and returns a boolean. Nothing here may
 * throw: these run inside admin requests and Action Scheduler jobs, and a
 * fatal in either is a broken store.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to api.idea89.com.
 */
class Idea89_Client {

	const TIMEOUT       = 15;
	const BATCH_TIMEOUT = 60;

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
	 * The site's hostname, sent as X-IDEA89-Domain for origin validation.
	 *
	 * @return string
	 */
	public function domain_header() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? $host : '';
	}

	/**
	 * Shared request headers.
	 *
	 * @return array<string, string>
	 */
	private function headers() {
		return array(
			'Content-Type'    => 'application/json',
			'X-IDEA89-Key'    => $this->config->get_api_key(),
			'X-IDEA89-Domain' => $this->domain_header(),
		);
	}

	/**
	 * Logs a message when WP_DEBUG is on. Never writes to the page.
	 *
	 * @param string $message Message to record.
	 * @return void
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'IDEA89: ' . $message );
		}
	}

	/**
	 * POSTs a JSON payload and reports whether it was accepted.
	 *
	 * @param string $path    Path beneath the API base, e.g. /v1/catalog/upsert.
	 * @param array  $payload Body to encode as JSON.
	 * @param int    $timeout Seconds.
	 * @return bool
	 */
	private function post( $path, array $payload, $timeout = self::TIMEOUT ) {
		if ( ! $this->config->is_configured() ) {
			$this->log( 'skipped ' . $path . ' — no API key configured' );
			return false;
		}

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			// wp_json_encode returns false on invalid UTF-8 rather than throwing.
			// Posting an empty body risks a 2xx that would be read as success,
			// so fail here instead.
			$this->log( $path . ' skipped — payload could not be encoded as JSON' );
			return false;
		}

		$response = wp_remote_post(
			$this->config->get_api_url() . $path,
			array(
				'timeout' => $timeout,
				'headers' => $this->headers(),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( $path . ' failed: ' . $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->log( $path . ' failed: HTTP ' . $code . ' ' . substr( (string) wp_remote_retrieve_body( $response ), 0, 500 ) );
			return false;
		}

		return true;
	}

	/**
	 * Upserts a batch of serialised products.
	 *
	 * @param array $products Serialised products, max 100.
	 * @return bool
	 */
	public function upsert_products( array $products ) {
		if ( empty( $products ) ) {
			return true;
		}
		return $this->post( '/v1/catalog/upsert', array( 'products' => $products ), self::BATCH_TIMEOUT );
	}

	/**
	 * Removes products from the catalogue (unpublished, trashed or deleted).
	 *
	 * @param array $external_ids IDs to remove, max 500.
	 * @return bool
	 */
	public function delete_products( array $external_ids ) {
		if ( empty( $external_ids ) ) {
			return true;
		}
		return $this->post( '/v1/catalog/delete', array( 'external_ids' => array_values( $external_ids ) ) );
	}

	/**
	 * Syncs content items (categories, pages, store info).
	 *
	 * @param array $items Content items, max 500.
	 * @return bool
	 */
	public function sync_content( array $items ) {
		if ( empty( $items ) ) {
			return true;
		}
		return $this->post( '/v1/catalog/content', array( 'items' => $items ) );
	}

	/**
	 * Withdraws content items (a page that was unpublished, trashed or deleted).
	 *
	 * The mirror of delete_documents(). /v1/catalog/content is upsert-only, so
	 * without this a page synced while published and then hidden would stay in
	 * store_content forever and keep being quoted to shoppers.
	 *
	 * @param string $type         Content type: category, cms_page or store_info.
	 * @param array  $external_ids IDs to remove, max 500.
	 * @return bool
	 */
	public function delete_content( $type, array $external_ids ) {
		if ( empty( $external_ids ) ) {
			return true;
		}
		return $this->post(
			'/v1/catalog/content/delete',
			array(
				'type'         => (string) $type,
				'external_ids' => array_values( $external_ids ),
			)
		);
	}

	/**
	 * Removes every FAQ for the store that is not in the supplied set.
	 *
	 * FAQs are keyed on the normalised question, not an external id, so removal
	 * is expressed as "here is everything that still exists" rather than "delete
	 * these". The caller MUST have enumerated the complete current set: a
	 * partial list deletes real FAQs.
	 *
	 * @param array $questions Every question currently present upstream.
	 * @return bool
	 */
	public function prune_faqs( array $questions ) {
		if ( empty( $questions ) ) {
			return true;
		}
		return $this->post( '/v1/catalog/faqs/prune', array( 'questions' => array_values( $questions ) ) );
	}

	/**
	 * Upserts coupon codes.
	 *
	 * @param array $promos Promos, max 50.
	 * @return bool
	 */
	public function upsert_promos( array $promos ) {
		if ( empty( $promos ) ) {
			return true;
		}
		return $this->post( '/v1/catalog/promos', array( 'promos' => $promos ) );
	}

	/**
	 * Upserts stock levels only.
	 *
	 * @param array $items Stock items, max 500.
	 * @return bool
	 */
	public function upsert_stock( array $items ) {
		if ( empty( $items ) ) {
			return true;
		}
		return $this->post( '/v1/catalog/stock', array( 'items' => $items ), self::BATCH_TIMEOUT );
	}

	/**
	 * Indexes documents (posts, pages, custom post types).
	 *
	 * @param array $documents Documents, max 50.
	 * @return bool
	 */
	public function index_documents( array $documents ) {
		if ( empty( $documents ) ) {
			return true;
		}
		return $this->post( '/v1/catalog/documents', array( 'documents' => $documents ), self::BATCH_TIMEOUT );
	}

	/**
	 * Removes documents that no longer exist or are no longer published.
	 *
	 * @param string $doc_type     Document type.
	 * @param array  $external_ids IDs to remove, max 500.
	 * @return bool
	 */
	public function delete_documents( $doc_type, array $external_ids ) {
		if ( empty( $external_ids ) ) {
			return true;
		}
		return $this->post(
			'/v1/catalog/documents/delete',
			array(
				'doc_type'     => $doc_type,
				'external_ids' => array_values( $external_ids ),
			)
		);
	}

	/**
	 * Upserts FAQ question and answer pairs.
	 *
	 * @param array $faqs FAQs, max 100.
	 * @return bool
	 */
	public function sync_faqs( array $faqs ) {
		if ( empty( $faqs ) ) {
			return true;
		}
		return $this->post( '/v1/catalog/faqs', array( 'faqs' => $faqs ) );
	}

	/**
	 * Pings GET /v1/catalog/stats with the configured key.
	 *
	 * /health is deliberately NOT used here: it is unauthenticated (it exists
	 * so uptime monitors can probe the API without a key), so it returns 200
	 * for a bogus or missing key just as readily as a real one — a merchant
	 * who mistypes their key would see "Connected", then a sync that silently
	 * does nothing. /v1/catalog/stats sits behind authenticateWidgetRequest
	 * (see api/src/routes/catalog.ts and api/src/middleware/auth.ts), so a
	 * wrong key genuinely fails here: 401 invalid_api_key for a key that
	 * matches no store, 403 domain_mismatch if X-IDEA89-Domain does not match
	 * the store's registered domain(s). This is the same endpoint the
	 * dashboard's "products synced" count reads, so it costs nothing extra.
	 *
	 * @return array{ok: bool, error: string}
	 */
	public function test_connection() {
		if ( ! $this->config->is_configured() ) {
			return array(
				'ok'    => false,
				'error' => __( 'No API key configured.', 'idea89-ai-shopping-assistant' ),
			);
		}

		$response = wp_remote_get(
			$this->config->get_api_url() . '/v1/catalog/stats',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'X-IDEA89-Key'    => $this->config->get_api_key(),
					'X-IDEA89-Domain' => $this->domain_header(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'test_connection failed: ' . $response->get_error_message() );
			return array(
				'ok'    => false,
				'error' => sprintf(
					/* translators: %s: error message */
					__( 'Connection failed: %s', 'idea89-ai-shopping-assistant' ),
					$response->get_error_message()
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return array(
				'ok'    => true,
				'error' => '',
			);
		}

		$this->log( 'test_connection: HTTP ' . $code );

		if ( 401 === $code || 403 === $code ) {
			return array(
				'ok'    => false,
				'error' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'API key rejected (HTTP %d). Check your key.', 'idea89-ai-shopping-assistant' ),
					$code
				),
			);
		}

		return array(
			'ok'    => false,
			'error' => sprintf(
				/* translators: %d: HTTP status code */
				__( 'API returned HTTP %d. Check your API URL and key.', 'idea89-ai-shopping-assistant' ),
				$code
			),
		);
	}
}
