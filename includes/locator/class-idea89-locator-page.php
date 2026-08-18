<?php
/**
 * Renders the store-finder page at the merchant's chosen slug.
 *
 * The page is virtual: there is no post behind it, so uninstalling the plugin
 * leaves no orphaned content. It renders inside the active theme, between
 * get_header() and get_footer(), so it inherits the merchant's own navigation
 * and footer rather than looking like a bolted-on microsite.
 *
 * The interactive map and list are the widget's locator bundle mounting into
 * the host element. Locations are additionally fetched server-side, but only
 * for the JSON-LD and the hero counts, which have to be in the HTML for search
 * engines rather than painted in later by script.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the virtual store-finder page.
 */
class Idea89_Locator_Page {

	const LOCATIONS_CACHE_KEY = 'idea89_locator_locations';
	const LOCATIONS_CACHE_TTL = 900;

	/**
	 * Locator settings.
	 *
	 * @var Idea89_Locator_Config
	 */
	private $locator;

	/**
	 * Dashboard settings.
	 *
	 * @var Idea89_Remote_Config
	 */
	private $remote;

	/**
	 * Plugin settings.
	 *
	 * @var Idea89_Config
	 */
	private $config;

	/**
	 * Whether this request is for the locator page.
	 *
	 * @var bool
	 */
	private $is_locator = false;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Locator_Config $locator Locator settings.
	 * @param Idea89_Remote_Config  $remote  Dashboard settings.
	 * @param Idea89_Config         $config  Plugin settings.
	 */
	public function __construct( Idea89_Locator_Config $locator, Idea89_Remote_Config $remote, Idea89_Config $config ) {
		$this->locator = $locator;
		$this->remote  = $remote;
		$this->config  = $config;
	}

	/**
	 * Hooks the page in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'parse_request', array( $this, 'detect' ), 20 );
		add_filter( 'template_include', array( $this, 'template' ) );
		add_filter( 'pre_get_document_title', array( $this, 'document_title' ) );
		add_action( 'wp_head', array( $this, 'head' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( $this, 'as_module' ), 10, 2 );
	}

	/**
	 * Loads the page styles and the locator bundles.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->is_locator ) {
			return;
		}

		wp_enqueue_style(
			'idea89-locator',
			IDEA89_PLUGIN_URL . 'assets/css/locator.css',
			array(),
			IDEA89_VERSION
		);

		$cfg      = $this->remote->get();
		$api_base = rtrim( (string) $this->config->get_api_url(), '/' );

		if ( '' === $api_base ) {
			return;
		}

		// Version is null on purpose: the API versions these bundles itself,
		// and appending ?ver= would fight its own cache headers.
		wp_enqueue_script( 'idea89-locator', $api_base . '/widget/v1/locator.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion

		if ( 'google' === (string) $cfg['provider'] ) {
			// Declared as a dependency rather than merely enqueued after:
			// these are ES modules, and the Google adapter calls a global the
			// core bundle registers. If it runs first that global is still
			// undefined, the adapter silently fails to register, and the map
			// quietly falls back to the other provider.
			wp_enqueue_script( 'idea89-locator-google', $api_base . '/widget/v1/locator-google.js', array( 'idea89-locator' ), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}

		wp_add_inline_script(
			'idea89-locator',
			$this->analytics_script( $api_base, (string) $this->config->get_api_key() ),
			'after'
		);
	}

	/**
	 * Marks our bundles as ES modules.
	 *
	 * Deliberately does not add async: modules already defer, and they run in
	 * source order only while async is absent.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public function as_module( $tag, $handle ) {
		if ( 'idea89-locator' !== $handle && 'idea89-locator-google' !== $handle ) {
			return $tag;
		}

		if ( false !== strpos( $tag, 'type="module"' ) ) {
			return $tag;
		}

		return str_replace( '<script ', '<script type="module" ', $tag );
	}

	/**
	 * Forwards the locator's analytics events to the widget endpoint.
	 *
	 * @param string $api_base API base URL.
	 * @param string $api_key  API key.
	 * @return string
	 */
	private function analytics_script( $api_base, $api_key ) {
		return sprintf(
			'document.addEventListener("locator:analytics",function(e){var d=e.detail||{};' .
			'fetch(%s+"/widget/v1/analytics",{method:"POST",headers:{"Content-Type":"application/json",' .
			'"X-IDEA89-Key":%s},body:JSON.stringify({name:d.name,properties:d.properties,' .
			'entry_point:"store-finder"}),keepalive:true}).catch(function(){});});',
			wp_json_encode( $api_base ),
			wp_json_encode( $api_key )
		);
	}

	/**
	 * Flags the request when it targets the locator slug.
	 *
	 * @param WP $wp Current WordPress environment.
	 * @return void
	 */
	public function detect( $wp ) {
		if ( ! $this->locator->is_enabled() ) {
			return;
		}

		$path = trim( isset( $wp->request ) ? (string) $wp->request : '', '/' );

		if ( '' === $path || $path !== $this->locator->get_url_path() ) {
			return;
		}

		// A real page at this slug wins. Shadowing content the merchant
		// already published would be a worse failure than the locator being
		// unreachable, and the settings screen warns about the collision.
		if ( self::slug_is_taken( $path ) ) {
			return;
		}

		if ( ! $this->remote->is_locator_plan_enabled() ) {
			return;
		}

		$this->is_locator = true;

		// Stop WordPress resolving this to a 404 before the template runs.
		$wp->query_vars = array();

		add_action(
			'wp',
			function () {
				global $wp_query;

				if ( $wp_query instanceof WP_Query ) {
					$wp_query->is_404  = false;
					$wp_query->is_home = false;
					status_header( 200 );
				}
			}
		);
	}

	/**
	 * Whether a published post or page already owns this slug.
	 *
	 * @param string $slug The slug.
	 * @return bool
	 */
	public static function slug_is_taken( $slug ) {
		$existing = get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) );

		return $existing instanceof WP_Post;
	}

	/**
	 * Swaps in our template for the locator request.
	 *
	 * @param string $template Theme template path.
	 * @return string
	 */
	public function template( $template ) {
		if ( ! $this->is_locator ) {
			return $template;
		}

		return IDEA89_PLUGIN_DIR . 'templates/locator.php';
	}

	/**
	 * The page title, from settings.
	 *
	 * @param string $title Incoming title.
	 * @return string
	 */
	public function document_title( $title ) {
		if ( ! $this->is_locator ) {
			return $title;
		}

		return $this->locator->get_text( 'idea89_locator_page_title' ) . ' | ' . get_bloginfo( 'name' );
	}

	/**
	 * Meta description and the store JSON-LD.
	 *
	 * @return void
	 */
	public function head() {
		if ( ! $this->is_locator ) {
			return;
		}

		printf(
			'<meta name="description" content="%s" />' . "\n",
			esc_attr( $this->locator->get_text( 'idea89_locator_meta_description' ) )
		);

		foreach ( $this->locations() as $location ) {
			$json = self::store_json_ld( $location );

			if ( '' === $json ) {
				continue;
			}

			// wp_json_encode output, printed as the contents of a JSON-LD
			// script tag. esc_html would corrupt it into invalid JSON.
			echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Whether the current request is the locator page.
	 *
	 * @return bool
	 */
	public function is_locator_request() {
		return $this->is_locator;
	}

	/**
	 * Everything the template needs, resolved once.
	 *
	 * @return array<string,mixed>
	 */
	public function view_data() {
		$cfg       = $this->remote->get();
		$locations = $this->locations();
		$override  = $this->locator->get_layout_override();

		return array(
			'layout'       => '' !== $override ? $override : (string) $cfg['storefinderLayout'],
			'brand_color'  => $this->config->get_brand_color() ? $this->config->get_brand_color() : (string) $cfg['brandColor'],
			'api_base'     => rtrim( (string) $this->config->get_api_url(), '/' ),
			'api_key'      => (string) $this->config->get_api_key(),
			'map_provider' => (string) $cfg['provider'],
			'map_key'      => $cfg['key'],
			'country'      => $cfg['country'],
			'count'        => (int) $cfg['count'],
			'locations'    => $locations,
			'stats'        => self::stats( $locations ),
			'text'         => array(
				'eyebrow'   => $this->locator->get_text( 'idea89_locator_hero_eyebrow' ),
				'h1'        => $this->locator->get_text( 'idea89_locator_hero_h1' ),
				'subhead'   => $this->locator->get_text( 'idea89_locator_hero_subhead' ),
				'help_head' => $this->locator->get_text( 'idea89_locator_help_heading' ),
				'help_body' => $this->locator->get_text( 'idea89_locator_help_body' ),
				'cta_label' => $this->locator->get_text( 'idea89_locator_help_cta_label' ),
				'cta_url'   => $this->locator->get_text( 'idea89_locator_help_cta_url' ),
			),
		);
	}

	/**
	 * Hero counts derived from the location list.
	 *
	 * Pure, so the counting rules are testable without a network call.
	 *
	 * @param array<int,array<string,mixed>> $locations Locations.
	 * @return array<string,mixed>
	 */
	public static function stats( $locations ) {
		$cities    = array();
		$countries = array();

		foreach ( $locations as $location ) {
			$city = self::field( $location, 'address', 'city' );

			if ( '' !== $city ) {
				$cities[ strtolower( $city ) ] = true;
			}

			$country = self::field( $location, 'address', 'country_code' );

			if ( '' !== $country ) {
				$countries[ strtoupper( $country ) ] = true;
			}
		}

		return array(
			'stores'    => count( $locations ),
			'cities'    => count( $cities ),
			'countries' => count( $countries ),
		);
	}

	/**
	 * JSON-LD for one location, or '' when it lacks a name.
	 *
	 * @param array<string,mixed> $location Location record.
	 * @return string
	 */
	public static function store_json_ld( $location ) {
		if ( empty( $location['name'] ) ) {
			return '';
		}

		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Store',
			'name'     => (string) $location['name'],
		);

		$address = array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => self::field( $location, 'address', 'line_1' ),
				'addressLocality' => self::field( $location, 'address', 'city' ),
				'postalCode'      => self::field( $location, 'address', 'postcode' ),
				'addressCountry'  => self::field( $location, 'address', 'country_code' ),
			)
		);

		if ( count( $address ) > 1 ) {
			$data['address'] = $address;
		}

		if ( ! empty( $location['phone'] ) ) {
			$data['telephone'] = (string) $location['phone'];
		}

		if ( ! empty( $location['url'] ) ) {
			$data['url'] = (string) $location['url'];
		}

		$lat = self::field( $location, 'geo', 'lat' );
		$lng = self::field( $location, 'geo', 'lng' );

		if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
			$data['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lat,
				'longitude' => (float) $lng,
			);
		}

		$json = wp_json_encode( $data );

		return false === $json ? '' : $json;
	}

	/**
	 * Reads one location field.
	 *
	 * /widget/v1/locations nests the interesting parts: address.city,
	 * address.country_code, geo.lat, geo.lng. Older payloads carried them
	 * flat, so the flat name is accepted as a fallback rather than assumed
	 * away, and a missing field yields '' instead of a notice.
	 *
	 * @param array<string,mixed> $location Location record.
	 * @param string              $group    Nested group, 'address' or 'geo'.
	 * @param string              $key      Field name inside the group.
	 * @return string
	 */
	private static function field( $location, $group, $key ) {
		if ( isset( $location[ $group ] ) && is_array( $location[ $group ] ) && isset( $location[ $group ][ $key ] ) ) {
			return trim( (string) $location[ $group ][ $key ] );
		}

		if ( isset( $location[ $key ] ) && ! is_array( $location[ $key ] ) ) {
			return trim( (string) $location[ $key ] );
		}

		return '';
	}

	/**
	 * Locations for JSON-LD and the hero counts, cached.
	 *
	 * The map does its own fetching; this is only the part search engines
	 * need in the HTML. A failure yields an empty list, which hides the
	 * counts rather than breaking the page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function locations() {
		$cached = get_transient( self::LOCATIONS_CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api_base = rtrim( (string) $this->config->get_api_url(), '/' );
		$api_key  = (string) $this->config->get_api_key();

		if ( '' === $api_base || '' === $api_key ) {
			return array();
		}

		$response = wp_remote_get(
			$api_base . '/widget/v1/locations',
			array(
				'timeout' => 5,
				'headers' => array(
					'X-IDEA89-Key' => $api_key,
					'Origin'       => home_url(),
					'Accept'       => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$list = ( is_array( $body ) && isset( $body['locations'] ) && is_array( $body['locations'] ) )
			? $body['locations']
			: array();

		set_transient( self::LOCATIONS_CACHE_KEY, $list, self::LOCATIONS_CACHE_TTL );

		return $list;
	}
}
