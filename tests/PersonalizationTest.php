<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/personalization/class-idea89-personalization-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/personalization/class-idea89-identity-token.php';

class PersonalizationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ) {
				return json_encode( $data );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Decodes the payload half of a token back into an array.
	 *
	 * @param string $token The token.
	 * @return array<string,mixed>
	 */
	private function payload_of( $token ) {
		$b64 = explode( '.', $token )[0];
		$pad = strlen( $b64 ) % 4;
		$b64 = strtr( $b64, '-_', '+/' ) . ( $pad ? str_repeat( '=', 4 - $pad ) : '' );

		return json_decode( base64_decode( $b64 ), true );
	}

	/**
	 * The MAC covers the base64 text, not the raw JSON. Getting that wrong
	 * produces a token that looks right and fails verification server-side.
	 */
	public function test_signature_covers_the_base64_payload() {
		$token = Idea89_Identity_Token::mint( 'sk_live_abc', 'shhh', 42, 1, true, 1000 );

		list( $payload_b64, $mac_b64 ) = explode( '.', $token );

		$expected = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $payload_b64, 'shhh', true ) ), '+/', '-_' ), '=' );

		$this->assertSame( $expected, $mac_b64 );
	}

	public function test_payload_carries_the_fields_the_verifier_reads() {
		$token   = Idea89_Identity_Token::mint( 'sk_live_abc', 'shhh', 42, 3, true, 1000 );
		$payload = $this->payload_of( $token );

		$this->assertSame( '42', $payload['magento_customer_id'] );
		$this->assertSame( 3, $payload['customer_group_id'] );
		$this->assertTrue( $payload['is_logged_in'] );
		$this->assertSame( 'sk_live_abc', $payload['store_ref'] );
		$this->assertSame( 1000, $payload['iat'] );
		$this->assertSame( 4600, $payload['exp'] );
	}

	/**
	 * exp is compared as seconds against Date.now() on the API side, so a
	 * millisecond value here would read as the year 47000 and never expire.
	 */
	public function test_exp_is_one_hour_in_seconds() {
		$payload = $this->payload_of( Idea89_Identity_Token::mint( 'k', 's', 1, 0, true, 2_000_000 ) );

		$this->assertSame( 3600, $payload['exp'] - $payload['iat'] );
	}

	public function test_guest_gets_a_null_customer_id() {
		$payload = $this->payload_of( Idea89_Identity_Token::mint( 'k', 's', null, 0, false, 10 ) );

		$this->assertNull( $payload['magento_customer_id'] );
		$this->assertFalse( $payload['is_logged_in'] );
	}

	/** base64url is unpadded and uses -_ rather than +/. */
	public function test_token_is_base64url_without_padding() {
		$token = Idea89_Identity_Token::mint( 'store-ref-with-+/-chars??', 'secret', 999999, 7, true, 12345 );

		$this->assertStringNotContainsString( '=', $token );
		$this->assertStringNotContainsString( '+', $token );
		$this->assertStringNotContainsString( '/', $token );
	}

	/** Signing with no secret is not possible, so no token is minted. */
	public function test_no_secret_means_no_token() {
		$this->assertNull( Idea89_Identity_Token::mint( 'k', '', 1, 0, true, 1 ) );
		$this->assertNull( Idea89_Identity_Token::mint( '', 's', 1, 0, true, 1 ) );
	}

	public function test_personalization_needs_both_toggle_and_secret() {
		$make = function ( $enabled, $secret ) {
			Functions\when( 'get_option' )->alias(
				function ( $name, $default = null ) use ( $enabled, $secret ) {
					if ( 'idea89_personalization_enabled' === $name ) {
						return $enabled;
					}
					if ( 'idea89_personalization_secret' === $name ) {
						return $secret;
					}
					return $default;
				}
			);
			return new Idea89_Personalization_Config();
		};

		$this->assertFalse( $make( false, '' )->is_usable() );
		$this->assertFalse( $make( true, '' )->is_usable(), 'A toggle with no secret cannot sign anything.' );
		$this->assertFalse( $make( false, 'abc' )->is_usable() );
		$this->assertTrue( $make( true, 'abc' )->is_usable() );
	}

	public function test_personalization_is_off_by_default() {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = null ) {
				return $default;
			}
		);

		$this->assertFalse( ( new Idea89_Personalization_Config() )->is_enabled() );
	}
}
