===  WP Analytics DataLayer ===
Contributors: webAnalyste
Tags: analytics, datalayer, gtm, ga4, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later

Lightweight WordPress plugin to push a clean, configurable analytics dataLayer for GTM / GA4.

== Description ==

WP Analytics DataLayer is a **governance plugin** for your analytics dataLayer.
It does **not** replace GTM. It feeds it with the right data.

**What it does:**
* Injects a clean `dataLayer.push()` on every page load
* Exposes configurable page context metadata (page_type, page_template, categories…)
* Exposes minimal user metadata (logged_in, role, hashed ID)
* Pushes GA4 e-commerce events via WooCommerce hooks

**What it does NOT do:**
* Expose all WordPress metadata
* Replace GTM or Google Analytics
* Store or transmit personal data

== Installation ==

1. Upload the `wp-analytics-datalayer` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** screen in WordPress
3. Navigate to **DataLayer** in the admin sidebar
4. Configure the fields and events you want to push

== Changelog ==

= 1.1.0 =
* Complete all GA4 events: remove_from_cart, search, login, sign_up, add_to_wishlist (YITH)
* Client-side events via frontend.js: select_item, add_shipping_info, add_payment_info, view_promotion, select_promotion
* Purchase deduplication (prevents double-push on page reload)
* Product brand + variant support in item mapping
* Admin: Export / Import / Reset settings
* Improved admin notices (reset, import errors)

= 1.0.0 =
* Initial release
