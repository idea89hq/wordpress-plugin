<?php
/**
 * Bridges WordPress and WooCommerce events to background sync jobs.
 *
 * Every handler only enqueues. Nothing here makes an HTTP call inline: these
 * fire during admin saves and order processing, where a slow or failing API
 * would stall the merchant's request.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Event listeners.
 */
class Idea89_Hooks {

	/**
	 * Registers every listener.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_changed' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_changed' ), 10, 1 );
		add_action( 'save_post_shop_coupon', array( $this, 'on_coupon_saved' ), 10, 1 );
		add_action( 'trashed_post', array( $this, 'on_post_trashed' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'on_post_before_delete' ), 10, 1 );
		add_action( 'save_post', array( $this, 'on_post_saved' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'on_post_deleted' ), 10, 1 );
	}

	/**
	 * Queues a single-product sync.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function on_product_saved( $product_id ) {
		if ( ! idea89_config()->is_configured() ) {
			return;
		}
		Idea89_Scheduler::enqueue( Idea89_Scheduler::HOOK_SYNC_PRODUCT, array( 'product_id' => (int) $product_id ) );
	}

	/**
	 * Queues a stock-only sync.
	 *
	 * @param object|int $product Product object or ID.
	 * @return void
	 */
	public function on_stock_changed( $product ) {
		if ( ! idea89_config()->is_configured() ) {
			return;
		}
		$product_id = is_object( $product ) && method_exists( $product, 'get_id' ) ? $product->get_id() : (int) $product;
		Idea89_Scheduler::enqueue( Idea89_Scheduler::HOOK_SYNC_STOCK, array( 'product_id' => (int) $product_id ) );
	}

	/**
	 * Queues a coupon resync.
	 *
	 * @param int $post_id Coupon post ID.
	 * @return void
	 */
	public function on_coupon_saved( $post_id ) {
		if ( ! idea89_config()->is_configured() || wp_is_post_revision( $post_id ) ) {
			return;
		}
		Idea89_Scheduler::enqueue( Idea89_Scheduler::HOOK_SYNC_PROMOS );
	}

	/**
	 * Withdraws a trashed product from the catalogue, or a trashed document
	 * (post, page or synced custom post type) from the retrieval index.
	 *
	 * Routed through the document sync job rather than a dedicated delete job
	 * for the non-product branch: sync_post() already withdraws anything
	 * should_index() rejects, and trashing does not remove the row, so there
	 * is no "read the type before it's gone" hazard here the way there is for
	 * before_delete_post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_post_trashed( $post_id ) {
		if ( ! idea89_config()->is_configured() ) {
			return;
		}
		$post_type = get_post_type( $post_id );

		if ( 'product' !== $post_type ) {
			// A trashed page must leave the content lane too. Both lanes can
			// hold the same page (a merchant may add 'page' to the document post
			// types), so this is not an either/or.
			if ( 'page' === $post_type ) {
				$this->withdraw_page_content( get_post( (int) $post_id ) );
			}
			Idea89_Scheduler::enqueue( Idea89_Scheduler::HOOK_SYNC_DOCUMENT, array( 'post_id' => (int) $post_id ) );
			return;
		}
		Idea89_Scheduler::enqueue( Idea89_Scheduler::HOOK_SYNC_PRODUCT, array( 'product_id' => (int) $post_id ) );
	}

	/**
	 * Queues removal of a page from the content lane when it is no longer
	 * publicly published.
	 *
	 * The content lane is upsert-only and batch-synced, so nothing else ever
	 * takes a page back out. Until this runs, a page the merchant unpublished,
	 * password-protected, trashed or marked noindex keeps being prompt-injected
	 * into every chat turn and quoted to shoppers.
	 *
	 * A page that is still eligible needs nothing queued: the content lane has
	 * no per-page upsert, and the next full content sync refreshes it.
	 *
	 * @param object|null $post Post object.
	 * @return void
	 */
	private function withdraw_page_content( $post ) {
		if ( ! is_object( $post ) || empty( $post->ID ) || 'page' !== $post->post_type ) {
			return;
		}
		if ( Idea89_Content_Syncer::page_is_eligible( $post ) ) {
			return;
		}
		$this->enqueue_page_withdrawal( $post->ID );
	}

	/**
	 * Queues the content-lane removal of a page unconditionally.
	 *
	 * Separate from withdraw_page_content() because the force-delete path must
	 * not consult eligibility: before_delete_post fires while the row is still
	 * intact and still says 'publish', so an eligibility check there would
	 * decide the page is fine and leave it in the store forever.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function enqueue_page_withdrawal( $post_id ) {
		Idea89_Scheduler::enqueue(
			Idea89_Scheduler::HOOK_DELETE_CONTENT,
			array(
				'external_id' => Idea89_Content_Syncer::page_external_id( $post_id ),
				'type'        => 'cms_page',
			)
		);
	}

	/**
	 * Removes a permanently-deleted product from the catalogue.
	 *
	 * A force-delete (bypassing the trash — e.g. "Empty Trash", a bulk delete
	 * plugin, or wp_delete_post( $id, true )) never fires 'trashed_post', so
	 * without this listener the product would stay in the catalogue and keep
	 * being recommended after it no longer exists. The post type is read here,
	 * before the post is actually removed, because it cannot be read afterwards.
	 *
	 * This routes through the dedicated delete job, not the sync job: by the
	 * time a queued job runs, the row is already gone, so a sync job (which
	 * resolves the product by id first) would find nothing and silently no-op
	 * instead of withdrawing it. The delete job carries the id directly and
	 * needs nothing to still exist.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_post_before_delete( $post_id ) {
		if ( ! idea89_config()->is_configured() ) {
			return;
		}
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		Idea89_Scheduler::enqueue( Idea89_Scheduler::HOOK_DELETE_PRODUCT, array( 'product_id' => (int) $post_id ) );
	}

	/**
	 * Queues a document sync when a synced post type is saved.
	 *
	 * The save_post action fires for every post type, including products and
	 * revisions, so both are filtered out here even though the scheduler-side
	 * sync_post() would also no-op on them — this avoids queuing a job at all
	 * for saves that can never result in a document write.
	 *
	 * @param int    $post_id Post ID.
	 * @param object $post    Post object.
	 * @return void
	 */
	public function on_post_saved( $post_id, $post = null ) {
		if ( ! idea89_config()->is_configured() ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( is_object( $post ) && 'product' === $post->post_type ) {
			return; // Products travel the catalogue lane, never the document lane.
		}

		// Content lane first, and before the document-lane filter below: pages
		// are not in the document post types by default, so a page save would
		// otherwise return early and never be reconsidered for withdrawal.
		if ( is_object( $post ) && 'page' === $post->post_type ) {
			$this->withdraw_page_content( $post );
		}

		if ( is_object( $post ) && ! in_array( $post->post_type, idea89_document_syncer()->synced_post_types(), true ) ) {
			return;
		}
		Idea89_Scheduler::enqueue( Idea89_Scheduler::HOOK_SYNC_DOCUMENT, array( 'post_id' => (int) $post_id ) );
	}

	/**
	 * Queues removal of a permanently deleted, non-product post.
	 *
	 * Deletion is captured on before_delete_post, alongside
	 * on_post_before_delete() above, because the post type is no longer
	 * readable once the row is actually gone.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_post_deleted( $post_id ) {
		if ( ! idea89_config()->is_configured() ) {
			return;
		}
		$post_type = get_post_type( (int) $post_id );
		if ( ! $post_type || 'product' === $post_type ) {
			return;
		}
		// A force-deleted page leaves the content lane unconditionally — it is
		// gone, whatever its status said a moment ago.
		if ( 'page' === $post_type ) {
			$this->enqueue_page_withdrawal( $post_id );
		}
		Idea89_Scheduler::enqueue(
			Idea89_Scheduler::HOOK_DELETE_DOCUMENT,
			array(
				'post_id'   => (int) $post_id,
				'post_type' => (string) $post_type,
			)
		);
	}
}
