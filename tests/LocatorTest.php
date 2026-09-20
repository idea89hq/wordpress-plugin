<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/locator/class-idea89-locator-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/locator/class-idea89-remote-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/locator/class-idea89-locator-page.php';

class LocatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias(
			function ( $data, $flags = 0 ) {
				return json_encode( $data, $flags );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/* ---------------- Slug handling ---------------- */

	public function test_slug_is_lowercased_and_stripped() {
		$this->assertSame( 'store-finder', Idea89_Locator_Config::sanitize_slug( '/Store-Finder/' ) );
		$this->assertSame( 'ourshops', Idea89_Locator_Config::sanitize_slug( 'our shops!' ) );
	}

	/**
	 * Underscores must survive. Stripping them stores "store_finder" but
	 * routes "storefinder", so the page 404s while the setting looks right.
	 */
	public function test_slug_keeps_underscores() {
		$this->assertSame( 'store_finder', Idea89_Locator_Config::sanitize_slug( 'store_finder' ) );
	}

	/**
	 * A merchant who types "cart" should lose the locator, not their basket.
	 */
	public function test_reserved_slugs_fall_back_to_the_default() {
		foreach ( array( 'cart', 'checkout', 'my-account', 'shop', 'wp-admin', 'wp-json', 'idea89' ) as $reserved ) {
			$this->assertSame(
				'store-finder',
				Idea89_Locator_Config::sanitize_slug( $reserved ),
				"'{$reserved}' should not be allowed"
			);
		}
	}

	public function test_empty_slug_falls_back_to_the_default() {
		$this->assertSame( 'store-finder', Idea89_Locator_Config::sanitize_slug( '' ) );
		$this->assertSame( 'store-finder', Idea89_Locator_Config::sanitize_slug( '///' ) );
		$this->assertSame( 'store-finder', Idea89_Locator_Config::sanitize_slug( '!!!' ) );
	}

	/* ---------------- Remote config parsing ---------------- */

	public function test_parses_the_cfg_object_from_the_loader() {
		$body = 'var x=1; var cfg = {"mapProvider":"google","mapKey":"gk","defaultCountryCode":"GB",'
			. '"nearestResultsCount":5,"brandColor":"#ff0000","storefinderLayout":"boxed","locatorEnabled":true}; more();';

		$cfg = Idea89_Remote_Config::parse( $body );

		$this->assertSame( 'google', $cfg['provider'] );
		$this->assertSame( 'gk', $cfg['key'] );
		$this->assertSame( 'GB', $cfg['country'] );
		$this->assertSame( 5, $cfg['count'] );
		$this->assertSame( '#ff0000', $cfg['brandColor'] );
		$this->assertSame( 'boxed', $cfg['storefinderLayout'] );
		$this->assertTrue( $cfg['locatorEnabled'] );
	}

	/**
	 * A plan gate that opens when the network hiccups is not a gate. Every
	 * unusable response must land on locatorEnabled = false.
	 */
	public function test_unparsable_bodies_fail_closed() {
		foreach ( array( '', 'not javascript at all', 'var cfg = {broken;', 'var cfg = {};' ) as $body ) {
			$cfg = Idea89_Remote_Config::parse( $body );
			$this->assertFalse( $cfg['locatorEnabled'], 'Should fail closed for: ' . $body );
		}
	}

	public function test_unknown_layout_falls_back_to_fullwidth() {
		$cfg = Idea89_Remote_Config::parse( 'var cfg = {"storefinderLayout":"diagonal"};' );

		$this->assertSame( 'fullwidth', $cfg['storefinderLayout'] );
	}

	public function test_wrong_types_fall_back_rather_than_propagate() {
		$cfg = Idea89_Remote_Config::parse( 'var cfg = {"mapProvider":42,"nearestResultsCount":"five","locatorEnabled":"yes"};' );

		$this->assertSame( 'stadia', $cfg['provider'] );
		$this->assertSame( 3, $cfg['count'] );
		$this->assertFalse( $cfg['locatorEnabled'] );
	}

	/* ---------------- Hero stats ---------------- */

	/**
	 * Shaped like the real /widget/v1/locations payload, which nests city and
	 * country under `address`. An earlier version of this test asserted a flat
	 * shape that the endpoint has never returned, so it passed while the hero
	 * counts would have rendered zero against live data.
	 */
	public function test_stats_count_distinct_cities_and_countries() {
		$locations = array(
			array( 'address' => array( 'city' => 'London', 'country_code' => 'GB' ) ),
			array( 'address' => array( 'city' => 'london', 'country_code' => 'gb' ) ),
			array( 'address' => array( 'city' => 'Manchester', 'country_code' => 'GB' ) ),
			array( 'address' => array( 'city' => 'Dublin', 'country_code' => 'IE' ) ),
		);

		$stats = Idea89_Locator_Page::stats( $locations );

		$this->assertSame( 4, $stats['stores'] );
		$this->assertSame( 3, $stats['cities'], 'London and london are one city.' );
		$this->assertSame( 2, $stats['countries'] );
	}

	public function test_stats_tolerate_missing_fields() {
		$stats = Idea89_Locator_Page::stats( array( array( 'name' => 'Popup' ), array() ) );

		$this->assertSame( 2, $stats['stores'] );
		$this->assertSame( 0, $stats['cities'] );
		$this->assertSame( 0, $stats['countries'] );
	}

	/* ---------------- JSON-LD ---------------- */

	public function test_json_ld_includes_address_and_geo() {
		$json = Idea89_Locator_Page::store_json_ld(
			array(
				'name'    => 'Leeds Showroom',
				'address' => array(
					'line_1'       => '1 High St',
					'city'         => 'Leeds',
					'postcode'     => 'LS1 1AA',
					'country_code' => 'GB',
				),
				'geo'     => array(
					'lat' => 53.8,
					'lng' => -1.55,
				),
				'phone'   => '0113 000 0000',
			)
		);

		$data = json_decode( $json, true );

		$this->assertSame( 'Store', $data['@type'] );
		$this->assertSame( 'Leeds Showroom', $data['name'] );
		$this->assertSame( 'LS1 1AA', $data['address']['postalCode'] );
		$this->assertSame( 53.8, $data['geo']['latitude'] );
		$this->assertSame( '0113 000 0000', $data['telephone'] );
	}

	public function test_json_ld_omits_geo_when_coordinates_are_missing() {
		$data = json_decode( Idea89_Locator_Page::store_json_ld( array( 'name' => 'Popup' ) ), true );

		$this->assertArrayNotHasKey( 'geo', $data );
		$this->assertArrayNotHasKey( 'address', $data );
	}

	/**
	 * The nested shape is what the endpoint sends today; the flat one is
	 * tolerated so an older payload does not blank the page.
	 */
	public function test_flat_location_shape_still_works() {
		$stats = Idea89_Locator_Page::stats(
			array( array( 'city' => 'Leeds', 'country_code' => 'GB' ) )
		);

		$this->assertSame( 1, $stats['cities'] );
		$this->assertSame( 1, $stats['countries'] );
	}

	/** A nameless record would emit meaningless structured data. */
	public function test_json_ld_is_empty_without_a_name() {
		$this->assertSame( '', Idea89_Locator_Page::store_json_ld( array( 'city' => 'Leeds' ) ) );
	}

	/**
	 * A location name (merchant-entered, fetched from the API) containing
	 * "</script>" must not be able to close the JSON-LD element: every
	 * < > & ' " is hex-encoded, which is still valid JSON and reads back
	 * as the original text.
	 */
	public function test_json_ld_cannot_close_its_own_script_element() {
		$json = Idea89_Locator_Page::store_json_ld( array( 'name' => 'Leeds </script><script>alert(1)</script>' ) );

		$this->assertStringNotContainsString( '</script>', $json );
		$this->assertStringNotContainsString( '<', $json );
		$this->assertStringContainsString( '\\u003C', $json );
		$this->assertSame( 'Leeds </script><script>alert(1)</script>', json_decode( $json, true )['name'] );
	}

	/**
	 * Same for the analytics hook: the API base URL and key are merchant
	 * settings, and neither may terminate the inline script they land in.
	 */
	public function test_analytics_script_cannot_be_terminated_by_a_configured_value() {
		$js = Idea89_Locator_Page::analytics_script( 'https://api.example.test', 'k</script><script>alert(1)</script>' );

		$this->assertStringNotContainsString( '</script>', $js );
		$this->assertStringContainsString( '\\u003C', $js );
		// Slashes were already escaped before this change; the URL still works.
		$this->assertStringContainsString( 'fetch("https:\\/\\/api.example.test"+"/widget/v1/analytics"', $js );
	}

	/**
	 * head() hands the JSON-LD to wp_print_inline_script_tag() with the
	 * ld+json type instead of echoing a hand-built <script> tag.
	 */
	public function test_head_prints_json_ld_through_the_inline_script_tag_api() {
		$page = new Idea89_Locator_Page(
			$this->createMock( Idea89_Locator_Config::class ),
			$this->createMock( Idea89_Remote_Config::class ),
			$this->createMock( Idea89_Config::class )
		);

		$flag = new ReflectionProperty( Idea89_Locator_Page::class, 'is_locator' );
		$flag->setAccessible( true );
		$flag->setValue( $page, true );

		Functions\when( 'get_transient' )->justReturn( array( array( 'name' => 'Leeds' ), array( 'city' => 'nameless' ) ) );
		Functions\when( 'esc_attr' )->returnArg( 1 );

		$printed = array();
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			function ( $data, $attributes = array() ) use ( &$printed ) {
				$printed[] = array( $data, $attributes );
			}
		);

		ob_start();
		$page->head();
		$html = ob_get_clean();

		$this->assertCount( 1, $printed, 'One JSON-LD block per named location; the nameless one is skipped.' );
		$this->assertSame( array( 'type' => 'application/ld+json' ), $printed[0][1] );
		$this->assertSame( 'Leeds', json_decode( $printed[0][0], true )['name'] );
		$this->assertStringNotContainsString( '<script', $html, 'No hand-built script tag is echoed.' );
	}
}
