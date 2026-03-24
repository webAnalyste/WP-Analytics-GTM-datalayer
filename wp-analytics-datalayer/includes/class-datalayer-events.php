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

	/**
	 * Register cart mutation hooks early — hooked on woocommerce_init (fires during
	 * init priority 0, before WC_Form_Handler::add_to_cart_action() on init priority 10).
	 *
	 * woocommerce_add_to_cart fires during init (form POST) or during WC AJAX before wp.
	 * boot() runs on wp — too late for either case.
	 * These hooks must therefore be registered here, independently of boot().
	 */
	public static function boot_cart_hooks(): void {
		$settings = WADL_Core::get_settings();

		if ( ! empty( $settings['event_add_to_cart'] ) ) {
			add_action( 'woocommerce_add_to_cart', [ __CLASS__, 'event_add_to_cart' ], 10, 6 );
		}
		if ( ! empty( $settings['event_remove_from_cart'] ) ) {
			add_action( 'woocommerce_cart_item_removed', [ __CLASS__, 'event_remove_from_cart' ], 10, 2 );
		}
		if ( ! empty( $settings['event_add_to_cart'] ) || ! empty( $settings['event_remove_from_cart'] ) ) {
			add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'inject_cart_events_fragment' ] );
		}
	}

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
		if ( ! empty( $settings['event_select_item'] ) || ! empty( $settings['event_add_to_cart'] ) ) {
			// Output product index JSON consumed by frontend.js
			add_action( 'wp_footer', [ __CLASS__, 'output_product_index' ], 5 );
		}
		if ( ! empty( $settings['event_view_item'] ) ) {
			add_action( 'wp_footer', [ __CLASS__, 'event_view_item' ] );
		}
			// add_to_cart / remove_from_cart hooks are registered in boot_cart_hooks()
		// on woocommerce_init — before WC_Form_Handler processes the form POST on init.
		// Only the wp_footer flush fallback (for redirect-based flows) lives here.
		if ( ! empty( $settings['event_add_to_cart'] ) || ! empty( $settings['event_remove_from_cart'] ) ) {
			add_action( 'wp_footer', [ __CLASS__, 'flush_cart_events' ], 5 );
		}
		if ( ! empty( $settings['event_view_cart'] ) ) {
			add_action( 'wp_footer', [ __CLASS__, 'event_view_cart' ] );
		}
		if ( ! empty( $settings['event_cart_all_items'] ) ) {
			add_action( 'wp_head', [ __CLASS__, 'push_cart_state' ], 2 );
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

	/**
	 * Push a GA4 ecommerce event.
	 * Clears the previous ecommerce object first (Google recommendation),
	 * then wraps payload inside an `ecommerce` key.
	 * Optional $extra keys are merged at the top level (e.g. cart state).
	 */
	private static function push_ecommerce( string $event_name, array $ecommerce, array $extra = [] ): void {
		$data = array_merge( [ 'event' => $event_name, 'ecommerce' => $ecommerce ], $extra );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script>window.dataLayer = window.dataLayer || [];window.dataLayer.push({ecommerce:null});window.dataLayer.push(' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ');</script>' . "\n";
	}

	/**
	 * Build the full cart node: { value, quantity, items }.
	 *
	 * Value is computed directly from cart items (product price × qty) rather than
	 * get_cart_contents_total(), which may be stale during woocommerce_add_to_cart
	 * because calculate_totals() has not yet run.
	 */
	private static function get_cart_payload(): array {
		$wc_cart = WC()->cart;
		if ( ! $wc_cart ) {
			return [ 'value' => 0.0, 'quantity' => 0, 'items' => [] ];
		}
		$items = [];
		$i     = 0;
		$value = 0.0;
		foreach ( $wc_cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			if ( $product ) {
				$qty     = (int) $cart_item['quantity'];
				$items[] = self::map_product( $product, $qty, $i++ );
				$value  += (float) $product->get_price() * $qty;
			}
		}
		return [
			'value'    => round( $value, 2 ),
			'quantity' => (int) $wc_cart->get_cart_contents_count(),
			'items'    => $items,
		];
	}

	/**
	 * Store a cart event in WC session for deferred output on next page load.
	 */
	private static function queue_cart_event( string $event_name, array $ecommerce, array $extra = [] ): void {
		if ( ! WC()->session ) return;
		$pending   = (array) WC()->session->get( 'wadl_pending_events', [] );
		$pending[] = [ 'event_name' => $event_name, 'ecommerce' => $ecommerce, 'extra' => $extra ];
		WC()->session->set( 'wadl_pending_events', $pending );
	}

	/**
	 * Inject pending cart events into WooCommerce cart fragments (AJAX response).
	 * Called by woocommerce_add_to_cart_fragments — fires for both add and remove
	 * AJAX requests (add_to_cart action and get_refreshed_fragments action).
	 * Clears the session queue so flush_cart_events() won't duplicate them.
	 *
	 * @param array $fragments
	 * @return array
	 */
	public static function inject_cart_events_fragment( array $fragments ): array {
		if ( ! WC()->session ) return $fragments;

		// Only inject pending events for actual cart-mutation AJAX actions (add_to_cart,
		// remove_from_cart). Skipping get_refreshed_fragments — that call fires on every
		// page load to refresh the cart widget; consuming the session queue here would
		// leave flush_cart_events() with nothing to flush, stranding the sessionStorage
		// flag and silently blocking the next AJAX add_to_cart push.
		$wc_ajax = isset( $_GET['wc-ajax'] ) ? sanitize_text_field( wp_unslash( $_GET['wc-ajax'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $wc_ajax, [ 'add_to_cart', 'remove_from_cart' ], true ) ) {
			return $fragments;
		}

		$pending = (array) WC()->session->get( 'wadl_pending_events', [] );
		if ( ! empty( $pending ) ) {
			$fragments['wadl_events'] = $pending;
			WC()->session->set( 'wadl_pending_events', [] );
		}
		return $fragments;
	}

	/**
	 * Output and clear all pending cart events stored in WC session.
	 * Fallback for non-AJAX flows (redirect add-to-cart, WooCommerce Blocks).
	 * Hooked on wp_footer (priority 5) when add/remove_from_cart is enabled.
	 */
	public static function flush_cart_events(): void {
		if ( ! WC()->session ) return;
		$pending = (array) WC()->session->get( 'wadl_pending_events', [] );
		if ( empty( $pending ) ) return;
		WC()->session->set( 'wadl_pending_events', [] );
		foreach ( $pending as $ev ) {
			if ( ! isset( $ev['event_name'], $ev['ecommerce'] ) ) continue;
			self::push_ecommerce( $ev['event_name'], $ev['ecommerce'], $ev['extra'] ?? [] );
		}
	}

	public static function map_product( \WC_Product $product, int $qty = 1, int $index = 0 ): array {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		$cats  = is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : [];

		$item = [
			'item_id'       => sanitize_text_field( $product->get_sku() ?: (string) $product->get_id() ),
			'item_name'     => sanitize_text_field( $product->get_name() ),
			'item_category' => sanitize_text_field( implode( ', ', $cats ) ),
			'price'         => (float) $product->get_price(),
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
		$index = [];
		$i     = 0;

		if ( is_product() ) {
			// Single product page — expose current product for the add_to_cart form interceptor.
			global $post;
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$index[ (string) $product->get_id() ] = self::map_product( $product, 1, 0 );
			}
		} elseif ( is_shop() || is_product_category() || is_product_tag() || is_search() ) {
			global $wp_query;
			foreach ( (array) $wp_query->posts as $post ) {
				$product = wc_get_product( $post->ID );
				if ( $product ) {
					$index[ (string) $product->get_id() ] = self::map_product( $product, 1, $i++ );
				}
			}
		}

		if ( empty( $index ) ) return;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script>window.wadlProducts = ' . wp_json_encode( $index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';</script>' . "\n";
	}

	// ---------- WooCommerce events ----------

	/**
	 * Push the full cart state on initial page load (wp_head priority 2).
	 * Outputs: window.dataLayer.push({ cart: { items: [...] } })
	 */
	public static function push_cart_state(): void {
		if ( ! WC()->cart ) return;
		self::push( [ 'cart' => self::get_cart_payload() ] );
	}

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

		self::push_ecommerce( 'view_item_list', [
			'item_list_name' => sanitize_text_field( single_cat_title( '', false ) ?: __( 'Shop', 'wp-analytics-datalayer' ) ),
			'items'          => $items,
		] );
	}

	public static function event_view_item(): void {
		if ( ! is_product() ) return;
		global $post;
		$product = wc_get_product( $post->ID );
		if ( ! $product ) return;

		self::push_ecommerce( 'view_item', [
			'currency' => sanitize_text_field( get_woocommerce_currency() ),
			'value'    => (float) $product->get_price(),
			'items'    => [ self::map_product( $product ) ],
		] );
	}

	public static function event_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id ): void {
		// Non-AJAX (form POST) add-to-cart: the JS form-submit interceptor on the product
		// page already pushes the event at click time. Queueing here would cause double
		// tracking whenever the redirect opens in a different tab (no sessionStorage flag).
		if ( ! wc_is_ajax() ) return;

		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product ) return;

		$settings = WADL_Core::get_settings();
		$extra    = ! empty( $settings['event_cart_all_items'] )
			? [ 'cart' => self::get_cart_payload() ]
			: [];

		self::queue_cart_event( 'add_to_cart', [
			'currency' => sanitize_text_field( get_woocommerce_currency() ),
			'value'    => round( (float) $product->get_price() * $quantity, 2 ),
			'items'    => [ self::map_product( $product, $quantity ) ],
		], $extra );
	}

	public static function event_remove_from_cart( string $cart_item_key, \WC_Cart $cart ): void {
		// Same rationale as event_add_to_cart: only queue for AJAX flows.
		if ( ! wc_is_ajax() ) return;

		// Cart item has already been removed — retrieve it from the removed_cart_contents.
		$removed = $cart->get_removed_cart_contents();
		$item    = $removed[ $cart_item_key ] ?? null;
		if ( ! $item ) return;

		$product = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
		if ( ! $product ) return;

		$settings = WADL_Core::get_settings();
		$extra    = ! empty( $settings['event_cart_all_items'] )
			? [ 'cart' => self::get_cart_payload() ]
			: [];

		self::queue_cart_event( 'remove_from_cart', [
			'currency' => sanitize_text_field( get_woocommerce_currency() ),
			'value'    => round( (float) $product->get_price() * (int) $item['quantity'], 2 ),
			'items'    => [ self::map_product( $product, (int) $item['quantity'] ) ],
		], $extra );
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

		self::push_ecommerce( 'view_cart', [
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

		self::push_ecommerce( 'begin_checkout', [
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

		self::push_ecommerce( 'purchase', [
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

		self::push_ecommerce( 'add_to_wishlist', [
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

		self::push_ecommerce( 'add_to_wishlist', [
			'currency' => sanitize_text_field( get_woocommerce_currency() ),
			'value'    => (float) $product->get_price(),
			'items'    => [ self::map_product( $product ) ],
		] );
	}
}

// Cart mutation hooks must be registered before woocommerce_add_to_cart fires (init).
add_action( 'woocommerce_init', [ 'WADL_Events', 'boot_cart_hooks' ] );
// All other hooks (rendering, output) can wait for wp.
add_action( 'wp', [ 'WADL_Events', 'boot' ] );
