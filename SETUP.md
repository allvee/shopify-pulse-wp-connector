# Setup — connect WooCommerce to your Shopify Pulse store

Takes ~5 minutes. You need admin access to both the Shopify Pulse platform and the
WooCommerce site.

## 1. Register an OAuth app (on the Shopify Pulse platform)

Create an app for this store with scopes **`orders.read`** and
**`orders.write`**. You can do this from the platform admin, or via the API:

```bash
curl -X POST https://<your-admin-host>/api/v1/apps \
  -H "X-Store-Sid: <your-sid>" \
  -H "Authorization: Bearer <your admin token>" \
  -d '{
    "name": "WooCommerce — <shop name>",
    "redirectUri": "https://<shop>/wp-admin/",
    "scopes": ["orders.read", "orders.write"]
  }'
```

Copy three things:
- **Store SID** — your store's tenant id.
- **Client ID** (`wapp_…`).
- **Client Secret** (`wsk_…`) — shown **once**. If you lose it, rotate it.

## 2. Install the plugin (on WooCommerce)

Upload `wafi-connector.zip` under *Plugins → Add New → Upload Plugin* and
activate it. (WooCommerce must be active.)

## 3. Configure

Open **Shopify Pulse** in the wp-admin menu and fill in:

| Field | Value |
| --- | --- |
| **Admin API base URL** | Your admin API host, e.g. `https://api.admin.yourdomain.com` (host only — `/api/v1` is added for you). |
| **Storefront API base URL** | Your storefront/client API host, e.g. `https://api.yourdomain.com`. Leave **blank** if it's the same host as the admin API. |
| **Store SID** | From step 1. |
| **Client ID / Client Secret** | From step 1. |

Then:
1. Tick **Active**.
2. Choose what to sync: **Orders**, **Abandoned carts**, **Analytics**, **Fraud** (and the fraud action: block / hold / flag).
3. Pick which **order statuses** trigger a push.
4. **Save changes**.

## 4. Verify & sync

- **Verify connection** → checks credentials + scopes. A green status banner
  (`Connected to store "<sid>"`) means you're live.
- **Sync now** → backfills your recent orders to the platform.

## Where do I find my host URLs?

They're the public domains your Shopify Pulse platform is served on:
- Admin API — usually `api.admin.<yourdomain>`
- Storefront API — usually `api.<yourdomain>`

If your whole platform runs on a single domain, put that in **Admin API base
URL** and leave **Storefront API base URL** blank.

## Notes

- One WooCommerce site connects to **one** Shopify Pulse store (one OAuth app = one
  store). Use a separate app per store.
- The access token lasts 1 hour and is refreshed automatically — you never
  handle it.
- Fraud screening **fails open**: if the platform is unreachable, checkout
  proceeds so sales aren't blocked.
- Logs: *WooCommerce → Status → Logs*, source `wafi-connector` (enable
  **Debug logging** in settings for verbose output).

See [README.md](./README.md) for the full architecture and endpoint reference.
