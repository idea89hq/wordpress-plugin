<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-content-syncer.php';
require_once IDEA89_PLUGIN_DIR . 'includes/sync/class-idea89-promo-syncer.php';

/** Stand-in for WC_Coupon. */
class Fake_WC_Coupon {
	public $data = array();

	public function __construct( array $data = array() ) {
		$this->data = array_merge(
			array(
				'id'           => 7,
				'code'         => 'SAVE10',
				'description'  => 'Ten percent off everything',
				'date_expires' => null,
				'amount'       => '10',
				'discount_type' => 'percent',
			),
			$data
		);
	}

	public function get_id() { return $this->data['id']; }
	public function get_code() { return $this->data['code']; }
	public function get_description() { return $this->data['description']; }
	public function get_date_expires() { return $this->data['date_expires']; }
	public function get_amount() { return $this->data['amount']; }
	public function get_discount_type() { return $this->data['discount_type']; }
}

class PromoSyncerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_serialises_the_core_fields() {
		$out = Idea89_Promo_Syncer::serialize_coupon( new Fake_WC_Coupon() );

		$this->assertSame( '7', $out['external_id'] );
		$this->assertSame( 'SAVE10', $out['code'] );
		$this->assertSame( 'Ten percent off everything', $out['description'] );
		$this->assertTrue( $out['is_active'] );
		$this->assertNull( $out['expires_at'] );
	}

	public function test_falls_back_to_a_generated_description() {
		// The API requires a non-empty description, and merchants routinely
		// leave the field blank. Sending "" would 400 the whole batch.
		$out = Idea89_Promo_Syncer::serialize_coupon( new Fake_WC_Coupon( array( 'description' => '' ) ) );

		$this->assertNotSame( '', $out['description'] );
		$this->assertStringContainsString( 'SAVE10', $out['description'] );
	}

	public function test_expiry_is_serialised_as_utc_with_a_literal_z() {
		// The API validates expires_at with Zod's z.string().datetime(), which
		// accepts ONLY a trailing Z. DateTime::format('c') emits a numeric
		// offset — and +00:00 is rejected just as hard as +01:00 — so the first
		// coupon with an expiry date would 400 the whole batch of 50 promos.
		$expires = new DateTime( '2026-12-25 00:00:00', new DateTimeZone( 'UTC' ) );
		$out     = Idea89_Promo_Syncer::serialize_coupon( new Fake_WC_Coupon( array( 'date_expires' => $expires ) ) );

		$this->assertSame( '2026-12-25T00:00:00Z', $out['expires_at'] );
	}

	public function test_expiry_carries_no_utc_offset_for_a_non_utc_store() {
		// WooCommerce hands back a DateTime in the store's timezone. A UK store
		// in summer produced "2026-09-01T23:59:59+01:00", which is exactly the
		// shape the API rejects.
		$expires = new DateTime( '2026-09-01 23:59:59', new DateTimeZone( 'Europe/London' ) );
		$out     = Idea89_Promo_Syncer::serialize_coupon( new Fake_WC_Coupon( array( 'date_expires' => $expires ) ) );

		$this->assertStringEndsWith( 'Z', $out['expires_at'] );
		$this->assertDoesNotMatchRegularExpression( '/[+\-]\d{2}:\d{2}$/', $out['expires_at'] );
		// Converted to UTC, not merely relabelled: 23:59:59 BST is 22:59:59 UTC.
		$this->assertSame( '2026-09-01T22:59:59Z', $out['expires_at'] );
	}

	public function test_description_truncation_never_leaves_invalid_utf8() {
		// A byte cut through a multi-byte character makes wp_json_encode()
		// return false, and Idea89_Client::post() then drops the entire batch
		// without making an HTTP call at all.
		$description = str_repeat( 'a', 499 ) . '£50 off';
		$out         = Idea89_Promo_Syncer::serialize_coupon(
			new Fake_WC_Coupon( array( 'description' => $description ) )
		);

		$this->assertSame( 500, mb_strlen( $out['description'], 'UTF-8' ) );
		$this->assertNotFalse( json_encode( $out['description'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_an_expired_coupon_is_marked_inactive() {
		$expires = new DateTime( '2020-01-01 00:00:00', new DateTimeZone( 'UTC' ) );
		$out     = Idea89_Promo_Syncer::serialize_coupon( new Fake_WC_Coupon( array( 'date_expires' => $expires ) ) );

		$this->assertFalse( $out['is_active'] );
	}

	public function test_description_is_truncated_to_the_api_limit() {
		$out = Idea89_Promo_Syncer::serialize_coupon(
			new Fake_WC_Coupon( array( 'description' => str_repeat( 'x', 900 ) ) )
		);

		$this->assertLessThanOrEqual( 500, strlen( $out['description'] ) );
	}
}
