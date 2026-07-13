<div align="center">

# Shopify Pulse Connector

**Connect your WooCommerce store to the Shopify Pulse platform — two-way sync, server-side analytics, and fraud screening in one plugin.**

![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-96588a.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)

</div>

---

Shopify Pulse Connector links a single WooCommerce store to the Shopify Pulse platform. It keeps customers and catalog in sync both ways, mirrors every order for reporting, pushes server-side conversion events, and screens checkouts through the platform's fraud engine — all from one admin screen.

> **This is the store-side plugin.** The platform-side connector API lives in a companion repository (`api.wafiperume.com`, under `apps/admin-api/.../connector`; see `docs/runbooks/woocommerce-connector.md`). This README covers only what runs on your WordPress site.

## Table of contents

- [Features](#features)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Admin screen](#admin-screen)
- [Architecture](#architecture)
- [Platform endpoints](#platform-endpoints)
- [Extending](#extending)
- [Notes](#notes)
- [License](#license)

## Features

**Orders** — Every order, paid or incomplete, is mirrored to the platform for unified reporting. Sync is idempotent (deduplicated on the WooCommerce order id) and runs through Action Scheduler with backoff retry and a payload-hash skip, so unchanged orders never re-send. Order lines are free-text and never touch platform inventory. Optional, off-by-default status sync-back reconciles WooCommerce status from the platform (forward-only).

**Abandoned carts** — Idle carts are captured to a table and swept every 15 minutes by WP-Cron (threshold configurable), then sent to the platform with a stable fingerprint. Captured rows are garbage-collected after 30 days.

**Analytics** — Server-side `Purchase` and `CompleteRegistration` events fire from WooCommerce; browser `PageView`, `ViewContent`, `AddToCart`, and `InitiateCheckout` are relayed through a same-site AJAX proxy. The platform fans events out to Meta CAPI, TikTok, and GA4. Purchases are deduped by the platform on order id.

**Fraud screening** — Checkouts are screened through the platform's 4-layer engine (phone/name/address heuristics, IP-velocity auto-block, courier delivery-history gate). You choose the action — block, hold, or flag. Screening **fails open**: if the API is unreachable, checkout proceeds. Works with both Classic and Block (Store API) checkout.

**Attribution** — First-touch and last-touch data (landing page, referrer, traffic source, UTM parameters, `gclid`/`fbclid`), client browser time (hour range, weekday, month, timezone), device, and visit count are attached to each order as the `app:woocommerce/attribution` metafield on the platform.

**Customer sync** — Two-way push and pull with last-write-wins, matched by email or phone. Direction is switchable: two-way, push-only, or pull-only.

**Catalog sync** — Categories (with hierarchy), brands, and products/variants sync both ways. Products carry images, category/brand membership, and variants (simple and variable). SEO title/description sync both ways via Yoast, Rank Math, or AIOSEO. Pushes are hash-gated; pulls are loop-safe (suppression flag) with last-write-wins.

**SEO redirects + robots** — Platform-managed 301/302 redirects are applied on the storefront via `template_redirect` (exact and trailing-slash match), and robots disallow rules are appended through the `robots_txt` filter.

### Sync matrix

| Entity | WC → Platform | Platform → WC | SEO fields |
| --- | :---: | :---: | :---: |
| Orders | Yes | Status only (opt-in, forward-only) | — |
| Customers | Yes | Yes | — |
| Categories | Yes | Yes | — |
| Brands | Yes | Yes | — |
| Products / variants | Yes | Yes | Two-way (Yoast / Rank Math / AIOSEO) |

**One-way flows:** abandoned carts, analytics events, and attribution are push-only (WC → Platform). SEO redirects and robots rules are pull-only (Platform → WC).

## How it works

```
                     WooCommerce store (this plugin)
  ┌────────────────────────────────────────────────────────────────┐
  │                                                                  │
  │  Order / customer / catalog change                               │
  │        │  hash-gate (skip if unchanged)                          │
  │        ▼                                                         │
  │   Action Scheduler ──── push ──────────────►  ADMIN API          │
  │                                               /connect/*         │
  │   WP-Cron (pull, under suppression flag) ◄────                   │
  │                                                                  │
  │   Browser + server events ── analytics ────►  STOREFRONT API     │
  │   Checkout screen ────────── fraud ────────►  /pixel/*, /fraud/* │
  │                                                                  │
  └────────────────────────────────────────────────────────────────┘
```

- **Idempotent match keys.** Entities match by external id (WooCommerce id) first, with handle/SKU or email/phone as a rename-safe fallback. Two entities sharing a handle are never merged.
- **Last-write-wins.** Any two-way conflict resolves to the most recent write.
- **Loop-safe.** Pushes are hash-gated so only real changes go out; pulls run under a suppression flag, so applying a pull never triggers a push back. Mirrored orders never double-send customer comms or pixels.
- **Stock stays in WooCommerce.** Catalog sync never writes platform inventory — WooCommerce remains the source of truth for stock.

## Requirements

| Component | Version | Notes |
| --- | --- | --- |
| WordPress | 5.8+ | |
| WooCommerce | 6.0+ | HPOS-compatible |
| PHP | 7.4+ | |
| Shopify Pulse store | One per site | One OAuth app = one store |

Authentication is OAuth 2.0 `client_credentials`. The plugin mints a `wat_` bearer token (1-hour TTL, auto-refreshed) via `POST {admin}/api/v1/oauth/token` and sends `Authorization` plus `X-Store-Sid` on every request. The admin host handles OAuth and `/connect/*`; the storefront host handles `/pixel/*` and `/fraud/*` (leave the storefront base blank to use the same host).

**OAuth scopes to grant the app:**

```
orders.read      orders.write
customers.read   customers.write
products.read    products.write
brands.write     categories.write     collections.write
```

## Installation

1. Upload and activate the plugin (**Plugins → Add New → Upload**, or drop the folder in `wp-content/plugins/`).
2. Register an OAuth app for your store on the Shopify Pulse platform, grant the scopes listed above, and copy the **client id**, **client secret**, and **store SID**.
3. Open **Shopify Pulse** in the WordPress admin, enter the API base URL and credentials, and click **Verify connection**.
4. Enable the features you want, set sync directions, then flip the **Active** master switch on.

For a step-by-step walkthrough, see `SETUP.md` in this repo or the **Quick setup guide** panel on the plugin's settings page.

## Configuration

All settings live in the `sp_connector_settings` option.

**Connection**

| Field | Meaning |
| --- | --- |
| `active` | Master switch — pauses all syncing without losing any settings. |
| `api_base` | Admin API base URL (OAuth + `/connect/*`). |
| `storefront_base` | Storefront API base URL (`/pixel/*`, `/fraud/*`). Blank = same host as admin. |
| `sid` | Store SID, sent as `X-Store-Sid`. |
| `client_id` | OAuth client id. |
| `client_secret` | OAuth client secret. |

**Feature toggles**

| Field | Meaning |
| --- | --- |
| `enable_orders` | Mirror orders to the platform. |
| `enable_abandoned` | Capture and sweep abandoned carts. |
| `enable_analytics` | Send server + browser conversion events. |
| `enable_fraud` | Screen checkouts through the fraud engine. |
| `fraud_action` | Action on a flagged checkout: `block`, `hold`, or `flag`. |
| `enable_customer_sync` | Turn on customer sync. |
| `customer_sync_dir` | Customer direction: `both`, `push`, or `pull`. |
| `enable_catalog_sync` | Turn on category/brand/product sync. |
| `catalog_sync_dir` | Catalog direction: `push`, `both`, or `pull`. |
| `order_statuses[]` | Which order statuses are pushed. |
| `abandoned_idle_min` | Minutes a cart must be idle before it counts as abandoned. |
| `allow_status_writeback` | Allow the platform to reconcile WooCommerce order status (forward-only). |
| `debug_log` | Write verbose logs via `WC_Logger`. |

## Admin screen

The **Shopify Pulse** settings page gives you:

- **Status badge** — live connection state: **Connected**, **Paused**, or **Not verified**.
- **Active master switch** — pauses every sync at once while preserving all settings.
- **Verify connection** — validates credentials and the OAuth handshake.
- **Sync now** — backfills recent orders on demand.
- **Quick-setup guide** panel, per-feature toggles and direction selects, the order-status push filter, the fraud action selector, and the debug-logging toggle.

## Architecture

```
includes/
├── class-sp-plugin.php          Singleton orchestrator / bootstrap
├── class-sp-settings.php        Admin screen + Verify / Sync / status badge
├── class-sp-api-client.php      OAuth token + signed HTTP (admin + storefront hosts)
├── class-sp-logger.php          WC_Logger wrapper
├── class-sp-order-mapper.php    WC_Order → payload
├── class-sp-order-sync.php      Order hooks → Action Scheduler → /connect/orders
├── class-sp-status-poller.php   Poll → reconcile WC status (forward-only)
├── class-sp-abandoned-sync.php  Capture table + idle-cart sweep
├── class-sp-attribution.php     Visitor tracker → order metafield
├── class-sp-analytics.php       Server Purchase + browser event proxy
├── class-sp-fraud.php           Checkout screen → block / hold / flag
├── class-sp-customer-sync.php   Customers push + pull
├── class-sp-catalog-sync.php    Categories + brands push + pull
├── class-sp-product-sync.php    Products + variations push + pull
├── class-sp-seo-sync.php        Redirects (301/302) + robots rules
├── class-sp-seo.php             Yoast / Rank Math / AIOSEO read + write bridge
└── class-sp-install.php         Capture-table dbDelta + cron schedules

assets/
├── js/sp-attr.js                Attribution tracker
└── js/sp-pixel.js               Browser analytics events

uninstall.php                      Cleans options, token, cron, and the capture table
```

## Platform endpoints

| Method | Path | Purpose | Host |
| --- | --- | --- | --- |
| POST | `/api/v1/oauth/token` | Mint / refresh the `wat_` bearer | Admin |
| POST / GET | `/connect/orders` | Push orders / read status | Admin |
| POST | `/connect/abandoned` | Push abandoned carts | Admin |
| POST / GET | `/connect/customers` | Two-way customer sync | Admin |
| POST / GET | `/connect/categories` | Two-way category sync | Admin |
| POST / GET | `/connect/brands` | Two-way brand sync | Admin |
| POST / GET | `/connect/products` | Two-way product / variant sync | Admin |
| GET | `/connect/redirects` | Fetch managed 301/302 redirects | Admin |
| GET | `/connect/robots` | Fetch robots disallow rules | Admin |
| POST | `/pixel/events` | Analytics events (fanned to Meta / TikTok / GA4) | Storefront |
| POST | `/fraud/screen` | Screen a checkout | Storefront |

## Extending

Use the `sp_connector_order_payload` filter to mutate the order payload before it is pushed.

```php
add_filter( 'sp_connector_order_payload', function ( array $payload, WC_Order $order ) {
    // Attach a custom field to every mirrored order.
    $payload['metafields']['app:woocommerce/gift_note'] = $order->get_customer_note();

    return $payload;
}, 10, 2 );
```

## Notes

- **One store per app.** A WooCommerce site connects to exactly one Shopify Pulse store (one OAuth app = one store).
- **Purchase is server-side.** The authoritative `Purchase` event fires from the server and is deduped by the platform on order id; browser events are supplemental.
- **Order lines are free-text.** Mirrored order lines carry no platform inventory impact — stock stays in WooCommerce.
- **Variant pull needs the product first.** Pulling variants requires the parent product to already exist on the WooCommerce side.
- **SEO redirects are one-way.** Redirects and robots rules are managed on the platform and applied read-only on the storefront.

## License

GPL-2.0-or-later.
