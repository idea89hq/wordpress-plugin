<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/orders/class-idea89-guest-rate-limit.php';
require_once IDEA89_PLUGIN_DIR . 'includes/acp/class-idea89-acp-feed.php';

/**
 * Plain stand-in for the WC_Product surface Idea89_Acp_Feed reads. Not
 * Fake_WC_Product from tests/bootstrap.php: that fake has no
 * get_catalog_visibility()/get_global_unique_id(), and adding them there
 * would change behaviour for every OTHER test file that already uses it.
 */
class Idea89_Fake_Acp_Product {
	private $data;

	public function __construct( array $data = array() ) {
		$this->data = array_merge(
			array(
				'id'                  => 1,
				'sku'                 => 'SKU-1',
				'name'                => 'Test Product',
				'short_description'   => 'Short.',
				'description'         => 'Long description.',
				'permalink'           => 'https://shop.example.test/p/1',
				'image_id'            => 11,
				'price'               => '19.99',
				'in_stock'            => true,
				'catalog_visibility'  => 'visible',
				'global_unique_id'    => '',
			),
			$data
		);
	}

	public function get_id() {
		return $this->data['id']; }
	public function get_sku() {
		return $this->data['sku']; }
	public function get_name() {
		return $this->data['name']; }
	public function get_short_description() {
		return $this->data['short_description']; }
	public function get_description() {
		return $this->data['description']; }
	public function get_permalink() {
		return $this->data['permalink']; }
	public function get_image_id() {
		return $this->data['image_id']; }
	public function get_price() {
		return $this->data['price']; }
	public function is_in_stock() {
		return $this->data['in_stock']; }
	public function get_catalog_visibility() {
		return $this->data['catalog_visibility']; }
	public function get_global_unique_id() {
		return $this->data['global_unique_id']; }
}

class AcpFeedTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'GBP' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Shop' );
		Functions\when( 'home_url' )->justReturn( 'https://shop.example.test' );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://shop.example.test/img.jpg' );
		Functions\when( 'wc_get_price_to_display' )->alias(
			function ( $product ) {
				return (float) $product->get_price();
			}
		);
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		// The per-IP throttle starts empty; the rate-limit tests below
		// override get_transient() to simulate an exhausted bucket.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param Idea89_Fake_Acp_Product[] $products
	 */
	private function with_products( array $products ) {
		// Behaves like wc_get_products() with paginate => true: slices by
		// limit/page and reports the full count, so these tests exercise the
		// same database-paged contract the real query has.
		Functions\when( 'wc_get_products' )->alias(
			function ( $args ) use ( $products ) {
				$limit = (int) $args['limit'];
				$page  = isset( $args['page'] ) ? (int) $args['page'] : 1;
				$slice = $limit > 0 ? array_slice( $products, ( $page - 1 ) * $limit, $limit ) : $products;
				return (object) array(
					'products'      => $slice,
					'total'         => count( $products ),
					'max_num_pages' => $limit > 0 ? (int) ceil( count( $products ) / $limit ) : 1,
				);
			}
		);
	}

	private function feed() {
		return new Idea89_Acp_Feed();
	}

	/**
	 * Feed switched on, plus whatever other options the test sets.
	 *
	 * @param array<string,mixed> $options Option name => value.
	 */
	private function with_options( array $options = array() ) {
		$options = array_merge( array( 'idea89_acp_enabled' => true ), $options );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
	}

	/**
	 * @param array<string,string> $headers Header name => value.
	 */
	private function request( array $headers = array() ) {
		$request = new WP_REST_Request();
		foreach ( $headers as $key => $value ) {
			$request->set_header( $key, $value );
		}
		return $request;
	}

	public function test_check_permission_is_deliberately_public() {
		// Never the literal '__return_true' string wp plugin check flags —
		// a named, documented callable that happens to always allow. See
		// the class docblock for why this route (unlike the four checkout
		// routes) is meant to be called by non-storefront agents.
		$this->assertTrue( $this->feed()->check_permission() );
	}

	/* ---------------- C1: off by default, gated on registration ---------------- */

	public function test_is_enabled_defaults_to_false() {
		Functions\when( 'get_option' )->justReturn( false );
		$this->assertFalse( Idea89_Acp_Feed::is_enabled() );
	}

	public function test_is_enabled_true_once_the_option_is_set() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return 'idea89_acp_enabled' === $name ? true : $default;
			}
		);
		$this->assertTrue( Idea89_Acp_Feed::is_enabled() );
	}

	public function test_register_routes_does_not_register_when_disabled() {
		// The gate is on REGISTRATION, not merely check_permission(): an
		// unauthenticated caller must get WordPress's own "route not found"
		// (the route was never added), and gated_page()'s catalogue
		// wc_get_products() query must be structurally unreachable — not
		// just unauthenticated-but-callable.
		Functions\when( 'get_option' )->justReturn( false );
		Functions\expect( 'register_rest_route' )->never();

		$this->feed()->register_routes();

		$this->assertTrue( true );
	}

	public function test_register_routes_registers_when_enabled() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return 'idea89_acp_enabled' === $name ? true : $default;
			}
		);
		Functions\expect( 'register_rest_route' )
			->once()
			->with(
				Idea89_Acp_Feed::REST_NAMESPACE,
				'/acp/feed\.json',
				Mockery::type( 'array' )
			);

		$this->feed()->register_routes();

		$this->assertTrue( true );
	}

	public function test_registered_route_never_uses_the_literal_return_true_permission_callback() {
		// wp plugin check flags the bare '__return_true' string; the named
		// check_permission() callable is fine even though it always allows
		// (this route is deliberately public — see the class docblock).
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return 'idea89_acp_enabled' === $name ? true : $default;
			}
		);
		$captured = null;
		Functions\expect( 'register_rest_route' )
			->once()
			->andReturnUsing(
				function ( $namespace, $route, $args ) use ( &$captured ) {
					$captured = $args;
					return true;
				}
			);

		$this->feed()->register_routes();

		$this->assertNotSame( '__return_true', $captured['permission_callback'] );
	}

	public function test_excludes_a_hidden_product() {
		$this->with_products(
			array(
				new Idea89_Fake_Acp_Product( array( 'sku' => 'VISIBLE-1' ) ),
				new Idea89_Fake_Acp_Product(
					array(
						'sku'                => 'HIDDEN-1',
						'catalog_visibility' => 'hidden',
					)
				),
			)
		);

		$result = $this->feed()->build( 1, 200 );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'VISIBLE-1', $result['products'][0]['id'] );
	}

	public function test_an_out_of_stock_product_appears_with_out_of_stock_availability_not_dropped() {
		// The trap: a store with "Hide out of stock items from the
		// catalogue" enabled tags an out-of-stock product with the SAME
		// product_visibility terms as a genuinely hidden one. This product
		// is deliberately still 'visible' in catalog_visibility (the
		// merchant never hid it) — only is_in_stock() is false — proving
		// gated_page() does not confuse the two.
		$this->with_products(
			array(
				new Idea89_Fake_Acp_Product(
					array(
						'sku'      => 'OOS-1',
						'in_stock' => false,
					)
				),
			)
		);

		$result = $this->feed()->build( 1, 200 );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'out_of_stock', $result['products'][0]['availability'] );
	}

	public function test_skips_a_product_with_no_resolvable_price_and_logs_it() {
		$this->with_products(
			array(
				new Idea89_Fake_Acp_Product(
					array(
						'sku'   => 'NOPRICE-1',
						'price' => '',
					)
				),
				new Idea89_Fake_Acp_Product(
					array(
						'sku'   => 'ZERO-1',
						'price' => '0',
					)
				),
				new Idea89_Fake_Acp_Product( array( 'sku' => 'PRICED-1' ) ),
			)
		);

		$result = $this->feed()->build( 1, 200 );

		// total reflects only the priced product — never "0.00" published
		// for the other two, and never counted in total either.
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['products'] );
		$this->assertSame( 'PRICED-1', $result['products'][0]['id'] );
	}

	public function test_page_is_clamped_up_to_1() {
		$this->with_products( array( new Idea89_Fake_Acp_Product() ) );

		$this->assertSame( 1, $this->feed()->build( 0, 200 )['page'] );
		$this->assertSame( 1, $this->feed()->build( -5, 200 )['page'] );
	}

	public function test_page_size_is_capped_at_200() {
		$this->with_products( array() );

		$this->assertSame( 200, $this->feed()->build( 1, 500 )['page_size'] );
	}

	public function test_build_clamps_a_zero_or_negative_page_size_up_to_1() {
		$this->with_products( array() );

		$this->assertSame( 1, $this->feed()->build( 1, 0 )['page_size'] );
		$this->assertSame( 1, $this->feed()->build( 1, -3 )['page_size'] );
	}

	public function test_handle_feed_defaults_a_missing_page_size_to_200_not_1() {
		// build()'s own clamp (tested above) floors an out-of-range page
		// size at 1 — correct for a caller who explicitly asked for 0.
		// handle_feed() sits in front of that for the common case, a
		// request with NO page_size at all, and must default it to 200
		// (a useful full page), not let it fall through to build()'s
		// floor of 1.
		$this->with_products( array( new Idea89_Fake_Acp_Product() ) );
		$this->with_options();

		$request = new WP_REST_Request();
		$response = $this->feed()->handle_feed( $request );

		$this->assertSame( 200, $response->get_data()['page_size'] );
	}

	public function test_pagination_slices_correctly_across_pages() {
		$products = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$products[] = new Idea89_Fake_Acp_Product(
				array(
					'sku' => 'SKU-' . $i,
					'id'  => $i,
				)
			);
		}
		$this->with_products( $products );

		$page1 = $this->feed()->build( 1, 2 );
		$page3 = $this->feed()->build( 3, 2 );

		$this->assertSame( 5, $page1['total'] );
		$this->assertSame( array( 'SKU-1', 'SKU-2' ), array_column( $page1['products'], 'id' ) );
		$this->assertSame( array( 'SKU-5' ), array_column( $page3['products'], 'id' ) );
	}

	public function test_a_page_past_the_end_returns_an_empty_products_array_not_page_1() {
		$this->with_products( array( new Idea89_Fake_Acp_Product() ) );

		$result = $this->feed()->build( 99, 200 );

		$this->assertSame( array(), $result['products'] );
		$this->assertSame( 1, $result['total'] );
	}

	public function test_feed_id_falls_back_to_the_numeric_id_when_sku_is_blank() {
		$this->with_products( array( new Idea89_Fake_Acp_Product( array( 'sku' => '', 'id' => 42 ) ) ) );

		$this->assertSame( '42', $this->feed()->build( 1, 200 )['products'][0]['id'] );
	}

	public function test_the_envelope_carries_version_and_seller() {
		$this->with_products( array() );

		$result = $this->feed()->build( 1, 200 );

		$this->assertSame( '2026-04-17', $result['version'] );
		$this->assertSame( 'Test Shop', $result['seller']['name'] );
		$this->assertSame( 'https://shop.example.test', $result['seller']['url'] );
	}

	/**
	 * @return array<string,mixed>|null The args build() passed to wc_get_products().
	 */
	private function capture_query( $page, $page_size ) {
		$captured = null;
		Functions\when( 'wc_get_products' )->alias(
			function ( $args ) use ( &$captured ) {
				$captured = $args;
				return (object) array(
					'products'      => array(),
					'total'         => 0,
					'max_num_pages' => 0,
				);
			}
		);

		$this->feed()->build( $page, $page_size );

		return $captured;
	}

	public function test_gated_products_queries_only_published_products() {
		$this->assertSame( 'publish', $this->capture_query( 1, 200 )['status'] );
	}

	/* ---------------- U9: paged in the query, never the whole catalogue ---------------- */

	public function test_the_query_is_paged_in_the_database_not_unbounded() {
		$args = $this->capture_query( 3, 50 );

		$this->assertSame( 50, $args['limit'] );
		$this->assertSame( 3, $args['page'] );
		$this->assertTrue( $args['paginate'] );
	}

	public function test_an_oversized_page_size_never_reaches_the_query() {
		$this->assertSame( Idea89_Acp_Feed::MAX_PAGE_SIZE, $this->capture_query( 1, 100000 )['limit'] );
	}

	public function test_total_is_the_database_count_not_the_page_count() {
		$products = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$products[] = new Idea89_Fake_Acp_Product(
				array(
					'sku' => 'SKU-' . $i,
					'id'  => $i,
				)
			);
		}
		$this->with_products( $products );

		$result = $this->feed()->build( 2, 3 );

		$this->assertSame( 7, $result['total'] );
		$this->assertSame( array( 'SKU-4', 'SKU-5', 'SKU-6' ), array_column( $result['products'], 'id' ) );
	}

	public function test_a_skipped_product_on_the_page_is_taken_off_total() {
		$this->with_products(
			array(
				new Idea89_Fake_Acp_Product( array( 'sku' => 'A', 'id' => 1 ) ),
				new Idea89_Fake_Acp_Product(
					array(
						'sku'                => 'HIDDEN',
						'id'                 => 2,
						'catalog_visibility' => 'hidden',
					)
				),
				new Idea89_Fake_Acp_Product( array( 'sku' => 'C', 'id' => 3 ) ),
			)
		);

		$result = $this->feed()->build( 1, 2 );

		$this->assertSame( array( 'A' ), array_column( $result['products'], 'id' ) );
		$this->assertSame( 2, $result['total'] );
	}

	public function test_an_unpaginated_query_result_yields_an_empty_feed_not_an_error() {
		Functions\when( 'wc_get_products' )->justReturn( array() );

		$result = $this->feed()->build( 1, 200 );

		$this->assertSame( array(), $result['products'] );
		$this->assertSame( 0, $result['total'] );
	}

	/* ---------------- U9: optional bearer secret + API-Version pin ---------------- */

	public function test_feed_is_public_when_no_secret_is_set() {
		$this->with_products( array( new Idea89_Fake_Acp_Product() ) );
		$this->with_options( array( 'idea89_acp_secret' => '' ) );

		$response = $this->feed()->handle_feed( $this->request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'public, max-age=900', $response->headers['Cache-Control'] );
	}

	public function test_secret_set_rejects_a_missing_authorization_header() {
		$this->with_products( array( new Idea89_Fake_Acp_Product() ) );
		$this->with_options( array( 'idea89_acp_secret' => 's3cret-value' ) );

		$response = $this->feed()->handle_feed( $this->request() );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( array( 'error' => 'unauthorized' ), $response->get_data() );
		$this->assertSame( 'no-store', $response->headers['Cache-Control'] );
	}

	public function test_secret_set_rejects_a_wrong_token() {
		$this->with_products( array( new Idea89_Fake_Acp_Product() ) );
		$this->with_options( array( 'idea89_acp_secret' => 's3cret-value' ) );

		$response = $this->feed()->handle_feed( $this->request( array( 'Authorization' => 'Bearer s3cret-valuX' ) ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_secret_set_rejects_the_secret_without_the_bearer_prefix() {
		$this->with_products( array( new Idea89_Fake_Acp_Product() ) );
		$this->with_options( array( 'idea89_acp_secret' => 's3cret-value' ) );

		$response = $this->feed()->handle_feed( $this->request( array( 'Authorization' => 's3cret-value' ) ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_secret_set_accepts_the_right_bearer_token_and_is_not_publicly_cacheable() {
		$this->with_products( array( new Idea89_Fake_Acp_Product() ) );
		$this->with_options( array( 'idea89_acp_secret' => 's3cret-value' ) );

		$response = $this->feed()->handle_feed( $this->request( array( 'Authorization' => 'Bearer s3cret-value' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data()['products'] );
		// A shared cache must never hand an authorised body to the next caller.
		$this->assertStringNotContainsString( 'public', $response->headers['Cache-Control'] );
	}

	public function test_handle_feed_returns_404_when_acp_is_disabled() {
		$this->with_products( array( new Idea89_Fake_Acp_Product() ) );
		$this->with_options( array( 'idea89_acp_enabled' => false ) );

		$response = $this->feed()->handle_feed( $this->request() );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'not_enabled', $response->get_data()['error'] );
	}

	public function test_an_unsupported_api_version_is_refused() {
		$this->with_products( array( new Idea89_Fake_Acp_Product() ) );
		$this->with_options();

		$response = $this->feed()->handle_feed( $this->request( array( 'API-Version' => '2025-01-01' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unsupported_api_version', $response->get_data()['error'] );
	}

	public function test_the_supported_api_version_is_accepted() {
		$this->with_products( array( new Idea89_Fake_Acp_Product() ) );
		$this->with_options();

		$response = $this->feed()->handle_feed( $this->request( array( 'API-Version' => Idea89_Acp_Feed::API_VERSION ) ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_bearer_is_checked_before_the_api_version() {
		// Same order as Magento's Auth::check(): an unauthorised caller
		// learns nothing about which versions the feed supports.
		$this->with_products( array() );
		$this->with_options( array( 'idea89_acp_secret' => 's3cret-value' ) );

		$response = $this->feed()->handle_feed( $this->request( array( 'API-Version' => 'bogus' ) ) );

		$this->assertSame( 401, $response->get_status() );
	}

	/* ---------------- U9: per-IP rate limit ---------------- */

	public function test_feed_is_rate_limited_once_the_bucket_is_full() {
		$this->with_products( array( new Idea89_Fake_Acp_Product() ) );
		$this->with_options();
		Functions\when( 'get_transient' )->justReturn( Idea89_Acp_Feed::RATE_LIMIT_MAX );

		$response = $this->feed()->handle_feed( $this->request() );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( array( 'error' => 'rate_limited' ), $response->get_data() );
		$this->assertSame( 'no-store', $response->headers['Cache-Control'] );
	}

	public function test_feed_is_served_below_the_ceiling() {
		$this->with_products( array( new Idea89_Fake_Acp_Product() ) );
		$this->with_options();
		Functions\when( 'get_transient' )->justReturn( Idea89_Acp_Feed::RATE_LIMIT_MAX - 1 );

		$response = $this->feed()->handle_feed( $this->request() );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_rate_limit_runs_before_the_bearer_check() {
		// Otherwise the secret could be guessed at an unthrottled rate.
		$this->with_products( array() );
		$this->with_options( array( 'idea89_acp_secret' => 's3cret-value' ) );
		Functions\when( 'get_transient' )->justReturn( Idea89_Acp_Feed::RATE_LIMIT_MAX );

		$response = $this->feed()->handle_feed( $this->request( array( 'Authorization' => 'Bearer wrong' ) ) );

		$this->assertSame( 429, $response->get_status() );
	}

	public function test_the_feed_bucket_has_its_own_prefix_and_ceiling() {
		$this->with_products( array() );
		$this->with_options();
		$keys = array();
		$ttls = array();
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( &$keys, &$ttls ) {
				$keys[] = $key;
				$ttls[] = $ttl;
				return true;
			}
		);

		$this->feed()->handle_feed( $this->request() );

		$this->assertSame( 60, Idea89_Acp_Feed::RATE_LIMIT_MAX );
		$this->assertCount( 1, $keys );
		$this->assertStringStartsWith( Idea89_Acp_Feed::RATE_LIMIT_PREFIX, $keys[0] );
		$this->assertSame( MINUTE_IN_SECONDS, $ttls[0] );
	}
}
