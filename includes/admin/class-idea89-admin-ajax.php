<?php
/**
 * Admin AJAX handlers for Test Connection and Sync Now.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the two admin button actions.
 */
class Idea89_Admin_Ajax {

	const NONCE_ACTION = 'idea89_admin';

	/**
	 * Registers both handlers.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_idea89_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'wp_ajax_idea89_sync_now', array( $this, 'handle_sync_now' ) );
	}

	/**
	 * Rejects the request unless it carries a valid nonce from an admin.
	 *
	 * A valid nonce only proves the request came from our own form; it says
	 * nothing about whether the current user is allowed to run this action,
	 * so the capability check is separate and mandatory.
	 *
	 * @return void
	 */
	private function guard() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'idea89-assistant' ) ), 403 );
		}
	}

	/**
	 * Pings the API and reports the result.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		$this->guard();

		$result = idea89_client()->test_connection();

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'message' => __( 'Connected. Your API key works.', 'idea89-assistant' ) ) );
		}

		wp_send_json_error( array( 'message' => $result['error'] ) );
	}

	/**
	 * Queues a full sync.
	 *
	 * @return void
	 */
	public function handle_sync_now() {
		$this->guard();

		if ( ! idea89_config()->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Add your API key first.', 'idea89-assistant' ) ) );
		}

		Idea89_Scheduler::enqueue( Idea89_Scheduler::HOOK_FULL_SYNC );

		wp_send_json_success(
			array(
				'message' => __( 'Sync queued. Progress appears under WooCommerce > Status > Scheduled Actions.', 'idea89-assistant' ),
			)
		);
	}
}
