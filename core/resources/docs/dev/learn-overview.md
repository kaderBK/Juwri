---
title: "Platform overview for AI agents"
audience: developer
group: Dev
updated: 2026-07-09
---

# Platform overview for AI agents

This platform is a multi-tenant, multi-vertical commerce SaaS. Each store gets a
public storefront page (`/{slug}`) rendered by a theme, plus QR/catalog pages,
ordering, and a REST API. A store's `store_type` selects its vertical —
`restaurant` (the default and historical baseline), `retail`, `cosmetic`,
`clothing`, `carparts`, `laundry`, `pharmacy`, or `hotel` — so the platform sells
food, goods, services, and stays, not just restaurant menus. Order fulfillment
modes span the food set (`takeaway`, `on-table`, `delivery`, `room_service`) and
the non-food set (`ship`, `pickup_slot`, `appointment`, `at_home`, `in_store`); a store only
accepts the modes its vertical allows.

Several developer surfaces exist. Pick the one that matches your task, then call
`search_docs` before answering any platform question from memory — the corpus
and the generated reference are authoritative; your training data is not.

## Surfaces

- **Liquid themes** — build storefront themes (sections, snippets, manifest,
  settings schema). Start with the `learn_platform` topic `themes`, then
  `get_liquid_reference` for the exact whitelist of drops/filters/tags and
  `get_section_schema` for the `{% schema %}` format. Validate every file with
  `validate_theme` before delivering it.
- **Storefront API** (public, read) — browse a restaurant's menu. Header
  `X-Storefront-Token: <token>` (scope `menu:read`), per-restaurant token,
  safe to embed.
- **Customer API** (ordering) — register/login/OTP for a Bearer customer token,
  then place orders. Prices are always computed server-side from the menu —
  never send prices.
- **Owner/Admin API** — OAuth-authenticated endpoints for managing restaurants,
  menus, orders, customers, and analytics
  (`/api/v1/stores/{id}/…`).
- **Driver API** — driver-scoped endpoints for delivery management: profile
  (`/api/v1/driver/me`), assigned deliveries, status updates, location push.
- **Staff API** — staff login via PIN or credentials, then order management,
  KDS bump/recall, and menu edits (`/api/v1/staff/…`).
- **Reseller API** — reseller-scoped stats, customer list, and invoices
  (`/api/v1/reseller/…`).
- **MCP servers** — five Model Context Protocol servers for AI-agent
  integration: Dev (public docs/validation), Catalog (public store search),
  Storefront (agent ordering), Customer (customer-scoped), Owner/Admin
  (restaurant management). See the [MCP servers overview](mcp/mcp-servers.md).

## Key endpoints

- `GET /api/v1/storefront/menu` — full menu: categories → items (id, name,
  price, variants[], extras[], variant_groups[], gallery[], flags).
- `GET /api/v1/storefront/restaurant` — restaurant info.
- `POST /api/v1/customer/orders` — place an order. Body:
  `{"type":"<fulfillment mode>","client_uuid":"<uuid>","items":[{"item_id":N,"quantity":N,"variation":N?,"extras":[N]}]}`.
  `type` is the store's fulfillment mode — food stores take `takeaway` /
  `on-table` / `delivery` / `room_service`, non-food stores `ship` /
  `pickup_slot` / `appointment` / `at_home` / `in_store` (only the modes the store's
  `store_type` allows). `client_uuid` makes retries idempotent.
- `GET /api/v1/track/{track_token}` — order status.

## Rules that agents get wrong

- Rate limit: 120 requests/min per API token; this dev MCP endpoint is 60/min.
- Variant (`variation`) and extra ids come from the `/menu` payload — never
  invent them.
- Anything not present in `get_liquid_reference` output does not exist in the
  theme engine, no matter what Shopify Liquid supports.

## Where things live

- Human docs: `/developers` (API) and `/developers/themes` (theme format).
- Machine summary: `/llms.txt`.
- Starter theme download: linked from `/developers/themes`.
