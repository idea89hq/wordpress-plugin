<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-client.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-scheduler.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-content-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-document-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/functions.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-hooks.php';

class HooksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** No idea89_api_key option set. */
	private function unconfigured() {
		Functions\when( 'get_option' )->justReturn( '' );
	}

	/** idea89_api_key set to a non-empty value. */
	private function configured() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				return 'idea89_api_key' === $name ? 'sk_test' : $default;
			}
		);
	}

	public function test_unconfigured_store_enqueues_nothing_on_product_saved() {
		$this->unconfigured();
		Functions\expect( 'as_enqueue_async_action' )->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_product_saved( 42 );

		$this->assertNull( $result );
	}

	public function test_unconfigured_store_enqueues_nothing_on_stock_changed() {
		$this->unconfigured();
		Functions\expect( 'as_enqueue_async_action' )->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_stock_changed( 42 );

		$this->assertNull( $result );
	}

	public function test_unconfigured_store_enqueues_nothing_on_coupon_saved() {
		$this->unconfigured();
		Functions\expect( 'as_enqueue_async_action' )->never();
		// is_configured() is false, so the '||' short-circuits before this
		// is ever reached.
		Functions\expect( 'wp_is_post_revision' )->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_coupon_saved( 7 );

		$this->assertNull( $result );
	}

	public function test_unconfigured_store_enqueues_nothing_on_post_trashed() {
		$this->unconfigured();
		Functions\expect( 'as_enqueue_async_action' )->never();
		Functions\expect( 'get_post_type' )->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_trashed( 9 );

		$this->assertNull( $result );
	}

	public function test_unconfigured_store_enqueues_nothing_on_post_before_delete() {
		$this->unconfigured();
		Functions\expect( 'as_enqueue_async_action' )->never();
		Functions\expect( 'get_post_type' )->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_before_delete( 9 );

		$this->assertNull( $result );
	}

	public function test_a_configured_store_enqueues_exactly_one_job_on_product_saved() {
		$this->configured();
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_SYNC_PRODUCT, array( 'product_id' => 42 ), Idea89_Scheduler::GROUP );

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_product_saved( 42 );

		$this->assertNull( $result );
	}

	public function test_a_configured_store_enqueues_a_stock_job_with_the_product_id() {
		$this->configured();
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_SYNC_STOCK, array( 'product_id' => 42 ), Idea89_Scheduler::GROUP );

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_stock_changed( 42 );

		$this->assertNull( $result );
	}

	public function test_a_configured_store_enqueues_a_promos_job_on_coupon_saved() {
		$this->configured();
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_SYNC_PROMOS, array(), Idea89_Scheduler::GROUP );

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_coupon_saved( 7 );

		$this->assertNull( $result );
	}

	public function test_a_configured_store_enqueues_the_delete_job_on_force_delete() {
		// before_delete_post fires for any post type; only a 'product' post
		// should route to the withdrawal job. It must be the dedicated delete
		// job, not the sync job: by the time a queued job runs, the row is
		// already gone, so the sync job (which resolves the product by id
		// first) would find nothing and silently no-op.
		$this->configured();
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_DELETE_PRODUCT, array( 'product_id' => 9 ), Idea89_Scheduler::GROUP );
		// Explicitly rule out the sync job — a regression back to the old
		// routing must fail this test, not merely fail to assert against it.
		Functions\expect( 'as_enqueue_async_action' )
			->with( Idea89_Scheduler::HOOK_SYNC_PRODUCT, Mockery::any(), Mockery::any() )
			->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_before_delete( 9 );

		$this->assertNull( $result );
	}

	public function test_a_non_product_post_before_delete_enqueues_nothing() {
		$this->configured();
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\expect( 'as_enqueue_async_action' )->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_before_delete( 9 );

		$this->assertNull( $result );
	}

	public function test_trashing_a_product_still_enqueues_the_product_sync_job() {
		$this->configured();
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_SYNC_PRODUCT, array( 'product_id' => 9 ), Idea89_Scheduler::GROUP );

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_trashed( 9 );

		$this->assertNull( $result );
	}

	public function test_trashing_a_non_product_post_enqueues_a_document_sync_job() {
		// sync_post() withdraws anything should_index() rejects, including a
		// trashed post, so a trashed non-product post is routed through the
		// same sync job rather than a dedicated delete job.
		$this->configured();
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_SYNC_DOCUMENT, array( 'post_id' => 9 ), Idea89_Scheduler::GROUP );

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_trashed( 9 );

		$this->assertNull( $result );
	}

	public function test_unconfigured_store_enqueues_nothing_on_post_saved() {
		$this->unconfigured();
		Functions\expect( 'as_enqueue_async_action' )->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_saved( 4, (object) array( 'post_type' => 'post' ) );

		$this->assertNull( $result );
	}

	public function test_a_post_revision_is_ignored_on_post_saved() {
		$this->configured();
		Functions\when( 'wp_is_post_revision' )->justReturn( true );
		Functions\expect( 'as_enqueue_async_action' )->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_saved( 4, (object) array( 'post_type' => 'post' ) );

		$this->assertNull( $result );
	}

	public function test_an_autosave_is_ignored_on_post_saved() {
		$this->configured();
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( true );
		Functions\expect( 'as_enqueue_async_action' )->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_saved( 4, (object) array( 'post_type' => 'post' ) );

		$this->assertNull( $result );
	}

	public function test_saving_a_product_never_enqueues_a_document_job() {
		// Products travel the catalogue lane exclusively — save_post fires
		// for every post type, including products, so this must be filtered
		// here even though sync_post() would also refuse a product.
		$this->configured();
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_saved( 4, (object) array( 'post_type' => 'product' ) );

		$this->assertNull( $result );
	}

	public function test_saving_a_post_type_not_selected_for_sync_enqueues_nothing() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				if ( 'idea89_api_key' === $name ) {
					return 'sk_test';
				}
				if ( 'idea89_sync_post_types' === $name ) {
					return array( 'post' );
				}
				return $default;
			}
		);
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page', 'case_study' ) );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_saved( 5, (object) array( 'post_type' => 'case_study' ) );

		$this->assertNull( $result );
	}

	public function test_a_configured_store_enqueues_a_document_job_for_a_synced_post_type() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				if ( 'idea89_api_key' === $name ) {
					return 'sk_test';
				}
				if ( 'idea89_sync_post_types' === $name ) {
					return array( 'post' );
				}
				return $default;
			}
		);
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_SYNC_DOCUMENT, array( 'post_id' => 4 ), Idea89_Scheduler::GROUP );

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_saved( 4, (object) array( 'post_type' => 'post' ) );

		$this->assertNull( $result );
	}

	public function test_unconfigured_store_enqueues_nothing_on_post_deleted() {
		$this->unconfigured();
		Functions\expect( 'as_enqueue_async_action' )->never();
		Functions\expect( 'get_post_type' )->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_deleted( 9 );

		$this->assertNull( $result );
	}

	public function test_deleting_a_product_never_enqueues_a_document_delete_job() {
		// Left entirely to on_post_before_delete()/HOOK_DELETE_PRODUCT above.
		$this->configured();
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\expect( 'as_enqueue_async_action' )
			->with( Idea89_Scheduler::HOOK_DELETE_DOCUMENT, Mockery::any(), Mockery::any() )
			->never();

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_deleted( 9 );

		$this->assertNull( $result );
	}

	public function test_a_configured_store_enqueues_the_document_delete_job_with_the_captured_post_type() {
		// The post type must be captured before deletion — the row is gone by
		// the time this queued job actually runs.
		$this->configured();
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with(
				Idea89_Scheduler::HOOK_DELETE_DOCUMENT,
				array(
					'post_id'   => 9,
					'post_type' => 'post',
				),
				Idea89_Scheduler::GROUP
			);

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_deleted( 9 );

		$this->assertNull( $result );
	}

	// -----------------------------------------------------------------
	// Content-lane withdrawal. /v1/catalog/content is upsert-only and is
	// pushed as one batch on a full sync, so nothing else ever takes a page
	// back out. Until the delete job runs, chat.ts prompt-injects the row on
	// every turn with no publication check and the assistant keeps quoting a
	// page the merchant took down.
	// -----------------------------------------------------------------

	private function page( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'ID'            => 12,
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_password' => '',
			),
			$overrides
		);
	}

	public function test_unpublishing_a_page_enqueues_a_content_withdrawal() {
		$this->configured();
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with(
				Idea89_Scheduler::HOOK_DELETE_CONTENT,
				array(
					'external_id' => 'page_12',
					'type'        => 'cms_page',
				),
				Idea89_Scheduler::GROUP
			);

		$hooks  = new Idea89_Hooks();
		$result = $hooks->on_post_saved( 12, $this->page( array( 'post_status' => 'draft' ) ) );

		$this->assertNull( $result );
	}

	public function test_password_protecting_a_page_enqueues_a_content_withdrawal() {
		$this->configured();
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_DELETE_CONTENT, Mockery::any(), Mockery::any() );

		$hooks = new Idea89_Hooks();
		$hooks->on_post_saved( 12, $this->page( array( 'post_password' => 'hunter2' ) ) );

		$this->assertTrue( true );
	}

	public function test_marking_a_page_noindex_enqueues_a_content_withdrawal() {
		$this->configured();
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key, $single = false ) {
				return 'rank_math_robots' === $key ? array( 'noindex' ) : '';
			}
		);
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_DELETE_CONTENT, Mockery::any(), Mockery::any() );

		$hooks = new Idea89_Hooks();
		$hooks->on_post_saved( 12, $this->page() );

		$this->assertTrue( true );
	}

	public function test_saving_a_still_published_page_enqueues_no_withdrawal() {
		// The content lane has no per-page upsert; the next full sync refreshes
		// it. Only ineligibility needs a job.
		$this->configured();
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );
		Functions\expect( 'as_enqueue_async_action' )
			->with( Idea89_Scheduler::HOOK_DELETE_CONTENT, Mockery::any(), Mockery::any() )
			->never();

		$hooks = new Idea89_Hooks();
		$hooks->on_post_saved( 12, $this->page() );

		$this->assertTrue( true );
	}

	public function test_trashing_a_page_enqueues_a_content_withdrawal() {
		$this->configured();
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'get_post' )->justReturn( $this->page( array( 'post_status' => 'trash' ) ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with(
				Idea89_Scheduler::HOOK_DELETE_CONTENT,
				array(
					'external_id' => 'page_12',
					'type'        => 'cms_page',
				),
				Idea89_Scheduler::GROUP
			);
		// The document lane still gets its own job — both lanes can hold the
		// same page, so this is not an either/or.
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_SYNC_DOCUMENT, array( 'post_id' => 12 ), Idea89_Scheduler::GROUP );

		$hooks = new Idea89_Hooks();
		$hooks->on_post_trashed( 12 );

		$this->assertTrue( true );
	}

	public function test_force_deleting_a_page_withdraws_it_without_asking_about_eligibility() {
		// before_delete_post fires while the row is intact and still says
		// 'publish'. An eligibility check here would decide the page is fine
		// and leave it in the store forever.
		$this->configured();
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\expect( 'get_post' )->never();
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with(
				Idea89_Scheduler::HOOK_DELETE_CONTENT,
				array(
					'external_id' => 'page_12',
					'type'        => 'cms_page',
				),
				Idea89_Scheduler::GROUP
			);
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_DELETE_DOCUMENT, Mockery::any(), Mockery::any() );

		$hooks = new Idea89_Hooks();
		$hooks->on_post_deleted( 12 );

		$this->assertTrue( true );
	}

	public function test_a_non_page_post_never_enqueues_a_content_withdrawal() {
		$this->configured();
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		// A 'post' still travels the document lane as normal...
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( Idea89_Scheduler::HOOK_SYNC_DOCUMENT, array( 'post_id' => 4 ), Idea89_Scheduler::GROUP );
		// ...but never the content lane, which only carries pages.
		Functions\expect( 'as_enqueue_async_action' )
			->with( Idea89_Scheduler::HOOK_DELETE_CONTENT, Mockery::any(), Mockery::any() )
			->never();

		$hooks = new Idea89_Hooks();
		$hooks->on_post_saved( 4, (object) array( 'post_type' => 'post', 'ID' => 4, 'post_status' => 'draft' ) );

		$this->assertTrue( true );
	}
}
