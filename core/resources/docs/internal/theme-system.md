---
title: "Theme system internals"
audience: internal
group: Internal
updated: 2026-09-18
---

# Theme system internals

The platform renders a restaurant's public storefront through one of two theme
engines. Both are selected by the `store_template` post option and resolved
in `StoreController@publicView`:

- **First-party Blade `store_templates`** — trusted PHP/Blade views shipped in the
  repo, discovered from the filesystem. This is the default and the fallback.
- **Marketplace Liquid themes** — untrusted, sandboxed packages (Shopify
  OS2.0-style) uploaded by sellers, stored per version, rendered through a closed
  whitelist. Referenced by a `mp:<slug>@<version>` template value.

The Liquid engine lives in `app/Storefront/` (drops, tags, resolver, validator,
linter, installer) and `app/Services/Rendering/` (the sandboxed renderer). See
also the developer-facing docs under `dev/themes/` and `dev/learn-themes.md`.

## Blade post_template auto-discovery

`App\Services\StorefrontThemeService` is the single source of truth for Blade
templates. `discover()` globs `resources/views/store_templates/*` and keeps any
directory that ships `public/assets/store_templates/<slug>/theme-info.txt`. The
`theme-info.txt` file (colon-delimited `Key: Value` lines) supplies Theme Name,
Description, Category, and Featured. Preview image is `preview.png` in the same
public dir.

`all()` merges discovery with the admin overlay in `post_template_meta` (display
name, description, preview image, featured, enabled, sort order). `available()`
is the enabled subset shown in the owner's template picker. Adding a new Blade
theme is purely additive: drop a view directory plus its `theme-info.txt`; no
registration code.

Resolution goes through `resolve_store_theme()` (helper in
`app/Http/Helpers/Helper.php`), which delegates to `StorefrontThemeService::resolve()`:

- A saved slug that still exists on disk renders as-is (even if disabled —
  existing users are grandfathered, WordPress-multisite style).
- A missing/deleted slug falls back to `config('settings.fallback_store_theme')`,
  then to `StorefrontThemeService::LAST_RESORT` (`crave`; saved `flipbook` maps to `folio` first — WI-03).

Note: `resolve_store_theme()` rewrites any unknown slug (including anything
`mp:`-prefixed) to the Blade fallback, so the marketplace resolver must be called
with the RAW option value BEFORE this helper runs. See
`rev/HOW-TO-CREATE-A-POST-TEMPLATE.md` for author guidance.

## Vertical-aware theme sets

Since the multi-vertical pack, a store's `posts.store_type` also gates which
Blade `post_template` it may pick. `App\Services\Theme\VerticalThemeSets` keeps a
small `slug => theme-set` map and defaults every unlisted slug to `'food'`
(`setFor()`), so every pre-existing food theme and every marketplace Liquid theme
stays in the food set untouched. `filterFor($templates, $storeType)` takes a
`StorefrontThemeService::available()`-shaped list and keeps only the templates whose
set equals `VerticalRegistry->themeSet($storeType)` (`food` | `retail` |
`service` | `hotel`, declared in `config/verticals.php`). It is an additive
wrapping filter over `available()` — it never touches
`StorefrontThemeService::discover()` — consumed by `User\StoreController` (the
create/edit picker) and `User\ThemeCustomizerController` (the customizer template
list). A restaurant never even sees the non-food slugs; a retail store never sees
a food theme.

The two foundational non-food themes:

- **`shelf`** (`retail` set) — a retail grid storefront (brand, price, variant
  swatches, add-to-cart) for the goods verticals; adds a Year→Make→Model fitment
  filter for `carparts` and an age-gate interstitial for `pharmacy`.
- **`bookla`** (`service` set) — a service-booking storefront (service list,
  slot picker, pickup/delivery address) for the `services` group (laundry).

Both are vocab-aware (they call `VerticalVocab`, never hardcode "Menu"/"Dish").
`SET_MAP` has since grown to carry the premium per-vertical templates (`torque`,
`carely`, `zevoa`, `maison`, `lumiere` in the `retail` set, `freshly` in
`service`) and a `hotel` set (`aurelia`); adding another is one `SET_MAP` entry,
never a controller change.

The set fallback is vertical-aware only for the **picker**: `filterFor()`
narrows what a store can choose, but render-time resolution still runs through
`resolve_store_theme()`'s unchanged `fallback_store_theme` → `LAST_RESORT`
(`crave`) chain, which is vertical-blind. `shelf`/`bookla` also lack a
`theme-info.txt`/`preview.png`, so — like the discovery gap noted above — they
are direct-render-only until that file ships on a real deploy. See
[multi-vertical.md](multi-vertical.md).

## Theme customizer v2 (Blade themes)

The customizer was rebuilt (2026-07, PRs #407–#413) into a schema-driven,
token-based editor shared by all 17 modern Blade themes plus `mp:*` themes.
Each theme ships a `theme.json` schema (under `assets/store_templates/<slug>/`);
the schema is DATA, validated server-side — an owner can never inject
HTML/JS/CSS. Key pieces (all under `App\Services\Theme\`):

- **`SchemaV2`** — normalizes v1 and v2 theme.json into one shape (tokens /
  presets / settings / sections / texts), expands `{"include": "<fragment>"}`
  groups from `assets/store_templates/_shared/fragments/*.json` (splash, search,
  stories, tokens — referenced, never copied), evaluates `visible_if`. A field
  hidden by `visible_if` is still validated on save.
- **Canonical keys** — one canonical key per setting. `config/theme-aliases.php`
  + `ThemeKeys`: aliases resolve at read in `theme_setting()` and fold on write
  in `saveDraft()`. The dedup migration snapshotted every pre-fold row into
  `theme_setting_versions` (`ThemeSettingVersion`). The read-side alias map is a
  one-release compatibility shim.
- **`theme_setting()` / `theme_sections()`** (Helper.php) — the frozen read
  API every theme uses. `theme_setting()` also resolves per-locale text maps: a
  `texts.*` override may be `{locale: string}` when the schema marks it
  `localized` (fallback: exact locale → store default language → any non-empty →
  theme default). `theme_sections()` honours a validated `sections._order`
  (unlisted ids keep schema order); only `crave` renders through the loop so
  far — the other 16 themes persist an order but render source order.
- **`Tokens`** — the design-token layer: sRGB mix maths, WCAG luminance, role
  ramps (5 colour roles × -300/-400/-600/-700 + `-rgb` + `-on-*`),
  radius/density scales, emitted by `store_templates/_partials/theme-tokens.blade.php`.
  **Overrides-only emission**: a var is emitted only for an owner-set role, so
  an untouched store renders byte-identical. All 17 themes' CSS is wired to the
  vars. Never emit a var for an unset role — it flips the legacy
  `var(--x, <fallback>)` rendering of every untouched store.
- **`ContrastGuard`** — warn-only WCAG check at preview/publish with a
  nearest-passing-shade 1-click fix.
- **Dark scheme (P3-01, opt-in)** — a theme that declares `scheme.mode`
  (light / dark / auto) and `colors_dark.*` (the kit themes) gets: `dark` = the
  dark roles under the normal `--tc-*` names; `auto` = the light block plus the
  dark roles in `@media (prefers-color-scheme: dark)` (`Tokens::darkBlock()`),
  where every `--tc-*` the light block sets and the dark block does not is
  written `initial` (override-leak reset). Themes that declare none of these
  keep the single block byte for byte. For those themes `ContrastGuard::failures()`
  checks BOTH schemes on the owner's colours plus the derived theme-only colours
  (surface-2, muted, border, border-strong; port of the kit's `check_derived.py`,
  parity fixture `tests/Fixtures/theme/contrast_parity.json`), and
  `ThemeCustomizerService::saveDraft()` / `publish()` REFUSE (422, nothing saved)
  a change that introduces a failing pair; other themes stay warn-only (K4).
- **`FontPairs`** + `config/theme-fonts.php` — 6 self-hosted woff2 font pairs
  (all Arabic-faced), emitted by `_partials/theme-fonts.blade.php` for the
  selected pair only; no Google Fonts CDN on the token-wired path.
- **Presets** (`applyPreset` stages a draft, never auto-publishes),
  **`PaletteFromLogo`** (GD dominant-colour extraction) + curated palettes, GD
  preset thumbnails via `php artisan theme:preset-thumbs`.
- **Power tools** — publish-time version history + revert, JSON export /
  schema-validated import (stages a draft), copy between same-owner same-family
  stores, click-to-edit inspector over the `postMessage` preview bridge (origin
  + parent-frame gated, CSS-var whitelist that cannot smuggle `url()`/braces).
- **`ThemeSchemaLintTest`** — CI drift guard (unique ids, blade keys
  declared/aliased, fragments referenced not copied, no legacy keys, etc.).

`ThemeCustomizerService` remains the draft→publish persistence layer for both
engines; marketplace themes still store settings under the versionless
`mp:<slug>` key. Full build record: `rev/customizer/HANDOFF.md`.

## Marketplace theme resolution

`Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver::resolve($raw, $gate = true)` parses
`mp:<slug>@<version>` (strict regex — the slug/version can never carry a path
segment) and locates the version on disk. Lookup order:

1. `storage/app/marketplace-themes/<slug>/<version>/` — the versioned upload store.
2. `resource_path('liquid-themes')` — in-repo reference themes (first-party ports
   like crave).

When `$gate` is true it consults `ThemeVersion` and serves only rows whose
`status === 'published'`. A version with no DB row (reference theme) is servable;
a DB/schema error reports and falls through to servable (an entitlement outage
must never blank a storefront). A resolved theme is a `MarketplaceTheme` value
object exposing `templateSource()`, `templateJson()`, `sectionSource()`,
`snippets()`, `locales()`, `assetMap()`, and `configSettingsSchema()`.

The render branch in `StoreController@publicView` (around line 1265) additionally
gates on `ThemePurchaseService::entitledForSlug()`; an unlicensed paid theme
falls through to the Blade fallback. A Liquid render failure also falls through —
a diner never sees a blank menu.

## Single-file vs sectioned (OS2.0) templates

An entrypoint is either `templates/index.liquid` (single-file) or
`templates/index.json` (OS2.0 sectioned). `MarketplaceTheme::isSectioned()`
switches the path.

`Juwri\Modules\Storefront\Storefront\SectionedTemplate` renders the sectioned form. The template JSON
is DATA (`{sections: {...}, order: [...]}`) listing section instances; each
section is `sections/<type>.liquid` carrying its `{% schema %}` block. Limits
(Shopify's documented bars, enforced at render AND lint):
`MAX_SECTIONS = 25`, `MAX_BLOCKS = 50`.

Settings precedence per section is `schema defaults ← template values ← merchant
values`. Merchant customizations are keyed by SECTION ID under a VERSIONLESS
template key (`mp:<slug>`, see `ThemeSetting` + `ThemeCustomizerService`), so a
theme version bump replaces section code without wiping merchant settings. In
`live` mode a section that throws is reported and skipped (one broken section
never blanks the page); in `upload` mode it rethrows so the author must fix it.
Each section is wrapped in `<div class="mb-section" id="mb-section-<id>">` so the
customizer can address it in the DOM.

## Drops: the data whitelist

`Juwri\Modules\Storefront\Storefront\ThemeContext::build()` is the ONLY thing that feeds the Liquid
renderer. It maps `StoreController`'s render-site variables into Drop presenters
(in `app/Storefront/Drops/`) and scalars — never a raw Eloquent model, `env()`,
or `config()`. The Drop layer IS the isolation boundary on shared hosting.

Globals exposed to a theme: `restaurant`, `options`, `settings`, `menu`,
`allergies`, `banners`, `branches`, `active_branch`, `table`, `customer`,
`localization`, `flags`, `stats`. Under the strict upload environment, any
variable outside this set errors, so the global set is effectively a closed
whitelist. `ThemeContext::registers()` supplies server-set values (currently
`store_id`) passed as render registers — these are NOT reachable as theme
variables, so markup cannot forge them.

Items already carry global-modifier merges and branch price/availability
adjustments applied earlier by `StoreController`; the Drops do no DB work.

## Rendering sandbox and limits

`App\Services\Rendering\LiquidRenderer` renders an untrusted string through two
nested guards:

- `RenderClient` (`renderInProcess`) — a `RenderBudget` (max output bytes + a
  best-effort pcntl wall-clock alarm). On breach or any error it throws
  `RenderFailedException` and the caller applies a safe fallback. Isolation
  strategy is pluggable (`inprocess | subprocess | jailed`); on shared hosting
  with `proc_open` disabled it degrades to `inprocess` and safety rests on the
  language layer.
- `LiquidEnvironmentFactory` (keepsuit/liquid) — no filesystem (so `{% render %}`
  cannot include arbitrary files), plus `ResourceLimits` from `config('platform.liquid')`
  (render length/score, assign score, cumulative caps). Two environments:
  `upload()` is strict (unknown var/filter = error, rethrows) for validation;
  `live()` is lenient (log misses, never 500 a menu) but still enforces
  `strictFilters`.

Filters are the closed set in `Juwri\Modules\Storefront\Storefront\ThemeFilters` (`t`, `money`,
`money_code`, `media_url`, `theme_asset`) plus keepsuit's standard safe filters.
`t` is READ-ONLY (unlike `___()` it never writes a missing lang key). `media_url`
scopes to the current restaurant via the `store_id` register and only
accepts a whitelisted path token. `theme_asset` rejects traversal and resolves
through a pure key-lookup asset map. `{% render 'name' %}` resolves through
`Juwri\Modules\Storefront\Storefront\SnippetMap` — an in-memory name→source map, key lookup only, so
traversal is impossible by construction.

## Package validation and linting

Two gates run on every uploaded package.

`Juwri\Modules\Storefront\Storefront\ThemePackageValidator::assertSafe($zipPath)` is the hard security
gate — the marketplace front door, run BEFORE extraction. It throws on the first
problem and returns the parsed manifest. Rules:

- Layout: `theme.json` at root; an entrypoint at `templates/index.liquid` or
  `templates/index.json`; only known top-level dirs (`ALLOWED_ROOTS`); depth ≤ 3.
- Caps: `MAX_ENTRIES = 1000`, `MAX_TOTAL_BYTES = 10 MB`, `MAX_ENTRY_BYTES = 2 MB`.
- Closed extension whitelist (`ALLOWED_EXTENSIONS`): liquid, json, css, png, jpg,
  jpeg, webp, svg, woff2. No `.php` anywhere, no `.js` in v1 (themes get
  interactivity from the commerce runtime).
- Traversal / absolute / drive / UNC / null-byte path rejection and symlink
  rejection.
- Markup scan on `.liquid`/`.svg` (`markupIssues`): rejects `<script>`, inline
  `on*=` handlers, `javascript:` URLs, `<iframe>`, and (SVG) `<foreignObject>`.
- Manifest (`manifestIssues`): valid JSON with `name`, a resolver-safe `slug`,
  and a resolver-safe `version` (slug/version become filesystem path segments).

`Juwri\Modules\Storefront\Storefront\ThemeLinter` is the author-facing quality report (theme-check
equivalent) run at submission. Errors fail a submission; warnings ship with the
review. Rules: `liquid-syntax` (strict parse), `json-invalid`, `missing-section`,
`missing-snippet`, `unknown-filter` (closed whitelist), `external-url` (assets
must be bundled), `schema-invalid`, `limit-exceeded` (>25 sections / >50 blocks),
and `unused-assign` (warning).

Both classes also expose in-memory `{path => content}` variants
(`ThemePackageValidator::lintFileMap`, `ThemeLinter::lintFiles`) that power the
public `validate_theme` dev-MCP tool, so authors get the marketplace's own
verdict pre-submission.

## Install and submission pipeline

`Juwri\Modules\Storefront\Storefront\ThemePackageInstaller::install()` re-runs `assertSafe()` (belt
and braces), then extracts to
`storage/app/marketplace-themes/<slug>/<version>/`. Only the `assets/` dir (and
`preview.png`) is copied to the statically-served
`public/assets/marketplace-themes/<slug>/<version>/`; everything else stays out
of the doc root. Versions are IMMUTABLE — installing an existing slug@version is
refused (sellers ship a new version). Each install indexes a `Theme` row
(`firstOrCreate`, `seller_id` null = platform-owned) and a `ThemeVersion` row
(`semver`, `manifest`, sha256 `checksum`, `status`).

`App\Storefront\ThemeSubmissionService::submit()` chains it:

```
zip → assertSafe (throws) → install(status=review) → linter → lint_report
    → lint errors = AUTO-REJECT → else waits in the human review queue
```

`runGate()` optionally runs `Juwri\Modules\Storefront\Storefront\LighthouseGate` against preview URLs
(`npx lighthouse`, mobile + desktop): average performance ≥ 60, minimum
accessibility ≥ 90 (`config('platform.lighthouse.*')`). Below bar auto-rejects
with the scores in `gate_report`. Admin approve (which publishes) and reject
actions live in `ThemeReviewController` (routes `admin/theme-reviews*`). A published
`ThemeVersion` is frozen — its model `saving` guard permits only `status` /
`updated_at` to change.

## CSP for theme code

The storefront CSP is `App\Support\Csp\StorefrontPolicy` (juwri's own CSP layer in
`App\Support\Csp`; it replaced spatie/laravel-csp in P1-00a), nonce-based with no
`unsafe-inline`. It is attached to the public `{slug}` route via the
`App\Http\Middleware\AddCspHeaders` middleware (`routes/web.php`).
`config/csp.php` runs it report-only unless `CSP_ENFORCE=true`, at which point it
enforces (allowlist: `'self'`-based, `object` none; img/font also `data:`/`https:`,
media/style also `https:`).

The commerce runtime carries the nonce end to end: `CommerceRuntime::registers()`
emits `csp_nonce` (via the `csp_nonce()` helper), and `CommerceTag` stamps both
its inline config `<script>` and the runtime `<script src>` with
`nonce="..."`. The inline config is JSON-encoded with `JSON_HEX_*` flags so
DB-sourced item names cannot break out of `</script>`. The marketplace shell
(`resources/views/store_templates/marketplace-shell.blade.php`) is the head/SEO/PWA
wrapper; the theme owns everything inside `<body>`.

## Commerce runtime

Sandboxed themes ship zero JavaScript. `Juwri\Modules\Storefront\Storefront\CommerceRuntime` builds
the server-side config the platform cart JS reads as `window.MB_COMMERCE`
(`public/assets/commerce-runtime/<version>/commerce.js`, version from
`config('platform.commerce.runtime_version')`, mtime cache-busted). It is the
ONLY bridge between server state and the cart: currency, charges, order-type and
feature flags, CSRF, endpoint routes (`restaurant.submitOrder`,
`restaurant.applyCoupon`, `restaurant.callTheWaiter`, the `customer.*` account
routes, etc.), the item map (variants absolute, extras + global-group choices
additive — the crave contract), and translated UI strings via `___()`.

A theme drops `{% commerce %}` once in its layout (`Juwri\Modules\Storefront\Storefront\Tags\CommerceTag`)
and gets the whole cart/checkout. The tag is idempotent (tracked with the
`__mb_commerce_emitted` render register) and reads `commerce_config` /
`commerce_runtime_url` from registers, so a theme cannot forge the config, CSRF,
or runtime URL. The config + POST contract mirror the first-party
`restaurant.submitOrder` controller, which stays untouched.

## Purchases and seller ledger

`App\Services\ThemePurchaseService` handles licensing; one purchase licenses the
whole ACCOUNT (a unique user+theme `ThemePurchase` row).

- `entitled(user, theme)` — free themes (`price <= 0`) need no license; a paid
  theme needs a `ThemePurchase` row. `entitledForSlug()` is the render-path
  variant: no `Theme` row → allowed; any DB error → report + allow.
- `purchase()` is idempotent (buying twice returns the existing row, no second
  charge). A paid purchase debits the buyer's wallet through
  `WalletService::debit()` (the ledgered choke point) with ref
  `theme:<id>:user:<id>`, records `price_paid`, then credits the seller via
  `SellerPayoutService::creditSale()`.

`App\Services\SellerPayoutService` is the revenue-share seam (wallet rails,
"Option A"). Every sale credits the seller's wallet with
`config('platform.marketplace.seller_share')` (default 0.70, Shopify-style),
rounded DOWN to the cent, idempotent per `payout:<ref>`. Platform-owned assets
(null `seller_id`) skip the credit. A payout failure is reported but never breaks
the buyer's purchase. Sellers request a cash-out via `WalletController` /
`SellerPayoutService::requestWithdrawal()` (the amount is debited at request
time); admins pay or reject in `Admin\WithdrawalAdminController`. The cash-out
rail is `manual` or `stripe_connect` (`StripeConnectService::activeRail()`); the
Stripe Connect Express path (`payViaStripe`, seller onboarding via
`wallet/stripe/connect`) is built but needs live Stripe keys. Sale-time
cross-border payout behind `creditSale()` (the class's "Option B") is still a seam.

Public marketplace routes (`MarketplaceController`, registered before the
`{slug}` catch-all): `marketplace` (index), `marketplace/{slug}` (show),
`marketplace/{slug}/preview` (render against the demo store — try before you buy,
no entitlement; `?version=` lets an admin preview a review-queue version),
`marketplace/{slug}/buy`, and `marketplace/{slug}/apply` (points a restaurant's
`store_template` at the latest published `mp:<slug>@<version>`,
entitlement-gated).

## Gotchas

- Call `MarketplaceThemeResolver::resolve()` with the RAW option value; never
  after `resolve_store_theme()`, which rewrites `mp:` slugs to the Blade
  fallback.
- New Liquid routes and single-segment paths must register BEFORE the `{slug}`
  catch-all in `routes/web.php`, or they are swallowed as a restaurant slug.
- Theme versions are immutable; a published version's content is frozen at the
  model level. Ship changes as a new version.
- Merchant section settings are keyed under the versionless `mp:<slug>` template
  key so a version bump preserves them.
- `CSP_ENFORCE` is not yet flipped in production — the storefront is
  enforce-READY (nonces in place, guarded by `CspEnforceTest`) but ships
  report-only until the per-theme browser pass and 3rd-party-embed allowlisting
  are done. This is an owner action, not built.
- The `LighthouseGate` requires `npx lighthouse` on the host; `available()`
  probes for it. The `runGate()` step only runs when the review pipeline supplies
  preview URLs.
