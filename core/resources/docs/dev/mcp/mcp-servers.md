---
title: "MCP servers overview"
audience: developer
group: Mcp
updated: 2026-09-10
---

# MCP servers overview

The platform exposes five Model Context Protocol (MCP) servers, each with a
distinct trust model and tool set. All use JSON-RPC 2.0 over HTTP POST (MCP
Streamable HTTP transport), support batch requests, and handle `initialize`,
`ping`, `tools/list`, and `tools/call`.

The `serverInfo.name` values below are the platform defaults. On a
white-label (reseller) domain each server advertises the reseller's brand
slug instead — e.g. `<reseller-brand>-dev-mcp` — so clients connecting via a
reseller host never see the platform name.

## Server summary

| Server | Endpoint | Auth | Rate limit | Tools |
|--------|----------|------|------------|-------|
| [Admin](#admin-mcp) | `POST /mcp` | API token (bearer) | 60/min per IP | 23 |
| [Storefront](#storefront-mcp) | `POST /mcp/storefront` | Storefront token (`agent` scope) | 120/min per token, capped by 60/min per IP | 12 |
| [Catalog](#catalog-mcp) | `POST /mcp/catalog` | None (public) | 60/min per IP | 3 |
| [Customer](#customer-mcp) | `POST /mcp/customer` | Customer token (bearer) | 60/min per IP | 7 |
| [Dev](#dev-mcp) | `POST /mcp/dev` | None (public) | 60/min per IP | 6 |

The Storefront server is the exception: it runs inside the `api` middleware
group, so it carries the shared 60/min-per-IP api limiter **in addition to**
its own 120/min-per-token `storefront` limiter. A request must pass both, so
a single-IP agent tops out at 60/min. See
[Errors and rate limits](../api/errors-rate-limits.md).

---

## Admin MCP

**Endpoint**: `POST /mcp`
**Auth**: API token (same bearer tokens as the REST API). Read tools need
`read` coverage; mutating tools require the matching `write` ability.
**Server name**: `juwri-mcp`

The owner's full management surface — everything the REST API can do, exposed
as MCP tools for AI assistants. All data is scoped to the token owner's
restaurants.

### Tools

| Tool | Ability required | Description |
|------|-----------------|-------------|
| `list_restaurants` | — | List the restaurants (or other vertical stores) you own, each with `store_type` and a vertical summary. |
| `get_menu` | — | Get a restaurant's categories and items. |
| `list_orders` | `orders:read` | List orders (filter by status, date range, search). |
| `get_order` | `orders:read` | Full detail for one order (line items, totals). |
| `get_analytics` | — | Order count and revenue over N days. |
| `update_order_status` | `write` | Advance an order's status. |
| `add_menu_item` | `write` | Create a menu item. |
| `update_menu_item` | `write` | Update a menu item's name/price/description. |
| `list_categories` | `menu:read` | List menu categories (id, name, position). |
| `add_category` | `menu:write` | Create a menu category. |
| `update_category` | `menu:write` | Rename or reorder a category. |
| `delete_category` | `menu:write` | Delete an empty category (refuses if items remain; optional `mode` `cascade`/`reparent` handles subcategories). |
| `delete_menu_item` | `menu:write` | Permanently delete a menu item. |
| `set_item_availability` | `menu:write` | Toggle sold-out (86) status. |
| `list_customers` | `customers:read` + `crm_suite` | List CRM customers with search. |
| `get_customer` | `customers:read` + `crm_suite` | Full CRM record for one customer. |
| `sales_report` | `analytics:read` | Revenue, order counts, and top items for a date range. |
| `get_restaurant_settings` | `restaurants:read` | Profile and ordering config, including `store_type` and a vertical summary (read-only). |
| `update_business_hours` | `restaurants:write` | Set business-hours text. |
| `list_coupons` | `orders:read` | List discount coupons. |
| `create_coupon` | `orders:write` | Create a food-order discount coupon. |
| `update_coupon` | `orders:write` | Edit an existing coupon. |
| `delete_coupon` | `orders:write` | Delete a coupon (irreversible). |
| `list_brands` | `menu:read` + `multi_vertical` | List a store's brands (retail, cosmetic, clothing, car-parts, pharmacy). |
| `list_variants` | `menu:read` + `multi_vertical` | List one item's size/colour variant matrix (SKU, barcode, stock per combination). |
| `list_shipments` | `orders:read` + `multi_vertical` | List shipment/tracking status for physical-goods orders. |
| `list_fitments` | `menu:read` + `multi_vertical` | List vehicle Year/Make/Model fitment rows for a car-parts item. |
| `list_expiring_items` | `menu:read` + `multi_vertical` | Batch/expiry compliance read for a pharmacy (OTC-only) or other expiry-tracked store. |
| `add_variant` | `write` + `multi_vertical` | Add a size/colour variant (an `options` axis map + price, optional sku/barcode/stock_qty) to a goods item. |
| `update_variant` | `write` + `multi_vertical` | Update a variant by `variant_id` — price, sku, barcode, stock_qty, active. |
| `delete_variant` | `write` + `multi_vertical` | Delete a variant by `variant_id`. |

That is 31 tools — but `tools/list` is filtered per request: a tool whose
ability the token lacks, or whose entitlement the tenant lacks, is simply
absent from the list. Never hardcode the count.

`list_customers` and `get_customer` additionally require the store owner's
`crm_suite` entitlement. Without it they are **hidden from `tools/list`**, and
a `tools/call` is refused with JSON-RPC error `-32003` (HTTP 403) before the
handler runs.

The vertical tools all require the store owner's **`multi_vertical`**
entitlement, gated the exact same way as `crm_suite` above (hidden from
`tools/list`, `-32003` on a direct call without it). Five are read-only
(`list_brands`, `list_variants`, `list_shipments`, `list_fitments`,
`list_expiring_items`); three write a goods item's variant matrix
(`add_variant`, `update_variant`, `delete_variant` — these also need the
token's `write` ability, like the other mutating tools). They exist so an agent working with a non-restaurant store
(retail, cosmetic, clothing, car parts, laundry, or pharmacy — see
`list_restaurants`'/`get_restaurant_settings`'s `store_type` field) can read
that vertical's own catalog concepts; a restaurant store simply has none of
this data, so these tools are additive no-ops for the legacy restaurant
fleet. The reads never mutate, and `list_expiring_items` in particular
never returns prescription, pharmacist, controlled-substance, or patient
data, only an item's own batch number/expiry date (this platform is
OTC-only for pharmacy).

See [admin-api.md](../api/admin-api.md) for the REST equivalent, ability
details, and its [Vertical resources](../api/admin-api.md#vertical-resources)
section.

---

## Storefront MCP

**Endpoint**: `POST /mcp/storefront`
**Auth**: Storefront token with the `agent` scope (`X-Storefront-Token`
header). Restaurant is resolved from the token — never from the client.
**Server name**: `juwri-storefront-mcp`

The customer/agent-facing ordering surface — the same tools the chat widget
uses. An AI agent can browse the menu, validate a cart, place an order, and
track it. Tool calls are logged under an MCP agent conversation for audit.

### Tools

Required arguments are **bold**; the rest are optional.

| Tool | Arguments | Description |
|------|-----------|-------------|
| `search_menu` | **`query`** (string), `filters` (object: vegan / halal / max_price) | Search the menu by name/description with dietary/price filters. |
| `get_item` | **`item_id`** (int) | Full details for one menu item (variants, extras). |
| `validate_cart` | **`items`** (array of cart items), `coupon_code` (string) | Server-side price and availability check; optional coupon preview. |
| `create_order` | **`items`** (array of cart items), `type` (`takeaway` \| `on-table`), `customer_name`, `phone`, `coupon_code`, `scheduled_at` | Place an unpaid order. |
| `order_status` | **`track_token`** (string) | Status of an order by its track token. |
| `restaurant_info` | — | Restaurant name, order types, and payment info. |
| `order_history` | **`phone`** (string), `limit` (int 1–10) | A returning customer's recent orders (matched by phone). |
| `store_credit_balance` | **`phone`** (string) | A customer's wallet balance (matched by phone). Read-only — credit cannot be spent here. |
| `reorder_last` | **`phone`** (string) | Rebuild the customer's last order as a cart draft. |
| `order_eta` | — | Estimated prep wait based on live kitchen load. |
| `suggest_upsell` | `cart_item_ids` (array of int) | Recommend up to 3 add-on items for a cart in progress. |
| `dietary_answer` | **`item_id`** (int), **`tag`** (string: `vegan`, `halal`, `vegetarian`, `gluten_free`, …) | Whether an item satisfies a dietary tag (`yes` / `no` / `unknown`). |

A **cart item** (used by `validate_cart` and `create_order`) is an object
with **`item_id`** (int) and **`quantity`** (int ≥ 1) required, plus optional
`variation` (int) and `extras` (array of int). All ids must come from
`search_menu` / `get_item` — never invented.

### `create_order` in detail

`items` is the only required argument. Notes on the rest:

- `type` — enum, `takeaway` or `on-table`. Delivery is not orderable through
  this surface.
- `scheduled_at` — `"YYYY-MM-DD HH:MM"` in the **restaurant's local time**,
  requests a pre-order; validated against the restaurant's scheduling rules
  and rejected if invalid.
- `coupon_code` — server-priced; the response carries `discount` and
  `coupon_applied`.
- Orders are created **UNPAID** — the customer pays on arrival. Prices are
  always computed server-side; never send money values.
- Store credit is only spent when the merchant enabled agent credit-spend
  **and** the diner is identified by a verified sender phone. The amount used
  comes back as `wallet_applied` — it is server-decided and cannot be forced.

---

## Catalog MCP

**Endpoint**: `POST /mcp/catalog`
**Auth**: None (public, read-only).
**Server name**: `juwri-catalog-mcp`

Platform-wide restaurant and dish discovery — lets an AI agent find stores and
dishes across the whole platform, then deep-link into a store's Storefront MCP
to order. Only active, publicly listed stores are returned; owner PII is never
emitted.

### Tools

| Tool | Description |
|------|-------------|
| `search_stores` | Find restaurants by keyword and/or city (up to 20 results). |
| `search_items` | Find dishes across all restaurants with dietary/price filters (up to 30). |
| `get_store` | Full public detail for one store by slug or id. |

---

## Customer MCP

**Endpoint**: `POST /mcp/customer`
**Auth**: Customer token (bearer, issued by the customer OTP/login flow).
Customer identity comes only from the token — one customer can never read
another's data.
**Server name**: `juwri-customer-mcp`

A diner's personal AI assistant surface — read their own orders, track
deliveries, and rebuild past orders.

### Tools

| Tool | Description |
|------|-------------|
| `my_orders` | Your recent orders (most recent first, up to 50). |
| `order_detail` | Full detail for one of your orders (line items, delivery info). |
| `track_order` | Live status of an order by id or track token. |
| `reorder` | Rebuild a past order as a cart draft. |
| `my_profile` | Your profile on file (name, phone, order count). |
| `my_bookings` | Your hotel room bookings at this venue (when it runs the hotel module). |
| `my_gift_cards` | Gift cards you purchased here — balance, status, and a Google Wallet save-link when Wallet is enabled. |

---

## Dev MCP

**Endpoint**: `POST /mcp/dev`
**Auth**: None (public, read-only).
**Server name**: `juwri-dev-mcp`

Developer documentation and theme validation for AI coding tools. Serves the
same information published on `/developers` and `/llms.txt`. No tenant data,
ever.

### Tools

| Tool | Description |
|------|-------------|
| `learn_platform` | Platform primer and routing to the right tool. |
| `get_liquid_reference` | The generated, authoritative Liquid whitelist. |
| `get_section_schema` | `{% schema %}` format and setting types. |
| `search_docs` | Keyword search across the developer docs corpus. |
| `fetch_full_doc` | Fetch one complete doc by corpus path. |
| `validate_theme` | Server-side lint of theme files before submission. |

See [dev-mcp-tools.md](dev-mcp-tools.md) for full tool documentation and
[connect-editor.md](connect-editor.md) for editor setup.

---

## Protocol notes

- All servers default to MCP protocol version `2025-06-18` and retain
  `2025-03-26` and `2024-11-05` for negotiated legacy clients. Send the desired
  `protocolVersion` in `initialize`; the server echoes it when supported and
  otherwise selects `2025-06-18`.
- After initialization, send `MCP-Protocol-Version: <negotiated-version>` on
  each POST. An absent header is accepted for compatibility (per the protocol,
  it is treated as `2025-03-26`); an unsupported value returns HTTP 400.
- Clients should send
  `Accept: application/json, text/event-stream`. This deployment responds with
  one JSON payload per POST and does not expose an SSE stream. `GET` is
  explicitly rejected with HTTP 405 and `Allow: POST`.
- Standard methods: `initialize`, `ping`, `tools/list`, `tools/call`. Batch
  requests (a JSON array body) are handled.
- Accepted notifications (messages without an `id`) return HTTP 202 with no
  body. A notification-only batch also returns 202; a mixed batch returns the
  JSON-RPC responses for messages that have ids.
- Non-browser clients normally omit `Origin`. When `Origin` is present, it
  must exactly match the request origin or one of the comma-separated origins
  in `MCP_ALLOWED_ORIGINS` (scheme, host, and port). Other origins return HTTP
  403. Do not use wildcard origins.
- Error codes follow JSON-RPC conventions, plus a few application codes:

  | Code | Meaning |
  |---|---|
  | `-32601` | Method not found |
  | `-32602` | Invalid params |
  | `-32603` | Internal error |
  | `-32001` | Token lacks the required ability |
  | `-32003` | Not entitled — the plan does not grant this tool (also answers HTTP **403**, unlike every other code, which stays 200) |
  | `-32004` | Not found (or belongs to another tenant) |
  | `-32005` | Plan limit reached (e.g. the menu-item cap) |
  | `-32006` | Refused on a business rule (e.g. deleting a category that still has items) |

  A mixed batch stays HTTP 200 — it can contain refused and served messages,
  so read each message's `error` rather than the status code.
- The five exact POST endpoints are CSRF-exempt because they use token or
  public MCP trust models rather than browser sessions.

## Related pages

- [Connect your AI editor](connect-editor.md) — endpoint configs for Claude
  Code, Cursor, and VS Code.
- [Dev MCP tools](dev-mcp-tools.md) — detailed tool reference for the Dev
  server.
- [Admin API](../api/admin-api.md) — the REST API that mirrors the Admin MCP.
- [Storefront API](../api/storefront.md) — the REST equivalent of Storefront
  MCP tools.
- [API tokens and abilities](../api/auth-tokens.md) — token types and scopes.
