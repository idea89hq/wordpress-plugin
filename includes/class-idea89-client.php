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
	 * Option holding the last catalogue write refused over the sync key:
	 * array{message: string, at: int}. Syncs run in Action Scheduler, so
	 * "Sync now" cannot return the refusal; the settings page reads this
	 * instead. Cleared by the next accepted catalogue write.
	 */
	const SYNC_KEY_REJECTION_OPTION = 'idea89_sync_key_rejection';

	/**
	 * API error codes that mean "the catalogue sync key is the problem".
	 *
	 * @var string[]
	 */
	const SYNC_KEY_ERRORS = array( 'sync_key_not_set', 'sync_key_required', 'invalid_sync_key' );

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
	 * The site's base path, sent as X-IDEA89-Site-Path.
	 *
	 * A WordPress install in a subfolder (https://example.com/wordpress) is a
	 * separate IDEA89 account from a shop at the domain root. The API uses this
	 * to reject an API key issued for the other site on the same host.
	 *
	 * Returns '/' for a root install, '/wordpress' for a subfolder.
	 *
	 * DO NOT change the root value back to the empty string. libcurl treats a
	 * header written as "Name: " (colon then only whitespace) as an instruction
	 * to REMOVE that header, so an empty value never reaches the API at all —
	 * and the API reads an absent header as "this plugin is too old to report a
	 * path" and lets the request through. A root install would then be able to
	 * sync into a subfolder store's catalog with the wrong API key, which is
	 * the exact mix-up this header exists to stop. '/' survives the wire, and
	 * the API's normalizeSitePath('/') returns '' — so it round-trips to the
	 * same value a root store is registered with, and still matches.
	 *
	 * @return string
	 */
	public function site_path_header() {
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return '/';
		}
		$path = strtolower( rtrim( $path, '/' ) );
		if ( '' === $path ) {
			return '/';
		}
		return '/' === substr( $path, 0, 1 ) ? $path : '/' . $path;
	}

	/**
	 * Human-readable identity of this site: 'example.com', or
	 * 'example.com/wordpress' for a subfolder install.
	 *
	 * Mirrors displaySite() in the API, and is deliberately NOT the same string
	 * as domain_header() . site_path_header(): the header reports '/' for a
	 * root install so that it survives the wire, but a merchant should be shown
	 * 'example.com', not 'example.com/'.
	 *
	 * @return string
	 */
	public function site_label() {
		$path = $this->site_path_header();
		return $this->domain_header() . ( '/' === $path ? '' : $path );
	}

	/**
	 * Shared request headers.
	 *
	 * @return array<string, string>
	 */
	private function headers() {
		return array(
			'Content-Type'       => 'application/json',
			'X-IDEA89-Key'       => $this->config->get_api_key(),
			'X-IDEA89-Domain'    => $this->domain_header(),
			'X-IDEA89-Site-Path' => $this->site_path_header(),
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
	 * The last catalogue write refused over the sync key, or null.
	 *
	 * @return array{message: string, at: int}|null
	 */
	public function get_sync_key_rejection() {
		$value = get_option( self::SYNC_KEY_REJECTION_OPTION, null );
		return is_array( $value ) && isset( $value['message'] ) ? $value : null;
	}

	/**
	 * The API's merchant-facing message when a response is a sync-key
	 * refusal, else null.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 * @return string|null
	 */
	public static function sync_key_error_message( $code, $body ) {
		if ( 401 !== (int) $code ) {
			return null;
		}
		$data = json_decode( (string) $body, true );
		if ( ! is_array( $data ) || ! isset( $data['error'] ) || ! in_array( $data['error'], self::SYNC_KEY_ERRORS, true ) ) {
			return null;
		}
		$message = isset( $data['message'] ) && is_string( $data['message'] ) ? trim( $data['message'] ) : '';
		if ( '' !== $message ) {
			return $message;
		}
		return sprintf(
			/* translators: %s: API error code */
			__( 'Catalogue sync was refused (%s). Check the Catalogue sync key setting.', 'idea89-ai-shopping-assistant' ),
			$data['error']
		);
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

		$headers = $this->headers();
		// Catalog writes only. Never added when unset: libcurl drops an empty
		// header value anyway, and an absent key keeps today's behaviour.
		$sync_key = $this->config->get_sync_key();
		if ( '' !== $sync_key && 0 === strpos( $path, '/v1/catalog/' ) ) {
			$headers['X-IDEA89-Sync-Key'] = $sync_key;
		}

		$response = wp_remote_post(
			$this->config->get_api_url() . $path,
			array(
				'timeout' => $timeout,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( $path . ' failed: ' . $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$response_body = (string) wp_remote_retrieve_body( $response );
			$this->log( $path . ' failed: HTTP ' . $code . ' ' . substr( $response_body, 0, 500 ) );
			$rejection = self::sync_key_error_message( $code, $response_body );
			if ( null !== $rejection ) {
				update_option(
					self::SYNC_KEY_REJECTION_OPTION,
					array(
						'message' => $rejection,
						'at'      => time(),
					),
					false
				);
			}
			return false;
		}

		if ( 0 === strpos( $path, '/v1/catalog/' ) && null !== $this->get_sync_key_rejection() ) {
			delete_option( self::SYNC_KEY_REJECTION_OPTION );
		}

		return true;
	}

	/**
	 * Writes the shared checkout-display setting to the merchant's IDEA89
	 * account, which is where it lives. WordPress holds only a render cache.
	 *
	 * Returns true only on a confirmed 2xx. The caller REVERTS the save when
	 * this is false: quietly keeping a local value the dashboard never learned
	 * about is the divergence this design exists to prevent.
	 *
	 * @param string $value Either 'full' or 'inline'.
	 * @return bool
	 */
	public function update_checkout_ui( $value ) {
		return $this->post( '/v1/plugin-settings', array( 'checkout_ui' => $value ) );
	}

	/**
	 * Writes the shared assistant name to the merchant's IDEA89 account.
	 *
	 * Same contract as update_checkout_ui: true only on a confirmed 2xx, and
	 * the caller reverts the save when it is false.
	 *
	 * @param string $value The name shown above the conversation.
	 * @return bool
	 */
	public function update_assistant_name( $value ) {
		return $this->post( '/v1/plugin-settings', array( 'assistant_name' => $value ) );
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
		// Schema 2: an IDEA89 API that predates it ignores the version, the
		// platform and the schema-2 product keys.
		return $this->post(
			'/v1/catalog/upsert',
			array(
				'schema_version' => 2,
				'platform'       => 'woocommerce',
				'products'       => $products,
			),
			self::BATCH_TIMEOUT
		);
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
	 * On success the response also names the connected site (host, or
	 * host/path for a subfolder install), so a key pasted from another
	 * IDEA89 account on the same host is visible immediately rather than
	 * silently syncing into the wrong store. A 403 site_path_mismatch
	 * response carries that same information in its message when the key
	 * belongs to a rival site entirely.
	 *
	 * @return array{ok: bool, error: string, site?: string}
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
					'X-IDEA89-Key'       => $this->config->get_api_key(),
					'X-IDEA89-Domain'    => $this->domain_header(),
					'X-IDEA89-Site-Path' => $this->site_path_header(),
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
			$body  = json_decode( wp_remote_retrieve_body( $response ), true );
			$site  = is_array( $body ) && isset( $body['site'] ) ? (string) $body['site'] : '';
			$check = $this->verify_catalog_access();
			if ( '' !== $check ) {
				return array(
					'ok'    => false,
					'error' => $check,
				);
			}
			return array(
				'ok'    => true,
				'error' => '',
				'site'  => $site,
			);
		}

		$this->log( 'test_connection: HTTP ' . $code );

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 403 === $code && is_array( $body ) && isset( $body['error'] ) && 'site_path_mismatch' === $body['error'] ) {
			// The API's message names the site this key really belongs to —
			// surfacing it verbatim is the whole point of the check, so it
			// must not be flattened into the generic "key rejected" wording.
			return array(
				'ok'    => false,
				'error' => isset( $body['message'] ) ? (string) $body['message'] : sprintf(
					/* translators: %d: HTTP status code */
					__( 'API key rejected (HTTP %d). Check your key.', 'idea89-ai-shopping-assistant' ),
					$code
				),
			);
		}

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

	/**
	 * Asks the API whether a catalogue sync from this site would be accepted:
	 * the same checks a real sync gets, sync key included, with nothing
	 * written. A missing key otherwise only shows up as an empty catalogue,
	 * because the sync itself runs in the background.
	 *
	 * @return string Empty when accepted, else a message for the merchant.
	 */
	private function verify_catalog_access() {
		$headers  = $this->headers();
		$sync_key = $this->config->get_sync_key();
		if ( '' !== $sync_key ) {
			$headers['X-IDEA89-Sync-Key'] = $sync_key;
		}

		$response = wp_remote_post(
			$this->config->get_api_url() . '/v1/catalog/verify',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $headers,
				'body'    => '{}',
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'catalogue access check failed: ' . $response->get_error_message() );
			return sprintf(
				/* translators: %s: error message */
				__( 'Connected, but the catalogue check failed: %s', 'idea89-ai-shopping-assistant' ),
				$response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		// 404: an API older than this plugin; the connection check already passed.
		if ( 200 === $code || 404 === $code ) {
			if ( 200 === $code && null !== $this->get_sync_key_rejection() ) {
				delete_option( self::SYNC_KEY_REJECTION_OPTION );
			}
			return '';
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$this->log( 'catalogue access check: HTTP ' . $code . ' ' . substr( $body, 0, 500 ) );
		$rejection = self::sync_key_error_message( $code, $body );
		if ( null !== $rejection ) {
			return sprintf(
				/* translators: %s: the API's explanation, e.g. how to create the key */
				__( 'Connected, but catalogue sync will be refused: %s', 'idea89-ai-shopping-assistant' ),
				$rejection
			);
		}
		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'Connected, but the API refused catalogue access (HTTP %d). Check your API key.', 'idea89-ai-shopping-assistant' ),
			$code
		);
	}
}
