# WP Analytics GTM DataLayer

> Lightweight WordPress plugin to push a clean, configurable analytics dataLayer for GTM / GA4.

[![Version](https://img.shields.io/badge/version-1.3.7-blue)](https://github.com/webAnalyste/WP-Analytics-GTM-datalayer/releases/latest)
[![License](https://img.shields.io/badge/license-GPLv2-green)](LICENSE)
[![Requires PHP](https://img.shields.io/badge/PHP-8.0%2B-purple)](https://php.net)
[![Tested on WP](https://img.shields.io/badge/WordPress-6.0--6.7-blue)](https://wordpress.org)

---

## What it does

**WP Analytics GTM DataLayer** is a **governance plugin** for your analytics dataLayer.
It does **not** replace GTM. It feeds it with the right data.

- Injects a clean `dataLayer.push()` on every page load
- Exposes configurable page context metadata (`page_type`, `page_template`, `categories`…)
- Exposes minimal, anonymised user metadata (`logged_in`, `role`, hashed ID)
- Pushes GA4 e-commerce events via WooCommerce hooks (`view_item`, `add_to_cart`, `begin_checkout`, `purchase`…)
- Simple admin UI: group toggles, real-time JSON preview, Export / Import / Reset

## What it does NOT do

- Expose raw personal data
- Replace GTM or Google Analytics
- Store or transmit any data to third parties

---

## Installation

1. Download the latest zip from [GitHub Releases](https://github.com/webAnalyste/WP-Analytics-GTM-datalayer/releases/latest)
2. In WordPress: **Plugins → Add New → Upload**, select the zip
3. Activate the plugin
4. Go to **DataLayer** in the admin sidebar
5. Configure the fields and events to expose

The following fields are **enabled by default** on activation:
`page_template`, `page_type`, `page_title`, `page_url`, `categories`, `user_logged_in`, `view_item`, `add_to_cart`, `begin_checkout`, `purchase`

---

## GA4 Events supported

| Event | Trigger |
|---|---|
| `view_item_list` | Shop / category pages |
| `view_item` | Single product page |
| `select_item` | Product click (client-side) |
| `add_to_cart` | WooCommerce add to cart |
| `remove_from_cart` | Cart item removal |
| `view_cart` | Cart page |
| `begin_checkout` | Checkout page |
| `add_shipping_info` | Shipping method selected |
| `add_payment_info` | Payment method selected |
| `purchase` | Order received (deduplicated) |
| `search` | WordPress search results |
| `login` | User login |
| `sign_up` | New user registration |
| `add_to_wishlist` | YITH Wishlist |
| `view_promotion` | Promo banner in viewport (IntersectionObserver) |
| `select_promotion` | Promo banner click |

---

## Automatic updates

The plugin self-updates from GitHub Releases — no WordPress.org account required.
WordPress will notify you of new versions in **Plugins → Installed Plugins**, just like any plugin from the official directory.

To force an immediate check: go to **DataLayer → Dashboard → Vérifier maintenant**.

---

## About

Developed and maintained by **[webAnalyste](https://www.webanalyste.com)** — a French agency specialised in **data, AI and no-code automation**.

We build tailor-made analytics stacks to improve the digital performance and SEO of our clients — from tracking architecture to GA4 implementation, GTM governance and data-driven growth strategies.

---

## Training

**[formations-analytics.com](https://www.formations-analytics.com)** — our training centre offering hands-on courses on:

- Google Analytics 4 (GA4)
- Google Tag Manager (GTM)
- Data Visualisation
- AI applied to digital marketing

Practical, operational training on the same subjects we implement for our clients every day.

---

## Changelog

### 1.3.7
- Fix critique updater : type hint `object → mixed` sur `check_for_update()` — WordPress passe `false` quand le transient est vide, causait un TypeError PHP 8 silencieux

### 1.3.6
- Fix updater : ajout du filtre `site_transient_update_plugins` (lecture) — détection même si le transient WP est en cache
- Fix updater : `wp_update_plugins()` appelé synchroniquement dans "Vérifier maintenant"

### 1.3.5
- Fix critique : les champs par défaut n'étaient pas activés lors d'une mise à jour
- Remplacement de `register_activation_hook` par `plugins_loaded` + vérification de version

### 1.3.4
- Popup "Voir les détails" enrichie : description complète, installation, changelog
- Backlinks agence dans la popup WordPress

### 1.3.2
- Champs actifs par défaut à l'activation : `page_template`, `page_type`, `page_title`, `page_url`, `categories`, `user_logged_in`, `view_item`, `add_to_cart`, `begin_checkout`, `purchase`

### 1.2.0
- Fix critique : réglages réinitialisés lors de la sauvegarde d'une autre page admin
- `save_settings()` ne met à jour que les clés du formulaire soumis

### 1.1.0
- Événements GA4 complets : `remove_from_cart`, `search`, `login`, `sign_up`, `add_to_wishlist`
- Événements client-side via `frontend.js`
- Déduplication de `purchase`
- Support brand + variant produit

### 1.0.0
- Initial release

---

## License

GPLv2 or later — see [LICENSE](LICENSE)
