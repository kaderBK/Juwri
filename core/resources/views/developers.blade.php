{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Public REST API + webhooks + MCP reference.

     EXTENDS the marketing frame (marketing.layout) so it carries the exact site
     nav + footer + fonts + theme toggle + language picker (one design language
     with the home). All doc styles are namespaced under a scoped `.dev-docs`
     wrapper (dd-* classes). SEO meta is pushed into the layout's yields. --}}
@extends('marketing.layout')

@section('title', $siteName.' — API & Webhooks Documentation')
@section('meta_description', 'Connect Claude Code or Cursor to our Dev MCP: live docs, the real Liquid whitelist, and instant theme validation. No token. No hallucinations. Plus the full REST API and signed webhooks reference.')
@section('canonical', route('developers'))

@push('styles')
<style nonce="{{ csp_nonce() }}">
    .dev-docs {
        --dd-text:#0a0910; --dd-muted:#6c6b70; --dd-acc:#8b3dff;
        --dd-panel:#f5f4f7; --dd-panel-2:#efeef2; --dd-border:#e7e6ea;
        --dd-green:#1f9d4d; --dd-blue:#2563eb; --dd-amber:#b7791f; --dd-radius:12px;
        --mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
        color:var(--dd-text);
    }
    :root[data-theme="dark"] .dev-docs {
        --dd-text:#e6edf3; --dd-muted:#9aa7b4;
        --dd-panel:#161b22; --dd-panel-2:#1b222c; --dd-border:#2a323d;
        --dd-green:#3fb950; --dd-blue:#58a6ff; --dd-amber:#f5a623;
    }
    .dev-docs a { color:var(--dd-acc); text-decoration:none; }
    .dev-docs a:hover { text-decoration:underline; }
    .dev-docs .wrap { max-width:980px; margin:0 auto; padding:0 24px; }
    .dev-docs .hero { padding:24px 0 8px; }
    .dev-docs .hero h1 { font-size:40px; line-height:1.1; margin:0 0 12px; letter-spacing:-.02em; color:var(--dd-text); }
    .dev-docs .hero p { color:var(--dd-muted); font-size:18px; max-width:640px; margin:0; }
    .dev-docs .dd-pill { display:inline-block; font-family:var(--mono); font-size:12px; padding:3px 8px; border-radius:999px; border:1px solid var(--dd-border); background:var(--dd-panel-2); color:var(--dd-muted); }
    .dev-docs .layout { display:grid; grid-template-columns:220px minmax(0, 1fr); gap:40px; padding:24px 0 96px; }
    .dev-docs .dd-main { min-width:0; }                 /* let code/tables scroll instead of overflowing (RTL cutoff) */
    .dev-docs .dd-main :is(p, li, h2, h3) { overflow-wrap:anywhere; }
    .dev-docs nav.toc { position:sticky; top:100px; align-self:start; font-size:14px; }
    .dev-docs nav.toc a { display:block; color:var(--dd-muted); padding:5px 0; border-inline-start:2px solid transparent; padding-inline-start:12px; }
    .dev-docs nav.toc a:hover { color:var(--dd-text); border-color:var(--dd-acc); text-decoration:none; }
    .dev-docs nav.toc strong { display:block; color:var(--dd-text); margin:16px 0 6px; font-size:12px; text-transform:uppercase; letter-spacing:.08em; }
    .dev-docs section { margin-bottom:48px; scroll-margin-top:100px; }
    .dev-docs h2 { font-size:26px; margin:0 0 12px; letter-spacing:-.01em; color:var(--dd-text); }
    .dev-docs h3 { font-size:18px; margin:28px 0 8px; color:var(--dd-text); }
    .dev-docs p, .dev-docs li { color:var(--dd-muted); }
    .dev-docs code { font-family:var(--mono); font-size:13px; background:var(--dd-panel-2); padding:2px 6px; border-radius:6px; border:1px solid var(--dd-border); color:var(--dd-text); }
    .dev-docs pre { background:var(--dd-panel); border:1px solid var(--dd-border); border-radius:var(--dd-radius); padding:16px 18px; overflow-x:auto; font-family:var(--mono); font-size:13px; line-height:1.55; color:var(--dd-text); }
    .dev-docs pre code { background:none; border:none; padding:0; }
    .dev-docs .method { font-family:var(--mono); font-weight:700; font-size:12px; padding:3px 8px; border-radius:6px; }
    .dev-docs .get { background:rgba(88,166,255,.15); color:var(--dd-blue); }
    .dev-docs .put, .dev-docs .patch { background:rgba(245,166,35,.15); color:var(--dd-amber); }
    .dev-docs .post { background:rgba(63,185,80,.15); color:var(--dd-green); }
    .dev-docs .delete { background:rgba(248,81,73,.15); color:#dc2626; }
    .dev-docs .ep { display:flex; align-items:center; flex-wrap:wrap; gap:8px 10px; font-family:var(--mono); font-size:14px; padding:10px 14px; border:1px solid var(--dd-border); border-radius:10px; background:var(--dd-panel); margin:8px 0; color:var(--dd-text); overflow-wrap:anywhere; }
    .dev-docs .table-wrap { overflow-x:auto; margin:12px 0; }
    .dev-docs table { width:100%; border-collapse:collapse; margin:12px 0; font-size:14px; }
    .dev-docs .table-wrap table { margin:0; }
    .dev-docs th, .dev-docs td { text-align:start; padding:9px 12px; border-bottom:1px solid var(--dd-border); }
    .dev-docs th { color:var(--dd-muted); font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.05em; }
    .dev-docs .note { border-inline-start:3px solid var(--dd-acc); background:var(--dd-panel); padding:12px 16px; border-radius:8px; margin:16px 0; color:var(--dd-text); }
    .dev-docs .dd-btn { display:inline-block; background:var(--dd-acc); color:#fff; font-weight:600; padding:10px 22px; border-radius:800px; }
    .dev-docs .dd-btn:hover { text-decoration:none; opacity:.92; }
    .dev-docs .dd-apifoot { border-top:1px solid var(--dd-border); color:var(--dd-muted); font-size:14px; padding:24px 0 8px; }
    @media (max-width:800px) { .dev-docs .layout { grid-template-columns:1fr; } .dev-docs nav.toc { position:static; } }
</style>
@endpush

@section('content')
<div class="dev-docs">
    <div class="wrap">
        <div class="hero">
            <span class="dd-pill">{{ ___('v1 · REST + Webhooks') }}</span>
            <h1>{{ ___('Developer documentation') }}</h1>
            <p>{{ ___('Build customer, driver, and merchant apps on one API. Read and write restaurants, menus, orders, and drivers; receive real-time events over signed webhooks. Everything is scoped to the token owner.') }}</p>
            <p style="margin-top:14px;">
                <span class="dd-pill">🛍️ {{ ___('Customer app') }}</span>
                <span class="dd-pill">🛵 {{ ___('Driver app') }}</span>
                <span class="dd-pill">🧑‍🍳 {{ ___('Merchant app') }}</span>
            </p>
            <p style="margin-top:14px;">
                {{ ___('Building for the marketplace?') }} <a href="{{ route('developers.apps') }}">{{ ___('App developer docs') }} →</a>
                &nbsp;·&nbsp; <a href="{{ route('developers.themes') }}">{{ ___('Theme developer docs') }} →</a>
            </p>
            <div class="note" style="margin-top:18px;">
                <strong>{{ ___('New:') }}</strong> {{ ___('our') }} <a href="#ai-editor">{{ ___('Dev MCP server') }}</a> {{ ___('turns any MCP-capable AI tool into a platform expert.') }}
                <code>learn_platform</code> {{ ___('primes it,') }} <code>get_liquid_reference</code> {{ ___('gives it the authoritative whitelist, and') }}
                <code>validate_theme</code> {{ ___('runs the marketplace\'s own checks on its output — the full learn → build → validate loop without leaving your editor.') }} <a href="#ai-editor">{{ ___('Connect in one command') }} →</a>
            </div>
        </div>
    </div>

    <div class="wrap layout">
        <nav class="toc">
            <strong>{{ ___('REST API') }}</strong>
            <a href="#start">{{ ___('Getting started') }}</a>
            <a href="#auth">{{ ___('Authentication') }}</a>
            <a href="#limits">{{ ___('Rate limits') }}</a>
            <a href="#errors">{{ ___('Errors') }}</a>
            <a href="#pagination">{{ ___('Pagination') }}</a>
            <strong>{{ ___('Merchant') }}</strong>
            <a href="#read">{{ ___('Read restaurants & menu') }}</a>
            <a href="#menu-crud">{{ ___('Manage menu') }}</a>
            <a href="#orders">{{ ___('Orders') }}</a>
            <a href="#analytics">{{ ___('Analytics & customers') }}</a>
            <a href="#storefront-api">{{ ___('Storefront API') }}</a>
            <strong>{{ ___('Delivery') }}</strong>
            <a href="#drivers">{{ ___('Manage drivers') }}</a>
            <a href="#driver-app">{{ ___('Driver app API') }}</a>
            <strong>{{ ___('Customer') }}</strong>
            <a href="#customer-auth">{{ ___('Customer accounts') }}</a>
            <a href="#customer-app">{{ ___('Customer app API') }}</a>
            <a href="#track">{{ ___('Order tracking') }}</a>
            <strong>{{ ___('Staff') }}</strong>
            <a href="#staff-auth">{{ ___('Staff login') }}</a>
            <a href="#staff-app">{{ ___('Staff app API') }}</a>
            <strong>{{ ___('Webhooks') }}</strong>
            <a href="#wh-setup">{{ ___('Setup') }}</a>
            <a href="#wh-events">{{ ___('Events') }}</a>
            <a href="#wh-payload">{{ ___('Payload') }}</a>
            <a href="#wh-verify">{{ ___('Verify signature') }}</a>
            <a href="#wh-retries">{{ ___('Retries') }}</a>
            <strong>{{ ___('More') }}</strong>
            <a href="#mcp-overview">{{ ___('MCP servers') }}</a>
            <a href="#mcp">{{ ___('MCP server') }}</a>
            <a href="#catalog-mcp">{{ ___('Catalog MCP') }}</a>
            <a href="#customer-mcp">{{ ___('Customer MCP') }}</a>
            <a href="#ai-editor">{{ ___('Connect your AI editor') }}</a>
            <a href="#changelog">{{ ___('Changelog') }}</a>
        </nav>

        <div class="dd-main">
            <section id="start">
                <h2>{{ ___('Getting started') }}</h2>
                <p>{{ ___('Create an API token from your dashboard under') }} <strong>{{ ___('API tokens & webhooks') }}</strong>. {{ ___('Choose the') }} <code>read</code> {{ ___('and/or') }} <code>write</code> {{ ___('abilities and copy the token — it is shown only once.') }}</p>
                <div class="ep"><span class="dd-pill">{{ ___('Base URL') }}</span> <code>{{ $baseUrl }}</code></div>
                <p>{{ ___('A quick check that your token works:') }}</p>
                <pre><code>curl {{ $baseUrl }}/restaurants \
  -H "Authorization: Bearer YOUR_TOKEN"</code></pre>
                <div class="note">{{ ___('The root') }} <a href="{{ $baseUrl }}"><code>GET {{ $baseUrl }}</code></a> {{ ___('returns a machine-readable index of available endpoints (no auth required). Machine-readable') }} <a href="{{ url('/api/v1/openapi.json') }}"><strong>{{ ___('OpenAPI 3.1 spec (JSON)') }}</strong></a> — {{ ___('generated from the live router, so it always matches the deployed API.') }}</div>
            </section>

            <section id="auth">
                <h2>{{ ___('Authentication') }}</h2>
                <p>{{ ___('Send your token as a Bearer header on every request:') }}</p>
                <pre><code>Authorization: Bearer YOUR_TOKEN</code></pre>
                <p>{{ ___('For quick tests you may instead pass') }} <code>?api_token=YOUR_TOKEN</code> {{ ___('as a query parameter, but the header is strongly preferred so tokens never leak into logs.') }}</p>
                <table>
                    <tr><th>{{ ___('Ability') }}</th><th>{{ ___('Grants') }}</th></tr>
                    <tr><td><code>read</code></td><td>{{ ___('All') }} <code>GET</code> {{ ___('endpoints (every resource).') }}</td></tr>
                    <tr><td><code>write</code></td><td>{{ ___('All mutating endpoints (and, being a superset, all reads).') }}</td></tr>
                </table>

                <h3>{{ ___('Scoped tokens') }}</h3>
                <p>{{ ___('Beyond coarse') }} <code>read</code>/<code>write</code>, {{ ___('a token can be limited to specific resources with') }} <code>resource:action</code> {{ ___('abilities. Resources:') }} <code>restaurants, menu, orders, customers, analytics, drivers, webhooks</code>; {{ ___('actions:') }} <code>read</code>, <code>write</code>. {{ ___('Pick them when creating the token in the dashboard.') }}</p>
                <table>
                    <tr><th>{{ ___('Example token') }}</th><th>{{ ___('Can do') }}</th></tr>
                    <tr><td><code>["orders:write"]</code></td><td>{{ ___('Read + write orders only (a POS integration).') }}</td></tr>
                    <tr><td><code>["menu:read"]</code></td><td>{{ ___('Read the menu; nothing else.') }}</td></tr>
                    <tr><td><code>["orders:read","analytics:read"]</code></td><td>{{ ___('A reporting dashboard.') }}</td></tr>
                </table>
                <p>{{ ___('Coverage rules:') }} <code>*</code> {{ ___('grants everything; a') }} <code>:write</code> {{ ___('scope also grants its') }} <code>:read</code>; {{ ___('coarse') }} <code>read</code>/<code>write</code> {{ ___('behave like') }} <code>*:read</code> / <code>*:write</code>. {{ ___('A request lacking the required scope returns') }} <code>403</code>. {{ ___('Legacy') }} <code>read</code>/<code>write</code> {{ ___('tokens are unaffected.') }}</p>
                <p>{{ ___('Tokens are hashed at rest (SHA-256) and may carry an optional expiry. Revoke any token instantly from the dashboard.') }}</p>
            </section>

            <section id="limits">
                <h2>{{ ___('Rate limits') }}</h2>
                <p>{{ ___('The API allows') }} <strong>{{ ___('120 requests per minute') }}</strong> {{ ___('per token. Exceeding it returns') }} <code>429 Too Many Requests</code> {{ ___('with a') }} <code>Retry-After</code> {{ ___('header. Standard rate-limit headers are included on every response:') }}</p>
                <pre><code>X-RateLimit-Limit: 120
X-RateLimit-Remaining: 118</code></pre>
            </section>

            <section id="errors">
                <h2>{{ ___('Errors') }}</h2>
                <p>{{ ___('Every error on an') }} <code>/api/v1</code> {{ ___('route returns conventional HTTP status codes and a single JSON envelope — a human') }} <code>message</code>, {{ ___('a stable machine') }} <code>code</code>, {{ ___('and (on validation) a per-field') }} <code>errors</code> {{ ___('map:') }}</p>
                <pre><code>{ "message": "Invalid or expired token.", "code": "unauthenticated" }

{ "message": "The given data was invalid.",
  "code": "validation_failed",
  "errors": { "title": ["The title field is required."] } }</code></pre>
                <table>
                    <tr><th>{{ ___('Status') }}</th><th><code>code</code></th><th>{{ ___('Meaning') }}</th></tr>
                    <tr><td><code>401</code></td><td><code>unauthenticated</code></td><td>{{ ___('Missing, invalid, or expired token.') }}</td></tr>
                    <tr><td><code>403</code></td><td><code>forbidden</code></td><td>{{ ___('Token lacks the required ability/scope.') }}</td></tr>
                    <tr><td><code>404</code></td><td><code>not_found</code></td><td>{{ ___('Resource not found') }} <em>{{ ___('or') }}</em> {{ ___('not owned by the token.') }}</td></tr>
                    <tr><td><code>422</code></td><td><code>validation_failed</code></td><td>{{ ___('Validation failed (see') }} <code>errors</code>).</td></tr>
                    <tr><td><code>429</code></td><td><code>rate_limited</code></td><td>{{ ___('Rate limit exceeded.') }}</td></tr>
                </table>
                <div class="note">{{ ___('Parse the machine') }} <code>code</code>, {{ ___('not the human') }} <code>message</code> — {{ ___('messages may be reworded or localized; codes are stable.') }}</div>
                <div class="note">{{ ___('Requesting a resource you do not own returns') }} <code>404</code>, {{ ___('not') }} <code>403</code> — {{ ___('the API never confirms the existence of another owner\'s data.') }}</div>
            </section>

            <section id="pagination">
                <h2>{{ ___('Pagination') }}</h2>
                <p>{{ ___('List endpoints return Laravel-style paginated envelopes. Use the') }} <code>?page=</code> {{ ___('query parameter to walk pages.') }}</p>
                <pre><code>{
  "data": [ ... ],
  "current_page": 1,
  "last_page": 3,
  "per_page": 20,
  "total": 47
}</code></pre>
            </section>

            <section id="read">
                <h2>{{ ___('Read restaurants & menu') }}</h2>

                <div class="ep"><span class="method get">GET</span> /restaurants</div>
                <p>{{ ___('List the restaurants owned by the token, paginated (20 per page).') }}</p>
                <pre><code>{
  "data": [
    { "id": 12, "title": "Nova Bistro", "slug": "nova-bistro",
      "url": "https://.../nova-bistro", "template": "linen",
      "created_at": "2026-06-01T10:22:00+00:00" }
  ],
  "current_page": 1, "last_page": 1, "total": 1
}</code></pre>

                <div class="ep"><span class="method get">GET</span> /stores/{id}</div>
                <p>{{ ___('A single restaurant with its menu categories and item count.') }}</p>

                <div class="ep"><span class="method get">GET</span> /stores/{id}/menu</div>
                <p>{{ ___('The full active menu grouped by category.') }}</p>
                <pre><code>[
  { "id": 3, "name": "Starters",
    "items": [
      { "id": 88, "name": "Bruschetta", "price": 6.50,
        "is_sold_out": false, "is_popular": true, "is_vegan": true,
        "is_halal": true, "calories": 210 }
    ]
  }
]</code></pre>

                <h3>{{ ___('Store types & verticals') }}</h3>
                <p>{{ ___('Every store has a') }} <code>store_type</code> — {{ ___('one of') }} <code>restaurant</code>, <code>retail</code>, <code>cosmetic</code>, <code>clothing</code>, <code>carparts</code>, <code>laundry</code>, <code>pharmacy</code>, <code>hotel</code> ({{ ___('restaurant is the default). It is returned on') }} <code>GET /restaurants</code> {{ ___('and') }} <code>GET /stores/{id}</code> {{ ___('inside a') }} <code>vertical</code> {{ ___('object with the store\'s') }} <code>label</code>, <code>group</code> (<code>food</code> / <code>goods</code> / <code>services</code> / <code>stays</code>), {{ ___('capability flags, and allowed') }} <code>fulfillment_modes</code>.</p>
                <p>{{ ___('Order') }} <code>type</code> {{ ___('is validated against the store\'s vertical. Alongside the food modes') }} (<code>on-table</code>, <code>takeaway</code>, <code>delivery</code>, <code>room_service</code>) {{ ___('non-food stores use') }} <code>ship</code>, <code>pickup_slot</code>, <code>appointment</code>, <code>at_home</code>, {{ ___('or') }} <code>in_store</code> — {{ ___('a store only accepts the modes its') }} <code>store_type</code> {{ ___('allows (see') }} <code>vertical.fulfillment_modes</code>).</p>
                <p>{{ ___('For a non-food store,') }} <code>GET /stores/{id}/menu</code> {{ ___('replaces the food item flags') }} (<code>is_vegan</code> / <code>is_halal</code> / <code>calories</code>) {{ ___('with goods fields —') }} <code>brand</code>, <code>stock</code>, {{ ___('and variant counts; the item\'s') }} <code>fitment</code>, <code>age_gate</code>, {{ ___('and') }} <code>expiry</code> {{ ___('capabilities are reported in the') }} <code>vertical</code> {{ ___('block above.') }}</p>

            </section>

            <section id="menu-crud">
                <h2>{{ ___('Manage menu') }} <span class="dd-pill">{{ ___('write') }}</span></h2>
                <p>{{ ___('Full CRUD for categories and items — everything a merchant app needs to run the menu. All write endpoints require a') }} <code>write</code> {{ ___('token and are scoped to a restaurant you own. Image uploads are out of scope for JSON; pass an already-hosted URL in') }} <code>image</code>.</p>

                <div class="ep"><span class="method post">POST</span> /stores/{id}/menu/categories</div>
                <div class="ep"><span class="method put">PUT</span> /stores/{id}/menu/categories/{categoryId}</div>
                <div class="ep"><span class="method post">POST</span> /stores/{id}/menu/items</div>
                <pre><code>curl -X POST {{ $baseUrl }}/restaurants/12/menu/items \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Content-Type: application/json" \
  -d '{
    "category_id": 3, "name": "Margherita", "price": 11.00,
    "description": "San Marzano, mozzarella, basil",
    "is_vegan": true, "is_halal": true, "calories": 850
  }'</code></pre>
                <div class="ep"><span class="method put">PUT</span> /stores/{id}/menu/items/{itemId}</div>
                <div class="ep"><span class="method patch">PATCH</span> /stores/{id}/menu/items/{itemId}/sold-out</div>
                <p>{{ ___('Quick "86" toggle. Send') }} <code>{"is_sold_out": true}</code>, {{ ___('or omit the body to flip.') }}</p>
                <div class="ep"><span class="method delete">DELETE</span> /stores/{id}/menu/items/{itemId}</div>
            </section>

            <section id="orders">
                <h2>{{ ___('Orders') }}</h2>

                <div class="ep"><span class="method get">GET</span> /stores/{id}/orders</div>
                <p>{{ ___('Orders newest first, paginated (30/page). Filter with') }} <code>?status=</code>.</p>

                <div class="ep"><span class="method get">GET</span> /stores/{id}/orders/{orderId}</div>
                <p>{{ ___('Full order detail with line items, extras, driver, and delivery timeline.') }}</p>

                <div class="ep"><span class="method post">POST</span> /stores/{id}/orders <span class="dd-pill">{{ ___('write') }}</span></div>
                <p>{{ ___('Create an order — this is how a') }} <strong>{{ ___('customer app') }}</strong> {{ ___('submits a cart (the merchant\'s backend holds the token). Every item is validated against the restaurant\'s live menu; sold-out or foreign items reject the whole order (422). Fires') }} <code>order.created</code> {{ ___('and returns the full order including its') }} <code>track_token</code>.</p>
                <pre><code>curl -X POST {{ $baseUrl }}/restaurants/12/orders \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Content-Type: application/json" \
  -d '{
    "type": "delivery",
    "customer_name": "A. Customer",
    "phone": "+15551234567",
    "address": "9 Cedar Road",
    "tip_amount": 3.00,
    "note": "Ring the bell",
    "source": "customer_app",
    "items": [
      { "item_id": 88, "quantity": 2, "variation": 5, "extras": [12], "note": "no onion" },
      { "item_id": 91, "quantity": 1 }
    ]
  }'</code></pre>
                <p>{{ ___('Order') }} <code>type</code> {{ ___('is one of') }} <code>on-table</code>, <code>takeaway</code>, <code>delivery</code>. {{ ___('For') }} <code>on-table</code> {{ ___('pass') }} <code>table_number</code>; {{ ___('for') }} <code>delivery</code> {{ ___('pass') }} <code>address</code>.</p>
                <p><strong>{{ ___('Idempotency.') }}</strong> {{ ___('Send an') }} <code>Idempotency-Key</code> {{ ___('header (or a body') }} <code>client_order_id</code>) {{ ___('on any order-create call. Retrying with the same key returns the original order and never creates a duplicate — safe for dropped responses and offline replay. Keys are scoped per restaurant.') }} {{ ___('The key must be a UUID (v7 recommended, v4 accepted); any other value is rejected with 422. Reusing a key with a different cart returns 409.') }}</p>

                <div class="ep"><span class="method put">PUT</span> /stores/{id}/orders/{orderId}/status <span class="dd-pill">{{ ___('write') }}</span></div>
                <p>{{ ___('Update the kitchen status') }} (<code>new|preparing|ready|delivered|completed|cancelled</code>). {{ ___('Fires') }} <code>order.status_changed</code>.</p>
            </section>

            <section id="storefront-api">
                <h2>{{ ___('Storefront API (per-restaurant token)') }}</h2>
                <p>{{ ___('A separate, public-facing API authenticated by a') }} <strong>{{ ___('per-restaurant storefront token') }}</strong> {{ ___('sent as') }} <code>X-Storefront-Token</code> {{ ___('(not the owner Bearer token). Issue these from your dashboard; each token can only ever reach its own restaurant. Read scope is') }} <code>menu:read</code>; {{ ___('placing orders needs the') }} <code>order:write</code> {{ ___('scope.') }}</p>

                <div class="ep"><span class="method get">GET</span> /storefront/menu</div>
                <p>{{ ___('Full menu for the token\'s restaurant (variants, extras, groups, gallery).') }}</p>

                <div class="ep"><span class="method get">GET</span> /storefront/restaurant</div>
                <p>{{ ___('Basic restaurant info for the token\'s restaurant.') }}</p>

                <div class="ep"><span class="method post">POST</span> /storefront/orders <span class="dd-pill">order:write</span></div>
                <p>{{ ___('Submit a cart on behalf of a diner. Server-priced and') }} <strong>{{ ___('UNPAID') }}</strong> {{ ___('(the diner pays on arrival);') }} <code>takeaway</code> {{ ___('or') }} <code>on-table</code> {{ ___('only. Every item is validated against the live menu — sold-out or foreign items reject the whole order (422). Caps: 40 items/order, 30 qty/line. Optional') }} <code>coupon_code</code> {{ ___('applies an owner discount server-side. Fires') }} <code>order.created</code> {{ ___('and returns') }} <code>track_token</code> + <code>continue_url</code>.</p>
                <pre><code>curl -X POST {{ url('/api/v1/storefront/orders') }} \
  -H "X-Storefront-Token: YOUR_STOREFRONT_TOKEN" -H "Content-Type: application/json" \
  -d '{
    "type": "takeaway",
    "customer_name": "A. Customer",
    "phone": "+15551234567",
    "coupon_code": "WELCOME10",
    "items": [
      { "item_id": 88, "quantity": 2, "variation": 5, "extras": [12] },
      { "item_id": 91, "quantity": 1 }
    ]
  }'</code></pre>
            </section>

            <section id="analytics">
                <h2>{{ ___('Analytics & customers') }}</h2>

                <div class="ep"><span class="method get">GET</span> /stores/{id}/analytics</div>
                <p>{{ ___('Sales summary over a date range') }} (<code>?from=YYYY-MM-DD&amp;to=YYYY-MM-DD</code>, {{ ___('default last 30 days): order counts by status/type, gross & paid revenue, average order value, and top items.') }}</p>
                <pre><code>{
  "range": { "from": "2026-06-02", "to": "2026-07-02" },
  "orders": { "total": 214, "paid": 198, "by_status": {...}, "by_type": {...} },
  "revenue": { "gross": 8420.50, "paid": 7990.00, "avg_order_value": 39.35 },
  "top_items": [ { "item_id": 88, "name": "Margherita", "quantity": 143 } ]
}</code></pre>

                <div class="ep"><span class="method get">GET</span> /stores/{id}/customers</div>
                <p>{{ ___('The restaurant\'s customer list (CRM), paginated. Filter with') }} <code>?search=</code>.</p>
            </section>

            <section id="drivers">
                <h2>{{ ___('Manage drivers') }} <span class="dd-pill">{{ ___('write') }}</span></h2>
                <p>{{ ___('Drivers belong to you and (optionally) to one restaurant. Creating or rotating a driver returns a raw') }} <strong>{{ ___('driver token exactly once') }}</strong> — {{ ___('hand it to the driver\'s app; they authenticate with it (see below).') }}</p>

                <div class="ep"><span class="method get">GET</span> /drivers</div>
                <div class="ep"><span class="method post">POST</span> /drivers</div>
                <pre><code>curl -X POST {{ $baseUrl }}/drivers \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"Alex","phone":"+15550001111","store_id":12}'

# → { "id": 7, "name": "Alex", ..., "token": "RAW_DRIVER_TOKEN_SHOWN_ONCE" }</code></pre>
                <div class="ep"><span class="method put">PUT</span> /drivers/{id}</div>
                <div class="ep"><span class="method delete">DELETE</span> /drivers/{id}</div>
                <div class="ep"><span class="method post">POST</span> /drivers/{id}/rotate-token</div>
                <p>{{ ___('Invalidates the old token and returns a fresh one.') }}</p>

                <h3>{{ ___('Assign & track a delivery') }}</h3>
                <div class="ep"><span class="method get">GET</span> /stores/{id}/deliveries</div>
                <p>{{ ___('Delivery orders, filterable by') }} <code>?delivery_status=</code> {{ ___('and') }} <code>?driver_id=</code>.</p>
                <div class="ep"><span class="method post">POST</span> /stores/{id}/orders/{orderId}/assign <span class="dd-pill">{{ ___('write') }}</span></div>
                <p>{{ ___('Assign a driver:') }} <code>{"driver_id": 7}</code>. {{ ___('Sets') }} <code>delivery_status=assigned</code> {{ ___('and fires') }} <code>order.driver_assigned</code>.</p>
                <div class="ep"><span class="method put">PUT</span> /stores/{id}/orders/{orderId}/delivery-status <span class="dd-pill">{{ ___('write') }}</span></div>
                <p>{{ ___('Override the delivery stage:') }} <code>pending | assigned | picked_up | out_for_delivery | delivered | failed</code>.</p>
            </section>

            <section id="driver-app">
                <h2>{{ ___('Driver app API') }}</h2>
                <p>{{ ___('The driver app authenticates with a') }} <strong>{{ ___('driver token') }}</strong> {{ ___('(not an owner token) issued above. Base path') }} <code>{{ $baseUrl }}/driver</code>. {{ ___('Every response is scoped to that one driver.') }}</p>
                <pre><code>Authorization: Bearer RAW_DRIVER_TOKEN</code></pre>
                <div class="ep"><span class="method get">GET</span> /driver/me</div>
                <p>{{ ___('The authenticated driver\'s profile.') }}</p>
                <div class="ep"><span class="method get">GET</span> /driver/deliveries</div>
                <p>{{ ___('Orders assigned to this driver. Add') }} <code>?active=1</code> {{ ___('to hide delivered/failed.') }}</p>
                <div class="ep"><span class="method put">PUT</span> /driver/deliveries/{orderId}/status</div>
                <p>{{ ___('Advance the delivery:') }} <code>{"delivery_status":"out_for_delivery"}</code> {{ ___('then') }} <code>"delivered"</code> {{ ___('(or') }} <code>"picked_up"</code> / <code>"failed"</code>, {{ ___('optional') }} <code>note</code>). {{ ___('Fires the same webhooks as the owner endpoint.') }}</p>
                <div class="ep"><span class="method put">PUT</span> /driver/location</div>
                <p>{{ ___('Push live position:') }} <code>{"lat":25.2048,"lng":55.2708}</code>. {{ ___('Surfaced to the customer\'s tracking view while out for delivery.') }}</p>
            </section>

            <section id="customer-auth">
                <h2>{{ ___('Customer accounts') }}</h2>
                <p>{{ ___('A') }} <strong>{{ ___('standalone customer app') }}</strong> {{ ___('authenticates its own users with a per-customer token (Sanctum-style: multiple devices, individually revocable). No owner token is involved. Customers are scoped per restaurant, so auth is under') }} <code>/stores/{id}/customer/…</code>. {{ ___('Browse the menu first with the public endpoint:') }}</p>
                <div class="ep"><span class="method get">GET</span> /menu/{storeId} <span class="dd-pill">{{ ___('public') }}</span></div>
                <p>{{ ___('Active menu grouped by category (sold-out items omitted). No auth.') }}</p>

                <h3>{{ ___('Register / login') }}</h3>
                <div class="ep"><span class="method post">POST</span> /stores/{id}/customer/register</div>
                <div class="ep"><span class="method post">POST</span> /stores/{id}/customer/login</div>
                <pre><code>curl -X POST {{ $baseUrl }}/restaurants/12/customer/login \
  -H "Content-Type: application/json" \
  -d '{"email":"sam@example.com","password":"secret123","device":"iPhone 15"}'

# → { "token": "RAW_CUSTOMER_TOKEN", "customer": { "id": 42, "name": "Sam", ... } }</code></pre>
                <h3>{{ ___('Passwordless (SMS OTP)') }}</h3>
                <div class="ep"><span class="method post">POST</span> /stores/{id}/customer/otp/request</div>
                <div class="ep"><span class="method post">POST</span> /stores/{id}/customer/otp/verify</div>
                <p>{{ ___('Request a code for a phone number, then verify it. Verify finds-or-creates the customer and returns a token. Auth endpoints are rate-limited (login/register 10/min, OTP request 6/min).') }}</p>
            </section>

            <section id="customer-app">
                <h2>{{ ___('Customer app API') }}</h2>
                <p>{{ ___('Authenticate with the customer token. Base path') }} <code>{{ $baseUrl }}/customer</code>. {{ ___('Everything is scoped to the authenticated customer — the order body can never spoof another customer\'s id.') }}</p>
                <pre><code>Authorization: Bearer RAW_CUSTOMER_TOKEN</code></pre>
                <div class="ep"><span class="method get">GET</span> /customer/me</div>
                <div class="ep"><span class="method put">PUT</span> /customer/me</div>
                <p>{{ ___('Profile read / update (name, email, phone, birthday, consents).') }}</p>
                <div class="ep"><span class="method post">POST</span> /customer/orders</div>
                <p>{{ ___('Place an order as this customer (same item shape as the merchant create endpoint; identity is taken from the token). Returns the order with its') }} <code>track_token</code>.</p>
                <div class="ep"><span class="method get">GET</span> /customer/orders</div>
                <p>{{ ___('The customer\'s own order history, paginated.') }}</p>
                <div class="ep"><span class="method get">GET</span> /customer/addresses</div>
                <div class="ep"><span class="method post">POST</span> /customer/addresses</div>
                <div class="ep"><span class="method delete">DELETE</span> /customer/addresses/{id}</div>
                <p>{{ ___('Saved delivery addresses (first one becomes default; supports') }} <code>lat</code>/<code>lng</code>).</p>
                <div class="ep"><span class="method post">POST</span> /customer/logout</div>
                <p>{{ ___('Revokes the token used for the request (that device only).') }}</p>
            </section>

            <section id="track">
                <h2>{{ ___('Order tracking') }} <span class="dd-pill">{{ ___('public') }}</span></h2>
                <p>{{ ___('No auth — access is gated by the order\'s unguessable') }} <code>track_token</code> {{ ___('(returned when the order is created). This powers a') }} <strong>{{ ___('customer app') }}</strong> {{ ___('live-tracking screen.') }}</p>
                <div class="ep"><span class="method get">GET</span> /track/{token}</div>
                <pre><code>{
  "id": 5501, "status": "preparing", "delivery_status": "out_for_delivery",
  "is_paid": true, "total": 42.00,
  "timeline": { "preparing_at": "...", "out_for_delivery_at": "..." },
  "items": [ { "name": "Margherita", "quantity": 2 } ],
  "driver": { "name": "Alex", "lat": 25.2, "lng": 55.27, "location_updated_at": "..." }
}</code></pre>
                <p>{{ ___('The driver block (with live coordinates) appears only once the order is picked up / out for delivery.') }}</p>
            </section>

            <section id="staff-auth">
                <h2>{{ ___('Staff login') }}</h2>
                <p>{{ ___('A') }} <strong>{{ ___('staff app') }}</strong> {{ ___('(POS / KDS / waiter) authenticates each staff member with a per-staff token. Two paths mirror the dashboard: email + password, or a quick numeric') }} <strong>{{ ___('PIN') }}</strong> {{ ___('for shared kitchen tablets. Staff are scoped per restaurant.') }}</p>
                <div class="ep"><span class="method post">POST</span> /stores/{id}/staff/login</div>
                <div class="ep"><span class="method post">POST</span> /stores/{id}/staff/pin</div>
                <pre><code>curl -X POST {{ $baseUrl }}/restaurants/12/staff/pin \
  -H "Content-Type: application/json" -d '{"pin":"4321","device":"Kitchen iPad"}'

# → { "token": "RAW_STAFF_TOKEN",
#     "staff": { "id": 3, "role": "kitchen", "permissions": ["kds"] } }</code></pre>
                <p>{{ ___('The response lists the staff member\'s effective') }} <strong>{{ ___('permissions') }}</strong> — {{ ___('a subset of') }} <code>orders, menu_edit, coupons, analytics, kds, customers</code> {{ ___('derived from their role (manager / cashier / kitchen / waiter) plus any per-staff overrides. Endpoints are permission-gated') }} (<code>403</code> {{ ___('otherwise).') }}</p>
            </section>

            <section id="staff-app">
                <h2>{{ ___('Staff app API') }}</h2>
                <p>{{ ___('Authenticate with the staff token. Base path') }} <code>{{ $baseUrl }}/staff</code>. {{ ___('All actions are scoped to the staff member\'s restaurant.') }}</p>
                <pre><code>Authorization: Bearer RAW_STAFF_TOKEN</code></pre>
                <div class="ep"><span class="method get">GET</span> /staff/me</div>
                <p>{{ ___('Profile with role and permission list.') }}</p>
                <div class="ep"><span class="method get">GET</span> /staff/orders <span class="dd-pill">orders</span></div>
                <div class="ep"><span class="method put">PUT</span> /staff/orders/{orderId}/status <span class="dd-pill">orders</span></div>
                <p>{{ ___('List orders and update kitchen status. Requires the') }} <code>orders</code> {{ ___('permission.') }}</p>
                <div class="ep"><span class="method get">GET</span> /staff/kds <span class="dd-pill">kds</span></div>
                <p>{{ ___('Live kitchen tickets grouped by order, filtered to the staff member\'s station (or') }} <code>?station_id=</code>). {{ ___('Shows only items still') }} <code>queued|preparing|ready</code>.</p>
                <div class="ep"><span class="method put">PUT</span> /staff/kds/items/{itemId}/bump <span class="dd-pill">kds</span></div>
                <div class="ep"><span class="method put">PUT</span> /staff/kds/items/{itemId}/recall <span class="dd-pill">kds</span></div>
                <p>{{ ___('Advance') }} (<code>queued → preparing → ready → served</code>) {{ ___('or step back one KDS status. The parent order\'s status re-syncs automatically.') }}</p>
                <div class="ep"><span class="method post">POST</span> /staff/menu/items <span class="dd-pill">menu_edit</span></div>
                <div class="ep"><span class="method put">PUT</span> /staff/menu/items/{itemId} <span class="dd-pill">menu_edit</span></div>
                <div class="ep"><span class="method delete">DELETE</span> /staff/menu/items/{itemId} <span class="dd-pill">menu_edit</span></div>
                <div class="ep"><span class="method patch">PATCH</span> /staff/menu/items/{itemId}/sold-out <span class="dd-pill">menu_edit</span></div>
                <div class="ep"><span class="method post">POST</span> /staff/menu/categories <span class="dd-pill">menu_edit</span></div>
                <p>{{ ___('Edit the menu from the floor (managers). Same payloads as the merchant menu endpoints, scoped to the staff member\'s restaurant.') }}</p>
                <div class="ep"><span class="method get">GET</span> /staff/analytics <span class="dd-pill">analytics</span></div>
                <p>{{ ___('Sales summary for the staff member\'s restaurant (same shape as the merchant analytics endpoint;') }} <code>?from=&amp;to=</code>).</p>
                <div class="ep"><span class="method post">POST</span> /staff/logout</div>
                <p>{{ ___('Revokes this device\'s token.') }}</p>
            </section>

            <section id="wh-setup">
                <h2>{{ ___('Webhooks — setup') }}</h2>
                <p>{{ ___('Register endpoints from the dashboard under') }} <strong>{{ ___('API tokens & webhooks') }}</strong>. {{ ___('Pick which events each endpoint receives. On save you get a per-endpoint') }} <strong>{{ ___('signing secret') }}</strong>; {{ ___('use the') }} <em>{{ ___('Test') }}</em> {{ ___('button to send a') }} <code>ping</code>. {{ ___('Pause an endpoint to stop delivery without losing its secret.') }}</p>
                <p>{{ ___('Your endpoint should respond with a') }} <code>2xx</code> {{ ___('status quickly (within 10s). Any other status — or a timeout — is treated as a failure and retried.') }}</p>
                <p>{{ ___('Endpoints can also be managed') }} <strong>{{ ___('programmatically') }}</strong> {{ ___('(for Zapier/Make REST-Hooks) with a') }} <code>webhooks:write</code> {{ ___('token:') }}</p>
                <pre><code>GET    /api/v1/webhook-endpoints            # list your endpoints
POST   /api/v1/webhook-endpoints            # {"url":"https://…","events":["order.created"]} → 201 {id, secret, …}
DELETE /api/v1/webhook-endpoints/{id}       # unsubscribe → 204</code></pre>
                <p>{{ ___('The') }} <code>secret</code> {{ ___('is returned') }} <strong>{{ ___('only') }}</strong> {{ ___('on create — store it to verify the signature.') }} <code>url</code> {{ ___('must be a public HTTPS endpoint (SSRF-guarded);') }} <code>events</code> {{ ___('must be from the list below (or') }} <code>*</code>).</p>
            </section>

            <section id="wh-events">
                <h2>{{ ___('Webhook events') }}</h2>
                <table>
                    <tr><th>{{ ___('Event') }}</th><th>{{ ___('Fires when') }}</th></tr>
                    <tr><td><code>order.created</code></td><td>{{ ___('A new order is placed (dashboard or API).') }}</td></tr>
                    <tr><td><code>order.status_changed</code></td><td>{{ ___('An order\'s kitchen status changes (dashboard, POS, or API).') }}</td></tr>
                    <tr><td><code>order.paid</code></td><td>{{ ___('An order is marked fully paid (gateway or split bill).') }}</td></tr>
                    <tr><td><code>order.driver_assigned</code></td><td>{{ ___('A driver is assigned to a delivery.') }}</td></tr>
                    <tr><td><code>order.out_for_delivery</code></td><td>{{ ___('The driver is en route to the customer.') }}</td></tr>
                    <tr><td><code>order.delivered</code></td><td>{{ ___('The delivery was completed.') }}</td></tr>
                    <tr><td><code>order.delivery_failed</code></td><td>{{ ___('The delivery could not be completed.') }}</td></tr>
                    <tr><td><code>refund.completed</code></td><td>{{ ___('A refund is completed for an order.') }}</td></tr>
                    <tr><td><code>reservation.created</code></td><td>{{ ___('A table reservation is created.') }}</td></tr>
                    <tr><td><code>reservation.cancelled</code></td><td>{{ ___('A table reservation is cancelled.') }}</td></tr>
                    <tr><td><code>customer.created</code></td><td>{{ ___('A new customer record is created.') }}</td></tr>
                    <tr><td><code>shift.opened</code></td><td>{{ ___('A cash-drawer / POS shift is opened.') }}</td></tr>
                    <tr><td><code>shift.closed</code></td><td>{{ ___('A cash-drawer / POS shift is closed.') }}</td></tr>
                    <tr><td><code>menu.updated</code></td><td>{{ ___('A menu item or category is created, updated, or deleted (any surface). Payload:') }} <code>{store_id, change, entity, id}</code>.</td></tr>
                    <tr><td><code>entitlement.changed</code></td><td>{{ ___('A feature entitlement is granted or revoked for the workspace (plan change, add-on, app install/uninstall, admin override). Payload:') }} <code>{action, feature_key, source_type, source_id, user_id, occurred_at}</code> {{ ___('where') }} <code>action</code> {{ ___('is') }} <code>granted</code> {{ ___('or') }} <code>revoked</code>.</td></tr>
                    <tr><td><code>subscription.*</code></td><td>{{ ___('Subscription lifecycle:') }} <code>subscription.paused</code>, <code>.resumed</code>, <code>.renewed</code>, <code>.expired</code>, <code>.plan_changed</code>, <code>.past_due</code>, <code>.expiring</code>, <code>.trial_ending</code>.</td></tr>
                    <tr><td><code>app.uninstalled</code></td><td>{{ ___('A marketplace app is uninstalled (delivered to the app\'s endpoint).') }}</td></tr>
                    <tr><td><code>*</code></td><td>{{ ___('Subscribe to every event above.') }}</td></tr>
                    <tr><td><code>ping</code></td><td>{{ ___('Sent by the') }} <em>{{ ___('Test') }}</em> {{ ___('button to verify wiring.') }}</td></tr>
                </table>
                <p>{{ ___('Failed a delivery while your endpoint was down? Use') }} <strong>{{ ___('Redeliver') }}</strong> {{ ___('on any row in the dashboard\'s Recent deliveries log to re-queue it with a fresh') }} <code>webhook-id</code>.</p>
            </section>

            <section id="wh-payload">
                <h2>{{ ___('Webhook payload') }}</h2>
                <p>{{ ___('Every delivery is a') }} <code>POST</code> {{ ___('with this JSON envelope and these headers:') }}</p>
                <pre><code>POST /your-endpoint HTTP/1.1
Content-Type: application/json
webhook-id: msg_a1b2c3d4e5f6g7h8i9j0k1l2
webhook-timestamp: 1751472240
webhook-signature: v1,K5f...base64...==
X-Webhook-Event: order.created          (legacy)
X-Webhook-Signature: 9a3f...hex...      (legacy, HMAC of body only)

{
  "id": "msg_a1b2c3d4e5f6g7h8i9j0k1l2",
  "event": "order.created",
  "created_at": "2026-07-02T18:04:00+00:00",
  "data": { "order_id": 5501, "total": "42.00" }
}</code></pre>
                <p>{{ ___('The') }} <code>id</code> {{ ___('is unique per delivery. Because retries reuse the same') }} <code>id</code>, {{ ___('use it to make your handler idempotent.') }}</p>
            </section>

            <section id="wh-verify">
                <h2>{{ ___('Verifying the signature') }}</h2>
                <p>{{ ___('The') }} <code>webhook-signature</code> {{ ___('header is an HMAC-SHA256, base64-encoded, computed over') }} <code>{id}.{timestamp}.{body}</code> {{ ___('using your endpoint\'s signing secret. Binding the id and timestamp into the signature is what makes a captured request safe against replay. Reject any request whose') }} <code>webhook-timestamp</code> {{ ___('is more than ~5 minutes old.') }}</p>

                <h3>{{ ___('PHP') }}</h3>
                <pre><code>$secret  = 'whsec_from_dashboard';
$id      = $_SERVER['HTTP_WEBHOOK_ID'];
$ts      = $_SERVER['HTTP_WEBHOOK_TIMESTAMP'];
$body    = file_get_contents('php://input');
$sent    = explode(',', $_SERVER['HTTP_WEBHOOK_SIGNATURE'])[1] ?? '';

if (abs(time() - (int) $ts) > 300) { http_response_code(400); exit; }

$expected = base64_encode(hash_hmac('sha256', "$id.$ts.$body", $secret, true));
if (!hash_equals($expected, $sent)) { http_response_code(401); exit; }

// verified — process $body
http_response_code(200);</code></pre>

                <h3>{{ ___('Node.js') }}</h3>
                <pre><code>const crypto = require('crypto');

function verify(req, secret) {
  const id  = req.headers['webhook-id'];
  const ts  = req.headers['webhook-timestamp'];
  const sig = (req.headers['webhook-signature'] || '').split(',')[1];
  if (Math.abs(Date.now() / 1000 - Number(ts)) > 300) return false;

  const expected = crypto
    .createHmac('sha256', secret)
    .update(`${id}.${ts}.${req.rawBody}`)
    .digest('base64');

  return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(sig || ''));
}</code></pre>
                <div class="note">{{ ___('A legacy') }} <code>X-Webhook-Signature</code> {{ ___('header (plain HMAC-SHA256 of the body, hex) is also sent for backward compatibility. New integrations should use') }} <code>webhook-signature</code>.</div>
            </section>

            <section id="wh-retries">
                <h2>{{ ___('Retries & delivery log') }}</h2>
                <p>{{ ___('Delivery is asynchronous and retried on failure with exponential backoff plus jitter: roughly') }} <strong>1m → 5m → 15m → 1h</strong> {{ ___('(up to 5 attempts total). Every attempt — success or failure — is recorded in the delivery log on your dashboard with its HTTP status, attempt number, and response snippet.') }}</p>
                <div class="note">{{ ___('Delivery is') }} <em>{{ ___('at-least-once') }}</em>. {{ ___('Deduplicate on the') }} <code>webhook-id</code> {{ ___('to handle occasional repeats.') }}</div>
            </section>

            <section id="mcp-overview">
                <h2>{{ ___('MCP servers') }}</h2>
                <div class="note"><strong>{{ ___('New:') }}</strong> {{ ___('a one-page, copy-paste setup guide for every client lives at') }} <a href="{{ url('/mcp') }}">/mcp</a> — {{ ___('per-client install commands, one-click deeplinks, the tool catalog and sample prompts. This page stays the deep reference.') }}</div>
                <p>{{ ___('Five Model Context Protocol servers connect AI agents (ChatGPT, Claude, Cursor) to the platform — pick the one that matches your audience. All speak JSON-RPC 2.0 over HTTP and negotiate protocol versions') }} <code>2024-11-05</code> / <code>2025-03-26</code> / <code>2025-06-18</code>.</p>
                <div style="overflow-x:auto;">
                <table>
                    <tr><th>{{ ___('Server') }}</th><th>{{ ___('Endpoint') }}</th><th>{{ ___('Audience') }}</th><th>{{ ___('Auth') }}</th><th>{{ ___('Tools') }}</th></tr>
                    <tr><td><strong>Admin</strong></td><td><code>{{ url('/mcp') }}</code></td><td>{{ ___('Store owners — manage the store') }}</td><td>{{ ___('API token (Bearer)') }}</td><td>23</td></tr>
                    <tr><td><strong>Storefront</strong></td><td><code>{{ url('/mcp/storefront') }}</code></td><td>{{ ___('A diner\'s agent — shop & order one store') }}</td><td>{{ ___('Storefront token (agent scope)') }}</td><td>12</td></tr>
                    <tr><td><strong>Customer</strong></td><td><code>{{ url('/mcp/customer') }}</code></td><td>{{ ___('A signed-in diner — their own orders') }}</td><td>{{ ___('Customer token (OTP login)') }}</td><td>5</td></tr>
                    <tr><td><strong>Catalog</strong></td><td><code>{{ url('/mcp/catalog') }}</code></td><td>{{ ___('Anyone — discover stores platform-wide') }}</td><td>{{ ___('Public') }}</td><td>3</td></tr>
                    <tr><td><strong>Dev</strong></td><td><code>{{ url('/mcp/dev') }}</code></td><td>{{ ___('AI coding tools — build themes/integrations') }}</td><td>{{ ___('Public') }}</td><td>7</td></tr>
                </table>
                </div>
                <p>{{ ___('Quickstart: jump to') }} <a href="#mcp">Admin</a>, <a href="#catalog-mcp">Catalog</a>, <a href="#customer-mcp">Customer</a>, {{ ___('or') }} <a href="#ai-editor">Dev</a>. {{ ___('The Storefront server shares the Admin connect pattern with an') }} <code>X-Storefront-Token</code> {{ ___('header instead of a Bearer token.') }}</p>
            </section>

            <section id="mcp">
                <h2>{{ ___('MCP server (Admin)') }}</h2>
                <p>{{ ___('An MCP-compatible AI client (Claude, ChatGPT, Cursor) can operate your restaurant in natural language using the same API tokens. Point it at:') }}</p>
                <div class="ep"><span class="method post">POST</span> <code>{{ $mcpUrl }}</code> <span class="dd-pill">JSON-RPC 2.0</span></div>
                <p>{{ ___('Authenticate with') }} <code>Authorization: Bearer YOUR_TOKEN</code>. {{ ___('Every tool declares the granular ability it needs') }} (<code>resource:action</code>); {{ ___('a legacy') }} <code>read</code> {{ ___('token covers every') }} <code>:read</code> {{ ___('tool and') }} <code>write</code> {{ ___('covers everything. All calls are scoped to your restaurants and rate-limited. Tools you lack the ability for are hidden from') }} <code>tools/list</code>.</p>

                <p><strong>{{ ___('Connect (Claude Code)') }}</strong></p>
                <pre><code>claude mcp add --transport http platform-admin {{ $mcpUrl }} \
  --header "Authorization: Bearer YOUR_TOKEN"</code></pre>

                <p><strong>{{ ___('List tools') }}</strong></p>
                <pre><code>curl -X POST {{ $mcpUrl }} \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'</code></pre>

                <p><strong>{{ ___('Call a tool') }}</strong> ({{ ___('e.g. add a menu item') }})</p>
                <pre><code>curl -X POST {{ $mcpUrl }} \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call",
       "params":{"name":"add_menu_item",
                 "arguments":{"store_id":12,"name":"Latte","price":4.5}}}'</code></pre>

                <h3>{{ ___('Tools') }}</h3>
                <table>
                    <tr><th>{{ ___('Tool') }}</th><th>{{ ___('Ability') }}</th><th>{{ ___('What it does') }}</th></tr>
                    <tr><td><code>list_restaurants</code></td><td>read</td><td>{{ ___('Restaurants you own.') }}</td></tr>
                    <tr><td><code>get_menu</code></td><td>read</td><td>{{ ___('A restaurant\'s categories & items.') }}</td></tr>
                    <tr><td><code>list_categories</code></td><td>menu:read</td><td>{{ ___('Categories with item counts.') }}</td></tr>
                    <tr><td><code>add_category</code></td><td>menu:write</td><td>{{ ___('Create a category.') }}</td></tr>
                    <tr><td><code>update_category</code></td><td>menu:write</td><td>{{ ___('Rename / reorder a category.') }}</td></tr>
                    <tr><td><code>delete_category</code></td><td>menu:write</td><td>{{ ___('Delete a category (refuses if it has items).') }}</td></tr>
                    <tr><td><code>add_menu_item</code></td><td>write</td><td>{{ ___('Create a menu item (plan-limit checked).') }}</td></tr>
                    <tr><td><code>update_menu_item</code></td><td>write</td><td>{{ ___('Edit an item\'s name/price/description.') }}</td></tr>
                    <tr><td><code>delete_menu_item</code></td><td>menu:write</td><td>{{ ___('Permanently delete an item.') }}</td></tr>
                    <tr><td><code>set_item_availability</code></td><td>menu:write</td><td>{{ ___('Mark an item in/out of stock (86 toggle).') }}</td></tr>
                    <tr><td><code>list_orders</code></td><td>orders:read</td><td>{{ ___('Orders newest first; status/date/search filters.') }}</td></tr>
                    <tr><td><code>get_order</code></td><td>orders:read</td><td>{{ ___('Full order detail incl. line items.') }}</td></tr>
                    <tr><td><code>update_order_status</code></td><td>write</td><td>{{ ___('Advance an order\'s kitchen status.') }}</td></tr>
                    <tr><td><code>list_customers</code></td><td>customers:read + <code>crm_suite</code></td><td>{{ ___('CRM list; search name/phone/email. Hidden from tools/list without the entitlement.') }}</td></tr>
                    <tr><td><code>get_customer</code></td><td>customers:read + <code>crm_suite</code></td><td>{{ ___('One customer\'s full record. Hidden from tools/list without the entitlement.') }}</td></tr>
                    <tr><td><code>sales_report</code></td><td>analytics:read</td><td>{{ ___('Revenue + order counts + top items for a range.') }}</td></tr>
                    <tr><td><code>get_restaurant_settings</code></td><td>restaurants:read</td><td>{{ ___('Profile + ordering settings snapshot.') }}</td></tr>
                    <tr><td><code>update_business_hours</code></td><td>restaurants:write</td><td>{{ ___('Set the business-hours text.') }}</td></tr>
                    <tr><td><code>list_coupons</code></td><td>orders:read</td><td>{{ ___('Your discount coupons.') }}</td></tr>
                    <tr><td><code>create_coupon</code></td><td>orders:write</td><td>{{ ___('Create a percentage/fixed coupon.') }}</td></tr>
                    <tr><td><code>update_coupon</code></td><td>orders:write</td><td>{{ ___('Edit a coupon.') }}</td></tr>
                    <tr><td><code>delete_coupon</code></td><td>orders:write</td><td>{{ ___('Delete a coupon.') }}</td></tr>
                </table>
            </section>

            <section id="catalog-mcp">
                <h2>{{ ___('Catalog MCP (discover restaurants)') }}</h2>
                <p>{{ ___('A public, read-only MCP server that lets AI agents discover restaurants and dishes across the whole platform, then deep-link into a specific store to order. No auth, rate-limited.') }}</p>
                <div class="ep"><span class="method post">POST</span> <code>{{ url('/mcp/catalog') }}</code> <span class="dd-pill">JSON-RPC 2.0 · public</span></div>
                <p><strong>{{ ___('Connect (Claude Code)') }}</strong></p>
                <pre><code>claude mcp add --transport http platform-catalog {{ url('/mcp/catalog') }}</code></pre>
                <table>
                    <tr><th>{{ ___('Tool') }}</th><th>{{ ___('What it does') }}</th></tr>
                    <tr><td><code>search_stores</code></td><td>{{ ___('Find restaurants by keyword/city (name, address, menu_url, storefront_mcp hint).') }}</td></tr>
                    <tr><td><code>search_items</code></td><td>{{ ___('Find dishes across all stores (query/dietary/max_price/city), grouped by store.') }}</td></tr>
                    <tr><td><code>get_store</code></td><td>{{ ___('Full public detail for one store by slug or id.') }}</td></tr>
                    <tr><td><code>list_starter_menus</code></td><td>{{ ___('The bundled starter-menu presets a new store can seed from (cafe, pizzeria, burger, bakery, lounge).') }}</td></tr>
                </table>
                <p>{{ ___('Only active, publicly listed stores appear; owners can opt out in their store settings. No owner contact details are ever returned. To place an order, use the store\'s') }} <a href="#mcp">{{ ___('storefront MCP') }}</a> {{ ___('with a per-store agent token.') }}</p>
            </section>

            <section id="customer-mcp">
                <h2>{{ ___('Customer Account MCP') }}</h2>
                <p>{{ ___('Lets a diner\'s AI assistant read, track, and reorder') }} <strong>{{ ___('their own') }}</strong> {{ ___('orders. Authenticated by a per-customer token from the existing OTP login; customer identity comes only from the token — a phone or customer id is never accepted as an argument.') }}</p>
                <div class="ep"><span class="method post">POST</span> <code>{{ url('/mcp/customer') }}</code> <span class="dd-pill">JSON-RPC 2.0 · customer token</span></div>

                <p><strong>{{ ___('Get a token (OTP flow)') }}</strong></p>
                <pre><code># 1) request a one-time code (sent to the customer's phone)
curl -X POST {{ $baseUrl }}/restaurants/12/customer/otp/request \
  -H "Content-Type: application/json" -d '{"phone":"+15551234567"}'

# 2) verify the code → returns a customer bearer token
curl -X POST {{ $baseUrl }}/restaurants/12/customer/otp/verify \
  -H "Content-Type: application/json" -d '{"phone":"+15551234567","code":"123456"}'</code></pre>

                <p><strong>{{ ___('Connect (Claude Code)') }}</strong></p>
                <pre><code>claude mcp add --transport http my-orders {{ url('/mcp/customer') }} \
  --header "Authorization: Bearer CUSTOMER_TOKEN"</code></pre>

                <table>
                    <tr><th>{{ ___('Tool') }}</th><th>{{ ___('What it does') }}</th></tr>
                    <tr><td><code>my_orders</code></td><td>{{ ___('Your recent orders (most recent first).') }}</td></tr>
                    <tr><td><code>order_detail</code></td><td>{{ ___('Full detail + line items for one of your orders.') }}</td></tr>
                    <tr><td><code>track_order</code></td><td>{{ ___('Live status by order id or track token.') }}</td></tr>
                    <tr><td><code>reorder</code></td><td>{{ ___('Rebuild a past order as a cart draft (skips sold-out items).') }}</td></tr>
                    <tr><td><code>my_profile</code></td><td>{{ ___('Your name, phone, and order count.') }}</td></tr>
                    <tr><td><code>my_bookings</code></td><td>{{ ___('Your own hotel room bookings at this venue (code, status, dates, room type, total).') }}</td></tr>
                </table>
                <pre><code>curl -X POST {{ url('/mcp/customer') }} \
  -H "Authorization: Bearer CUSTOMER_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call",
       "params":{"name":"my_orders","arguments":{"limit":5}}}'</code></pre>
            </section>

            <section id="ai-editor">
                <h2>{{ ___('Connect your AI editor') }}</h2>
                <p>{{ ___('Building a theme or an integration with Claude Code, Cursor, or VS Code? Point it at the public') }} <strong>{{ ___('Dev MCP server') }}</strong> {{ ___('— your AI tool gets live platform docs, the generated Liquid whitelist, and server-side theme validation. No token needed.') }}</p>
                <div class="ep"><span class="method post">POST</span> <code>{{ $devMcpUrl }}</code> <span class="dd-pill">JSON-RPC 2.0 · public</span></div>
                <p><strong>Claude Code</strong></p>
                <pre><code>claude mcp add --transport http platform-dev {{ $devMcpUrl }}</code></pre>
                <p><strong>Cursor</strong> — <code>.cursor/mcp.json</code></p>
                <pre><code>{ "mcpServers": { "platform-dev": { "url": "{{ $devMcpUrl }}" } } }</code></pre>
                <p><strong>VS Code</strong> — <code>.vscode/mcp.json</code></p>
                <pre><code>{ "servers": { "platform-dev": { "type": "http", "url": "{{ $devMcpUrl }}" } } }</code></pre>
                <p>{{ ___('Tools:') }} <code>learn_platform</code> ({{ ___('start here') }}), <code>search_docs</code> / <code>fetch_full_doc</code>, <code>get_liquid_reference</code>, <code>get_section_schema</code>, <code>validate_theme</code>, <code>list_webhook_events</code>. {{ ___('Recommended agent workflow: learn → build → validate → deliver.') }}</p>
                <div class="note">{{ ___('The authenticated MCP server above') }} (<code>{{ $mcpUrl }}</code>) {{ ___('operates your restaurant data; this one serves documentation and validation and is safe to share publicly.') }}</div>
            </section>

            <section id="changelog">
                <h2>{{ ___('Changelog') }}</h2>
                <table>
                    <tr><th>{{ ___('Date') }}</th><th>{{ ___('Change') }}</th></tr>
                    <tr><td>2026-08-20</td><td>{{ ___('Hotel PMS + growth release:') }} <code>refund.completed</code>, <code>reservation.created</code>, <code>reservation.cancelled</code>, <code>customer.created</code>, <code>shift.opened</code>, <code>shift.closed</code> {{ ___('webhook events; staff push-device registration +') }} <code>2fa</code> {{ ___('endpoints; new MCP tools') }} <code>hotel_availability</code>, <code>my_bookings</code>, <code>list_starter_menus</code>, <code>list_webhook_events</code>.</td></tr>
                    <tr><td>2026-07-28</td><td>{{ ___('Router-generated discovery index +') }} <a href="{{ url('/api/v1/openapi.json') }}">{{ ___('OpenAPI 3.1 spec') }}</a> {{ ___('(always in parity with the deployed API);') }} <code>Idempotency-Key</code> {{ ___('on order-create; per-token API rate limits;') }} <code>subscription.*</code> + <code>app.uninstalled</code> {{ ___('webhook events + delivery') }} <strong>{{ ___('Redeliver') }}</strong>.</td></tr>
                    <tr><td>2026-07-07</td><td>{{ ___('Public') }} <a href="#ai-editor">{{ ___('Dev MCP server') }}</a> {{ ___('for AI coding tools: live docs search, generated Liquid reference, server-side theme validation.') }}</td></tr>
                    <tr><td>2026-07-02</td><td>{{ ___('Granular token scopes') }} (<code>resource:action</code>); {{ ___('staff menu editing + analytics endpoints.') }}</td></tr>
                    <tr><td>2026-07-02</td><td>{{ ___('Staff app: per-staff token auth (password + PIN), role-permission gating, order status, KDS bump/recall.') }}</td></tr>
                    <tr><td>2026-07-02</td><td>{{ ___('Standalone customer app: per-customer token auth (register/login/OTP), profile, order placement + history, saved addresses, public menu browse.') }}</td></tr>
                    <tr><td>2026-07-02</td><td>{{ ___('Full management API: menu CRUD, order creation, drivers + delivery lifecycle, driver-app token API, public order tracking, sales analytics, customers. New delivery webhook events.') }}</td></tr>
                    <tr><td>2026-07-02</td><td>{{ ___('Queued webhook delivery with retries; Standard-Webhooks signing') }} (<code>webhook-id/timestamp/signature</code>); <code>order.paid</code> {{ ___('event; public docs.') }}</td></tr>
                    <tr><td>2026-06-26</td><td>{{ ___('Initial v1 REST API, tokens, and webhook endpoints.') }}</td></tr>
                </table>
                <p style="margin-top:24px;"><a class="dd-btn" href="{{ url('/') }}">{{ ___('Back to') }} {{ $siteName }}</a></p>
            </section>
        </div>
    </div>

    <div class="wrap">
        <div class="dd-apifoot">{{ $siteName }} {{ ___('API v1 · Base URL') }} <code>{{ $baseUrl }}</code></div>
    </div>
</div>
@endsection
