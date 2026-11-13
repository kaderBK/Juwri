---
title: "Architecture overview"
audience: internal
group: Internal
updated: 2026-09-18
---

# Architecture overview

juwri is a single Laravel monolith that serves a multi-tenant,
multi-vertical commerce platform — restaurants by default and heritage, plus
retail, cosmetics, clothing, car parts, laundry and OTC pharmacy stores, each
selected per store by `posts.store_type`. Non-restaurant verticals are a
config-driven layer over the shared core, not a fork (see
[multi-vertical.md](multi-vertical.md)). One codebase renders the marketing
site, the owner dashboard, the platform admin panel, the customer-facing
storefronts, and every JSON API and MCP surface. This page maps the moving parts
for engineers.

## Runtime and stack

- PHP 8.2, Laravel (`laravel/framework: ^12.61.1` in `core/composer.json` — the
  app predates that upgrade, so older code still reads Laravel 10-era). Server
  rendering is Blade; interactivity is Alpine.js v3; icons are inline Lucide SVG.
- The application lives entirely under `core/`. There is no separate frontend
  repo or SPA. `core/routes/` holds the route tables, `core/app/` the
  controllers/models/services, `core/resources/views/` the Blade, and
  `core/resources/liquid-themes/` the source for marketplace storefront themes.
- Authentication uses Passport for API tokens (`api` guard) and session guards
  for humans. See [guards](#authentication-and-actors).

## Multi-tenant model

The tenant hierarchy is owner then restaurant then branch, but the class names
do not match the domain words — this is the single most important gotcha:

- **User** (`App\Models\User`, `users` table) is the restaurant owner / account
  holder. Billing, plan, wallet, and API tokens hang off the user.
- **Restaurant** is a **`Post`** (`App\Models\Post`, `posts` table). A "post"
  is a restaurant/menu page — or, since the multi-vertical pack, a
  non-restaurant store, its `store_type` setting the vertical (`restaurant` is
  the default). `Post::user()` is the owner; `Post::branches()`,
  `Post::menu_categories()`, `Post::tables()` are its children. Many child
  tables key on `store_id`, which holds the `posts.id` value whatever the
  vertical.
- **Branch** (`App\Models\Branch`, `belongsTo Post` via `store_id`) is a physical
  location. Orders, staff, and stock scope to a branch.
- **StaffMember** and **Customer** are per-restaurant actors with their own
  models and guards, not `User` rows.

When you read "restaurant" in routes (`{store}`, `store_id`), expect a
`Post`. `active_theme()` and dashboard context are keyed off the owning `User`.

## Authentication and actors

Guards are defined in `core/config/auth.php`:

- `web` — session, `users` provider. The owner dashboard, admin panel, and POS
  owner access.
- `staff` — session, `StaffMember` provider. Staff PWA, KDS, POS staff.
- `customer` — session, `Customer` provider. Diner login on a storefront.
- `api` — Passport, `users` provider. Owner-token REST API and the MCP server.

Beyond the four guards, several **token middlewares** authenticate app clients
without a session (see `core/app/Http/Middleware/`): `ApiTokenAuth` (owner API,
scoped abilities), `StorefrontTokenAuth` (public read + agent), `CustomerTokenAuth`,
`StaffTokenAuth`, and `DriverTokenAuth`. `ApiToken::hasAbility()` implements the
`resource:action` scope model — a legacy `read`/`write` token still covers every
`:read`/anything route, so granular scopes are additive.

Other middleware in `core/app/Http/Middleware/`:

- `ImpersonationGuard` — enforces a hard time cap on admin impersonation sessions.
- `ResellerApiAuth` — bearer-token auth for the reseller API; 401/403 on failure.
- `ResolveCustomDomain` — binds the reseller context when the request arrives on
  a reseller's custom domain.
- `ResolveResellerBranding` — injects the acting reseller's branding (logo,
  colors, name) into the view/config so white-label pages render under the
  reseller's identity.
- `RememberLocale` — persists the visitor's chosen locale into the session so
  subsequent requests honour it without redirects.
- `CheckRegistrationDisabled` — blocks the signup route when the admin has
  disabled public registration.
- `UpdateLastActive` — stamps `last_active_at` on the authenticated user per
  request (throttled).
- `IsDemoMode` — gates destructive actions in demo/sandbox environments.

The dashboard resolves its acting entity through `dashboard_actor()` /
`dashboard_owner()` helpers (in `core/app/Http/Helpers/Helper.php`) so a
staff session can drive owner-shaped screens. The `staff.dashboard` and
`dashboard.sync` middleware enforce permissions and pin the active store/branch.

## Route surfaces

Routes are registered in `core/routes/` and wired by
`core/app/Providers/RouteServiceProvider.php` (which loads `web.php` under the
`web` group and `api.php` under `api`). `api.php` is nearly empty — almost every
surface, including the JSON APIs, is declared in `web.php`. Ordering matters:
all named single-segment routes must be registered **before** the
`Route::get('{slug}')` storefront catch-all near the end of `web.php`, or they
resolve as a restaurant page and 404.

The surfaces:

- **Marketing / web** — `HomeController`, `BlogController`, `/pricing`,
  `/page/{slug}`, auth (`core/routes/auth.php`). Localized via
  `app.locale` (or the language-prefix middleware when
  `settings.include_language_code` is on).
- **Owner dashboard** — the `User` namespace group under
  `['auth','staff.dashboard','dashboard.sync']`: `/dashboard`, `/stores/*`,
  orders, KDS, floor map, loyalty, coupons, settings, analytics.
- **Staff app** — `auth:staff` group at `/staff-app/*` (`StaffAppController`)
  plus the JSON `api/v1/staff/*` surface for the PWA token client.
- **POS** — `auth:staff,web` group at `/pos/*` (`Staff\PosController`), usable by
  a staff `pos` permission or the owner web guard; actor resolved in-controller.
- **Storefront (public)** — `Route::get('{slug}')` renders a restaurant page;
  `{store}/send-order`, `call-waiter`, coupon/review/NPS, order tracking,
  wallet payment, customer auth/OTP. See [storefront themes](#storefront-themes).
- **Vertical surfaces** — the multi-vertical pack's routes live in per-lane
  files under `core/routes/verticals/`: `lane-{a..f}.php` (dashboard, `require`d
  inside the owner dashboard group) and `public/lane-{a..f}.php` (storefront,
  `require`d before the `{slug}` catch-all). Each build lane owns its own file
  so lanes never edit `web.php` or each other's. This is an additive vertical
  layer over the shared controllers/models, not a fork — see
  [multi-vertical.md](multi-vertical.md).
- **Admin panel** — `admin.*` name prefix under `admin_url()` with the `admin`
  and `demo` middleware (`Admin` namespace). Dashboard, orders, plans, users,
  menu templates, settings, etc.
- **Reseller portal** — `/reseller/*` under `auth` + `ResellerOwnerMiddleware`,
  scoped to the acting owner's reseller.
- **Menu Studio** — public no-signup `/studio/*` (`Studio\StudioController`),
  AI-heavy endpoints per-route throttled.
- **REST API v1** — `api/v1/*` families: owner (`ApiTokenAuth` scoped),
  `storefront` (read), `customer`, `staff`, `driver`. `GET /api/v1` returns a
  self-describing endpoint index. Docs at `/developers`, summary at `/llms.txt`.
- **MCP** — `POST /mcp` (owner `ApiTokenAuth`), `POST /mcp/storefront`
  (agent-scoped storefront token), `POST /mcp/dev` (public, read-only reference).
- **Webhooks** — `/webhook/{gateway}`, `/ipn/{gateway}`, plus SMS, WhatsApp,
  delivery-platform, and HungerStation inbound hooks. CSRF-exempt via the
  explicit `webhook/*` path rule; HungerStation is registered before `webhook/{gateway}`
  so it is not shadowed.

## Blade theme system (THEME_NAME)

The whole product UI (dashboard, admin, marketing, auth) is themed by a single
active Blade theme. `active_theme()` (`Helper.php`) returns the view prefix
`templates.<name>.` and `active_theme(true)` the asset path
`assets/templates/<name>/`. The name comes from `env('THEME_NAME')`, overridable
per request by a `template` session value (an admin can switch it via
`Admin\TemplateController`, which calls `set_env('THEME_NAME', …)`).

Since WI-16h the bought dashboard theme (J9) and its `templates/<name>/` view and
asset folders are gone: every owner, admin, marketing and auth screen is built on
the owner-ui kit (`<x-ui.*>` components, `assets/owner-ui/`). `THEME_NAME` is
`juwri` (`.env`, `phpunit.xml`, `tests/TestCase.php`); the value only feeds the
`activeTheme` view variables some controllers still pass. Product design rules
live in `PRODUCT.md`.

### No build step for the owner UI

The owner-ui kit ships as static, hand-authored CSS and JavaScript — there is no
compile, PostCSS, Tailwind, or Vite pipeline. The Node package is retained only
for the Puppeteer E2E journeys. Do not reach for `@vite` in owner Blade views.

## Storefront themes

The customer-facing menu page (`{slug}` → `User\StoreController@publicView`) uses
a separate theming system from the product UI, and there are two engines:

- **Blade `store_templates`** — the built-in storefront themes under
  `core/resources/views/store_templates/`. The restaurant's chosen template
  (`store_template` on its post options) is normalized by
  `resolve_store_theme()` and rendered as `store_templates.<theme>.index`.
  The bundled Crave, Glow, Matcha themes are Blade `store_templates`.
- **Liquid marketplace themes** — source under
  `core/resources/liquid-themes/<theme>/<version>/` (sections, snippets,
  templates, manifest, settings schema). When the chosen template resolves to a
  marketplace theme, `MarketplaceThemeResolver` matches it and the page renders
  through `store_templates.marketplace-shell`. The Liquid runtime lives in
  `core/app/Storefront/` — `Drops/*` expose whitelisted data to templates,
  `ThemeFilters`/`Tags` are the custom filter/tag set, and `ThemeLinter` /
  `validate_theme` gate submissions. The whitelist is the contract: anything not
  in the Liquid reference does not exist, regardless of Shopify parity.

Both are strictly server-side. No tenant-authored PHP or JS runs on the server;
storefront customization is data (settings) plus sandboxed Liquid.

## Rate limiting

`RouteServiceProvider::configureRateLimiting()` defines the named limiters:
`api` (60/min per user or IP), `storefront` (per-token, `api.storefront_rate`,
default 120/min), the `auth-*` login/register/password/verification family,
`public-form` / `public-check` / `newsletter` for anonymous posts,
`restaurant-order` / `restaurant-waiter` keyed by restaurant+IP, and the
`studio-export` / `studio-fit` studio limiters. Inline `throttle:N,1` is also
used per route.

## Related pages

- [Platform overview for AI agents](../dev/learn-overview.md)
- [Storefront API](../dev/learn-storefront-api.md)
- [Themes](../dev/learn-themes.md)
