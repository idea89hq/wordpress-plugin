<?php
/**
 * Pushes WooCommerce coupons to /v1/catalog/promos.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coupon sync.
 */
class Idea89_Promo_Syncer {

	const BATCH_SIZE      = 50;
	const MAX_DESCRIPTION = 500;

	/**
	 * Configuration reader.
	 *
	 * @var Idea89_Config
	 */
	private $config;

	/**
	 * API client.
	 *
	 * @var Idea89_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Config $config Configuration reader.
	 * @param Idea89_Client $client API client.
	 */
	public function __construct( Idea89_Config $config, Idea89_Client $client ) {
		$this->config = $config;
		$this->client = $client;
	}

	/**
	 * Serialises one coupon into the promo shape.
	 *
	 * The API requires a non-empty description and merchants routinely leave
	 * the field blank, so an empty one is replaced with a generated summary
	 * rather than 400-ing the entire batch.
	 *
	 * @param object $coupon A WC_Coupon or a test double.
	 * @return array
	 */
	public static function serialize_coupon( $coupon ) {
		$code    = (string) $coupon->get_code();
		$expires = $coupon->get_date_expires();

		$expires_at = null;
		$is_active  = true;

		if ( $expires && method_exists( $expires, 'getTimestamp' ) ) {
			// UTC with a literal Z, not DateTime::format('c').
			//
			// format('c') emits the site's offset — "2026-09-01T23:59:59+01:00"
			// on a UK store in summer. The API validates expires_at with Zod's
			// z.string().datetime(), which by default accepts ONLY a trailing Z
			// and rejects any numeric offset, including +00:00. So the very first
			// coupon carrying an expiry date would 400 the entire batch of up to
			// 50 promos, and the merchant would see no coupons at all rather than
			// one bad one. gmdate() converts to UTC and stamps the Z.
			$expires_at = gmdate( 'Y-m-d\TH:i:s\Z', $expires->getTimestamp() );
			$is_active  = $expires->getTimestamp() > time();
		}

		$description = trim( (string) $coupon->get_description() );
		if ( '' === $description ) {
			$amount      = (string) $coupon->get_amount();
			$description = 'percent' === $coupon->get_discount_type()
				? sprintf( '%s%% off with code %s', $amount, $code )
				: sprintf( 'Discount of %s with code %s', $amount, $code );
		}

		return array(
			'external_id' => (string) $coupon->get_id(),
			'code'        => $code,
			'description' => Idea89_Content_Syncer::truncate( $description, self::MAX_DESCRIPTION ),
			'expires_at'  => $expires_at,
			'is_active'   => $is_active,
		);
	}

	/**
	 * Sends every published coupon.
	 *
	 * @return bool
	 */
	public function sync_all() {
		if ( ! $this->config->is_configured() ) {
			return false;
		}

		$coupon_posts = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => self::BATCH_SIZE,
				'fields'         => 'ids',
			)
		);

		if ( ! is_array( $coupon_posts ) || empty( $coupon_posts ) ) {
			return true;
		}

		$promos = array();
		foreach ( $coupon_posts as $coupon_id ) {
			$coupon = new WC_Coupon( $coupon_id );
			if ( ! $coupon->get_code() ) {
				continue;
			}
			$promos[] = self::serialize_coupon( $coupon );
		}

		return $this->client->upsert_promos( $promos );
	}
}
