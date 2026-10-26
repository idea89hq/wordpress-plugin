<?php
/**
 * The product image gallery for the chat's image viewer.
 *
 * GET /wp-json/idea89/v1/product-gallery?id=<product id>  (or ?sku=<sku>)
 *
 * The widget asks for it only when a shopper presses the enlarge button on a
 * product card, so a conversation full of cards costs nothing until someone
 * wants a closer look. Returns {images: [{url, label}]}: the product image
 * then its gallery, at the "large" size, at most 12. Only for a published
 * product the shop shows (not hidden from the catalogue), so a guessed id of
 * a draft or hidden product answers 404 and nothing more. The same contract
 * as the Magento modules' idea89/product/gallery.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and serves the gallery route.
 */
class Idea89_Product_Gallery {

	const REST_NAMESPACE = 'idea89/v1';
	const MAX_IMAGES     = 12;

	/**
	 * Plugin config (module enabled).
	 *
	 * @var Idea89_Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Config $config Plugin config.
	 */
	public function __construct( $config ) {
		$this->config = $config;
	}

	/**
	 * Hooks the route registration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers GET /product-gallery.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/product-gallery',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				// Public, read-only, and limited to what the shop already shows.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /product-gallery
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		if ( ! $this->config->is_enabled() || ! function_exists( 'wc_get_product' ) ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$id  = (string) $request->get_param( 'id' );
		$sku = trim( (string) $request->get_param( 'sku' ) );
		if ( '' !== $id && ctype_digit( $id ) ) {
			$product_id = (int) $id;
		} elseif ( '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$product_id = (int) wc_get_product_id_by_sku( $sku );
		} else {
			$product_id = 0;
		}

		$product = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product || 'publish' !== $product->get_status() || 'hidden' === $product->get_catalog_visibility() ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$ids = array_values( array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', (array) $product->get_gallery_image_ids() ) ) ) );
		$ids = array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_IMAGES );

		$images = array();
		foreach ( $ids as $attachment_id ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'large' );
			if ( ! $url ) {
				continue;
			}
			$alt      = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
			$images[] = array(
				'url'   => esc_url_raw( $url ),
				'label' => sanitize_text_field( $alt ),
			);
		}

		$response = new WP_REST_Response( array( 'images' => $images ), 200 );
		// Read-only and the same for every shopper: short public caching.
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}
}
