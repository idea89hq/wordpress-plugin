<?php
/**
 * Typed accessors for the order-tracking settings.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and clamps the order-tracking options.
 */
class Idea89_Order_Tracking_Config {

	const DEFAULT_MAX_RECENT = 3;
	const MIN_RECENT         = 1;
	const MAX_RECENT         = 10;

	/**
	 * Whether the merchant has switched order tracking on.
	 *
	 * Defaults to off: the endpoints read customer orders, so they stay shut
	 * until the merchant opts in rather than appearing on upgrade.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) get_option( 'idea89_order_tracking_enabled', false );
	}

	/**
	 * Whether the widget should offer a "Track parcel" button.
	 *
	 * @return bool
	 */
	public function show_tracking_button() {
		return (bool) get_option( 'idea89_order_tracking_show_button', true );
	}

	/**
	 * Where the widget sends a shopper who needs a human.
	 *
	 * @return string
	 */
	public function get_support_url() {
		$url = trim( (string) get_option( 'idea89_order_tracking_support_url', '' ) );

		return '' === $url ? '' : $url;
	}

	/**
	 * Label for the support link.
	 *
	 * @return string
	 */
	public function get_support_label() {
		$label = trim( (string) get_option( 'idea89_order_tracking_support_label', '' ) );

		return '' === $label ? __( 'Contact support', 'idea89-ai-shopping-assistant' ) : $label;
	}

	/**
	 * How many recent orders the widget may list, clamped to a sane range.
	 *
	 * @return int
	 */
	public function get_max_recent_orders() {
		$value = (int) get_option( 'idea89_order_tracking_max_recent', self::DEFAULT_MAX_RECENT );

		if ( $value < self::MIN_RECENT ) {
			return self::MIN_RECENT;
		}

		if ( $value > self::MAX_RECENT ) {
			return self::MAX_RECENT;
		}

		return $value;
	}
}
