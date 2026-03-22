<?php
defined( 'ABSPATH' ) || exit;

/**
 * GA4 WooCommerce events — hooked only when WooCommerce is active and the
 * corresponding toggle is enabled in settings.
 *
 * Server-side events: view_item_list, view_item, add_to_cart, remove_from_cart,
 *                     view_cart, begin_checkout, purchase, search, login, sign_up,
 *                     add_to_wishlist.
 * Client-side events (frontend.js): select_item, add_shipping_info,
 *                     add_payment_info, view_promotion, select_promotion.
 */
class WADL_Events {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) return;
		self::$booted = true;

		$settings = WADL_Core::get_settings();

		// --- WooCommerce-dependent events ---
		if ( function_exists( 'WC' ) ) {
			self::boot_woocommerce( $settings );
		}

		// --- WordPress-native events (no WooCommerce required) ---
		if ( ! empty( $settings['event_login'] ) ) {
			add_action( 'wp_login', [ __CLASS__, 'on_login' ], 10, 2 );
		}
		if ( ! empty( $settings['event_sign_up'] ) ) {
			add_action( 'user_register', [ __CLASS__, 'on_sign_up' ] );
		}
		if ( ! empty( $settings['event_search'] ) ) {
			add_action( 'wp_footer', [ __CLASS__, 'event_search' ] );
		}
	}

	private static function boot_woocommerce( array $settings ): void {
		if ( ! empty( $settings['event_view_item_list'] ) ) {
			add_action( 'wp_footer', [ __CLASS__, 'event_view_item_list' ] );
		}
		if ( ! empty( $settings['event_select_item'] ) ) {
			// Output product index JSON consumed by frontend.js
			add_action( 'wp_footer', [ __CLASS__, 'output_product_index' ], 5 );
		}
		if ( ! empty( $settings['event_view_item'] ) ) {
			add_action( 'wp_footer', [ __CLASS__, 'event_view_item' ] );
		}
		if ( ! empty( $settings['event_add_to_cart'] ) ) {
			add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'event_add_to_cart' ], 10, 6 );
		}
		if ( ! empty( $settings['event_remove_from_cart'] ) ) {
			add_action( 'woocommerce_cart_item_removed', [ __CLASS__, 'event_remove_from_cart' ], 10, 2 );
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
		if ( ! empty( $settings['event_add_to_wishlist'] ) ) {
			// YITH WooCommerce Wishlist
			add_action( 'yith_wcwl_added_to_wishlist', [ __CLASS__, 'event_add_to_wishlist' ], 10, 3 );
			// WooCommerce Wishlist & Anony plugin compatibility
			add_action( 'woocommerce_wishlist_add_item', [ __CLASS__, 'event_add_to_wishlist_generic' ], 10, 1 );
		}
	}

	// ---------- Helpers ----------

	private static function push( array $event ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script>window.dataLayer = window.dataLayer || [];window.dataLayer.push(' . wp_json_encode( $event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ');</script>' . "\n";
	}

	public static function map_product( \WC_Product $product, int $qty = 1, int $index = 0 ): array {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		$cats  = is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : [];

		$item = [
			'item_id'       => sanitize_text_field( $product->get_sku() ?: (string) $product->get_id() ),
			'item_name'     => sanitize_text_field( $product->get_name() ),
			'item_category' => sanitize_text_field( implode( ', ', $cats ) ),
			'price'         => (float) $product->get_price(),
			'currency'      => sanitize_text_field( get_woocommerce_currency() ),
			'quantity'      => (int) $qty,
			'index'         => $index,
		];

		// Brand support (WooCommerce Brands / custom taxonomy)
		$brand_terms = get_the_terms( $product->get_id(), 'product_brand' )
			?: get_the_terms( $product->get_id(), 'pa_brand' );
		if ( is_array( $brand_terms ) && ! empty( $brand_terms ) ) {
			$item['item_brand'] = sanitize_text_field( $brand_terms[0]->name );
		}

		// Variant name for variable products
		if ( $product->is_type( 'variation' ) ) {
			$item['item_variant'] = sanitize_text_field( implode( ' / ', $product->get_variation_attributes() ) );
		}

		return $item;
	}

	// ---------- Product index for select_item (JS) ----------

	public static function output_product_index(): void {
		if ( ! ( is_shop() || is_product_category() || is_product_tag() || is_search() ) ) return;

		global $wp_query;
		$index   = [];
		$i       = 0;

		foreach ( (array) $wp_query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$index[ (string) $product->get_id() ] = self::map_product( $product, 1, $i++ );
			}
		}

		if ( empty( $index ) ) return;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script>window.wadlProducts = ' . wp_json_encode( $index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';</script>' . "\n";
	}

	// ---------- WooCommerce events ----------

	public static function event_view_item_list(): void {
		if ( ! ( is_shop() || is_product_category() || is_product_tag() ) ) return;

		global $wp_query;
		$items = [];
		$i     = 0;

		foreach ( (array) $wp_query->posts as $post ) {
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
			'value'    => round( (float) $product->get_price() * $quantity, 2 ),
			'items'    => [ self::map_product( $product, $quantity ) ],
		] );
	}

	public static function event_remove_from_cart( string $cart_item_key, \WC_Cart $cart ): void {
		// Cart item has already been removed — retrieve it from the removed_cart_contents.
		$removed = $cart->get_removed_cart_contents();
		$item    = $removed[ $cart_item_key ] ?? null;
		if ( ! $item ) return;

		$product = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
		if ( ! $product ) return;

		self::push( [
			'event'    => 'remove_from_cart',
			'currency' => sanitize_text_field( get_woocommerce_currency() ),
			'value'    => round( (float) $product->get_price() * (int) $item['quantity'], 2 ),
			'items'    => [ self::map_product( $product, (int) $item['quantity'] ) ],
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
			'value'    => round( (float) $cart->get_cart_contents_total(), 2 ),
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
			'value'    => round( (float) $cart->get_cart_contents_total(), 2 ),
			'items'    => $items,
		] );
	}

	public static function event_purchase( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		// Deduplication: skip if already pushed for this order.
		if ( $order->get_meta( '_wadl_purchase_pushed' ) ) return;

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
			'value'          => round( (float) $order->get_total(), 2 ),
			'tax'            => round( (float) $order->get_total_tax(), 2 ),
			'shipping'       => round( (float) $order->get_shipping_total(), 2 ),
			'coupon'         => sanitize_text_field( implode( ', ', $order->get_coupon_codes() ) ),
			'items'          => $items,
		] );

		// Mark as pushed to prevent duplicate on page reload.
		$order->update_meta_data( '_wadl_purchase_pushed', '1' );
		$order->save_meta_data();
	}

	// ---------- WordPress-native events ----------

	public static function event_search(): void {
		if ( ! is_search() ) return;

		global $wp_query;
		self::push( [
			'event'       => 'search',
			'search_term' => sanitize_text_field( get_search_query() ),
			'results'     => (int) ( $wp_query->found_posts ?? 0 ),
		] );
	}

	/**
	 * Store pending login event in user meta — flushed on next front-end page load.
	 */
	public static function on_login( string $user_login, \WP_User $user ): void {
		update_user_meta( $user->ID, '_wadl_pending_event', 'login' );
	}

	/**
	 * Store pending sign_up event in user meta — flushed on next front-end page load.
	 */
	public static function on_sign_up( int $user_id ): void {
		update_user_meta( $user_id, '_wadl_pending_event', 'sign_up' );
	}

	// ---------- Wishlist events ----------

	/**
	 * YITH WooCommerce Wishlist.
	 *
	 * @param int $product_id
	 * @param int $wishlist_id
	 * @param int $user_id
	 */
	public static function event_add_to_wishlist( int $product_id, int $wishlist_id, int $user_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) return;

		self::push( [
			'event'    => 'add_to_wishlist',
			'currency' => sanitize_text_field( get_woocommerce_currency() ),
			'value'    => (float) $product->get_price(),
			'items'    => [ self::map_product( $product ) ],
		] );
	}

	/**
	 * Generic wishlist hook fallback (single product_id param).
	 */
	public static function event_add_to_wishlist_generic( int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) return;

		self::push( [
			'event'    => 'add_to_wishlist',
			'currency' => sanitize_text_field( get_woocommerce_currency() ),
			'value'    => (float) $product->get_price(),
			'items'    => [ self::map_product( $product ) ],
		] );
	}
}

add_action( 'wp', [ 'WADL_Events', 'boot' ] );
