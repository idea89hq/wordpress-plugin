<?php
/**
 * Reduces a WooCommerce order to the few fields the chat widget renders.
 *
 * This class is the privacy boundary for order tracking. It is an allow-list,
 * not a deny-list: nothing reaches the response unless it is named here, so a
 * future WooCommerce field cannot leak by default. Deliberately never emitted:
 * customer id, full email address, billing or shipping addresses, phone
 * numbers, payment method or transaction details, per-item prices, customer
 * notes, and order meta.
 *
 * @package Idea89
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps a WC_Order onto the widget's order shape.
 */
class Idea89_Order_Sanitizer {

	/**
	 * WooCommerce status => the canonical status the widget renders.
	 *
	 * The widget knows eight: pending, holding, processing, complete, shipped,
	 * delivered, refunded, cancelled. Anything unmapped falls back to
	 * 'processing', which is the neutral "in progress" pill.
	 *
	 * @var array<string,string>
	 */
	private static $status_map = array(
		'pending'           => 'pending',
		'checkout-draft'    => 'pending',
		'on-hold'           => 'holding',
		'processing'        => 'processing',
		'completed'         => 'complete',
		'cancelled'         => 'cancelled',
		'refunded'          => 'refunded',
		'failed'            => 'cancelled',
		// Common statuses added by shipment plugins.
		'shipped'           => 'shipped',
		'partially-shipped' => 'shipped',
		'delivered'         => 'delivered',
	);

	/**
	 * Tracking URL builder.
	 *
	 * @var Idea89_Tracking_Url_Resolver
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @param Idea89_Tracking_Url_Resolver $resolver Tracking URL builder.
	 */
	public function __construct( Idea89_Tracking_Url_Resolver $resolver ) {
		$this->resolver = $resolver;
	}

	/**
	 * Builds the widget payload for one order.
	 *
	 * @param WC_Order $order  The order.
	 * @param bool     $detail Include line items and tracking.
	 * @return array<string,mixed>
	 */
	public function sanitize( $order, $detail = false ) {
		$status = (string) $order->get_status();

		$payload = array(
			'increment_id'    => (string) $order->get_order_number(),
			'placed_at'       => $this->placed_at( $order ),
			'status'          => self::map_status( $status ),
			'status_label'    => $this->status_label( $status ),
			'total_formatted' => $this->format_total( $order ),
			'item_count'      => $this->item_count( $order ),
			'shipping_method' => (string) $order->get_shipping_method(),
		);

		if ( ! $detail ) {
			return $payload;
		}

		$payload['items']    = $this->items( $order );
		$payload['tracking'] = $this->tracking( $order );

		return $payload;
	}

	/**
	 * Collapses a WooCommerce status onto one the widget knows.
	 *
	 * @param string $status WooCommerce status, with or without the wc- prefix.
	 * @return string
	 */
	public static function map_status( $status ) {
		$key = strtolower( trim( (string) $status ) );

		if ( 0 === strpos( $key, 'wc-' ) ) {
			$key = substr( $key, 3 );
		}

		return isset( self::$status_map[ $key ] ) ? self::$status_map[ $key ] : 'processing';
	}

	/**
	 * The order date as an ISO 8601 string, or null when unset.
	 *
	 * @param WC_Order $order The order.
	 * @return string|null
	 */
	private function placed_at( $order ) {
		$created = $order->get_date_created();

		if ( ! $created ) {
			return null;
		}

		return $created->date( 'c' );
	}

	/**
	 * Human-readable status label, falling back to the raw status.
	 *
	 * @param string $status WooCommerce status.
	 * @return string
	 */
	private function status_label( $status ) {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$bare     = strtolower( trim( (string) $status ) );

		if ( 0 === strpos( $bare, 'wc-' ) ) {
			$bare = substr( $bare, 3 );
		}

		$key = 'wc-' . $bare;

		if ( isset( $statuses[ $key ] ) ) {
			return (string) $statuses[ $key ];
		}

		return ucfirst( str_replace( '-', ' ', (string) $status ) );
	}

	/**
	 * Order total as a plain display string.
	 *
	 * WooCommerce's wc_price() returns markup; the widget renders text, so tags
	 * are stripped and entities decoded to leave a bare "£49.99".
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	private function format_total( $order ) {
		if ( ! function_exists( 'wc_price' ) ) {
			return (string) $order->get_total();
		}

		$html = wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) );

		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Total quantity across line items.
	 *
	 * @param WC_Order $order The order.
	 * @return int
	 */
	private function item_count( $order ) {
		$count = 0;

		foreach ( $order->get_items() as $item ) {
			$count += (int) $item->get_quantity();
		}

		return $count;
	}

	/**
	 * Line items reduced to name and quantity. No prices.
	 *
	 * @param WC_Order $order The order.
	 * @return array<int,array<string,mixed>>
	 */
	private function items( $order ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name' => (string) $item->get_name(),
				'qty'  => (int) $item->get_quantity(),
			);
		}

		return $items;
	}

	/**
	 * Tracking entries, read from whichever shipment plugin is installed.
	 *
	 * WooCommerce core stores no tracking data. The two shapes handled here
	 * cover the official WooCommerce Shipment Tracking extension and AfterShip.
	 * Anything else can be supplied through the idea89_order_tracking filter,
	 * which receives the same normalised shape.
	 *
	 * @param WC_Order $order The order.
	 * @return array<int,array<string,mixed>>
	 */
	private function tracking( $order ) {
		$entries = array();

		// WooCommerce Shipment Tracking.
		$shipment_items = $order->get_meta( '_wc_shipment_tracking_items', true );

		if ( is_array( $shipment_items ) ) {
			foreach ( $shipment_items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$provider = '';

				if ( ! empty( $item['tracking_provider'] ) ) {
					$provider = (string) $item['tracking_provider'];
				} elseif ( ! empty( $item['custom_tracking_provider'] ) ) {
					$provider = (string) $item['custom_tracking_provider'];
				}

				$entries[] = $this->tracking_entry(
					$provider,
					isset( $item['tracking_number'] ) ? (string) $item['tracking_number'] : '',
					isset( $item['custom_tracking_link'] ) ? (string) $item['custom_tracking_link'] : ''
				);
			}
		}

		// AfterShip.
		$aftership_number = (string) $order->get_meta( '_aftership_tracking_number', true );

		if ( '' !== $aftership_number ) {
			$entries[] = $this->tracking_entry(
				(string) $order->get_meta( '_aftership_tracking_provider_name', true ),
				$aftership_number,
				''
			);
		}

		$entries = array_values( array_filter( $entries ) );

		/**
		 * Filters the tracking entries returned to the chat widget.
		 *
		 * @param array<int,array<string,mixed>> $entries Normalised entries.
		 * @param WC_Order                       $order   The order.
		 */
		$filtered = apply_filters( 'idea89_order_tracking', $entries, $order );

		return is_array( $filtered ) ? $filtered : $entries;
	}

	/**
	 * Normalises one tracking record, or null when it carries no number.
	 *
	 * @param string $carrier      Carrier code or name.
	 * @param string $number       Tracking number.
	 * @param string $explicit_url URL the plugin supplied, if any.
	 * @return array<string,mixed>|null
	 */
	private function tracking_entry( $carrier, $number, $explicit_url ) {
		$number = trim( $number );

		if ( '' === $number ) {
			return null;
		}

		$explicit_url = trim( $explicit_url );
		$url          = '' !== $explicit_url
			? esc_url_raw( $explicit_url )
			: $this->resolver->resolve( $carrier, $number );

		return array(
			'carrier'       => strtolower( trim( $carrier ) ),
			'carrier_title' => '' !== trim( $carrier ) ? trim( $carrier ) : __( 'Carrier', 'idea89-ai-shopping-assistant' ),
			'number'        => $number,
			'url'           => $url ? $url : null,
		);
	}
}
