<?php
/**
 * The postMessage bridge that lets the framed checkout talk to the chat
 * widget.
 *
 * Same envelope as the Magento 2 and Magento 1 modules:
 * {source:'idea89-checkout', type:'ready'|'resize'|'error'|'success', ...}.
 * `error` carries `code`; `success` carries `orderId`, `total`, `currency`.
 * The widget's panel code (api/src/widget/checkout/index.ts) is the only
 * reader of these messages and is not touched by this plugin — matching the
 * envelope exactly is what makes this bridge work with it.
 *
 * Every script this class emits starts with `if(window.parent===window)
 * {return;}` as its FIRST statement. That is deliberate and load-bearing:
 * the thank-you page this bridge also runs on (see
 * Idea89_Mini_Checkout::render_success_bridge()) is hit by every WooCommerce
 * shopper, framed or not, on every order. A bridge that did anything before
 * that check — even just building the payload — risks throwing on a normal,
 * unframed purchase and breaking order confirmation for every merchant who
 * installs this plugin.
 *
 * DELIBERATE DIVERGENCE FROM MAGENTO 2's Block\Checkout\Bridge: that class
 * carries a single template and a `bridge_mode` layout argument
 * ('handshake' vs 'success', defaulting to 'handshake' when missing or
 * unrecognised) to tell the checkout page and the success page apart,
 * because Magento renders both through the same block/template pair. Here
 * there is no shared template to disambiguate: handshake_js() is printed
 * only from Idea89_Mini_Checkout::print_document() (the stripped checkout
 * page) and success_js() only from that class's woocommerce_thankyou
 * handler (the real thank-you page). The discriminator
 * is which method the caller reaches, not a mode value read out of either
 * one — so there is no "unrecognised mode" branch to default anywhere,
 * because no branch exists at all. Functionally the same guarantee as
 * Magento's default-to-handshake rule (an ambiguous or missing signal can
 * never produce a false 'success'), just enforced by the call graph instead
 * of by a value.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the postMessage envelopes the framed checkout sends its parent.
 *
 * Each *_js() method returns JavaScript only. The <script> element around
 * it is always printed by core's wp_print_inline_script_tag(), here or at
 * the call site — never built by hand and never buffered into a string
 * that is echoed later. Every JSON payload is encoded with the JSON_HEX_*
 * flags so that no value (an order number rewritten by another plugin,
 * say) can carry a "</script>" that closes the element early.
 */
class Idea89_Checkout_Bridge {

	/**
	 * Flags for wp_json_encode() when the JSON lands inside a <script> element.
	 * < > & ' " become \u003C etc.: still the same value to JavaScript,
	 * invisible to the HTML parser.
	 */
	const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

	/**
	 * Script for the stripped checkout page: announces `ready`, then reports
	 * `resize` whenever the document height changes by more than a noise
	 * threshold, so the parent frame can grow the panel to fit. The caller
	 * prints it through wp_print_inline_script_tag().
	 *
	 * @return string JavaScript.
	 */
	public function handshake_js() {
		return '(function(){'
			. 'if(window.parent===window){return;}'
			. 'var post=function(m){try{window.parent.postMessage(m,window.location.origin);}catch(e){}};'
			. "post({source:'idea89-checkout',type:'ready'});"
			. 'var last=0;var send=function(){'
			. 'var h=Math.ceil(document.documentElement.scrollHeight);'
			. 'if(Math.abs(h-last)<12){return;}last=h;'
			. "post({source:'idea89-checkout',type:'resize',height:h});};"
			. 'send();'
			. 'if(window.ResizeObserver){new ResizeObserver(send).observe(document.body);}'
			. 'else{setInterval(send,600);}'
			. '})();';
	}

	/**
	 * Script for the merchant's real thank-you page. $order is the ONLY
	 * signal this method trusts that an order genuinely completed; whether
	 * the shopper is framed at all is decided entirely client-side by the
	 * window.parent guard baked into every script this class emits, never by
	 * anything read here.
	 *
	 * The caller (Idea89_Mini_Checkout::render_success_bridge()) is
	 * responsible for confirming $order is a real, loaded order before
	 * calling this — see that method's docblock for why session state alone
	 * can never be trusted for that on WooCommerce, same trap as Magento 2's
	 * getLastRealOrder() — and prints it through wp_print_inline_script_tag().
	 *
	 * @param WC_Order $order The completed order.
	 * @return string JavaScript.
	 */
	public function success_js( $order ) {
		// Reaching the thank-you page is not proof of a placed order: a
		// declined or abandoned payment lands here too, with the order left
		// failed, cancelled or pending. Only a confirmed order may tell the
		// widget "success"; anything else posts a non-success envelope.
		if ( ! $this->is_confirmed( $order ) ) {
			$failed  = method_exists( $order, 'has_status' ) && $order->has_status( array( 'failed', 'cancelled' ) );
			$payload = wp_json_encode(
				$failed
					// The widget falls back to its checkout CTA on an error,
					// which is the right next step after a declined payment.
					? array(
						'source' => 'idea89-checkout',
						'type'   => 'error',
						'code'   => 'order_failed',
					)
					// Pending can mean an off-site payment still settling.
					// The widget ignores this type, so the panel stays on
					// WooCommerce's own order page rather than inviting a
					// second payment through the fallback CTA.
					: array(
						'source' => 'idea89-checkout',
						'type'   => 'pending',
					),
				self::JSON_FLAGS
			);

			return '(function(){'
				. 'if(window.parent===window){return;}'
				. 'try{window.parent.postMessage(' . $payload . ',window.location.origin);}catch(e){}'
				. '})();';
		}

		$payload = wp_json_encode(
			array(
				'source'   => 'idea89-checkout',
				'type'     => 'success',
				'orderId'  => (string) $order->get_order_number(),
				'total'    => (string) $order->get_total(),
				'currency' => (string) $order->get_currency(),
			),
			self::JSON_FLAGS
		);

		return '(function(){'
			. 'if(window.parent===window){return;}'
			. 'try{window.parent.postMessage(' . $payload . ',window.location.origin);}catch(e){}'
			. '})();';
	}

	/**
	 * Whether $order is one the merchant has genuinely accepted: a paid
	 * status (processing, completed, plus any a plugin registers through
	 * WooCommerce's own woocommerce_order_is_paid_statuses filter) or
	 * on-hold, which is where the offline gateways (bacs, cheque) leave a
	 * placed order awaiting manual payment. An allowlist, not a denylist, so
	 * pending, failed, cancelled, draft and any unknown status never count.
	 *
	 * @param WC_Order $order The order the thank-you page was rendered for.
	 * @return bool
	 */
	public function is_confirmed( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'has_status' ) ) {
			return false;
		}
		$statuses   = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		$statuses[] = 'on-hold';
		return (bool) $order->has_status( $statuses );
	}

	/**
	 * Prints a minimal same-origin document whose only job is to tell the
	 * framing widget why the checkout will not render, so it falls back
	 * immediately instead of waiting out the widget's 6-second handshake
	 * timeout. Same envelope and shape as Magento 2's
	 * Controller\Checkout\Mini::bridgeError() and the Magento 1 equivalent.
	 *
	 * The markup is a literal; the only data on the page is the envelope,
	 * and core prints that as the script element.
	 *
	 * @param string $code Machine-readable reason, e.g. 'empty_cart'.
	 * @return void
	 */
	public function print_error_page( $code ) {
		echo '<!doctype html><meta charset="utf-8"><title></title>';
		wp_print_inline_script_tag( $this->error_js( $code ) );
	}

	/**
	 * The error envelope JavaScript on its own.
	 *
	 * @param string $code Machine-readable reason, e.g. 'empty_cart'.
	 * @return string JavaScript.
	 */
	public function error_js( $code ) {
		$payload = wp_json_encode(
			array(
				'source' => 'idea89-checkout',
				'type'   => 'error',
				'code'   => (string) $code,
			),
			self::JSON_FLAGS
		);

		return 'if(window.parent!==window){'
			. 'window.parent.postMessage(' . $payload . ',window.location.origin);}';
	}
}
