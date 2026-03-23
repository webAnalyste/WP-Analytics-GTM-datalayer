# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**WP Analytics GTM DataLayer** — WordPress plugin (GPL-2.0) that injects a configurable `dataLayer.push()` on every page for GTM / GA4. Requires PHP 8.0+, WordPress 6.0+. WooCommerce is optional (unlocks GA4 e-commerce events).

Current version: `WADL_VERSION` constant in `wp-analytics-datalayer/wp-analytics-datalayer.php`.

## Release workflow

To publish a new version:
1. Bump `WADL_VERSION` in `wp-analytics-datalayer.php` (appears twice: plugin header + `define`)
2. Rebuild the versioned ZIP: `zip -r wp-analytics-datalayer-X.Y.Z.zip wp-analytics-datalayer/`
3. Commit + push to `main` — the updater fetches the raw PHP file from `raw.githubusercontent.com` to detect new versions, then downloads the versioned ZIP from the same branch

There is no build step, no composer, no npm.

## Architecture

### Data flow

```
wp_head (priority 1)
  └─ WADL_Core::inject_datalayer()
       ├─ WADL_Content::get_payload()   → page/content metadata
       ├─ WADL_User::get_payload()      → user metadata
       └─ outputs: window.dataLayer.push({ event:'page_view', ...fields })
```

### Settings

All settings are stored as a single flat array in `wp_options` under `WADL_OPTION_KEY` (`wadl_settings`). Keys use three prefixes: `content_`, `user_`, `event_`. Each is a boolean (0/1).

`WADL_Core::get_settings()` always returns `wp_parse_args(saved, defaults)` — never read the option directly.

`WADL_Admin::save_settings()` uses `PAGE_PREFIXES` to only update keys matching the submitted form's prefix, preventing cross-page reset.

### Event execution model

**Server-side events** (`WADL_Events`, hooked via `add_action('wp', ...)`) output `<script>` tags directly, except:

- `add_to_cart` / `remove_from_cart`: fire during WooCommerce AJAX (output buffer discarded). They are **queued in WC session** via `queue_cart_event()` and flushed on the next page load by `flush_cart_events()` hooked on `wp_footer` (priority 5).
- `login` / `sign_up`: fire during authentication (no front-end context). They are **stored in user meta** (`_wadl_pending_event`) and flushed by `WADL_Core::flush_pending_user_events()` on the next `wp_head`.

**Client-side events** (`assets/frontend.js`): `select_item`, `add_shipping_info`, `add_payment_info`, `view_promotion`, `select_promotion`. The script is enqueued only when at least one of these is enabled. Product data for `select_item` is injected as `window.wadlProducts` (id → item map).

### `event_cart_all_items` option

When enabled, two things happen:
- `push_cart_state()` runs on `wp_head` (priority 2) and pushes `{ cart: { value, quantity, items } }` on every page load.
- `add_to_cart` and `remove_from_cart` events include a top-level `cart` node (current cart state after the action) merged via the `$extra` parameter of `push_ecommerce()`.

### GA4 ecommerce convention

Every ecommerce event calls `push_ecommerce()` which:
1. Pushes `{ecommerce: null}` first (Google's recommended pattern to clear previous state)
2. Pushes `{ event, ecommerce: {...}, ...$extra }` — `$extra` is merged at the **top level**, not inside `ecommerce`

### `purchase` deduplication

After pushing the event, `_wadl_purchase_pushed` meta is set on the order to prevent re-fire on page reload.

### Updater

`WADL_Updater` hooks into WordPress's native plugin update mechanism. Version detection reads the `Version:` header directly from the raw GitHub PHP file (no API, no rate limit). Falls back to `sslverify: false` on SSL errors. Cache TTL: 6h on success, 5min on error.

## File map

```
wp-analytics-datalayer/
├── wp-analytics-datalayer.php   Entry point, constants, requires
├── includes/
│   ├── class-datalayer-core.php     Settings, page_view push, defaults, pending user events
│   ├── class-datalayer-content.php  Page/content metadata payload
│   ├── class-datalayer-user.php     User metadata payload
│   ├── class-datalayer-events.php   All GA4 events (server + helpers)
│   └── class-datalayer-updater.php  GitHub-based auto-updater
├── admin/
│   ├── class-datalayer-admin.php    Admin UI (menus, forms, export/import/reset)
│   └── assets/                      admin.css, admin.js
└── assets/
    └── frontend.js                  Client-side events
```

## Key rules

- All settings keys are `0` or `1` (never `true`/`false`). Cast with `(bool)` when reading for conditions.
- `DEFAULTS_ON` in `WADL_Core` lists keys that are force-reset to `1` on every version upgrade — do not remove keys from this list without intent.
- Output containing dataLayer JSON must use `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` and be accompanied by a `phpcs:ignore WordPress.Security.EscapeOutput` comment — the JSON is already safe by construction (`wp_json_encode` on sanitized data).
- User IDs are hashed with SHA-256 + `NONCE_SALT` — never expose raw IDs.
- `map_product()` is the single source of truth for the GA4 item object shape.
