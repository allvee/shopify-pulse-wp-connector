<div align="center">

# Wafi Commerce Connector

**Two-way sync between WooCommerce and the [Wafi Commerce](https://wafiperfume.com) platform — orders, customers, catalog, analytics, fraud and SEO.**

[![Version](https://img.shields.io/badge/version-1.0.0-2563eb.svg)](https://github.com/allvee/wafi-wp-connector/releases)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b.svg)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-96588a.svg)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb3.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-3da639.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

</div>

---

Connect any WooCommerce store to a single Wafi store with one OAuth credential, then run your business from the platform: mirror orders, keep customers and catalog in sync **both directions**, forward analytics to Meta / TikTok / GA4, screen checkouts through a multi-layer fraud engine, and manage SEO redirects centrally.

> **Two repositories.** This is the **store-side plugin**. The platform-side connector API lives in `api.wafiperume.com` (`apps/admin-api/.../connector`), documented in `docs/runbooks/woocommerce-connector.md`.

## Table of contents

- [Feature matrix](#feature-matrix)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Architecture](#architecture)
- [Platform endpoints](#platform-endpoints)
- [Sync model](#sync-model)
- [Extending](#extending)
- [Limitations](#limitations)
- [License](#license)

## Feature matrix

| Domain | WooCommerce → Platform | Platform → WooCommerce | Notes |
| --- | :---: | :---: | --- |
| **Orders** (paid + incomplete) | ✅ | ✅ *(status)* | Idempotent mirror; free-text lines (no inventory impact) |
| **Abandoned carts** | ✅ | — | Idle-cart sweep with a stable fingerprint |
| **Customers** | ✅ | ✅ | Match by email/phone; last-write-wins |
| **Categories** | ✅ | ✅ | Hierarchy preserved |
| **Brands** | ✅ | ✅ | `product_brand` / third-party taxonomies |
| **Products + variants** | ✅ | ✅ | Simple + variable; images, membership |
| **SEO title / description** | ✅ | ✅ | Yoast · Rank Math · AIOSEO |
| **SEO redirects / robots** | — | ✅ | Applied on the storefront (301/302 + `robots.txt`) |
| **Analytics** | ✅ | — | PageView · ViewContent · AddToCart · InitiateCheckout · Purchase · Registration |
| **Fraud screening** | ✅ | — | 4-layer: phone/name/address · IP velocity · courier history |

## How it works

```
                       WooCommerce (this plugin)                         Wafi platform
  ┌───────────────────────────────────────────────┐        ┌───────────────────────────────┐
  │  Order / cart / customer / product hooks       │──push─▶│  OAuth  POST /connect/*        │
  │  Action Scheduler queue  ·  hash-gated         │        │  idempotent upsert, LWW        │
  │                                                │◀─poll──│  GET  /connect/*  (cursor)     │
  │  WP-Cron pull  ·  suppression flag             │        │                               │
  │  Browser + server analytics  ─────────────────────────▶│  POST /pixel/events (CAPI)     │
  │  Checkout screen  ────────────────────────────────────▶│  POST /fraud/screen            │
  └───────────────────────────────────────────────┘        └───────────────────────────────┘
```

- **Push** runs through **Action Scheduler** (bundled with WooCommerce) with backoff retry, so a slow API call never blocks checkout, and is **hash-gated** so unchanged entities never re-send.
- **Pull** runs on **WP-Cron** and applies only platform changes newer than the last seen (last-write-wins), under a **suppression flag** so a pulled write never bounces back as a push.
- **Idempotent everywhere** — the platform matches on the external id (WooCommerce id) with a handle/SKU/email fallback, so re-delivery updates instead of duplicating.

## Requirements

| | |
| --- | --- |
| WordPress | 5.8+ |
| WooCommerce | 6.0+ (HPOS compatible) |
| PHP | 7.4+ |
| Platform | A registered Wafi OAuth app with the scopes below |

**OAuth scopes** (grant what you use): `orders.read` · `orders.write` · `customers.read` · `customers.write` · `products.read` · `products.write` · `brands.write` · `categories.write` · `collections.write`.

## Installation

1. Upload `wafi-connector.zip` via **Plugins → Add New → Upload Plugin**, then **Activate** (WooCommerce must be active).
2. Open **Wafi Connector** in the admin menu.
3. Fill in the connection fields (see below) and click **Verify connection** — a green status badge means you're live.
4. Choose what to sync, then **Save**. Use **Sync now** to backfill recent orders.

A step-by-step guide is also available in [`SETUP.md`](./SETUP.md) and inside the plugin's settings screen.

## Configuration

| Setting | Description |
| --- | --- |
| **Admin API base URL** | Wafi admin host (OAuth + `/connect/*`), e.g. `https://api.admin.yourdomain.com`. `/api/v1` is appended automatically. |
| **Storefront API base URL** | Client/storefront host (`/pixel/*`, `/fraud/*`). Leave blank if it's the same host. |
| **Store SID** | The `X-Store-Sid` tenant id — one WooCommerce site ↔ one Wafi store. |
| **Client ID / Secret** | OAuth app credentials (`wapp_…` / `wsk_…`). Secret is write-only; blank keeps the stored value. |
| **Connection: Active** | Master switch — pauses all syncing without losing settings. |
| **Orders / Abandoned / Analytics / Fraud** | Independent toggles. |
| **Customer sync** | Off · direction: two-way / push / pull. |
| **Catalog sync** | Off · direction (categories, brands, products, SEO). |
| **When fraud is detected** | `block` · `hold` · `flag`. |
| **Order statuses to push** | Which statuses trigger an order push. |
| **Allow status writeback** | Let the platform drive WooCommerce order status (forward-only). |
| **Debug logging** | Verbose logs under *WooCommerce → Status → Logs* (source `wafi-connector`). |

## Architecture

```
wafi-connector.php              bootstrap · constants · activation · WooCommerce guard
includes/
  class-wafi-plugin.php          singleton orchestrator (wires + registers hooks)
  class-wafi-settings.php        admin screen · Verify · Sync now · status badge
  class-wafi-api-client.php      OAuth token lifecycle · signed HTTP (admin + storefront hosts)
  class-wafi-logger.php          WC_Logger wrapper
  ── orders ─────────────────────────────────────────────────────────────────────
  class-wafi-order-mapper.php    WC_Order → ingest payload
  class-wafi-order-sync.php      order hooks → Action Scheduler → /connect/orders
  class-wafi-status-poller.php   poll /connect/orders → reconcile WC status (forward-only)
  class-wafi-abandoned-sync.php  cart capture table + sweep → /connect/abandoned
  ── growth ─────────────────────────────────────────────────────────────────────
  class-wafi-attribution.php     first/last-touch + browser time → order metafield
  class-wafi-analytics.php       server Purchase + browser proxy → /pixel/events
  class-wafi-fraud.php           checkout screening → /fraud/screen (block/hold/flag)
  ── two-way sync ───────────────────────────────────────────────────────────────
  class-wafi-customer-sync.php   customers push + pull (last-write-wins)
  class-wafi-catalog-sync.php    categories + brands push + pull (hierarchy)
  class-wafi-product-sync.php    products + variations push + pull
  class-wafi-seo-sync.php        redirects (301/302) + robots.txt from the platform
  class-wafi-seo.php             SEO read/write bridge (Yoast · Rank Math · AIOSEO)
  class-wafi-install.php         capture table (dbDelta) + cron schedules
assets/js/
  wafi-attr.js                   visitor attribution tracker
  wafi-pixel.js                  browser analytics via same-site AJAX proxy
uninstall.php                    removes options · token · cron · capture table
```

## Platform endpoints

All under `…/api/v1`, OAuth-guarded (`wat_` bearer + `X-Store-Sid`).

| Method | Path | Scope | Purpose |
| --- | --- | --- | --- |
| `GET` | `/connect/ping` | `orders.read` | Verify credentials + scopes |
| `POST`/`GET` | `/connect/orders` | `orders.*` | Order upsert / sync-back poll |
| `POST` | `/connect/abandoned` | `orders.write` | Abandoned cart upsert |
| `POST`/`GET` | `/connect/customers` | `customers.*` | Customer upsert / poll |
| `POST`/`GET` | `/connect/{brands,categories,collections}` | `*.write` / `products.read` | Taxonomy upsert / poll |
| `POST`/`GET` | `/connect/products` | `products.*` | Product upsert / poll |
| `POST`/`GET` | `/connect/redirects` · `GET /connect/robots` | `products.*` | SEO redirects / robots |
| `POST` | `/fraud/screen` | `orders.write` | Checkout fraud verdict *(storefront host)* |
| `POST` | `/pixel/events` | *public* | Analytics ingest *(storefront host)* |

## Sync model

- **Match keys** — orders/products/customers/terms by external id first, then handle / SKU / email-phone. Rename-safe.
- **Conflict resolution** — last-write-wins by `updatedAt`; a stale push is a no-op, a stale pull is skipped.
- **Loop prevention** — pushes are gated on a content hash; pulls run under a suppression flag and refresh that hash so applied changes don't echo back.
- **Stock stays in WooCommerce** — catalog sync never writes platform inventory; order lines are free-text.

## Extending

```php
// Mutate the order payload before it is pushed.
add_filter( 'wafi_connector_order_payload', function ( $payload, $order ) {
    $payload['tags'][] = 'from-woo';
    return $payload;
}, 10, 2 );
```

## Limitations

- One WooCommerce site connects to one Wafi store (one OAuth app = one store).
- Purchase pixel is server-side only (deduped by the platform on order id).
- Product **pull** updates existing products and creates simple ones; a multi-variant product must exist in WooCommerce first (creation is logged, not forced).
- SEO redirects/robots are one-way (platform → WooCommerce).

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
