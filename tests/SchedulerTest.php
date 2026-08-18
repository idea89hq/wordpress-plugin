<?php

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-client.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-content-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-document-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/functions.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-scheduler.php';

/**
 * A scheduler whose handlers stand in for the real ones's collaborators.
 *
 * Overriding the handler is the honest way to test the guard: the real
 * run_sync_promos() reaches WooCommerce, and the failure being guarded against
 * is precisely WooCommerce not being there.
 */
class Throwing_Scheduler extends Idea89_Scheduler {
	public $throwable;
	public $was_called    = false;
	public $received_args = null;

	public function __construct() {
		$this->throwable = new RuntimeException( 'WooCommerce is mid-update' );
	}

	public function run_sync_promos() {
		$this->was_called = true;
		if ( $this->throwable ) {
			throw $this->throwable;
		}
		return 'ok';
	}

	public function run_delete_document( $post_id = 0, $post_type = '' ) {
		$this->received_args = array( $post_id, $post_type );
		return null;
	}
}

class SchedulerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				if ( 'idea89_api_key' === $name ) {
					return 'sk_test';
				}
				if ( 'idea89_api_url' === $name ) {
					return 'https://api.example.test';
				}
				return $default;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://shop.example.test' );
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return isset( $response['body'] ) ? $response['body'] : '';
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_run_delete_product_sends_the_id_as_a_string() {
		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://api.example.test/v1/catalog/delete',
				Mockery::on(
					function ( $args ) {
						$body = json_decode( $args['body'], true );
						// Sent as a string, not an int: the API's external_id
						// column is a string everywhere else in the contract.
						return isset( $body['external_ids'] ) && array( '9' ) === $body['external_ids'];
					}
				)
			)
			->andReturn(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
				)
			);

		$scheduler = new Idea89_Scheduler();
		$result    = $scheduler->run_delete_product( 9 );

		$this->assertNull( $result );
	}

	public function test_run_delete_product_is_a_no_op_for_id_zero() {
		Functions\expect( 'wp_remote_post' )->never();

		$scheduler = new Idea89_Scheduler();
		$result    = $scheduler->run_delete_product( 0 );

		$this->assertNull( $result );
	}

	public function test_run_delete_product_defaults_to_a_no_op() {
		Functions\expect( 'wp_remote_post' )->never();

		$scheduler = new Idea89_Scheduler();
		$result    = $scheduler->run_delete_product();

		$this->assertNull( $result );
	}

	public function test_run_delete_document_with_a_post_type_skips_resolving_it_again() {
		// The post type is already known — captured before deletion by
		// Idea89_Hooks::on_post_deleted() — so this must not call
		// get_post_type(), which would find nothing once the row is gone.
		Functions\expect( 'get_post_type' )->never();
		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://api.example.test/v1/catalog/documents/delete',
				Mockery::on(
					function ( $args ) {
						$body = json_decode( $args['body'], true );
						return 'post' === $body['doc_type'] && array( '9' ) === $body['external_ids'];
					}
				)
			)
			->andReturn(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
				)
			);

		$scheduler = new Idea89_Scheduler();
		$result    = $scheduler->run_delete_document( 9, 'post' );

		$this->assertNull( $result );
	}

	public function test_run_delete_document_without_a_post_type_falls_back_to_resolving_it() {
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://api.example.test/v1/catalog/documents/delete',
				Mockery::on(
					function ( $args ) {
						$body = json_decode( $args['body'], true );
						return 'post' === $body['doc_type'] && array( '9' ) === $body['external_ids'];
					}
				)
			)
			->andReturn(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
				)
			);

		$scheduler = new Idea89_Scheduler();
		$result    = $scheduler->run_delete_document( 9 );

		$this->assertNull( $result );
	}

	public function test_run_delete_content_withdraws_the_page_from_the_content_lane() {
		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://api.example.test/v1/catalog/content/delete',
				Mockery::on(
					function ( $args ) {
						$body = json_decode( $args['body'], true );
						return 'cms_page' === $body['type'] && array( 'page_12' ) === $body['external_ids'];
					}
				)
			)
			->andReturn(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
				)
			);

		$scheduler = new Idea89_Scheduler();
		$result    = $scheduler->run_delete_content( 'page_12' );

		$this->assertNull( $result );
	}

	public function test_run_delete_content_is_a_no_op_without_an_external_id() {
		Functions\expect( 'wp_remote_post' )->never();

		$scheduler = new Idea89_Scheduler();

		$this->assertNull( $scheduler->run_delete_content( '' ) );
		$this->assertNull( $scheduler->run_delete_content() );
	}

	// -----------------------------------------------------------------
	// The Throwable guard. run_sync_promos() constructs `new WC_Coupon()`,
	// which fatals outright if WooCommerce is mid-update or has been
	// deactivated while jobs are still queued, and a fatal inside a
	// WooCommerce hook takes down whatever request ran the queue.
	//
	// Action Scheduler does NOT retry an action whose handler throws — it
	// marks it `failed`, which is a real merchant-visible signal under
	// WooCommerce > Status > Scheduled Actions. So catching the throwable
	// costs visibility rather than preventing a retry storm, and the guard
	// has to report what it caught: hence the unconditional log and the
	// idea89_job_failed action asserted below.
	// -----------------------------------------------------------------

	public function test_a_handler_whose_collaborator_throws_does_not_propagate() {
		$scheduler = new Throwing_Scheduler();

		$this->capture_error_log(
			function () use ( $scheduler ) {
				$this->assertNull( $scheduler->run_guarded( 'run_sync_promos' ) );
			}
		);

		$this->assertTrue( $scheduler->was_called, 'the handler must actually have run' );
	}

	public function test_the_guard_catches_error_not_just_exception() {
		// PHP 7+ raises Error (not Exception) for a missing class, which is
		// exactly what `new WC_Coupon()` does when WooCommerce is deactivated.
		// A `catch ( Exception $e )` would not catch it.
		$scheduler            = new Throwing_Scheduler();
		$scheduler->throwable = new Error( 'Class "WC_Coupon" not found' );

		$this->capture_error_log(
			function () use ( $scheduler ) {
				$this->assertNull( $scheduler->run_guarded( 'run_sync_promos' ) );
			}
		);
	}

	/**
	 * Runs $fn with error_log() redirected to a temp file, and returns what
	 * was written. Also keeps the guard's (deliberately unconditional) log out
	 * of the test runner's output.
	 */
	private function capture_error_log( callable $fn ) {
		$file     = tempnam( sys_get_temp_dir(), 'idea89-log-' );
		$previous = ini_set( 'error_log', $file );
		try {
			$fn();
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous );
		}
		$contents = (string) file_get_contents( $file );
		unlink( $file );
		return $contents;
	}

	public function test_a_caught_failure_is_logged_unconditionally() {
		// Not behind WP_DEBUG: it is off on every production store, and a job
		// that never succeeds must still leave a trace there.
		$this->assertFalse( defined( 'WP_DEBUG' ) && WP_DEBUG, 'this test is only meaningful with WP_DEBUG off' );

		$scheduler = new Throwing_Scheduler();

		$logged = $this->capture_error_log(
			function () use ( $scheduler ) {
				$scheduler->run_guarded( 'run_sync_promos', array(), Idea89_Scheduler::HOOK_SYNC_PROMOS );
			}
		);

		$this->assertStringContainsString( Idea89_Scheduler::HOOK_SYNC_PROMOS, $logged );
		$this->assertStringContainsString( 'WooCommerce is mid-update', $logged );
	}

	public function test_a_caught_failure_is_announced_as_an_action() {
		// The observable half of the guard. Without this the job reports
		// success and vanishes, and the plugin has no other error surface.
		$boom                 = new RuntimeException( 'WooCommerce is mid-update' );
		$scheduler            = new Throwing_Scheduler();
		$scheduler->throwable = $boom;

		Actions\expectDone( 'idea89_job_failed' )
			->once()
			->with( Idea89_Scheduler::HOOK_SYNC_PROMOS, $boom );

		$result = $this->capture_error_log(
			function () use ( $scheduler ) {
				$scheduler->run_guarded( 'run_sync_promos', array(), Idea89_Scheduler::HOOK_SYNC_PROMOS );
			}
		);

		$this->assertNotSame( '', $result );
	}

	public function test_the_failure_action_names_the_hook_not_the_method() {
		// The hook is what the merchant sees in Scheduled Actions; the private
		// method name means nothing to them.
		$scheduler = new Throwing_Scheduler();

		Actions\expectDone( 'idea89_job_failed' )
			->once()
			->with( 'idea89_sync_promos', Mockery::type( 'Throwable' ) );

		$logged = $this->capture_error_log(
			function () use ( $scheduler ) {
				$scheduler->run_guarded( 'run_sync_promos', array(), 'idea89_sync_promos' );
			}
		);

		$this->assertStringNotContainsString( 'run_sync_promos failed', $logged );
	}

	public function test_nothing_is_announced_when_the_handler_succeeds() {
		$scheduler            = new Throwing_Scheduler();
		$scheduler->throwable = null;

		Actions\expectDone( 'idea89_job_failed' )->never();

		$this->assertSame( 'ok', $scheduler->run_guarded( 'run_sync_promos' ) );
	}

	public function test_every_handler_is_registered_with_its_own_hook_name() {
		// guarded() takes the hook first so the failure action can name it. A
		// registration that passes the wrong hook would report a failure
		// against a job that did not fail.
		$source = file_get_contents( IDEA89_PLUGIN_DIR . 'includes/class-idea89-scheduler.php' );

		preg_match_all(
			'/add_action\(\s*(self::HOOK_[A-Z_]+)\s*,\s*\$this->guarded\(\s*(self::HOOK_[A-Z_]+)/',
			$source,
			$matches
		);

		$this->assertNotEmpty( $matches[1] );
		$this->assertSame( $matches[1], $matches[2] );
	}

	public function test_the_guard_returns_the_handler_result_when_nothing_throws() {
		$scheduler = new Throwing_Scheduler();
		$scheduler->throwable = null;

		$this->assertSame( 'ok', $scheduler->run_guarded( 'run_sync_promos' ) );
	}

	public function test_the_guard_forwards_the_job_arguments() {
		$scheduler = new Throwing_Scheduler();
		$scheduler->throwable = null;

		$this->assertNull( $scheduler->run_guarded( 'run_delete_document', array( 9, 'post' ) ) );
		$this->assertSame( array( 9, 'post' ), $scheduler->received_args );
	}

	public function test_every_job_handler_is_registered_through_the_guard() {
		// A handler wired straight to add_action() bypasses run_guarded()
		// entirely, which is exactly the drift this guard exists to prevent.
		$source = file_get_contents( IDEA89_PLUGIN_DIR . 'includes/class-idea89-scheduler.php' );

		preg_match_all( '/add_action\(\s*self::(HOOK_[A-Z_]+)\s*,\s*([^,)]+)/', $source, $matches );

		$this->assertNotEmpty( $matches[1], 'no add_action( self::HOOK_... ) registrations found at all' );

		foreach ( $matches[1] as $i => $hook ) {
			$this->assertStringContainsString(
				'$this->guarded(',
				$matches[2][ $i ],
				$hook . ' is registered without the Throwable guard, so a throw from its handler '
				. 'would escape into Action Scheduler and be retried forever'
			);
		}
	}

	public function test_every_enqueued_or_scheduled_hook_has_a_registered_handler() {
		// Regression test: idea89_sync_content used to be enqueued by
		// run_full_sync() with no add_action() registered for it anywhere. The
		// job would queue, run as a no-op, and report success — a silent
		// dead-letter. This scans the plugin's own source rather than
		// asserting against a hand-maintained list, so a future hook that is
		// enqueued and forgotten from add_action() is caught even if nobody
		// remembers to update a test — that "remember to update the list"
		// step is exactly what let this bug through twice already.
		// The whole plugin, not just includes/. idea89-assistant.php and
		// uninstall.php both call Action Scheduler today, and a hook enqueued
		// from either was invisible to this scan.
		$sources = '';
		$dir     = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( rtrim( IDEA89_PLUGIN_DIR, '/' ), RecursiveDirectoryIterator::SKIP_DOTS ),
				function ( $current ) {
					if ( ! $current->isDir() ) {
						return true;
					}
					// vendor/ is third-party and enormous; tests/ contains this
					// very pattern as a string literal.
					return ! in_array( $current->getFilename(), array( 'vendor', 'tests', 'node_modules' ), true );
				}
			)
		);
		foreach ( $dir as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$sources .= file_get_contents( $file->getPathname() ) . "\n";
			}
		}

		// Any HOOK_* constant handed to enqueue() or to Action Scheduler,
		// however it is qualified.
		//
		// [^;]* rather than [^)]*: a character class excluding ')' cannot cross
		// one, so it stops dead at the first nested call and never reaches the
		// hook argument. That is not hypothetical — it is why
		//   as_schedule_recurring_action( time() + HOUR_IN_SECONDS, ..., self::HOOK_FULL_SYNC, ... )
		// matched nothing at all and the daily reconcile went unchecked. ';'
		// ends the statement, so the class still cannot leak into the next one.
		//
		// The function list covers Action Scheduler's whole public scheduling
		// API, not just the calls that happen to exist today — the point of a
		// scan is to catch the call nobody remembered to add here.
		preg_match_all(
			'/(?:enqueue'
			. '|as_enqueue_async_action'
			. '|as_schedule_single_action'
			. '|as_schedule_recurring_action'
			. '|as_schedule_cron_action'
			. ')\([^;]*?(?:self|Idea89_Scheduler)::(HOOK_[A-Z_]+)/s',
			$sources,
			$used
		);

		$scheduler = file_get_contents( IDEA89_PLUGIN_DIR . 'includes/class-idea89-scheduler.php' );
		preg_match_all( '/add_action\(\s*self::(HOOK_[A-Z_]+)/', $scheduler, $registered );

		$missing = array_values( array_diff( array_unique( $used[1] ), array_unique( $registered[1] ) ) );

		$this->assertSame(
			array(),
			$missing,
			'These hooks are enqueued or scheduled but have no add_action() handler, so their jobs '
			. 'would run, do nothing, and report success: ' . implode( ', ', $missing )
		);
	}
}
