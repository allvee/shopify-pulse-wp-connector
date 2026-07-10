# Wafi Commerce Connector (WooCommerce → Wafi platform)

Mirror a WooCommerce store's **orders**, **incomplete/abandoned carts** and
**analytics** into a [Wafi Commerce](https://wafiperfume.com) store, so the
store can be managed from the Wafi platform. Connect any WooCommerce site to
one Wafi store with an OAuth app credential.

> This is the **store-side plugin**. The platform-side ingest lives in the
> `api.wafiperume.com` repo (`apps/admin-api/.../connector`), documented in
> `docs/runbooks/woocommerce-connector.md`.

## What it does

| Flow | WooCommerce trigger | Platform endpoint |
| --- | --- | --- |
| **Order push** (paid + unpaid/incomplete) | `woocommerce_new_order`, `woocommerce_order_status_changed` | `POST /connect/orders` |
| **Abandoned carts** | cart snapshots + a 15-min WP-Cron sweep | `POST /connect/abandoned` |
| **Analytics** | server Purchase/registration + browser PageView/ViewContent/AddToCart/InitiateCheckout | `POST /pixel/events` |
| **Fraud screening** (4-layer) | checkout validation (classic + blocks) | `POST /fraud/screen` |
| **Status sync-back** (optional) | 10-min WP-Cron poll | `GET /connect/orders` |

- Orders are pushed as **free-text lines** — no product mapping needed, and
  ingestion never touches the platform's inventory.
- **Idempotent**: the platform dedupes on the WooCommerce order id; the plugin
  also skips unchanged payloads. Re-delivery updates, never duplicates.
- Order pushes run through **Action Scheduler** (bundled with WooCommerce)
  with exponential-backoff retry, so a slow/failed API call never blocks
  checkout.

## Requirements

- WordPress 5.8+, WooCommerce 6.0+, PHP 7.4+.
- A registered Wafi OAuth app with scopes `orders.read` + `orders.write`
  (see the platform runbook).

## Install

1. Zip this directory (or download a release) and upload via
   *Plugins → Add New → Upload Plugin*, then **Activate**.
2. Go to **Wafi Connector** in the admin menu and fill in:
   - **Platform API base URL** — host only, e.g.
     `https://api.admin.wafiperfume.com` (the plugin appends `/api/v1`).
   - **Store SID**, **OAuth Client ID**, **OAuth Client Secret**.
   - Pick what to sync (orders / abandoned carts / analytics) and which order
     statuses trigger a push.
3. Click **Test connection** — a green line means the credentials + scopes are
   good (`GET /connect/ping`).

## Configuration reference

| Setting | Meaning |
| --- | --- |
| Admin API base URL | Wafi admin API host (OAuth + `/connect/*`). `/api/v1` is appended automatically. |
| Storefront API base URL | Client/storefront API host (`/pixel/*`, `/fraud/*`). Leave blank if it's the same host as the admin API. |
| Store SID | The `X-Store-Sid` tenant id. One site → one store. |
| Client ID / Secret | OAuth app credentials (`wapp_…` / `wsk_…`). Secret is write-only; blank keeps the stored value. |
| Orders / Abandoned / Analytics / Fraud | Independent on/off toggles. |
| When fraud is detected | `block` (reject), `hold` (create + On-hold for review), or `flag` (order note only). |
| Order statuses to push | A push fires when an order enters one of these. |
| Abandoned idle threshold | Minutes a cart must be idle before the sweep pushes it. |
| Allow status writeback | Let the platform update WooCommerce status via the poll (forward-only). Off by default. |
| Debug logging | Verbose logs under *WooCommerce → Status → Logs* (source `wafi-connector`). |

### Fraud screening

With fraud enabled, each checkout is screened through the platform's 4-layer
engine (phone/name/address, IP-velocity, courier history) before the order is
finalized. The chosen action blocks, holds, or flags a failing order. It
**fails open** — if the API is unreachable the checkout proceeds, so an outage
never blocks legitimate sales. Requires fraud prevention enabled on the
platform for the store.

## How auth works

The plugin mints a `client_credentials` access token (`wat_…`, 1-hour TTL, no
refresh) and caches it in a transient until ~1 min before expiry. Every request
carries `Authorization: Bearer wat_…` **and** `X-Store-Sid`. On a 401 the token
is re-minted once and the request retried.

## Architecture

```
wafi-connector.php            bootstrap, constants, activation, WooCommerce guard
includes/
  class-wafi-plugin.php        singleton orchestrator (wires + registers hooks)
  class-wafi-settings.php      admin settings screen + Test connection
  class-wafi-api-client.php    OAuth token lifecycle + signed HTTP
  class-wafi-logger.php        WC_Logger wrapper (source: wafi-connector)
  class-wafi-order-mapper.php  WC_Order → IngestOrderDto payload
  class-wafi-order-sync.php    order hooks → Action Scheduler → POST /connect/orders
  class-wafi-abandoned-sync.php cart capture table + sweep → POST /connect/abandoned
  class-wafi-analytics.php     server Purchase + browser event proxy → /pixel/events
  class-wafi-fraud.php         checkout screening → POST /fraud/screen (block/hold/flag)
  class-wafi-status-poller.php poll GET /connect/orders → reconcile WC status
  class-wafi-install.php       capture table (dbDelta) + cron schedules
assets/js/wafi-pixel.js        browser events via same-site AJAX proxy
uninstall.php                  removes options, token, cron, capture table
```

### Extending

- `wafi_connector_order_payload` (filter) — mutate the order payload before it
  is pushed: `add_filter( 'wafi_connector_order_payload', fn( $payload, $order ) => … , 10, 2 )`.

## Notes & limits

- Abandoned carts require a contact (email or phone) to be recoverable —
  anonymous carts are skipped.
- Purchase pixel is **server-side only** (deduped by the platform on order id);
  the browser never fires Purchase.
- Order line items are free-text, so the platform copy carries no `variantId`
  and posts no stock movement.
- One WordPress site connects to one Wafi store (one OAuth app = one sid).

## License

GPL-2.0-or-later.
