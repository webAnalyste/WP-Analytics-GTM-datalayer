<?php
defined( 'ABSPATH' ) || exit;

/**
 * GA4 WooCommerce events — hooked only when WooCommerce is active and the
 * corresponding toggle is enabled in settings.
 */
class WADL_Events {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted || ! function_exists( 'WC' ) ) return;
		self::$booted = true;

		$settings = WADL_Core::get_settings();

		if ( ! empty( $settings['event_view_item_list'] ) ) {
			// Fired on shop/archive pages via JS — enqueue inline script
			add_action( 'wp_footer', [ __CLASS__, 'event_view_item_list' ] );
		}
		if ( ! empty( $settings['event_view_item'] ) ) {
			add_action( 'wp_footer', [ __CLASS__, 'event_view_item' ] );
		}
		if ( ! empty( $settings['event_add_to_cart'] ) ) {
			add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'event_add_to_cart' ], 10, 6 );
		}
		if ( ! empty( $settings['event_view_cart'] ) ) {
			add_action( 'wp_footer', [ __CLASS__, 'event_view_cart' ] );
		}
		if ( ! empty( $settings['event_begin_checkout'] ) ) {
			add_action( 'wp_footer', [ __CLASS__, 'event_begin_checkout' ] );
		}
		if ( ! empty( $settings['event_purchase'] ) ) {
			add_action( 'woocommerce_thankyou', [ __CLASS__, 'event_purchase' ] );
		}
	}

	// ---------- Helpers ----------

	private static function push( array $event ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script>window.dataLayer = window.dataLayer || [];window.dataLayer.push(' . wp_json_encode( $event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ');</script>' . "\n";
	}

	private static function map_product( \WC_Product $product, int $qty = 1, int $index = 0 ): array {
		return [
			'item_id'       => sanitize_text_field( $product->get_sku() ?: (string) $product->get_id() ),
			'item_name'     => sanitize_text_field( $product->get_name() ),
			'item_category' => sanitize_text_field( implode( ', ', wp_list_pluck( get_the_terms( $product->get_id(), 'product_cat' ) ?: [], 'name' ) ) ),
			'price'         => (float) $product->get_price(),
			'currency'      => sanitize_text_field( get_woocommerce_currency() ),
			'quantity'      => (int) $qty,
			'index'         => $index,
		];
	}

	// ---------- Events ----------

	public static function event_view_item_list(): void {
		if ( ! ( is_shop() || is_product_category() || is_product_tag() ) ) return;

		global $wp_query;
		$items = [];
		$i     = 0;

		foreach ( $wp_query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$items[] = self::map_product( $product, 1, $i++ );
			}
		}

		if ( empty( $items ) ) return;

		self::push( [
			'event'          => 'view_item_list',
			'item_list_name' => sanitize_text_field( single_cat_title( '', false ) ?: __( 'Shop', 'wp-analytics-datalayer' ) ),
			'items'          => $items,
		] );
	}

	public static function event_view_item(): void {
		if ( ! is_product() ) return;
		global $post;
		$product = wc_get_product( $post->ID );
		if ( ! $product ) return;

		self::push( [
			'event' => 'view_item',
			'items' => [ self::map_product( $product ) ],
		] );
	}

	public static function event_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id ): void {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product ) return;

		self::push( [
			'event'    => 'add_to_cart',
			'currency' => sanitize_text_field( get_woocommerce_currency() ),
			'value'    => (float) $product->get_price() * $quantity,
			'items'    => [ self::map_product( $product, $quantity ) ],
		] );
	}

	public static function event_view_cart(): void {
		if ( ! is_cart() ) return;

		$cart  = WC()->cart;
		$items = [];
		$i     = 0;

		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'];
			if ( $product ) {
				$items[] = self::map_product( $product, (int) $item['quantity'], $i++ );
			}
		}

		if ( empty( $items ) ) return;

		self::push( [
			'event'    => 'view_cart',
			'currency' => sanitize_text_field( get_woocommerce_currency() ),
			'value'    => (float) $cart->get_cart_contents_total(),
			'items'    => $items,
		] );
	}

	public static function event_begin_checkout(): void {
		if ( ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) return;

		$cart  = WC()->cart;
		$items = [];
		$i     = 0;

		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'];
			if ( $product ) {
				$items[] = self::map_product( $product, (int) $item['quantity'], $i++ );
			}
		}

		self::push( [
			'event'    => 'begin_checkout',
			'currency' => sanitize_text_field( get_woocommerce_currency() ),
			'value'    => (float) $cart->get_cart_contents_total(),
			'items'    => $items,
		] );
	}

	public static function event_purchase( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		$items = [];
		$i     = 0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product ) {
				$items[] = self::map_product( $product, (int) $item->get_quantity(), $i++ );
			}
		}

		self::push( [
			'event'          => 'purchase',
			'transaction_id' => sanitize_text_field( (string) $order->get_order_number() ),
			'currency'       => sanitize_text_field( $order->get_currency() ),
			'value'          => (float) $order->get_total(),
			'tax'            => (float) $order->get_total_tax(),
			'shipping'       => (float) $order->get_shipping_total(),
			'coupon'         => sanitize_text_field( implode( ', ', $order->get_coupon_codes() ) ),
			'items'          => $items,
		] );
	}
}

add_action( 'wp', [ 'WADL_Events', 'boot' ] );
