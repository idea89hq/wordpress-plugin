<?php
/**
 * Typed accessors for the checkout experience setting.
 *
 * Same ladder as the Magento 2 module (Idea89\Assistant\Model\CheckoutConfig):
 * exactly one rung at a time, from today's cart-page redirect up through an
 * assistant-rendered checkout. Never read idea89_checkout_mode through
 * get_option() directly elsewhere — this class is the single place the
 * option name and the default value are written down.
 *
 * Unlike Magento, WooCommerce's own cart and checkout pages are already
 * discoverable through wc_get_cart_url() / wc_get_checkout_url() (Woo keeps
 * its own page registry in Settings > Advanced), so this class has no
 * cart_path/checkout_path override options of its own — it always derives
 * the path from those two functions.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitises and defaults the checkout mode and paths.
 */
class Idea89_Checkout_Config {

	/** Tier 0 — today's behaviour: navigate to the cart page. */
	const UI_FULL   = 'full';
	const UI_INLINE = 'inline';

	const MODE_OFF = 'off';
	/** Tier 1 — cart summary card in chat, then jump straight to checkout. Shipped default. */
	const MODE_EXPRESS = 'express';
	/** Tier 2 — the merchant's own WooCommerce checkout inside the chat panel. */
	const MODE_EMBEDDED = 'embedded';
	/** Tier 3 — assistant-rendered checkout; we place the order. Beta. */
	const MODE_NATIVE = 'native';

	/** Fallback used when wc_get_cart_url() is missing, empty or unparsable. */
	const DEFAULT_CART_PATH = '/cart/';
	/** Fallback used when wc_get_checkout_url() is missing, empty or unparsable. */
	const DEFAULT_CHECKOUT_PATH = '/checkout/';

	/**
	 * Every mode the select recognises.
	 *
	 * @var string[]
	 */
	private static $modes = array(
		self::MODE_OFF,
		self::MODE_EXPRESS,
		self::MODE_EMBEDDED,
		self::MODE_NATIVE,
	);

	/**
	 * The checkout ladder rung, or the shipped default when unset or
	 * unrecognised.
	 *
	 * A value we don't recognise (hand-edited row, downgraded plugin)
	 * collapses to the shipped default rather than leaving the widget in an
	 * undefined branch. Express is the safe landing spot: it adds a basket
	 * confirmation card and changes nothing about the merchant's checkout.
	 *
	 * @return string
	 */
	public function get_mode() {
		$mode = (string) get_option( 'idea89_checkout_mode', self::MODE_EXPRESS );
		return in_array( $mode, self::$modes, true ) ? $mode : self::MODE_EXPRESS;
	}

	/**
	 * Whether the store is on the Express handoff rung.
	 *
	 * @return bool
	 */
	public function is_express() {
		return self::MODE_EXPRESS === $this->get_mode();
	}

	/**
	 * Whether the store is on the Checkout-in-chat rung.
	 *
	 * @return bool
	 */
	public function is_embedded() {
		return self::MODE_EMBEDDED === $this->get_mode();
	}

	/**
	 * Whether the store is on the Native checkout rung.
	 *
	 * @return bool
	 */
	public function is_native() {
		return self::MODE_NATIVE === $this->get_mode();
	}

	/**
	 * True on every rung above Off, i.e. the assistant offers a checkout CTA.
	 *
	 * @return bool
	 */
	public function is_at_least_express() {
		return self::MODE_OFF !== $this->get_mode();
	}

	/**
	 * How native checkout presents itself: 'full' or 'inline'.
	 *
	 * This mirrors the value held in the merchant's IDEA89 account. It is a
	 * render cache for the settings screen, NOT the source of truth, and the
	 * widget does not read it: the widget reads cfg.checkoutUi, served fresh
	 * by the API on every load, so a change is live immediately rather than
	 * waiting on whatever WordPress has cached.
	 *
	 * Anything unrecognised collapses to 'full', the shipped presentation. A
	 * stray value must not silently change what every shopper sees.
	 *
	 * @return string
	 */
	public function get_checkout_ui() {
		$value = get_option( 'idea89_checkout_ui', self::UI_FULL );

		// is_string first, not a blind cast: get_option returns whatever is in
		// the row, and casting an array raises a PHP warning before the
		// comparison even runs.
		return ( is_string( $value ) && self::UI_INLINE === $value ) ? self::UI_INLINE : self::UI_FULL;
	}

	/**
	 * The pinned checkout bar the widget docks above its composer whenever
	 * the live basket has items. Independent of the ladder above — see
	 * is_at_least_express() — even when the mode is Off the bar's click
	 * still routes through the widget's own _goCheckout(), which falls back
	 * to a plain cart-page navigation. Defaults ON, same as Magento's
	 * XML_CHECKOUT_BAR (Idea89\Assistant\Model\CheckoutConfig): new
	 * persistent chrome, so it stays a real per-store toggle even though it
	 * is inert on an empty basket.
	 *
	 * @return bool
	 */
	public function is_checkout_bar_enabled() {
		return (bool) get_option( 'idea89_checkout_bar_enabled', true );
	}

	/**
	 * The cart page path, reduced from wc_get_cart_url() so a store living in
	 * a subdirectory still resolves correctly against window.location.origin
	 * in the widget.
	 *
	 * @return string
	 */
	public function get_cart_path() {
		$url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';
		return $this->url_to_path( $url, self::DEFAULT_CART_PATH );
	}

	/**
	 * The checkout page path, reduced the same way.
	 *
	 * @return string
	 */
	public function get_checkout_path() {
		$url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '';
		return $this->url_to_path( $url, self::DEFAULT_CHECKOUT_PATH );
	}

	/**
	 * Reduces a full URL to its path component, with exactly one leading and
	 * one trailing slash — same normalisation convention as the Magento 2
	 * module's CheckoutConfig::normalisePath(). Falls back to $default when
	 * the URL is empty or wp_parse_url() cannot make sense of it.
	 *
	 * @param string $url          Full URL, e.g. from wc_get_cart_url().
	 * @param string $fallback_path Path to use when $url does not yield one.
	 * @return string
	 */
	private function url_to_path( $url, $fallback_path ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( null === $path || '' === trim( (string) $path, '/' ) ) {
			return $fallback_path;
		}
		return '/' . trim( $path, '/' ) . '/';
	}
}
