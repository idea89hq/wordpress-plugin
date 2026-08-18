<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-client.php';

class ClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) {
				if ( 'idea89_api_key' === $name ) {
					return 'sk_test_key';
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
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_domain_header_is_the_host_only() {
		$client = new Idea89_Client( new Idea89_Config() );
		$this->assertSame( 'shop.example.test', $client->domain_header() );
	}

	public function test_empty_batch_is_a_no_op_success_without_an_http_call() {
		Functions\expect( 'wp_remote_post' )->never();
		$client = new Idea89_Client( new Idea89_Config() );
		$this->assertTrue( $client->upsert_products( array() ) );
	}

	public function test_upsert_products_posts_to_the_catalog_endpoint() {
		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://api.example.test/v1/catalog/upsert',
				Mockery::on(
					function ( $args ) {
						return 'sk_test_key' === $args['headers']['X-IDEA89-Key']
							&& 'shop.example.test' === $args['headers']['X-IDEA89-Domain']
							&& 'application/json' === $args['headers']['Content-Type']
							&& 60 === $args['timeout']
							&& false !== strpos( $args['body'], '"products"' );
					}
				)
			)
			->andReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{}' ) );

		$client = new Idea89_Client( new Idea89_Config() );
		$this->assertTrue( $client->upsert_products( array( array( 'external_id' => '1' ) ) ) );
	}

	public function test_non_2xx_response_returns_false() {
		Functions\when( 'wp_remote_post' )->justReturn(
			array( 'response' => array( 'code' => 500 ), 'body' => 'boom' )
		);
		$client = new Idea89_Client( new Idea89_Config() );
		$this->assertFalse( $client->upsert_products( array( array( 'external_id' => '1' ) ) ) );
	}

	public function test_wp_error_returns_false_and_does_not_throw() {
		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http', 'down' ) );
		$client = new Idea89_Client( new Idea89_Config() );
		$this->assertFalse( $client->upsert_products( array( array( 'external_id' => '1' ) ) ) );
	}

	public function test_no_http_call_when_the_key_is_missing() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'wp_remote_post' )->never();
		$client = new Idea89_Client( new Idea89_Config() );
		$this->assertFalse( $client->upsert_products( array( array( 'external_id' => '1' ) ) ) );
	}

	/**
	 * /health is unauthenticated and returns 200 for ANY key, including a
	 * bogus one — a merchant who mistypes their key would see "Connected",
	 * then a sync that silently does nothing (Task 19, D1). test_connection()
	 * must instead ping an endpoint that actually validates X-IDEA89-Key, and
	 * /v1/catalog/stats is that endpoint (behind authenticateWidgetRequest in
	 * api/src/routes/catalog.ts). Asserting the exact URL here is a
	 * regression guard against silently pointing this back at /health.
	 */
	public function test_test_connection_pings_the_authenticated_stats_endpoint_with_a_valid_key() {
		Functions\expect( 'wp_remote_get' )
			->once()
			->with(
				'https://api.example.test/v1/catalog/stats',
				Mockery::on(
					function ( $args ) {
						return 'sk_test_key' === $args['headers']['X-IDEA89-Key']
							&& 'shop.example.test' === $args['headers']['X-IDEA89-Domain'];
					}
				)
			)
			->andReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{"total":"5","in_stock":"5","last_sync":null}' ) );

		$client = new Idea89_Client( new Idea89_Config() );
		$result = $client->test_connection();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '', $result['error'] );
	}

	public function test_test_connection_reports_a_rejected_key() {
		Functions\when( 'wp_remote_get' )->justReturn(
			array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"invalid_api_key"}' )
		);
		$client = new Idea89_Client( new Idea89_Config() );
		$result = $client->test_connection();
		$this->assertFalse( $result['ok'] );
		// The wording names the key, not just the HTTP status, so a merchant
		// reading the admin screen knows what to check.
		$this->assertStringContainsString( 'key', strtolower( $result['error'] ) );
		$this->assertStringContainsString( '401', $result['error'] );
	}

	/**
	 * The origin-mismatch branch of authenticateWidgetRequest (a valid key,
	 * but X-IDEA89-Domain doesn't match the store's registered domain) also
	 * returns a rejection code the merchant needs surfaced, not swallowed as
	 * a generic failure.
	 */
	public function test_test_connection_reports_a_domain_mismatch() {
		Functions\when( 'wp_remote_get' )->justReturn(
			array( 'response' => array( 'code' => 403 ), 'body' => '{"error":"domain_mismatch"}' )
		);
		$client = new Idea89_Client( new Idea89_Config() );
		$result = $client->test_connection();
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'key', strtolower( $result['error'] ) );
		$this->assertStringContainsString( '403', $result['error'] );
	}

	public function test_test_connection_succeeds_on_200() {
		Functions\when( 'wp_remote_get' )->justReturn(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"total":"0","in_stock":"0","last_sync":null}' )
		);
		$client = new Idea89_Client( new Idea89_Config() );
		$this->assertTrue( $client->test_connection()['ok'] );
	}

	/**
	 * A network failure (DNS, timeout, connection refused, ...) must report
	 * not-ok without throwing — this can run inside an admin AJAX handler,
	 * where an uncaught exception would 500 the request instead of showing
	 * the merchant a plain failure message.
	 */
	public function test_test_connection_reports_a_network_error_without_throwing() {
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_request_failed', 'Could not resolve host' ) );
		$client = new Idea89_Client( new Idea89_Config() );
		$result = $client->test_connection();
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'Could not resolve host', $result['error'] );
	}

	public function test_test_connection_makes_no_http_call_when_the_key_is_missing() {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\expect( 'wp_remote_get' )->never();

		$client = new Idea89_Client( new Idea89_Config() );
		$result = $client->test_connection();

		$this->assertFalse( $result['ok'] );
		$this->assertNotSame( '', $result['error'] );
	}

	public function test_delete_content_posts_the_type_and_ids() {
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
			->andReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{}' ) );

		$client = new Idea89_Client( new Idea89_Config() );
		$this->assertTrue( $client->delete_content( 'cms_page', array( 'page_12' ) ) );
	}

	public function test_delete_content_sends_a_list_not_a_map() {
		// A PHP array with non-sequential keys encodes as a JSON object, which
		// the API's z.array() rejects — so the ids are re-indexed on the way out.
		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				Mockery::any(),
				Mockery::on(
					function ( $args ) {
						return false !== strpos( $args['body'], '"external_ids":["page_2"]' );
					}
				)
			)
			->andReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{}' ) );

		$client = new Idea89_Client( new Idea89_Config() );
		$this->assertTrue( $client->delete_content( 'cms_page', array( 5 => 'page_2' ) ) );
	}

	public function test_delete_content_with_no_ids_makes_no_http_call() {
		Functions\expect( 'wp_remote_post' )->never();
		$client = new Idea89_Client( new Idea89_Config() );
		$this->assertTrue( $client->delete_content( 'cms_page', array() ) );
	}

	public function test_prune_faqs_posts_the_full_question_set() {
		Functions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://api.example.test/v1/catalog/faqs/prune',
				Mockery::on(
					function ( $args ) {
						$body = json_decode( $args['body'], true );
						return array( 'Do you deliver?', 'What is the returns window?' ) === $body['questions'];
					}
				)
			)
			->andReturn( array( 'response' => array( 'code' => 200 ), 'body' => '{}' ) );

		$client = new Idea89_Client( new Idea89_Config() );
		$this->assertTrue( $client->prune_faqs( array( 'Do you deliver?', 'What is the returns window?' ) ) );
	}

	public function test_prune_faqs_with_an_empty_set_makes_no_http_call() {
		// An empty list is what a detection regression produces. It must never
		// reach the API, where it could only mean "delete everything".
		Functions\expect( 'wp_remote_post' )->never();
		$client = new Idea89_Client( new Idea89_Config() );
		$this->assertTrue( $client->prune_faqs( array() ) );
	}

	public function test_a_payload_that_cannot_be_encoded_is_never_posted() {
		Functions\when( 'wp_json_encode' )->justReturn( false );
		Functions\expect( 'wp_remote_post' )->never();

		$client = new Idea89_Client( new Idea89_Config() );

		$this->assertFalse( $client->upsert_products( array( array( 'external_id' => '1' ) ) ) );
	}
}
