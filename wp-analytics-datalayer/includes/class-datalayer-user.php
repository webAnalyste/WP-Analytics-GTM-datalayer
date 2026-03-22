<?php
defined( 'ABSPATH' ) || exit;

class WADL_User {

	/**
	 * Build user payload based on active settings.
	 * NEVER exposes raw PII.
	 */
	public static function get_payload( array $settings ): array {
		$data = [];
		$user = wp_get_current_user();

		if ( ! empty( $settings['user_logged_in'] ) ) {
			$data['user_logged_in'] = is_user_logged_in();
		}

		if ( ! empty( $settings['user_role'] ) && is_user_logged_in() ) {
			$roles = (array) ( $user->roles ?? [] );
			$data['user_role'] = ! empty( $roles ) ? sanitize_key( $roles[0] ) : '';
		}

		if ( ! empty( $settings['user_id_hashed'] ) && is_user_logged_in() ) {
			// One-way hash — never expose raw user ID
			$data['user_id_hashed'] = substr( hash( 'sha256', (string) $user->ID . NONCE_SALT ), 0, 16 );
		}

		if ( ! empty( $settings['user_customer_status'] ) && is_user_logged_in() && function_exists( 'wc_get_orders' ) ) {
			$order_count = wc_get_orders( [
				'customer' => $user->ID,
				'limit'    => 1,
				'return'   => 'ids',
			] );
			$data['customer_status'] = ! empty( $order_count ) ? 'returning' : 'new';
		}

		return $data;
	}
}
