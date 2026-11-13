# Developer docs

Technical reference for building on the platform: the REST APIs, Liquid
marketplace themes, the app platform, and the Model Context Protocol servers.
The platform is a multi-tenant restaurant menu and online-ordering SaaS; each
restaurant gets a public storefront rendered by a theme, QR menus, ordering,
and a versioned REST API under `/api/v1`. These pages are precise and
code-grounded — every route, limit, and field is drawn from the source, and
anything specced but not yet built is called out. For task-based guides aimed
at restaurant owners see the users docs; this pillar assumes you write code.

## Orientation

- [Platform overview for AI agents](learn-overview.md) — the three developer
  surfaces (APIs, themes, MCP) and how to pick the one your task needs.
- [Storefront & Customer APIs](learn-storefront-api.md) — the two public token
  types, their trust levels, and how read (storefront) and write (customer)
  split.
- [Building Liquid themes](learn-themes.md) — the closed-whitelist Liquid
  engine, package layout, and what the reference tools expose.

## REST APIs

- [Admin API reference](api/admin-api.md) — the tenant-scoped owner API:
  personal-token auth, abilities, endpoints, MCP server, errors, rate limits.
- [API tokens & abilities](api/auth-tokens.md) — creating a personal token in
  the dashboard, the ability list, and one-time secret display.
- [Storefront API (public read)](api/storefront.md) — per-restaurant,
  read-only menu browsing with the `X-Storefront-Token` header.
- [Storefront API cookbook](api/storefront-cookbook.md) — practical recipes:
  authenticate, render a menu, poll availability, hand off to ordering.
- [Customer API — placing orders](api/customer-orders.md) — bearer-token
  customer auth (including phone OTP) and the order-placement flow.
- [Webhooks](api/webhooks.md) — subscribing to real-time events from the
  queued, retrying delivery pipeline.
- [Webhook events catalog](api/webhooks-events.md) — every outbound event,
  its payload shape, signature verification, and retry behaviour.
- [Errors and rate limits](api/errors-rate-limits.md) — the shared error
  envelope, HTTP status codes, validation errors, throttles, and CSRF.
- [Staff API](api/staff-api.md) — staff auth (PIN / credentials), orders, KDS,
  menu management.
- [Driver API](api/driver-api.md) — driver-scoped delivery management.

## Themes

- [Theme development quickstart](themes/quickstart.md) — zero to a submittable
  theme: scaffold, write a section, wire settings, validate, package, submit.
- [Theme package structure](themes/structure.md) — the fixed zip layout and
  the rules the validator enforces before extraction.
- [Sections, snippets and templates](themes/sections.md) — the render model,
  section instances, and fail-safe rendering.
- [Section schema & settings](themes/schema-settings.md) — the `{% schema %}`
  JSON block, how settings reach the template, and the v2 merchant-customizer
  contract (canonical keys, versionless `mp:<slug>` persistence).
- [Custom Liquid tags](themes/tags.md) — the generated whitelist of custom
  tags, including `{% commerce %}`.
- [Liquid filters](themes/filters.md) — the generated theme-filter whitelist
  layered on standard Liquid core filters.
- [Liquid drops (objects)](themes/drops.md) — the generated object reference;
  properties listed are exactly what a template can touch.
- [Theme commerce runtime](themes/commerce.md) — how the platform-owned
  commerce bundle wires cart, checkout, ordering, and accounts into a theme.
- [Theme validation](themes/validation.md) — the package (security) and lint
  layers, runnable locally with the `validate_theme` MCP tool.
- [Submitting a theme to the marketplace](themes/submission.md) — the submit,
  gate, and review flow.

## Apps

- [App platform](apps/app-platform.md) — publishing a third-party app that
  owners install: OAuth2 scopes, install lifecycle, embedded sessions, billing.

## MCP servers

- [Connect your AI editor](mcp/connect-editor.md) — point Claude Code, Cursor,
  or Copilot at the Dev MCP server for live docs and validation.
- [Dev MCP server tools](mcp/dev-mcp-tools.md) — the six read-only tools at
  `POST /mcp/dev`, their limits, and the drift-pinned reference.
- [MCP servers overview](mcp/mcp-servers.md) — all five MCP servers, their
  tools, and auth requirements.
- **Catalog MCP** (`POST /mcp/catalog`) — public, unauthenticated catalog
  search (stores and dishes across the platform).
- **Customer MCP** (`POST /mcp/customer`) — customer-facing tools, customer-token
  auth.
- **Storefront MCP** (`POST /mcp/storefront`) — agent/storefront-scoped tools,
  storefront-token auth (`agent` scope).
- **Owner/Admin MCP** (`POST /mcp`) — owner data tools, personal-token auth (see
  [Admin API](api/admin-api.md)).

## Coverage gaps

These are known gaps between what the docs describe and what is wired today.

- Theme submission has no self-service author upload or submit endpoint. The
  package validator and submission service are built, but the only route is an
  admin-only review queue; no public author-facing entry point or contact
  channel is documented. See [submission](themes/submission.md).
- The app platform has no developer-facing registration UI and no
  admin/superadmin review screen. App rows and their status transitions
  (draft, review, approved, rejected, delisted) are managed at the data layer
  only — planned, not yet available. See [app platform](apps/app-platform.md).
- Storefront token issuance is backend-route-only. The polished dashboard panel
  for owners to mint storefront tokens is a deferred follow-up. See the
  [cookbook](api/storefront-cookbook.md).
- Client-side commerce-runtime specifics (exact localStorage key, poll
  intervals, and the online-payment path in v1) are grounded in the spec, not
  tracked code: the runtime `commerce.js` bundle is a build artifact and is not
  in the source tree. Server-side config and endpoints are verified. See
  [commerce runtime](themes/commerce.md).
- API rate limiting composes two throttles (the shared `api` limiter plus an
  inline per-route throttle); the effective ceiling is inferred from middleware
  order, not load-tested. `config/api.php` is absent, so `storefront_rate`
  falls back to the hardcoded 120/min default a deployment could override. See
  [errors and rate limits](api/errors-rate-limits.md).
