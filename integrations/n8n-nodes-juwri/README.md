# n8n-nodes-juwri

An [n8n](https://n8n.io) community node for **juwri** — the restaurant
ordering platform. Build no-code automations on top of your restaurant: react to
new orders, keep your menu in sync, and pull orders/customers into any of the
400+ apps n8n connects to.

This package ships two nodes and one credential:

| Node | Kind | What it does |
|------|------|--------------|
| **juwri** | Action | Read and write orders, menu items and customers over the owner REST API. |
| **juwri Trigger** | Trigger | Starts a workflow when juwri delivers a webhook event (new order, refund, reservation, …). |
| **juwri API** | Credential | One API token, reused by both nodes and by the generic HTTP Request node. |

> This is a community node. Nodes/labels are platform-branded (juwri);
> reseller/white-label deployments still install this same package and point it
> at their own host via the credential's **Base URL**.

---

## Installation

**n8n Cloud / self-hosted (v1.0+):** Settings → **Community Nodes** → **Install**
→ enter `n8n-nodes-juwri` → confirm. (On n8n Cloud the node appears once
the package is verified.)

**Manual (self-hosted):**

```bash
cd ~/.n8n/nodes        # or your N8N_CUSTOM_EXTENSIONS dir
npm install n8n-nodes-juwri
```

Restart n8n. The nodes appear under **juwri**.

---

## Credential — juwri API

1. In your juwri dashboard open **Settings → API** and mint an API token.
   Grant it only the abilities the workflow needs:
   - `orders:read`, `orders:write`
   - `menu:read`, `menu:write`
   - `customers:read`
   - `webhooks:read`, `webhooks:write` (required by the Trigger node)
2. In n8n create a **juwri API** credential:
   - **Base URL** — the origin of your platform, e.g. `https://app.juwri.com`
     (no trailing path; use your branded host on a white-label deployment).
   - **API Token** — the token from step 1.
3. Click **Test**. n8n calls `GET /api/v1/me` and shows your store name on success.

Every request is sent as `Authorization: Bearer <token>`. The token is also
selectable from n8n's built-in **HTTP Request** node (as a *Predefined
Credential Type*) so you can call any endpoint this package does not wrap yet.

---

## juwri (action node)

| Resource | Operation | REST call |
|----------|-----------|-----------|
| Order | List | `GET /api/v1/stores/{id}/orders` |
| Order | Get | `GET /api/v1/stores/{id}/orders/{orderId}` |
| Order | Update Status | `PUT /api/v1/stores/{id}/orders/{orderId}/status` |
| Menu Item | List | `GET /api/v1/stores/{id}/menu` |
| Menu Item | Create | `POST /api/v1/stores/{id}/menu/items` |
| Menu Item | Update | `PUT /api/v1/stores/{id}/menu/items/{itemId}` |
| Menu Item | Set Availability | `PATCH /api/v1/stores/{id}/menu/items/{itemId}/sold-out` |
| Customer | List | `GET /api/v1/stores/{id}/customers` |
| Customer | Find | `GET /api/v1/stores/{id}/customers?search=` |

Notes:

- **Restaurant ID** is required on every operation. Get it from a `stores`
  listing (`GET /api/v1/stores`) or the dashboard URL.
- **List** operations return **one item per record** (the paginator's `data`
  envelope is unwrapped for you) and fetch a single page — raise **Limit** (max
  100 server-side) or use the Trigger for continuous flows.
- **Update Status** accepts one of `new`, `preparing`, `ready`, `delivered`,
  `completed`, `cancelled`, and returns the full order.
- **Customer → Create** is intentionally not offered: the owner API has no
  customer-create endpoint today (customers are created by diners ordering).

---

## juwri Trigger

Activating a workflow with this node **subscribes** a webhook endpoint on the
platform (`POST /api/v1/webhook-endpoints` with `{ url, events }`); deactivating
it **unsubscribes** (`DELETE /api/v1/webhook-endpoints/{id}`). The node stores
the created endpoint's id in the workflow's static data and only ever deletes
**that** id.

Pick one or more **Events**:

`order.created`, `order.status_changed`, `order.paid`, `refund.completed`,
`reservation.created`, `reservation.cancelled`, `customer.created`,
`shift.opened`, `shift.closed`, `menu.updated`, `entitlement.changed`,
`subscription.*` (paused / resumed / renewed / expired / plan_changed /
past_due / expiring / trial_ending), `app.uninstalled` — or **All Events** (`*`).

Each delivery is emitted as one item — the Standard Webhooks envelope:

```json
{
  "id": "msg_h2K9xW3qLp8RtY5vN0aBcDe7",
  "event": "order.created",
  "created_at": "2026-07-08T14:03:11+00:00",
  "data": {
    "order_id": 4812, "restaurant_id": 37, "type": "delivery",
    "status": "new", "total": 96.5, "currency": "USD",
    "track_token": "tk_9f3a2c7e", "source": "api"
  }
}
```

### Verifying the signature (optional)

Deliveries are signed with the [Standard Webhooks](https://www.standardwebhooks.com)
scheme. The signing **secret** is returned once when the subscription is created
(the node keeps it in static data). Each request carries:

- `webhook-id` — the message id (also `data.id`… see envelope `id`)
- `webhook-timestamp` — unix seconds
- `webhook-signature` — `v1,<base64>` where the base64 is
  `HMAC_SHA256(secret, "{webhook-id}.{webhook-timestamp}.{raw-body}")`

A legacy header pair is also sent for older receivers:
`X-Webhook-Event: <event>` and `X-Webhook-Signature: <hex HMAC_SHA256(secret, raw-body)>`.

n8n does not verify signatures automatically; add a Code node if you need it.

---

## Examples

Ready-to-import workflow JSON lives in [`templates/`](./templates). A few of the
patterns:

### 1. New order → Slack alert

**juwri Trigger** (`order.created`) → **Slack** (Send Message). Map
`{{$json.data.order_id}}`, `{{$json.data.total}}` and `{{$json.data.type}}` into
the message. Your kitchen hears about every order in real time.

### 2. New order → append a row to Google Sheets

**juwri Trigger** (`order.created`) → **Google Sheets** (Append). Columns
from `{{$json.created_at}}`, `{{$json.data.order_id}}`, `{{$json.data.total}}`,
`{{$json.data.currency}}` — a live orders ledger with zero backend.

### 3. Sold-out sync → Slack

**juwri Trigger** (`menu.updated`) → **IF** (`{{$json.data.change}}` is
`updated`) → **Slack**. Alert staff whenever the menu changes.

### 4. Refund completed → refund log / email

**juwri Trigger** (`refund.completed`) → **Gmail**. `refund.completed` is
delivered **instantly** (it has no polling list endpoint), so a webhook trigger
is the only way to catch it — this node handles it natively.

### 5. Scheduled digest of today's orders

**Schedule Trigger** → **juwri** (Order → List, filter *Created After* =
`{{$today}}`) → **Item Lists**/**Set** → **Gmail**. A daily sales summary.

---

## Local development & end-to-end test

Requires **Node 22+**.

```bash
npm install          # dev deps only; the package has ZERO runtime deps
npm run build        # tsc -> dist/ + copies icons/codex
npm test             # vitest (credential + action + trigger lifecycle)
npm run lint         # eslint-plugin-n8n-nodes-base (verification rules)
```

**Live e2e against a running platform + n8n** (ground-truth check):

1. `npm run build`, then link the package into a local n8n (`npm link`, or copy
   into `~/.n8n/nodes`) and start `npx n8n` (v2.x).
2. Create a **juwri API** credential pointing at your dev host and **Test**
   it (expect your store name).
3. Add a **juwri Trigger** (e.g. `order.created`) and **activate** the
   workflow. Confirm the subscription was created — in the platform DB:
   `SELECT id, url, events, active FROM webhook_endpoints WHERE user_id = <you>;`
   (a new row whose `url` is the node's webhook URL).
4. Fire a test delivery (dashboard **Webhooks → Send test**, or place a real
   order) and confirm the workflow runs with the envelope above.
5. **Deactivate** the workflow and confirm the row is **gone** from
   `webhook_endpoints` — the node deleted exactly the id it created.

---

## Verification / review notes

- **Zero runtime dependencies** (`npm ls --prod` is empty). `n8n-workflow` is a
  peer dependency provided by the host; TypeScript/eslint/vitest are dev-only.
- The action node's property skeleton is **derived from the platform OpenAPI
  spec** (`GET /api/v1/openapi.json`) and inlined as TypeScript — see
  [`scripts/openapi-props.md`](./scripts/openapi-props.md). No generator ships at
  install time.
- `refund.completed` is a **REST-hook-only** event (no polling fallback).
- CI (`.github/workflows/ci.yml`) runs build + lint + tests + the
  `@n8n/scan-community-package` security scan on Node 22.

---

## Publishing (maintainers)

Publishing a **verified** community node requires npm **provenance** (since
2026-05-01). `.github/workflows/publish.yml` does this from GitHub Actions:

1. Create the GitHub repo `n8n-nodes-juwri` and push this package to it.
2. On npmjs.com, configure **trusted publishing** (OIDC) for the package, or add
   an automation token as the `NPM_TOKEN` repo secret.
3. Bump `version` in `package.json`, commit, and cut a **GitHub Release** with a
   matching tag — the workflow builds, lints, tests, scans and runs
   `npm publish --provenance --access public`.
4. Submit for verification / list templates at
   [creators.n8n.io](https://creators.n8n.io).

## License

[MIT](./LICENSE)
