<?php
defined( 'ABSPATH' ) || exit;

class WADL_Content {

	/**
	 * Resolve page_template using WP conditional tags.
	 */
	private static function get_page_template(): string {
		if ( is_front_page() ) return 'front-page';
		if ( is_home() )       return 'home';
		if ( function_exists( 'is_product' ) && is_product() ) return 'product';
		if ( function_exists( 'is_cart' ) && is_cart() )       return 'cart';
		if ( function_exists( 'is_checkout' ) && is_checkout() ) return 'checkout';
		if ( function_exists( 'is_account_page' ) && is_account_page() ) return 'account';
		if ( is_single() )     return 'single';
		if ( is_page() )       return 'page';
		if ( is_archive() )    return 'archive';
		if ( is_tax() )        return 'taxonomy';
		if ( is_search() )     return 'search';
		if ( is_404() )        return '404';
		return 'other';
	}

	/**
	 * Resolve page_type (business/analytics semantic).
	 */
	private static function get_page_type(): string {
		if ( is_front_page() ) return 'frontpage';
		if ( function_exists( 'is_product' ) && is_product() ) return 'product';
		if ( function_exists( 'is_cart' ) && is_cart() )       return 'cart';
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			// Distinguish order confirmation from checkout
			if ( is_wc_endpoint_url( 'order-received' ) ) return 'order_confirmation';
			return 'checkout';
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) return 'account';
		if ( is_singular( 'post' ) ) return 'article';
		if ( is_page() )             return 'page';
		if ( is_category() )         return 'category_listing';
		if ( is_archive() || is_tax() ) return 'listing';
		if ( is_search() )           return 'search_results';
		if ( is_404() )              return '404';
		return 'other';
	}

	/**
	 * Build content payload based on active settings.
	 */
	public static function get_payload( array $settings ): array {
		$data = [];

		// --- Page context ---
		if ( ! empty( $settings['content_page_template'] ) ) {
			$data['page_template'] = self::get_page_template();
		}
		if ( ! empty( $settings['content_page_type'] ) ) {
			$data['page_type'] = self::get_page_type();
		}
		if ( ! empty( $settings['content_page_title'] ) ) {
			$data['page_title'] = html_entity_decode( get_the_title(), ENT_QUOTES, 'UTF-8' );
		}
		if ( ! empty( $settings['content_page_url'] ) ) {
			$data['page_url'] = esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );
		}
		if ( ! empty( $settings['content_post_type'] ) ) {
			$data['post_type'] = sanitize_key( get_post_type() ?: '' );
		}
		if ( ! empty( $settings['content_post_id'] ) && is_singular() ) {
			$data['post_id'] = (int) get_the_ID();
		}
		if ( ! empty( $settings['content_post_slug'] ) && is_singular() ) {
			global $post;
			$data['post_slug'] = isset( $post ) ? sanitize_title( $post->post_name ) : '';
		}

		// --- Content classification ---
		if ( ! empty( $settings['content_categories'] ) && ( is_singular( 'post' ) || is_category() ) ) {
			$cats = get_the_category();
			if ( $cats ) {
				$data['categories'] = array_values( array_map( fn( $c ) => sanitize_text_field( $c->name ), $cats ) );
			}
		}
		if ( ! empty( $settings['content_tags'] ) && is_singular( 'post' ) ) {
			$tags = get_the_tags();
			if ( $tags ) {
				$data['tags'] = array_values( array_map( fn( $t ) => sanitize_text_field( $t->name ), $tags ) );
			}
		}
		if ( ! empty( $settings['content_main_taxonomy'] ) ) {
			$post_type = get_post_type();
			if ( $post_type ) {
				$taxonomies = get_object_taxonomies( $post_type );
				if ( ! empty( $taxonomies ) ) {
					$data['main_taxonomy'] = sanitize_key( $taxonomies[0] );
				}
			}
		}
		if ( ! empty( $settings['content_author_id_hashed'] ) && is_singular() ) {
			$author_id = (int) get_the_author_meta( 'ID' );
			if ( $author_id ) {
				$data['author_id_hashed'] = substr( hash( 'sha256', (string) $author_id . NONCE_SALT ), 0, 16 );
			}
		}
		if ( ! empty( $settings['content_publish_date'] ) && is_singular() ) {
			$data['publish_date'] = get_the_date( 'Y-m-d' ) ?: '';
		}
		if ( ! empty( $settings['content_modified_date'] ) && is_singular() ) {
			$data['modified_date'] = get_the_modified_date( 'Y-m-d' ) ?: '';
		}

		// --- Navigation context ---
		if ( ! empty( $settings['content_pagination_number'] ) ) {
			$paged = (int) ( get_query_var( 'paged' ) ?: get_query_var( 'page' ) ?: 1 );
			$data['pagination_number'] = $paged;
		}
		if ( ! empty( $settings['content_search_term'] ) && is_search() ) {
			$data['search_term'] = sanitize_text_field( get_search_query() );
		}
		if ( ! empty( $settings['content_search_results_count'] ) && is_search() ) {
			global $wp_query;
			$data['search_results_count'] = (int) ( $wp_query->found_posts ?? 0 );
		}

		return $data;
	}
}
