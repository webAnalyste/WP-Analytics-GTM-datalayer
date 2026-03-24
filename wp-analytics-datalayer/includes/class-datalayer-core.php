<?php
defined( 'ABSPATH' ) || exit;

class WADL_Core {

	/**
	 * Default settings.
	 * Enabled by default: page_type, page_template, page_title, page_url,
	 * categories, user_logged_in, view_item, add_to_cart, begin_checkout, purchase.
	 */
	public static function get_defaults(): array {
		return [
			// Content — Page context
			'content_page_template'        => 1, // enabled by default
			'content_page_type'            => 1,
			'content_page_title'           => 1,
			'content_page_url'             => 1,
			'content_post_type'            => 0,
			'content_post_id'              => 0,
			'content_post_slug'            => 0,
			// Content — Classification
			'content_categories'           => 1,
			'content_tags'                 => 0,
			'content_main_taxonomy'        => 0,
			'content_author_id_hashed'     => 0,
			'content_publish_date'         => 0,
			'content_modified_date'        => 0,
			// Content — Navigation
			'content_pagination_number'    => 0,
			'content_search_term'          => 1,
			'content_search_results_count' => 1,
			// User
			'user_logged_in'               => 1,
			'user_role'                    => 0,
			'user_id_hashed'               => 0,
			'user_customer_status'         => 0,
			// GA4 Events — Catalogue
			'event_view_item_list'         => 0,
			'event_select_item'            => 0,
			'event_view_item'              => 1, // enabled by default
			// GA4 Events — Cart
			'event_add_to_cart'            => 1,
			'event_remove_from_cart'       => 0,
			'event_view_cart'              => 0,
			'event_update_cart'            => 0,
			// GA4 Events — Funnel
			'event_begin_checkout'         => 1,
			'event_add_shipping_info'      => 0,
			'event_add_payment_info'       => 0,
			// GA4 Events — Purchase
			'event_purchase'               => 1,
			// GA4 Events — Optional
			'event_search'                 => 0,
			'event_login'                  => 0,
			'event_sign_up'                => 0,
			'event_add_to_wishlist'        => 0,
			'event_view_promotion'         => 0,
			'event_select_promotion'       => 0,
		];
	}

	/**
	 * Retrieve merged settings (defaults + saved).
	 */
	public static function get_settings(): array {
		$saved    = get_option( WADL_OPTION_KEY, [] );
		$defaults = self::get_defaults();
		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Champs activés obligatoirement par défaut.
	 */
	private const DEFAULTS_ON = [
		'content_page_template',
		'content_page_type',
		'content_categories',
		'event_view_item',
		'event_add_to_cart',
		'event_begin_checkout',
		'event_purchase',
	];

	/**
	 * Appelé sur plugins_loaded à chaque montée de version.
	 * Fonctionne pour les installations fraîches ET les mises à jour.
	 */
	public static function on_activate(): void {
		if ( get_option( 'wadl_db_version' ) === WADL_VERSION ) {
			return;
		}

		$saved = get_option( WADL_OPTION_KEY, false );

		if ( $saved === false ) {
			// Première installation — écrire tous les defaults.
			update_option( WADL_OPTION_KEY, self::get_defaults() );
		} else {
			// Mise à jour — forcer les champs requis à 1.
			foreach ( self::DEFAULTS_ON as $key ) {
				$saved[ $key ] = 1;
			}
			// 1.3.22: supprimer event_cart_all_items (renommé en event_update_cart, désactivé).
			unset( $saved['event_cart_all_items'] );
			if ( ! isset( $saved['event_update_cart'] ) ) {
				$saved['event_update_cart'] = 0;
			}
			update_option( WADL_OPTION_KEY, $saved );
		}

		update_option( 'wadl_db_version', WADL_VERSION );
	}

	/**
	 * Build and output the dataLayer initialization script.
	 */
	public static function inject_datalayer(): void {
		$settings = self::get_settings();
		$payload  = [ 'event' => 'page_view' ];

		$content = WADL_Content::get_payload( $settings );
		if ( ! empty( $content ) ) {
			$payload = array_merge( $payload, $content );
		}

		$user = WADL_User::get_payload( $settings );
		if ( ! empty( $user ) ) {
			$payload = array_merge( $payload, $user );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script>window.dataLayer = window.dataLayer || [];window.dataLayer.push(' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ');</script>' . "\n";

		self::flush_pending_user_events();
	}

	/**
	 * Enqueue front-end JS for client-side events.
	 */
	public static function enqueue_frontend_scripts(): void {
		$settings = self::get_settings();

		$needs_cart_js = (bool) $settings['event_add_to_cart']
			|| (bool) $settings['event_remove_from_cart']
			|| (bool) $settings['event_update_cart'];

		$needs_js = $needs_cart_js
			|| (bool) $settings['event_select_item']
			|| (bool) $settings['event_add_shipping_info']
			|| (bool) $settings['event_add_payment_info']
			|| (bool) $settings['event_view_promotion']
			|| (bool) $settings['event_select_promotion'];

		if ( ! $needs_js ) return;

		// jQuery required only for cart events (added_to_cart / removed_from_cart jQuery events).
		wp_enqueue_script(
			'wadl-frontend',
			WADL_PLUGIN_URL . 'assets/frontend.js',
			$needs_cart_js ? [ 'jquery' ] : [],
			WADL_VERSION,
			true
		);

		wp_localize_script( 'wadl-frontend', 'wadlConfig', [
			'currency' => function_exists( 'get_woocommerce_currency' ) ? sanitize_text_field( get_woocommerce_currency() ) : 'EUR',
			'events'   => [
				'add_to_cart'       => (bool) $settings['event_add_to_cart'],
				'remove_from_cart'  => (bool) $settings['event_remove_from_cart'],
				'update_cart'       => (bool) $settings['event_update_cart'],
				'select_item'       => (bool) $settings['event_select_item'],
				'add_shipping_info' => (bool) $settings['event_add_shipping_info'],
				'add_payment_info'  => (bool) $settings['event_add_payment_info'],
				'view_promotion'    => (bool) $settings['event_view_promotion'],
				'select_promotion'  => (bool) $settings['event_select_promotion'],
			],
		] );
	}

	/**
	 * Push pending user-level events (login / sign_up) stored as user meta.
	 */
	private static function flush_pending_user_events(): void {
		if ( ! is_user_logged_in() ) return;
		$user_id = get_current_user_id();
		$pending = get_user_meta( $user_id, '_wadl_pending_event', true );
		if ( ! $pending ) return;

		$allowed = [ 'login', 'sign_up' ];
		$event   = sanitize_key( $pending );
		if ( ! in_array( $event, $allowed, true ) ) {
			delete_user_meta( $user_id, '_wadl_pending_event' );
			return;
		}

		$settings = self::get_settings();
		if ( ! empty( $settings[ 'event_' . $event ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<script>window.dataLayer = window.dataLayer || [];window.dataLayer.push(' . wp_json_encode( [ 'event' => $event ], JSON_UNESCAPED_UNICODE ) . ');</script>' . "\n";
		}

		delete_user_meta( $user_id, '_wadl_pending_event' );
	}

	/**
	 * Return the dataLayer payload as array (for debug screen).
	 */
	public static function get_preview_payload(): array {
		$settings = self::get_settings();
		$payload  = [ 'event' => 'page_view' ];

		$content = WADL_Content::get_payload( $settings );
		if ( ! empty( $content ) ) {
			$payload = array_merge( $payload, $content );
		}

		$user = WADL_User::get_payload( $settings );
		if ( ! empty( $user ) ) {
			$payload = array_merge( $payload, $user );
		}

		return $payload;
	}
}

add_action( 'wp_enqueue_scripts', [ 'WADL_Core', 'enqueue_frontend_scripts' ] );
