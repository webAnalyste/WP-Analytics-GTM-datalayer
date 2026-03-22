===  WP Analytics GTM DataLayer ===
Contributors: webAnalyste
Tags: analytics, datalayer, gtm, ga4, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.3.3
License: GPLv2 or later

Lightweight WordPress plugin to push a clean, configurable analytics dataLayer for GTM / GA4.

== Description ==

WP Analytics GTM DataLayer is a **governance plugin** for your analytics dataLayer.
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

== About ==

This plugin is developed and maintained by **[webAnalyste](https://www.webanalyste.com)**, a French agency specialised in data, AI and no-code automation.

We build tailor-made analytics stacks to improve the digital performance and SEO of our clients — from tracking architecture to GA4 implementation, GTM governance and data-driven growth strategies.

**Need training?** Check out **[formations-analytics.com](https://www.formations-analytics.com)**, our training centre offering hands-on courses on analytics, GA4, GTM, data visualisation and AI-powered marketing.

== Installation ==

1. Upload the `wp-analytics-datalayer` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** screen in WordPress
3. Navigate to **DataLayer** in the admin sidebar
4. Configure the fields and events you want to push

== Changelog ==

= 1.3.3 =
* Version de démonstration du système de mise à jour automatique

= 1.3.2 =
* Simplification : suppression du système de migration, remplacé par un hook d'activation
* À l'activation du plugin, les champs suivants sont ON par défaut dans le dataLayer :
  page_template, page_type, page_title, page_url, categories, user_logged_in,
  view_item, add_to_cart, begin_checkout, purchase

= 1.3.1 =
* Fix: migration élargie — active par défaut page_type, page_template, categories, view_item, add_to_cart, begin_checkout, purchase sur toutes les installs existantes (couvre aussi le bug de reset v1.0/1.1)

= 1.3.0 =
* Fix: langue — load_plugin_textdomain() désormais appelé correctement
* Fix: auto-update — cache d'erreur réduit à 30 min (était 12h, bloquait les re-checks)
* Fix: auto-update — cache vidé quand WordPress force une vérification
* Nouveau: bouton "Vérifier maintenant" dans le dashboard pour forcer la détection de mise à jour
* Nouveau: dossier languages/ + fichier .pot pour traductions futures
* Défauts activés: page_template et view_item maintenant ON par défaut
* Migration automatique: active page_template et view_item sur les installs existantes

= 1.2.0 =
* Fix: réglages réinitialisés lors de la sauvegarde d'une autre page (bug critique)
* Fix: save_settings() ne met à jour que les clés du formulaire soumis

= 1.1.0 =
* Complete all GA4 events: remove_from_cart, search, login, sign_up, add_to_wishlist (YITH)
* Client-side events via frontend.js: select_item, add_shipping_info, add_payment_info, view_promotion, select_promotion
* Purchase deduplication (prevents double-push on page reload)
* Product brand + variant support in item mapping
* Admin: Export / Import / Reset settings
* Improved admin notices (reset, import errors)

= 1.0.0 =
* Initial release
