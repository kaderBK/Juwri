---
title: "Admin API reference"
audience: developer
group: Api
updated: 2026-09-10
---

# Admin API reference

The admin (owner) API lives under `/api/v1` and is authenticated with a
personal API token. Every endpoint is tenant-scoped: a token can only see and
change data belonging to the restaurants of the user who created it.

Responses are JSON. List endpoints use standard Laravel pagination
(`data`, `current_page`, `next_page_url`, ...).

## Authentication

Send the token as a bearer header (preferred) or, as a fallback, the
`api_token` query parameter:

```bash
curl https://your-domain/api/v1/stores \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Tokens are stored hashed (SHA-256) — the raw value is shown once at creation
and cannot be recovered. Each successful request updates the token's
`last_used_at` timestamp, so unused tokens are easy to spot and revoke.

## Creating a token

Dashboard → **API & Webhooks**. When creating a token you set:

- **Name** — required, up to 80 characters. Use one token per integration.
- **Abilities** — the scopes below. If none are picked the token defaults to
  `read` (read everything).
- **Expiry** — optional, 1–3650 days. Omit for a non-expiring token.

Delete a token from the same page to revoke it immediately. To rotate, create
a new token, switch the integration over, then delete the old one.

## Abilities

Abilities are `resource:action` pairs. Resources: `restaurants`, `menu`,
`orders`, `customers`, `analytics`, `drivers`, `webhooks`. Actions: `read`,
`write`.

Two coarse abilities exist as shorthand:

- `read` — equivalent to `*:read` (all GET endpoints on every resource).
- `write` — equivalent to `*:write` (everything; write implies read).

Coverage rules:

- A `write` ability also grants the matching `read` (e.g. `orders:write`
  covers `orders:read`).
- `*` grants everything.
- Legacy tokens created with only `['read']` or `['write']` keep working —
  the granular scopes are purely additive.

## Endpoints

Each route declares the ability it requires; the table shows the minimum.
The API is also self-describing: `GET /api/v1` returns a discovery index of
every live endpoint, and `GET /api/v1/openapi.json` serves an OpenAPI 3.1
document generated from the router (always in parity with the live routes).

### Restaurants and menu (read)

| Method | Path | Ability |
|---|---|---|
| GET | `/api/v1/stores` | `restaurants:read` |
| GET | `/api/v1/stores/{id}` | `restaurants:read` |
| GET | `/api/v1/stores/{id}/menu` | `menu:read` |

`GET /stores` and `GET /stores/{id}` return id, title, slug (plus
categories/item count on the single-restaurant form), public URL, template,
created_at, and two vertical fields: `store_type` (`restaurant` by default;
`retail`, `cosmetic`, `clothing`, `carparts`, `laundry`, or `pharmacy` on a
multi-vertical store) and a nested `vertical` object — `label`, `group`
(`food`/`goods`/`services`), a `capabilities` map (`food_attrs`,
`variant_goods`, `fitment`, `age_gate`, `expiry`, `service`, each a bool),
and `fulfillment_modes` (the `orders.type` values this store may use — see
the order-type note below). A legacy/unknown `store_type` falls back to
`restaurant`, so an integrator that predates this pack can ignore both
fields entirely and keep working. `GET /stores/{id}` is paginated 20 per
page for the list form.

`GET /stores/{id}/menu` returns categories in display order, each with
its active items. A `restaurant`-type store's items keep their exact
pre-existing shape (`name`, `price`, `is_sold_out`, `is_popular`,
`is_vegan`, `is_halal`, `calories`) — nothing changed there. A non-food
item instead omits those food-only fields and, only when the underlying data
exists, adds `brand` (`{id, name}`), `weight`, `stock` (only when the item
tracks inventory), `has_variants`, and — when it has variants —
`variant_count` and `variants_in_stock`. See
[Vertical resources](#vertical-resources) below for the dedicated
brand/variant/shipment/fitment endpoints.

### Menu management (`menu:write`)

| Method | Path |
|---|---|
| POST | `/api/v1/stores/{id}/menu/categories` |
| PUT | `/api/v1/stores/{id}/menu/categories/{categoryId}` |
| DELETE | `/api/v1/stores/{id}/menu/categories/{categoryId}` |
| POST | `/api/v1/stores/{id}/menu/items` |
| PUT | `/api/v1/stores/{id}/menu/items/{itemId}` |
| DELETE | `/api/v1/stores/{id}/menu/items/{itemId}` |
| PATCH | `/api/v1/stores/{id}/menu/items/{itemId}/sold-out` |

### Orders

| Method | Path | Ability |
|---|---|---|
| GET | `/api/v1/stores/{id}/orders` | `orders:read` |
| POST | `/api/v1/stores/{id}/orders` | `orders:write` |
| GET | `/api/v1/stores/{id}/orders/{orderId}` | `orders:read` |
| PUT | `/api/v1/stores/{id}/orders/{orderId}/status` | `orders:write` |

The order list accepts a `status` query filter and is paginated 30 per page.
Valid statuses for the status update: `new`, `preparing`, `ready`,
`delivered`, `completed`, `cancelled`. A successful status update also fires
the `order.status_changed` webhook (see [webhooks](webhooks.md)).

### Customers and analytics (read)

| Method | Path | Ability |
|---|---|---|
| GET | `/api/v1/stores/{id}/customers` | `customers:read` + `crm_suite` |
| GET | `/api/v1/stores/{id}/guests` | `customers:read` + `crm_suite` |
| GET | `/api/v1/stores/{id}/guests/segments` | `customers:read` + `crm_suite` |
| GET | `/api/v1/stores/{id}/analytics` | `analytics:read` |

The customers and guests endpoints additionally require the **`crm_suite`**
entitlement, matching the web CRM. See [Feature gates](#feature-gates) below.
The guest endpoints are the read-only guest CDP: `/guests` returns unified
guest profiles with RFM segment labels; `/guests/segments` returns the count
per segment.

### Drivers and delivery

Every route in this section additionally requires the **`driver_app`**
entitlement — see [Feature gates](#feature-gates).

| Method | Path | Ability |
|---|---|---|
| GET | `/api/v1/drivers` | `drivers:read` |
| POST | `/api/v1/drivers` | `drivers:write` |
| PUT | `/api/v1/drivers/{id}` | `drivers:write` |
| DELETE | `/api/v1/drivers/{id}` | `drivers:write` |
| POST | `/api/v1/drivers/{id}/rotate-token` | `drivers:write` |
| GET | `/api/v1/stores/{id}/deliveries` | `orders:read` |
| POST | `/api/v1/stores/{id}/orders/{orderId}/assign` | `orders:write` |
| PUT | `/api/v1/stores/{id}/orders/{orderId}/delivery-status` | `orders:write` |

### Webhook endpoints (REST Hooks)

Programmatic webhook-endpoint management — the subscribe/unsubscribe surface
Zapier- and Make-style integrations use. Endpoints created here behave exactly
like dashboard-created ones (same events, same signatures — see
[webhooks](webhooks.md)).

| Method | Path | Ability |
|---|---|---|
| GET | `/api/v1/webhook-endpoints` | `webhooks:read` |
| POST | `/api/v1/webhook-endpoints` | `webhooks:write` |
| DELETE | `/api/v1/webhook-endpoints/{id}` | `webhooks:write` |

`POST` takes `{ "url": "https://…", "events": ["order.created", …] }` — the
URL must be public HTTPS (private, loopback and metadata addresses are
rejected), and every event name must be in the subscribable list (see
[webhook events](webhooks-events.md)).

### Vertical resources

A store whose `store_type` is not `restaurant` (see above) carries catalog
concepts a restaurant doesn't. Read-only endpoints expose them, each nested
under the restaurant (or, for variants, under one of its menu items) the
same way the endpoints above are: brands (a store's brand list — retail,
cosmetic, clothing, carparts, pharmacy), product variants (the size/colour
matrix on one item, each with its own SKU, stock quantity and barcode),
shipments (parcel/tracking status for physical-goods orders), and vehicle
fitments (the Year/Make/Model compatibility rows on a car-parts item, with
optional item/year/make/model filters). Each uses the same ability as the
resource it belongs to (`menu:read` for brands/variants/fitments,
`orders:read` for shipments) plus the **`multi_vertical`** feature (see
[Feature gates](#feature-gates)) — a restaurant token never sees them since a
restaurant has none of this data. **Product variants are also writable**:
`POST /api/v1/stores/{id}/menu/items/{itemId}/variants` creates one (body:
`options` axis map + `price`, optional `sku`/`barcode`/`stock_qty`), and
`PUT`/`DELETE` on `.../variants/{variantId}` edit or remove it — all with
`menu:write` + `multi_vertical`. Exact paths for the rest are still being
finalized as this surface rolls out; watch the self-describing `GET /api/v1`
index and `GET /api/v1/openapi.json` for the live, authoritative list.

## Feature gates

Some endpoints need more than an ability: the **token owner's plan** must
include the feature. The gate runs *after* token auth, so the entitlement
subject is always the token owner, and on an API request the refusal is a
JSON `403` — never a redirect, never a silently truncated list.

| Feature | Endpoints |
|---|---|
| `crm_suite` | `GET /api/v1/stores/{id}/customers` |
| `driver_app` | all of `/api/v1/drivers*`, `GET /stores/{id}/deliveries`, `POST /stores/{id}/orders/{orderId}/assign`, `PUT /stores/{id}/orders/{orderId}/delivery-status` |
| `multi_vertical` | the brands/variants/shipments/vehicle-fitments read endpoints (see [Vertical resources](#vertical-resources)) |

Refusal body:

```json
{ "error": "This feature is not included in your current plan.", "feature": "driver_app" }
```

A token with the right ability but the wrong plan is a `403` you cannot fix
by re-minting the token — the plan has to change. Note the same `driver_app`
gate also guards the driver-facing `/api/v1/driver/*` group, so losing the
feature stops tokens already in drivers' phones too (see
[Driver API](driver-api.md)).

### MCP server

`POST /mcp` (JSON-RPC) accepts the same bearer tokens. Read tools need `read`
coverage; mutating tools require the token's write ability. See
[connect an editor](../mcp/connect-editor.md).

## Errors

- **401 Unauthenticated** — no token sent, unknown token, or an expired
  token. Body: `{"error": "Unauthenticated."}` or
  `{"error": "Invalid or expired token."}`.
- **403 missing ability** — the token is valid but was not granted the scope
  the route requires. Body:
  `{"error": "This token lacks the 'orders:write' ability."}`. Fix by
  minting a token with the right abilities — abilities on an existing token
  cannot be edited.
- **404** — the resource exists but belongs to another tenant, or does not
  exist. Cross-tenant access is indistinguishable from not-found by design.

## Rate limits

- Owner API (`/api/v1/...`): 120 requests per minute.
- MCP server (`POST /mcp`): 60 requests per minute.

Exceeding a limit returns HTTP 429; back off and retry after the window.

## Related pages

- [API tokens and abilities](auth-tokens.md) — token hygiene quick reference.
- [Webhooks](webhooks.md) — outbound signed events (`order.created`,
  `order.status_changed`, `order.paid`, `menu.updated`,
  `entitlement.changed`).
- [Storefront API](storefront.md) — public read-only menu tokens.
- [Customer orders API](customer-orders.md) — end-customer ordering tokens.
