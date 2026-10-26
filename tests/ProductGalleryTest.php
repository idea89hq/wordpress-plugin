<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/product/class-idea89-product-gallery.php';

/**
 * A product as the gallery route reads it.
 */
class Idea89_Fake_Gallery_Product {
	public $status     = 'publish';
	public $visibility = 'visible';
	public $image      = 11;
	public $gallery    = array( 12, 13 );
	public function get_status() {
		return $this->status; }
	public function get_catalog_visibility() {
		return $this->visibility; }
	public function get_image_id() {
		return $this->image; }
	public function get_gallery_image_ids() {
		return $this->gallery; }
}

/**
 * GET /product-gallery: the chat's image viewer asks for it on demand
 * (owner request 2026-10-26).
 */
class ProductGalleryTest extends TestCase {

	/** @var Idea89_Fake_Gallery_Product */
	private $product;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->product = new Idea89_Fake_Gallery_Product();
		$product       = $this->product;
		Functions\when( 'get_option' )->alias( fn ( $name, $default = false ) => 'idea89_enabled' === $name ? true : $default );
		Functions\when( 'wc_get_product' )->alias( fn ( $id ) => 7 === $id ? $product : false );
		Functions\when( 'wc_get_product_id_by_sku' )->alias( fn ( $sku ) => 'MUG-1' === $sku ? 7 : 0 );
		Functions\when( 'wp_get_attachment_image_url' )->alias( fn ( $id, $size ) => 13 === $id ? false : "https://shop.test/img/{$id}-{$size}.jpg" );
		Functions\when( 'get_post_meta' )->alias( fn ( $id ) => 11 === $id ? 'Front view' : '' );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function get( array $params ) {
		$request = new WP_REST_Request();
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return ( new Idea89_Product_Gallery( new Idea89_Config() ) )->handle( $request );
	}

	public function test_returns_the_product_image_then_the_gallery_skipping_missing_files() {
		$res = $this->get( array( 'id' => '7' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame(
			array(
				array( 'url' => 'https://shop.test/img/11-large.jpg', 'label' => 'Front view' ),
				array( 'url' => 'https://shop.test/img/12-large.jpg', 'label' => '' ),
			),
			$res->get_data()['images']
		);
		$this->assertSame( 'public, max-age=300', $res->headers['Cache-Control'] );
	}

	public function test_finds_by_sku_too() {
		$this->assertSame( 200, $this->get( array( 'sku' => 'MUG-1' ) )->get_status() );
	}

	public function test_a_draft_hidden_or_unknown_product_is_not_found() {
		$this->product->status = 'draft';
		$this->assertSame( 404, $this->get( array( 'id' => '7' ) )->get_status() );
		$this->product->status     = 'publish';
		$this->product->visibility = 'hidden';
		$this->assertSame( 404, $this->get( array( 'id' => '7' ) )->get_status() );
		$this->assertSame( 404, $this->get( array( 'id' => '99' ) )->get_status() );
		$this->assertSame( 404, $this->get( array( 'id' => 'abc' ) )->get_status() );
	}

	public function test_off_when_the_plugin_is_off() {
		Functions\when( 'get_option' )->justReturn( false );
		$this->assertSame( 404, $this->get( array( 'id' => '7' ) )->get_status() );
	}
}
