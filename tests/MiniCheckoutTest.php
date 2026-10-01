<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/class-idea89-checkout-config.php';
require_once IDEA89_PLUGIN_DIR . 'includes/checkout/class-idea89-checkout-bridge.php';
require_once IDEA89_PLUGIN_DIR . 'includes/checkout/class-idea89-mini-checkout.php';
require_once IDEA89_PLUGIN_DIR . 'includes/admin/class-idea89-admin-settings.php';

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal stand-in for WordPress's post object — only the property
	 * detect_checkout_type() and the block/shortcode renderers actually read.
	 */
	class WP_Post {
		public $post_content;

		public function __construct( $post_content = '' ) {
			$this->post_content = $post_content;
		}
	}
}

/**
 * Fake WC() global: just enough surface for Idea89_Mini_Checkout's cart guard.
 */
class Fake_WC {
	public $cart;

	public function __construct( $cart = null ) {
		$this->cart = $cart;
	}
}

/**
 * Fake WC_Cart: just is_empty().
 */
class Fake_WC_Cart {
	private $is_empty;

	public function __construct( $is_empty = true ) {
		$this->is_empty = $is_empty;
	}

	public function is_empty() {
		return $this->is_empty;
	}
}

/**
 * Fake WC_Order: just the three fields the success envelope carries.
 */
class Fake_WC_Order {
	private $data;

	public function __construct( array $data = array() ) {
		$this->data = array_merge(
			array(
				'order_number' => '1042',
				'total'        => '59.98',
				'currency'     => 'GBP',
				'status'       => 'processing',
			),
			$data
		);
	}

	public function has_status( $status ) {
		return in_array( $this->data['status'], (array) $status, true );
	}

	public function get_order_number() {
		return $this->data['order_number'];
	}

	public function get_total() {
		return $this->data['total'];
	}

	public function get_currency() {
		return $this->data['currency'];
	}
}

class MiniCheckoutTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( '__' )->returnArg( 1 );
		// Core's inline-script printer, as it prints for an HTML5 theme:
		// <script>DATA</script> plus a newline, attributes sanitized by core.
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			function ( $data, $attributes = array() ) {
				echo '<script>' . $data . '</script>' . "\n";
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $options
	 */
	private function with_options( array $options ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) use ( $options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
	}

	/**
	 * render() prints; capture it the way a browser would receive it.
	 *
	 * @param Idea89_Mini_Checkout $mini
	 * @return string
	 */
	private function rendered( Idea89_Mini_Checkout $mini ) {
		ob_start();
		$mini->render();
		return ob_get_clean();
	}

	/**
	 * The error page, captured.
	 *
	 * @param Idea89_Checkout_Bridge $bridge
	 * @param string                 $code
	 * @return string
	 */
	private function error_page( Idea89_Checkout_Bridge $bridge, $code ) {
		ob_start();
		$bridge->print_error_page( $code );
		return ob_get_clean();
	}

	private function embedded_mini_checkout() {
		$this->with_options(
			array(
				'idea89_enabled'       => true,
				'idea89_checkout_mode' => 'embedded',
			)
		);

		return new Idea89_Mini_Checkout( new Idea89_Config(), new Idea89_Checkout_Config(), new Idea89_Checkout_Bridge() );
	}

	/* ---------------- Task 6.3: query var handling ---------------- */

	public function test_maybe_render_does_nothing_when_the_query_var_is_absent() {
		Functions\when( 'get_query_var' )->justReturn( '' );
		$mini = $this->embedded_mini_checkout();

		ob_start();
		$mini->maybe_render();
		$output = ob_get_clean();

		// No output at all — ordinary WooCommerce/WordPress requests must be
		// completely untouched by this class being registered.
		$this->assertSame( '', $output );
	}

	public function test_query_var_is_added_to_the_recognised_list() {
		$mini = $this->embedded_mini_checkout();
		$vars = $mini->add_query_var( array( 'existing' ) );

		$this->assertSame( array( 'existing', Idea89_Mini_Checkout::QUERY_VAR ), $vars );
	}

	/* ---------------- Task 6.3: guards, in the documented order ---------------- */

	public function test_renders_the_bridge_error_when_the_mode_is_not_embedded() {
		$this->with_options(
			array(
				'idea89_enabled'       => true,
				'idea89_checkout_mode' => 'express',
			)
		);
		$mini = new Idea89_Mini_Checkout( new Idea89_Config(), new Idea89_Checkout_Config(), new Idea89_Checkout_Bridge() );

		$html = $this->rendered( $mini );

		$this->assertStringContainsString( '"source":"idea89-checkout"', $html );
		$this->assertStringContainsString( '"type":"error"', $html );
		$this->assertStringContainsString( '"code":"not_available"', $html );
	}

	public function test_renders_the_bridge_error_when_the_plugin_is_disabled() {
		$this->with_options(
			array(
				'idea89_enabled'       => false,
				'idea89_checkout_mode' => 'embedded',
			)
		);
		$mini = new Idea89_Mini_Checkout( new Idea89_Config(), new Idea89_Checkout_Config(), new Idea89_Checkout_Bridge() );

		$this->assertStringContainsString( '"code":"not_available"', $this->rendered( $mini ) );
	}

	public function test_renders_the_bridge_error_on_an_empty_cart() {
		Functions\when( 'WC' )->justReturn( new Fake_WC( new Fake_WC_Cart( true ) ) );
		$mini = $this->embedded_mini_checkout();

		$html = $this->rendered( $mini );

		$this->assertStringContainsString( '"code":"empty_cart"', $html );
	}

	public function test_renders_the_bridge_error_when_wc_cart_is_not_available_at_all() {
		// WC()->cart can legitimately be null this early in the request
		// lifecycle (e.g. before woocommerce_init has run) — must fail
		// closed to empty_cart, never fatal on a null->is_empty() call.
		Functions\when( 'WC' )->justReturn( new Fake_WC( null ) );
		$mini = $this->embedded_mini_checkout();

		$this->assertStringContainsString( '"code":"empty_cart"', $this->rendered( $mini ) );
	}

	public function test_render_falls_back_to_unsupported_checkout_when_the_probe_is_unknown() {
		// Guard order matters here: the cart must be non-empty for the probe
		// to even run, so this proves detect_checkout_type() is consulted
		// AFTER the cart guard, not instead of it.
		Functions\when( 'WC' )->justReturn( new Fake_WC( new Fake_WC_Cart( false ) ) );
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );
		$mini = $this->embedded_mini_checkout();

		$html = $this->rendered( $mini );

		$this->assertStringContainsString( '"code":"unsupported_checkout"', $html );
	}

	/* ---------------- Task 6.3: the rendered document ---------------- */

	public function test_renders_the_document_with_the_marker_and_wp_head_and_wp_footer() {
		Functions\when( 'WC' )->justReturn( new Fake_WC( new Fake_WC_Cart( false ) ) );
		Functions\when( 'wp_head' )->alias(
			function () {
				echo '<!-- head-marker -->';
			}
		);
		Functions\when( 'wp_footer' )->alias(
			function () {
				echo '<!-- footer-marker -->';
			}
		);
		Functions\when( 'wc_get_page_id' )->justReturn( 42 );
		Functions\when( 'get_post' )->justReturn( new WP_Post( '[woocommerce_checkout]' ) );
		Functions\when( 'has_block' )->justReturn( false );
		Functions\when( 'has_shortcode' )->justReturn( true );
		Functions\when( 'do_shortcode' )->justReturn( '<form>checkout fields</form>' );

		$mini = $this->embedded_mini_checkout();
		$html = $this->rendered( $mini );

		$this->assertStringContainsString( 'idea89-mini-checkout', $html );
		$this->assertStringContainsString( '<!-- head-marker -->', $html );
		$this->assertStringContainsString( '<!-- footer-marker -->', $html );
		$this->assertStringContainsString( '<form>checkout fields</form>', $html );
		// wp_head must run before the checkout content, and wp_footer after
		// the bridge script — otherwise enqueued styles/scripts land in the
		// wrong place relative to what they style or depend on.
		$this->assertLessThan( strpos( $html, 'checkout fields' ), strpos( $html, 'head-marker' ) );
		$this->assertGreaterThan( strpos( $html, 'checkout fields' ), strpos( $html, 'footer-marker' ) );
	}

	public function test_renders_via_do_blocks_when_the_probe_says_block() {
		Functions\when( 'WC' )->justReturn( new Fake_WC( new Fake_WC_Cart( false ) ) );
		Functions\when( 'wp_head' )->justReturn( null );
		Functions\when( 'wp_footer' )->justReturn( null );
		Functions\when( 'wc_get_page_id' )->justReturn( 42 );
		Functions\when( 'get_post' )->justReturn( new WP_Post( '<!-- wp:woocommerce/checkout /-->' ) );
		Functions\when( 'has_block' )->justReturn( true );
		Functions\when( 'do_blocks' )->justReturn( '<div class="wc-block-checkout">block checkout</div>' );

		$mini = $this->embedded_mini_checkout();
		$html = $this->rendered( $mini );

		$this->assertStringContainsString( 'block checkout', $html );
	}

	/**
	 * The stripped page is printed, never buffered into a string and echoed:
	 * wp_head() and wp_footer() print themselves, and every <script> element
	 * — the bridge handshake included — is printed by core's
	 * wp_print_inline_script_tag(), so nothing this class echoes directly is
	 * a script tag.
	 */
	public function test_document_handshake_is_printed_through_the_core_inline_script_api() {
		Functions\when( 'WC' )->justReturn( new Fake_WC( new Fake_WC_Cart( false ) ) );
		Functions\when( 'wp_head' )->justReturn( null );
		Functions\when( 'wp_footer' )->justReturn( null );
		Functions\when( 'wc_get_page_id' )->justReturn( 42 );
		Functions\when( 'get_post' )->justReturn( new WP_Post( '[woocommerce_checkout]' ) );
		Functions\when( 'has_block' )->justReturn( false );
		Functions\when( 'has_shortcode' )->justReturn( true );
		Functions\when( 'do_shortcode' )->justReturn( '<form>checkout fields</form>' );

		$printed = null;
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			function ( $data, $attributes = array() ) use ( &$printed ) {
				$printed = $data;
			}
		);

		$mini = $this->embedded_mini_checkout();

		ob_start();
		$mini->render();
		$echoed = ob_get_clean();

		$this->assertNotNull( $printed, 'The handshake must reach core\'s printer.' );
		$this->assertStringContainsString( "type:'ready'", $printed );
		$this->assertStringNotContainsString( '<script', $echoed, 'No script tag is echoed by hand; core prints it.' );
		$this->assertStringContainsString( '<form>checkout fields</form>', $echoed );
	}

	public function test_error_page_is_printed_through_the_core_inline_script_api() {
		$this->with_options(
			array(
				'idea89_enabled'       => true,
				'idea89_checkout_mode' => 'express',
			)
		);
		$mini = new Idea89_Mini_Checkout( new Idea89_Config(), new Idea89_Checkout_Config(), new Idea89_Checkout_Bridge() );

		$printed = null;
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			function ( $data, $attributes = array() ) use ( &$printed ) {
				$printed = $data;
			}
		);

		ob_start();
		$mini->render();
		$echoed = ob_get_clean();

		$this->assertNotNull( $printed, 'The error envelope must reach core\'s printer.' );
		$this->assertStringContainsString( '"code":"not_available"', $printed );
		$this->assertStringNotContainsString( '<script', $echoed, 'No script tag is echoed by hand; core prints it.' );
		$this->assertStringStartsWith( '<!doctype html>', $echoed );
	}

	/* ---------------- Task 6.3: the thank-you success hook ---------------- */

	public function test_thank_you_hook_guards_against_being_unframed_as_the_first_statement() {
		// This is the load-bearing assertion carried across from the Magento
		// bug: window.parent === window must be the FIRST statement inside
		// the emitted script, because woocommerce_thankyou fires on every
		// order, framed or not, and this is the only thing that keeps an
		// unframed purchase from being touched at all.
		Functions\when( 'wc_get_order' )->justReturn( new Fake_WC_Order() );
		$mini = $this->embedded_mini_checkout();

		ob_start();
		$mini->render_success_bridge( 1042 );
		$script = ob_get_clean();

		$this->assertStringStartsWith( '<script>(function(){if(window.parent===window){return;}', $script );
	}

	public function test_thank_you_hook_prints_nothing_when_checkout_mode_is_not_embedded() {
		$this->with_options(
			array(
				'idea89_enabled'       => true,
				'idea89_checkout_mode' => 'express',
			)
		);
		Functions\when( 'wc_get_order' )->justReturn( new Fake_WC_Order() );
		$mini = new Idea89_Mini_Checkout( new Idea89_Config(), new Idea89_Checkout_Config(), new Idea89_Checkout_Bridge() );

		ob_start();
		$mini->render_success_bridge( 1042 );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_thank_you_hook_prints_nothing_when_the_order_cannot_be_loaded() {
		// wc_get_order() returns false for a bad id — never trust the hook's
		// mere firing as proof an order exists.
		Functions\when( 'wc_get_order' )->justReturn( false );
		$mini = $this->embedded_mini_checkout();

		ob_start();
		$mini->render_success_bridge( 999999 );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/* ---------------- Idea89_Checkout_Bridge ---------------- */

	public function test_handshake_script_guards_first_and_announces_ready() {
		$bridge = new Idea89_Checkout_Bridge();
		$script = $bridge->handshake_js();

		$this->assertStringStartsWith( '(function(){if(window.parent===window){return;}', $script );
		$this->assertStringContainsString( "type:'ready'", $script );
		$this->assertStringContainsString( "type:'resize'", $script );
	}

	public function test_success_script_carries_order_id_total_and_currency() {
		$bridge = new Idea89_Checkout_Bridge();
		$script = $bridge->success_js( new Fake_WC_Order( array( 'order_number' => '1042', 'total' => '59.98', 'currency' => 'GBP' ) ) );

		$this->assertStringContainsString( '"type":"success"', $script );
		$this->assertStringContainsString( '"orderId":"1042"', $script );
		$this->assertStringContainsString( '"total":"59.98"', $script );
		$this->assertStringContainsString( '"currency":"GBP"', $script );
	}

	public function test_error_page_carries_the_code() {
		$bridge = new Idea89_Checkout_Bridge();
		$html   = $this->error_page( $bridge, 'empty_cart' );

		$this->assertStringContainsString( '"source":"idea89-checkout"', $html );
		$this->assertStringContainsString( '"type":"error"', $html );
		$this->assertStringContainsString( '"code":"empty_cart"', $html );
		$this->assertStringContainsString( 'window.parent!==window', $html );
	}

	public function test_no_script_ever_targets_a_wildcard_origin() {
		// A wildcard target origin would leak the order id and total to any
		// page that frames the checkout — this must never happen.
		$bridge = new Idea89_Checkout_Bridge();
		$order  = new Fake_WC_Order();

		foreach (
			array(
				$bridge->handshake_js(),
				$bridge->success_js( $order ),
				$this->error_page( $bridge, 'empty_cart' ),
			) as $script
		) {
			$this->assertStringNotContainsString( "'*'", $script );
			$this->assertStringContainsString( 'window.location.origin', $script );
		}
	}

	/**
	 * The bridge builds JavaScript; the <script> element around it is
	 * printed by core (wp_print_inline_script_tag), never hand-written.
	 */
	public function test_bridge_scripts_are_wrapped_by_core_not_by_hand() {
		$bridge = new Idea89_Checkout_Bridge();
		$order  = new Fake_WC_Order();

		$this->assertStringStartsWith( '(function(){if(window.parent===window){return;}', $bridge->handshake_js() );
		$this->assertStringStartsWith( '(function(){if(window.parent===window){return;}', $bridge->success_js( $order ) );
		$this->assertStringStartsWith( 'if(window.parent!==window){', $bridge->error_js( 'empty_cart' ) );

		foreach ( array( $bridge->handshake_js(), $bridge->success_js( $order ), $bridge->error_js( 'empty_cart' ) ) as $js ) {
			$this->assertStringNotContainsString( '<script', $js );
		}
	}

	public function test_thank_you_hook_prints_through_the_core_inline_script_api() {
		Functions\when( 'wc_get_order' )->justReturn( new Fake_WC_Order( array( 'order_number' => '1042' ) ) );
		$mini = $this->embedded_mini_checkout();

		$printed = null;
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			function ( $data, $attributes = array() ) use ( &$printed ) {
				$printed = $data;
			}
		);

		ob_start();
		$mini->render_success_bridge( 1042 );
		$echoed = ob_get_clean();

		$this->assertSame( '', $echoed, 'Nothing is echoed directly; core prints the tag.' );
		$this->assertStringContainsString( '"orderId":"1042"', $printed );
	}

	/**
	 * The order number is filterable by other plugins (sequential-number
	 * plugins rewrite it), so it is treated as untrusted inside the script:
	 * a "</script>" in it must not be able to close the element.
	 */
	public function test_success_payload_cannot_close_the_script_element() {
		$bridge = new Idea89_Checkout_Bridge();
		$js     = $bridge->success_js( new Fake_WC_Order( array( 'order_number' => '1042</script><script>alert(1)</script>' ) ) );

		$this->assertStringNotContainsString( '</script>', $js );
		$this->assertStringContainsString( '\\u003C', $js );
	}

	public function test_error_code_cannot_close_the_script_element() {
		$bridge = new Idea89_Checkout_Bridge();
		$js     = $bridge->error_js( 'x</script>' );

		$this->assertStringNotContainsString( '</script>', $js );
		$this->assertStringContainsString( '\\u003C', $js );
	}

	/* ---------------- Task 6.4: detect_checkout_type() ---------------- */

	public function test_detect_checkout_type_finds_the_block() {
		Functions\when( 'wc_get_page_id' )->justReturn( 42 );
		Functions\when( 'get_post' )->justReturn( new WP_Post( '<!-- wp:woocommerce/checkout /-->' ) );
		Functions\when( 'has_block' )->justReturn( true );
		Functions\when( 'has_shortcode' )->justReturn( false );

		$this->assertSame( 'block', Idea89_Mini_Checkout::detect_checkout_type() );
	}

	public function test_detect_checkout_type_finds_the_classic_shortcode() {
		Functions\when( 'wc_get_page_id' )->justReturn( 42 );
		Functions\when( 'get_post' )->justReturn( new WP_Post( '[woocommerce_checkout]' ) );
		Functions\when( 'has_block' )->justReturn( false );
		Functions\when( 'has_shortcode' )->justReturn( true );

		$this->assertSame( 'shortcode', Idea89_Mini_Checkout::detect_checkout_type() );
	}

	public function test_detect_checkout_type_is_unknown_when_neither_is_present() {
		Functions\when( 'wc_get_page_id' )->justReturn( 42 );
		Functions\when( 'get_post' )->justReturn( new WP_Post( '<p>Some other content entirely.</p>' ) );
		Functions\when( 'has_block' )->justReturn( false );
		Functions\when( 'has_shortcode' )->justReturn( false );

		$this->assertSame( 'unknown', Idea89_Mini_Checkout::detect_checkout_type() );
	}

	public function test_detect_checkout_type_is_unknown_when_there_is_no_checkout_page() {
		Functions\when( 'wc_get_page_id' )->justReturn( 0 );

		$this->assertSame( 'unknown', Idea89_Mini_Checkout::detect_checkout_type() );
	}

	public function test_detect_checkout_type_prefers_block_when_both_are_present() {
		// A store mid-migration could have the block on a page that still
		// carries the shortcode text somewhere else in its content — the
		// probe must check block first, per the brief's documented order.
		Functions\when( 'wc_get_page_id' )->justReturn( 42 );
		Functions\when( 'get_post' )->justReturn( new WP_Post( '[woocommerce_checkout]<!-- wp:woocommerce/checkout /-->' ) );
		Functions\when( 'has_block' )->justReturn( true );
		Functions\when( 'has_shortcode' )->justReturn( true );

		$this->assertSame( 'block', Idea89_Mini_Checkout::detect_checkout_type() );
	}

	/* ---------------- Task 6.4: admin status line ---------------- */

	public function test_checkout_probe_message_for_block() {
		$this->assertSame(
			'Your checkout uses the WooCommerce Checkout block. Test the assistant panel on your storefront before going live.',
			Idea89_Admin_Settings::checkout_probe_message( 'block' )
		);
	}

	public function test_checkout_probe_message_for_shortcode() {
		$this->assertSame(
			'Your checkout uses the classic checkout shortcode, which works with the assistant panel.',
			Idea89_Admin_Settings::checkout_probe_message( 'shortcode' )
		);
	}

	public function test_checkout_probe_message_for_unknown() {
		$this->assertSame(
			'We could not identify your checkout page, so the assistant will send shoppers to it instead of showing it in the chat.',
			Idea89_Admin_Settings::checkout_probe_message( 'unknown' )
		);
	}

	/* ---------------- only a confirmed order signals success ---------------- */

	public function test_success_script_is_sent_for_on_hold_and_completed_orders() {
		$bridge = new Idea89_Checkout_Bridge();
		foreach ( array( 'processing', 'completed', 'on-hold' ) as $status ) {
			$js = $bridge->success_js( new Fake_WC_Order( array( 'status' => $status ) ) );
			$this->assertStringContainsString( '"type":"success"', $js, $status );
		}
	}

	public function test_a_failed_or_cancelled_order_posts_an_error_not_success() {
		$bridge = new Idea89_Checkout_Bridge();
		foreach ( array( 'failed', 'cancelled' ) as $status ) {
			$js = $bridge->success_js( new Fake_WC_Order( array( 'status' => $status ) ) );
			$this->assertStringNotContainsString( '"type":"success"', $js, $status );
			$this->assertStringNotContainsString( '"orderId"', $js, $status );
			$this->assertStringContainsString( '"type":"error"', $js, $status );
			$this->assertStringContainsString( '"code":"order_failed"', $js, $status );
			$this->assertStringStartsWith( '(function(){if(window.parent===window){return;}', $js );
		}
	}

	public function test_a_pending_or_draft_order_posts_a_non_success() {
		$bridge = new Idea89_Checkout_Bridge();
		foreach ( array( 'pending', 'checkout-draft', 'trash', 'some-unknown-status' ) as $status ) {
			$js = $bridge->success_js( new Fake_WC_Order( array( 'status' => $status ) ) );
			$this->assertStringNotContainsString( '"type":"success"', $js, $status );
			$this->assertStringContainsString( '"type":"pending"', $js, $status );
		}
	}

	public function test_thank_you_hook_does_not_signal_success_for_a_failed_order() {
		Functions\when( 'wc_get_order' )->justReturn( new Fake_WC_Order( array( 'status' => 'failed' ) ) );
		$mini = $this->embedded_mini_checkout();

		ob_start();
		$mini->render_success_bridge( 1042 );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '"type":"success"', $output );
		$this->assertStringContainsString( '"code":"order_failed"', $output );
	}
}
