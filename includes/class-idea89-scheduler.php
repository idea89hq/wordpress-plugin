<?php
/**
 * Action Scheduler job definitions.
 *
 * Action Scheduler rather than WP-Cron: WP-Cron is request-triggered, so a
 * "daily" sync on a low-traffic store may not run for days, and long batch jobs
 * get killed mid-request. Action Scheduler ships with WooCommerce, runs a real
 * queue with retries, and exposes progress in WooCommerce > Status >
 * Scheduled Actions.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and dispatches background jobs.
 */
class Idea89_Scheduler {

	const GROUP = 'idea89';

	const HOOK_FULL_SYNC          = 'idea89_full_sync';
	const HOOK_SYNC_PAGE          = 'idea89_sync_catalog_page';
	const HOOK_SYNC_PRODUCT       = 'idea89_sync_product';
	const HOOK_DELETE_PRODUCT     = 'idea89_delete_product';
	const HOOK_SYNC_STOCK         = 'idea89_sync_stock';
	const HOOK_SYNC_CONTENT       = 'idea89_sync_content';
	const HOOK_SYNC_DOCUMENT      = 'idea89_sync_document';
	const HOOK_DELETE_DOCUMENT    = 'idea89_delete_document';
	const HOOK_SYNC_ALL_DOCUMENTS = 'idea89_sync_all_documents';
	const HOOK_SYNC_PROMOS        = 'idea89_sync_promos';
	const HOOK_SYNC_FAQS          = 'idea89_sync_faqs';
	const HOOK_DELETE_CONTENT     = 'idea89_delete_content';

	/**
	 * Wires job handlers to their hooks.
	 *
	 * Every handler is registered through guarded() rather than as a bare
	 * callable — see run_guarded() for why that matters.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK_FULL_SYNC, $this->guarded( self::HOOK_FULL_SYNC, 'run_full_sync' ) );
		add_action( self::HOOK_SYNC_PAGE, $this->guarded( self::HOOK_SYNC_PAGE, 'run_sync_page' ), 10, 1 );
		add_action( self::HOOK_SYNC_PRODUCT, $this->guarded( self::HOOK_SYNC_PRODUCT, 'run_sync_product' ), 10, 1 );
		add_action( self::HOOK_DELETE_PRODUCT, $this->guarded( self::HOOK_DELETE_PRODUCT, 'run_delete_product' ), 10, 1 );
		add_action( self::HOOK_SYNC_STOCK, $this->guarded( self::HOOK_SYNC_STOCK, 'run_sync_stock' ), 10, 1 );
		add_action( self::HOOK_SYNC_CONTENT, $this->guarded( self::HOOK_SYNC_CONTENT, 'run_sync_content' ) );
		add_action( self::HOOK_SYNC_DOCUMENT, $this->guarded( self::HOOK_SYNC_DOCUMENT, 'run_sync_document' ), 10, 1 );
		add_action( self::HOOK_DELETE_DOCUMENT, $this->guarded( self::HOOK_DELETE_DOCUMENT, 'run_delete_document' ), 10, 2 );
		add_action( self::HOOK_DELETE_CONTENT, $this->guarded( self::HOOK_DELETE_CONTENT, 'run_delete_content' ), 10, 2 );
		add_action( self::HOOK_SYNC_ALL_DOCUMENTS, $this->guarded( self::HOOK_SYNC_ALL_DOCUMENTS, 'run_sync_all_documents' ) );
		add_action( self::HOOK_SYNC_PROMOS, $this->guarded( self::HOOK_SYNC_PROMOS, 'run_sync_promos' ) );
		add_action( self::HOOK_SYNC_FAQS, $this->guarded( self::HOOK_SYNC_FAQS, 'run_sync_faqs' ) );
		add_action( 'init', array( __CLASS__, 'schedule_daily' ) );
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
	 * Wraps a handler method name in the callable Action Scheduler will invoke.
	 *
	 * The hook is carried through alongside the method so a failure can be
	 * reported in the merchant's vocabulary (the hook name is what appears in
	 * WooCommerce > Status > Scheduled Actions), not ours.
	 *
	 * @param string $hook   Hook the handler is registered against.
	 * @param string $method Handler method on this class.
	 * @return callable
	 */
	private function guarded( $hook, $method ) {
		$scheduler = $this;
		return function () use ( $scheduler, $hook, $method ) {
			return $scheduler->run_guarded( $method, func_get_args(), $hook );
		};
	}

	/**
	 * Runs one job handler, catching anything it throws.
	 *
	 * The single choke point every job goes through, rather than a try/catch
	 * repeated inside twelve handlers — one that gets forgotten is the one that
	 * takes the site down.
	 *
	 * Throwable, not Exception: in PHP 7+ a missing class raises Error, which
	 * does not extend Exception. run_sync_promos() constructs `new WC_Coupon()`,
	 * which fatals outright if WooCommerce is mid-update or has been deactivated
	 * while jobs are still queued, and the rest are equally exposed through the
	 * WooCommerce and WordPress objects they touch. A fatal raised inside a
	 * WooCommerce hook takes down whatever request ran the queue, so it is still
	 * worth catching.
	 *
	 * But caught is not the same as hidden, and this deliberately does both the
	 * catching and the reporting. Action Scheduler does NOT retry an action
	 * whose handler throws — it marks the action `failed` and moves on, which is
	 * a real, merchant-visible signal under WooCommerce > Status > Scheduled
	 * Actions. Swallowing the throwable therefore does not prevent a retry
	 * storm (there is none to prevent); what it does is convert a visible
	 * failure into an action that reports success and disappears. Since the
	 * plugin has no other error surface, the failure is logged unconditionally
	 * — not behind WP_DEBUG, which is off on every production store — and
	 * re-emitted as an action other code can observe.
	 *
	 * Public so it is directly exercisable: WordPress hooks cannot be fired in
	 * these unit tests, so a private guard would be a guard nobody can prove.
	 *
	 * @param string $method Handler method on this class.
	 * @param array  $args   Arguments passed by Action Scheduler.
	 * @param string $hook   Hook the handler is registered against.
	 * @return mixed
	 */
	public function run_guarded( $method, array $args = array(), $hook = '' ) {
		try {
			return call_user_func_array( array( $this, $method ), $args );
		} catch ( Throwable $e ) {
			$hook = '' === (string) $hook ? $method : (string) $hook;

			// Unconditional: a job that never succeeds must leave a trace on a
			// store where WP_DEBUG is off, which is all of them.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'IDEA89: scheduled job %s failed: %s', $hook, $e->getMessage() ) );

			/**
			 * Fires when a scheduled job handler throws.
			 *
			 * The observable half of the guard: an admin notice, a health
			 * check or a merchant's own logger can hang off this without the
			 * plugin having to grow an error surface here.
			 *
			 * @param string    $hook Hook whose handler failed.
			 * @param Throwable $e    The throwable that was caught.
			 */
			do_action( 'idea89_job_failed', $hook, $e );

			return null;
		}
	}

	/**
	 * True when Action Scheduler is available.
	 *
	 * @return bool
	 */
	private static function available() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Enqueues a job to run as soon as the queue reaches it.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Job arguments.
	 * @return void
	 */
	public static function enqueue( $hook, array $args = array() ) {
		if ( ! self::available() ) {
			return;
		}
		as_enqueue_async_action( $hook, $args, self::GROUP );
	}

	/**
	 * Ensures the daily reconcile is scheduled exactly once.
	 *
	 * @return void
	 */
	public static function schedule_daily() {
		if ( ! self::available() || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( as_has_scheduled_action( self::HOOK_FULL_SYNC, array(), self::GROUP ) ) {
			return;
		}
		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_FULL_SYNC, array(), self::GROUP );
	}

	/**
	 * Daily reconcile: catalogue, content, documents, FAQs and promos.
	 *
	 * @return void
	 */
	public function run_full_sync() {
		idea89_catalog_syncer()->sync_all();
		self::enqueue( self::HOOK_SYNC_CONTENT );
		self::enqueue( self::HOOK_SYNC_ALL_DOCUMENTS );
		self::enqueue( self::HOOK_SYNC_PROMOS );
		self::enqueue( self::HOOK_SYNC_FAQS );
	}

	/**
	 * Syncs one catalogue page and enqueues the next when more remain.
	 *
	 * @param int $page Page number.
	 * @return void
	 */
	public function run_sync_page( $page = 1 ) {
		$result = idea89_catalog_syncer()->sync_page( (int) $page );
		if ( ! empty( $result['has_more'] ) ) {
			self::enqueue( self::HOOK_SYNC_PAGE, array( 'page' => (int) $page + 1 ) );
		}
	}

	/**
	 * Syncs a single product.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function run_sync_product( $product_id = 0 ) {
		idea89_catalog_syncer()->sync_product( (int) $product_id );
	}

	/**
	 * Withdraws a permanently deleted product from the catalogue.
	 *
	 * Deletion cannot route through the sync job: that job resolves the product
	 * by id, and by the time it runs the row is gone. The id is carried here
	 * instead, captured before deletion.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function run_delete_product( $product_id = 0 ) {
		$product_id = (int) $product_id;
		if ( ! $product_id ) {
			return;
		}
		idea89_client()->delete_products( array( (string) $product_id ) );
	}

	/**
	 * Pushes stock for one product.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function run_sync_stock( $product_id = 0 ) {
		idea89_stock_syncer()->sync_product( (int) $product_id );
	}

	/**
	 * Pushes categories, pages and store info.
	 *
	 * @return void
	 */
	public function run_sync_content() {
		idea89_content_syncer()->sync_all();
	}

	/**
	 * Indexes one post, or withdraws it when it is no longer eligible.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function run_sync_document( $post_id = 0 ) {
		idea89_document_syncer()->sync_post( (int) $post_id );
	}

	/**
	 * Removes one post from the document index.
	 *
	 * $post_type is expected to be supplied: Idea89_Hooks::on_post_deleted()
	 * captures it before the post row is removed, because it cannot be read
	 * afterwards (see Idea89_Hooks::on_post_before_delete() for the same
	 * pattern on the product side). The resolve-it-ourselves fallback exists
	 * only for a caller that does not have the type in hand.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type, captured before deletion.
	 * @return void
	 */
	public function run_delete_document( $post_id = 0, $post_type = '' ) {
		if ( '' === $post_type ) {
			idea89_document_syncer()->delete_post( (int) $post_id );
			return;
		}
		idea89_client()->delete_documents( (string) $post_type, array( (string) (int) $post_id ) );
	}

	/**
	 * Withdraws one content item (a page that is no longer publicly published).
	 *
	 * Like run_delete_document(), this carries what it needs rather than
	 * resolving it: Idea89_Hooks builds the external_id while the post still
	 * exists, so a force-delete leaves nothing for this job to look up.
	 *
	 * @param string $external_id Content external id, e.g. page_12.
	 * @param string $type        Content type.
	 * @return void
	 */
	public function run_delete_content( $external_id = '', $type = 'cms_page' ) {
		$external_id = (string) $external_id;
		if ( '' === $external_id ) {
			return;
		}
		idea89_content_syncer()->delete_item( $external_id, (string) $type );
	}

	/**
	 * Reconciles every document.
	 *
	 * Unchanged documents cost nothing: the API short-circuits on content_hash.
	 *
	 * @return void
	 */
	public function run_sync_all_documents() {
		idea89_document_syncer()->sync_all();
	}

	/**
	 * Pushes every coupon.
	 *
	 * @return void
	 */
	public function run_sync_promos() {
		idea89_promo_syncer()->sync_all();
	}

	/**
	 * Detects and pushes FAQs.
	 *
	 * @return void
	 */
	public function run_sync_faqs() {
		idea89_faq_syncer()->sync_all();
	}
}
