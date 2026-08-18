<?php
/**
 * Derives a public tracking URL from a carrier code and tracking number.
 *
 * WooCommerce core stores no tracking data at all, so numbers arrive from
 * whichever shipment-tracking plugin the merchant runs. Those plugins agree on
 * almost nothing: carrier codes vary in case, carry vendor prefixes, and append
 * service suffixes. normalise() reduces them to a single key before lookup.
 *
 * Returns null when nothing matches, so the caller can fall back to a URL the
 * plugin supplied itself and the widget can hide the button when both are null.
 * Guessing a wrong carrier URL is worse than showing no button.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps carrier codes to public tracking URLs.
 */
class Idea89_Tracking_Url_Resolver {

	/**
	 * Normalised carrier key => URL template, with {n} for the tracking number.
	 *
	 * @var array<string,string>
	 */
	private static $patterns = array(
		'ups'         => 'https://www.ups.com/track?tracknum={n}',
		'fedex'       => 'https://www.fedex.com/fedextrack/?tracknumbers={n}',
		'dhl'         => 'https://www.dhl.com/global-en/home/tracking.html?tracking-id={n}',
		'royal-mail'  => 'https://www.royalmail.com/track-your-item#/tracking-results/{n}',
		'dpd'         => 'https://www.dpd.co.uk/apps/tracking/?reference={n}',
		'usps'        => 'https://tools.usps.com/go/TrackConfirmAction?qtc_tLabels1={n}',
		'evri'        => 'https://www.evri.com/track/parcel/{n}',
		'hermes'      => 'https://www.evri.com/track/parcel/{n}',
		'tnt'         => 'https://www.tnt.com/express/en_gb/site/shipping-tools/tracking.html?searchType=con&cons={n}',
		'parcelforce' => 'https://www.parcelforce.com/portal/pw/track?trackNumber={n}',
		'yodel'       => 'https://www.yodel.co.uk/tracking/{n}',
	);

	/**
	 * Carrier codes that collapse onto a canonical key.
	 *
	 * @var array<string,string>
	 */
	private static $aliases = array(
		'royalmail'    => 'royal-mail',
		'rm'           => 'royal-mail',
		'royal'        => 'royal-mail',
		'usps'         => 'usps',
		'dhlexpress'   => 'dhl',
		'deutschepost' => 'dhl',
	);

	/**
	 * Builds a tracking URL, or null when the carrier is unknown.
	 *
	 * @param string $carrier_code    Carrier code as the shipping plugin stored it.
	 * @param string $tracking_number The tracking number.
	 * @return string|null
	 */
	public function resolve( $carrier_code, $tracking_number ) {
		$key    = $this->normalise( (string) $carrier_code );
		$number = trim( (string) $tracking_number );

		if ( '' === $key || '' === $number ) {
			return null;
		}

		if ( ! isset( self::$patterns[ $key ] ) ) {
			return null;
		}

		return str_replace( '{n}', rawurlencode( $number ), self::$patterns[ $key ] );
	}

	/**
	 * Reduces a carrier code to a lookup key.
	 *
	 * Strips vendor prefixes, drops a service suffix after the first
	 * underscore or hyphen, removes spaces, then applies known aliases.
	 *
	 * @param string $carrier_code Raw carrier code.
	 * @return string
	 */
	private function normalise( $carrier_code ) {
		$code = strtolower( trim( $carrier_code ) );

		if ( '' === $code ) {
			return '';
		}

		foreach ( array( 'mt_', 'magento_', 'custom_', 'ext_', 'wc_', 'wf_' ) as $prefix ) {
			if ( 0 === strpos( $code, $prefix ) ) {
				$code = substr( $code, strlen( $prefix ) );
			}
		}

		// A service suffix (ups_ground, fedex-express) collapses to the base carrier.
		$parts = preg_split( '/[_-]/', $code, 2 );
		$base  = is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : $code;
		$base  = preg_replace( '/[^a-z0-9]/', '', $base );

		if ( null === $base || '' === $base ) {
			return '';
		}

		if ( isset( self::$aliases[ $base ] ) ) {
			return self::$aliases[ $base ];
		}

		return $base;
	}
}
