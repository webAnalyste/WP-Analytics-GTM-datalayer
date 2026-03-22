<?php
/**
 * Plugin Name: WP Analytics DataLayer
 * Plugin URI:  https://github.com/webAnalyste/WP-Analytics-GTM-datalayer
 * Description: Lightweight plugin to configure a clean analytics dataLayer for GTM/GA4. Controls page context, user metadata and GA4 ecommerce events.
 * Version:     1.1.0
 * Author:      webAnalyste
 * License:     GPL-2.0-or-later
 * Text Domain: wp-analytics-datalayer
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WADL_VERSION', '1.1.0' );
define( 'WADL_PLUGIN_FILE', __FILE__ );
define( 'WADL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WADL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WADL_OPTION_KEY', 'wadl_settings' );

require_once WADL_PLUGIN_DIR . 'includes/class-datalayer-core.php';
require_once WADL_PLUGIN_DIR . 'includes/class-datalayer-content.php';
require_once WADL_PLUGIN_DIR . 'includes/class-datalayer-user.php';
require_once WADL_PLUGIN_DIR . 'includes/class-datalayer-events.php';

if ( is_admin() ) {
	require_once WADL_PLUGIN_DIR . 'admin/class-datalayer-admin.php';
	new WADL_Admin();
}

add_action( 'wp_head', [ 'WADL_Core', 'inject_datalayer' ], 1 );
