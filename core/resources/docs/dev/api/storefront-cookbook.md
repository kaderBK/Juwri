---
title: "Storefront API cookbook"
audience: developer
group: Api
updated: 2026-07-29
---

# Storefront API cookbook

Practical recipes for building a client against the public Storefront API:
render a menu, keep availability fresh, handle tokens, and avoid the common
mistakes. For the endpoint list and field summary see the
[Storefront API reference](storefront.md).

The Storefront API serves one restaurant's live menu and accepts orders,
resolved entirely from the token, so a client never sends (and can never reach)
another restaurant's id.

## Before you start: get a token

A storefront token is per-restaurant. The owner mints one from their account
(server route `POST /api/storefront-tokens`, name `storefront.token.create`),
supplying `store_id` (the restaurant), an optional `label`, `scopes`, and
`allowed_origins`. The raw token is shown once and stored only as a SHA-256
hash — copy it immediately; it cannot be retrieved again. Revoke with
`DELETE /api/storefront-tokens/{id}`.

Scopes are `menu:read` (default), `order:write`, and `agent`. The two public
storefront endpoints below both require only `menu:read`, and a `:write` scope
implicitly grants the matching `:read`.

Note: token issuance is currently a backend route only — the polished dashboard
panel for managing tokens is a deferred follow-up, so owners may need help
generating one.

## Recipe: authenticate a request

Send the raw token in the `X-Storefront-Token` header:

```
GET /api/v1/storefront/menu HTTP/1.1
Host: your-restaurant.example.com
X-Storefront-Token: <your-storefront-token>
```

Query strings leak tokens into access logs, browser history, and referrers.
Production is header-only (`TOKEN_QUERY_FALLBACK=false`). A deprecated
`storefront_token` query fallback can be enabled temporarily in local/testing
for legacy clients; responses carry deprecation headers and the usage is
logged. Do not design a new integration around it.

The restaurant is derived from the token. There is no restaurant id in the URL,
which is why the same token can only ever return its own menu.

## Recipe: render a menu

`GET /api/v1/storefront/menu` returns categories, each with its in-stock items:

```json
{
  "store": { "id": 42, "title": "Cafe Nour", "slug": "cafe-nour" },
  "categories": [
    {
      "id": 7,
      "name": "Mains",
      "items": [
        {
          "id": 314,
          "name": "Lamb Kofta",
          "description": "Charcoal grilled",
          "price": 18.0,
          "image": "https://.../storage/menu/kofta.jpg",
          "gallery": ["https://.../storage/menu/kofta-2.jpg"],
          "is_popular": true,
          "is_vegan": false,
          "is_halal": true,
          "is_gluten_free": false,
          "calories": 640,
          "variants": [
            { "id": 90, "price": 22.0, "options": { "size": "Large" } }
          ],
          "variant_groups": [
            {
              "id": 5,
              "title": "Spice level",
              "options": ["Mild", "Hot"],
              "required": true,
              "min_select": 1,
              "max_select": 1,
              "option_prices": []
            }
          ],
          "extras": [
            { "id": 12, "title": "Extra sauce", "price": 1.5, "image": null }
          ]
        }
      ]
    }
  ]
}
```

Rendering notes:

- `price`, `variants[].price`, and `extras[].price` are floats for display only.
  Never sum them client-side and send a total back — the server reprices at
  order time and ignores any client total.
- `variants[]` are pre-priced combinations; `variant_groups[]` are choice groups
  with `required`, `min_select`, and `max_select` selection rules to enforce in
  your UI. `extras[]` are add-ons.
- Keep the `id` of every item, variant, variant-group option, and extra — these
  are the only ids the ordering API accepts.
- `image` and `gallery[]` are absolute URLs (null when unset).

For restaurant name, description, phone, and address, call
`GET /api/v1/storefront/restaurant`.

## Recipe: poll for availability

The menu payload is already filtered to what a customer can order right now:

- Inactive categories are omitted.
- Inactive items are omitted.
- Sold-out items are omitted (they do not appear with a flag — they simply drop
  out of their category's `items`).

There is no separate availability endpoint and no per-item "in stock" boolean.
To keep a long-lived menu view fresh, re-fetch `GET /api/v1/storefront/menu`
periodically and diff by `id`: an id that disappeared is now unavailable, and a
returning id is back. A poll interval of 30–60 seconds is plenty for most
storefronts and stays well under the rate limit.

Diet and merchandising flags (`is_popular`, `is_vegan`, `is_halal`,
`is_gluten_free`) are attributes of the item, not availability signals.

## Recipe: place an order via the Storefront API

A storefront token with the `order:write` scope can place orders directly via
`POST /api/v1/storefront/orders`. This is the recommended path for headless
storefronts and AI agents — no customer login is needed.

```
POST /api/v1/storefront/orders HTTP/1.1
Host: your-restaurant.example.com
X-Storefront-Token: <your-storefront-token>
Content-Type: application/json
```

```json
{
  "type": "takeaway",
  "items": [
    { "item_id": 314, "quantity": 1, "variation": 90, "extras": [12] }
  ]
}
```

- The token must carry the `order:write` scope (403 otherwise).
- `item_id` is the menu item `id`; `variation` is a `variants[].id`; `extras[]`
  are `extras[].id`. Sending ids that are not present in the menu payload is
  rejected.
- The server prices the order from these ids — omit any money fields.
- For write scopes the middleware enforces the token's `allowed_origins` list
  when an `Origin` header is present. Configure origins when minting the token.

## Alternative: hand off to the Customer API

If your flow has an authenticated end-customer, ordering can also go through the
Customer API under a per-customer token (`POST /api/v1/customer/orders`). Carry
the ids straight from the menu payload — the request body is the same.

See the [Customer orders reference](customer-orders.md) for authentication and
the full order body.

## Handling tokens and rate limits

- 401 — missing, invalid, or revoked token. The response is
  `{"message": "...", "code": "unauthenticated"}`. Do not retry; the token needs replacing.
- 403 — the token is valid but lacks the required scope, or the restaurant's
  plan does not include the storefront API. Also do not retry blindly.
- 429 — rate limited. The limit is 120 requests per minute, counted **per
  token** (not per IP), so multiple clients sharing one token share one budget.
  Back off exponentially and honour the `Retry-After` header.

Because the limit is per token, give each independent integration its own token
rather than reusing one — that also lets the owner revoke one without breaking
the others. See [Errors and rate limits](errors-rate-limits.md) for the shared
error conventions.

## Common pitfalls

- Doing price math on the client. Prices are display values; the server is the
  source of truth and recomputes at order time.
- Reusing one token across many clients, then hitting 429 collectively. Mint a
  token per integration.
- Treating a missing item as an error. A dropped id usually means sold out or
  deactivated, not a failure — reconcile against the latest menu.
- Forgetting `order:write` scope. The `POST /api/v1/storefront/orders` endpoint
  requires a token with the `order:write` scope — a default `menu:read` token
  will get a 403.
- Passing the token in the query string in production. Prefer the
  `X-Storefront-Token` header so it stays out of logs.
- Sending a restaurant id. The API does not accept one — the token decides the
  restaurant.
