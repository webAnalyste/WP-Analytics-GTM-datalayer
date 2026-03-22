<?php
defined( 'ABSPATH' ) || exit;

class WADL_Core {

	/**
	 * Default settings — everything off by default (privacy-first).
	 */
	public static function get_defaults(): array {
		return [
			// Content — Page context
			'content_page_template'       => 0,
			'content_page_type'           => 1,
			'content_page_title'          => 1,
			'content_page_url'            => 1,
			'content_post_type'           => 0,
			'content_post_id'             => 0,
			'content_post_slug'           => 0,
			// Content — Classification
			'content_categories'          => 1,
			'content_tags'                => 0,
			'content_main_taxonomy'       => 0,
			'content_author_id_hashed'    => 0,
			'content_publish_date'        => 0,
			'content_modified_date'       => 0,
			// Content — Navigation
			'content_pagination_number'   => 0,
			'content_search_term'         => 1,
			'content_search_results_count'=> 1,
			// User
			'user_logged_in'              => 1,
			'user_role'                   => 0,
			'user_id_hashed'              => 0,
			'user_customer_status'        => 0,
			// GA4 Events — Catalogue
			'event_view_item_list'        => 0,
			'event_select_item'           => 0,
			'event_view_item'             => 0,
			// GA4 Events — Cart
			'event_add_to_cart'           => 1,
			'event_remove_from_cart'      => 0,
			'event_view_cart'             => 0,
			// GA4 Events — Funnel
			'event_begin_checkout'        => 1,
			'event_add_shipping_info'     => 0,
			'event_add_payment_info'      => 0,
			// GA4 Events — Purchase
			'event_purchase'              => 1,
			// GA4 Events — Optional
			'event_search'                => 0,
			'event_login'                 => 0,
			'event_sign_up'               => 0,
			'event_add_to_wishlist'       => 0,
			'event_view_promotion'        => 0,
			'event_select_promotion'      => 0,
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
