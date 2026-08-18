<?php
/**
 * Mints the per-store HMAC identity token.
 *
 * The widget forwards this to IDEA89 as an opaque header so the backend can
 * tell a returning customer apart without the storefront ever sending a name,
 * an email or an address. The browser holds the token but cannot forge one:
 * the secret stays on the server.
 *
 * Wire format is fixed by the API's verifier (api/src/lib/identity.ts):
 *
 *   base64url( payloadJson ) . "." . base64url( hmacSHA256( payloadB64, secret ) )
 *
 * Two details there are easy to get wrong and both are load-bearing: the MAC
 * covers the base64 text rather than the raw JSON, and `exp` is in seconds.
 * The customer id field is named magento_customer_id on every platform: it is
 * the established wire name, not a statement about this store.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds signed identity tokens.
 */
class Idea89_Identity_Token {

	const LIFETIME = 3600;

	/**
	 * Mints a token, or null when there is no secret to sign with.
	 *
	 * @param string   $store_ref   The store's API key.
	 * @param string   $secret      Shared signing secret.
	 * @param int|null $customer_id Logged-in customer id, or null.
	 * @param int      $group_id    Customer group id.
	 * @param bool     $logged_in   Whether the shopper is logged in.
	 * @param int|null $now         Unix time, injectable for tests.
	 * @return string|null
	 */
	public static function mint( $store_ref, $secret, $customer_id, $group_id, $logged_in, $now = null ) {
		$secret    = (string) $secret;
		$store_ref = (string) $store_ref;

		if ( '' === $secret || '' === $store_ref ) {
			return null;
		}

		$now = null === $now ? time() : (int) $now;

		$payload = array(
			'magento_customer_id' => $customer_id ? (string) $customer_id : null,
			'customer_group_id'   => (int) $group_id,
			'is_logged_in'        => (bool) $logged_in,
			'store_ref'           => $store_ref,
			'iat'                 => $now,
			'exp'                 => $now + self::LIFETIME,
		);

		$json = wp_json_encode( $payload );

		if ( false === $json ) {
			return null;
		}

		$payload_b64 = self::base64url( $json );
		$mac_b64     = self::base64url( hash_hmac( 'sha256', $payload_b64, $secret, true ) );

		return $payload_b64 . '.' . $mac_b64;
	}

	/**
	 * Unpadded base64url, as the verifier expects.
	 *
	 * @param string $raw Bytes to encode.
	 * @return string
	 */
	private static function base64url( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Token encoding, not obfuscation.
	}
}
