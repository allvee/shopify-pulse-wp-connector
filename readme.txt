=== Shopify Pulse Connector ===
Contributors: shopifypulse
Tags: woocommerce, orders, analytics, sync, abandoned cart
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.9
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mirror WooCommerce orders, incomplete/abandoned carts and analytics into your Shopify Pulse store and manage them from the platform.

== Description ==

Shopify Pulse Connector links any WooCommerce store to a single Shopify Pulse
store using an OAuth app credential. It pushes:

* **Orders** — every order (paid and unpaid/incomplete) is mirrored as a native
  order on the platform, idempotently. Re-delivery updates, never duplicates.
* **Abandoned carts** — carts idle past a configurable threshold are pushed so
  they land in the platform's recovery inbox.
* **Analytics** — WooCommerce events (PageView, ViewContent, AddToCart,
  InitiateCheckout, Purchase, CompleteRegistration) are forwarded to the
  platform, which fans them out to Meta CAPI / TikTok / GA4.
* **Fraud screening** — an optional 4-layer check (phone/name/address, IP
  velocity, courier delivery history) runs at checkout and can block, hold, or
  flag risky orders. Fails open on API errors.

Order pushes run through Action Scheduler with automatic retry, so a slow API
call never blocks checkout. An optional sync-back poll can reconcile
WooCommerce order status from the platform.

== Installation ==

1. Upload and activate the plugin (WooCommerce must be active).
2. Open **Shopify Pulse** in the admin menu.
3. Enter the Platform API base URL (host only), Store SID, OAuth Client ID and
   Client Secret. Register the OAuth app on the platform with scopes for the
   features you enable (orders, customers, products, brands, categories,
   collections) — orders-only is not enough once product/customer/catalog sync
   is turned on. See SETUP.md for the full list.
4. Choose what to sync and click **Test connection**.

== Frequently Asked Questions ==

= Do I need to map WooCommerce products to platform products? =

No. Order lines are pushed as free-text (title/sku/price), so no mapping is
required and ingestion never affects platform inventory.

= Can one plugin connect to multiple Shopify Pulse stores? =

No — one WooCommerce site connects to one Shopify Pulse store (one OAuth app = one
store). Run separate sites for separate stores.

== Changelog ==

= 1.7.0 =
* Blocked-checkout popup: when fraud screening or the courier gate stops an order, the shopper now sees a modern, clear popup explaining why — with Call / WhatsApp buttons so a genuine buyer can still reach you. Set the number under Settings → fraud (or it falls back to the connected store's contact).
* Verify connection now shows the connected store's name + profile (contact, currency, domain) and lists the OAuth permissions the plugin was granted, grouped by area with read/write badges, so you can see exactly what access it has.

= 1.6.1 =
* UX/accessibility pass (data-dense dashboard guidelines): contact icons now use dashicons instead of emoji, visible keyboard focus rings on all controls, reduced-motion support, status messages announced to screen readers, and the details popup is a proper Escape-dismissible dialog.

= 1.6.0 =
* Abandoned carts screen redesigned for a cleaner, fully responsive layout — equal-height KPIs, aligned tables, and a modernized details popup with product thumbnails, a matched WooCommerce customer, timestamps, status, and cart totals.
* Menu: Settings and Abandoned carts now show icons in the Shopify Pulse admin menu.
* Auto-generate missing SKUs: a product/variant with no SKU gets a unique one (SP-<id>) written to WooCommerce at sync time so the platform can map it (new toggle under Two-way sync → Products, on by default).

= 1.5.2 =
* Fix: capture any email the shopper actually enters. Email stays optional (phone-only carts are fine), but the previous strict validation could drop a valid address; now any "@"-shaped email is kept and pushed.

= 1.5.1 =
* Data quality: captured contacts and cart lines are now normalized and length-clamped to the platform's limits both when stored and when pushed — invalid emails and malformed phones are dropped, over-long titles/SKUs are trimmed, and only the platform's line fields are sent. Stops a cart with bad data from being rejected and retried forever.

= 1.5.0 =
* Block (Store API) checkout support for abandoned carts. A lightweight front-end beacon reads the contact + address + cart from the checkout data store and captures the cart as the shopper fills the form — WooCommerce Blocks fires no server hook before order placement, so classic-checkout capture alone missed these. Dedupe-safe per browser; only loads on a block-based checkout with abandoned-cart sync enabled.

= 1.4.2 =
* Abandoned carts + Settings screens are now fully mobile responsive. On phones the carts table reflows into labelled cards.
* Row actions (Details, Convert, Resync, Cancel, Fake, Delete) are consolidated into a single "Actions" menu button per row instead of a crowded button strip.

= 1.4.1 =
* Fix: carts are only captured once the shopper has a contact (email or phone) — an incomplete order needs a way to be reached. This stops the worklist filling with blank add-to-cart rows that have no customer info. Existing no-contact rows are hidden from the worklist and analytics (and garbage-collected on the normal 30-day schedule).
* Courier ratio check now shows a clear "No data" (with a tooltip) when the platform has no BDCourier history for the number — or when BDCourier isn't configured for the store on the platform. Note: the BDCourier API key lives on the platform (per store), not in the plugin; the plugin reads the ratio through the connection.

= 1.4.0 =
* Abandoned carts screen is now a full worklist matching the platform: per-row Check courier ratio (bdcourier delivery-success %), Convert to a WooCommerce order, Cancel, mark Fake, View details, Delete, and Sync/Resync.
* Bulk actions: select rows (or select-all) and Resync, Convert to order, Cancel, mark Fake, or Delete the whole selection at once.
* AJAX search (name / phone / email / product) plus filters by status, product, and captured date range — no page reload.
* Convert creates a native WooCommerce order (pending) from the captured cart — re-adding products by id/SKU with the captured price — so it flows through the normal order pipeline and mirrors to the platform. Cancel / Fake only change the local status and Delete only removes the local row; none touch the platform (the cart was already synced there on capture).
* Captured lines now record the product id, enabling the product filter and exact re-add on Convert.

= 1.3.0 =
* Abandoned carts now capture the shopper's name and full billing/shipping address at the checkout step and push them to the platform, so a recovered cart arrives with who + where (was phone-only).
* New "Abandoned carts" admin screen under Shopify Pulse: recovery analytics (captured / open / pushed / recovered + value), a drop-off funnel, and a filtered cart list with per-row and bulk Resync. Resync re-pushes without ever duplicating a cart on the platform (upsert on the stable cart fingerprint).
* Recovered carts are now retained (marked, not deleted) for 30 days to power the recovery-rate analytics, then garbage-collected.

= 1.2.5 =
* Fix: token now requests the app's full registered scope set instead of a hardcoded orders-only scope, so product/customer/catalog sync no longer 403s with "You don't have permission to do that". Cached token is dropped on upgrade so the fix applies immediately.

= 1.2.4 =
* Products list: a Shopify Pulse column showing Synced, or a per-product Sync button.

= 1.2.3 =
* Orders list: the Shopify Pulse column now sits right after the Status column.

= 1.2.2 =
* Orders list: a Shopify Pulse column showing Synced, or a per-order Sync
  button (works on both classic + HPOS order screens).

= 1.2.1 =
* Separate Sync buttons for Orders, Products, Customers and Categories.
* Proper WordPress admin-menu icon (tinted monochrome), correctly sized.

= 1.2.0 =
* Full internal rename to Shopify Pulse (code prefixes, slug, asset + cookie
  names). One-time data migration re-keys existing synced-order meta and
  renames the capture table, so no synced data is lost.

= 1.1.0 =
* Redesigned settings dashboard with live analytics KPIs.
* Independent two-way sync per entity (categories, brands, products,
  customers) each with its own on/off + push/pull/both direction.
* Courier delivery-ratio checkout gate (block orders below a threshold).
* Shipping mapping: map each WooCommerce shipping method to a platform
  shipping rate; delivery charges link to the rate on the platform.
* Faithful order money mirroring: fees + gift-cards, partial refunds,
  authoritative totals for platform-side reconciliation.
* COD orders mirror as unpaid until delivered; instant abandoned-cart
  push; order-push retry with backoff; two-way SEO redirects.
* Self-heals its table + cron schedules on update-in-place.

= 1.0.0 =
* Initial release: order push, abandoned-cart sweep, analytics forwarding,
  4-layer fraud screening, optional status sync-back.
