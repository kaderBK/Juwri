---
title: "Multi-vertical commerce internals"
audience: internal
group: Internal
updated: 2026-09-10
---

# Multi-vertical commerce internals

Engineering reference for the multi-vertical pack (spec in `rev/multi-vertical/`,
six parallel build lanes A–F plus a finalizer lane V, originally built on
branch `mv/lane-v`). **Merged to `main` (PR #32); not yet deployed to
production** — see [Status and owner-owes](#status-and-owner-owes) before
assuming any of this is live for real stores. Covers how a store picks a
vertical, the shared seams every lane consumes, the new tables, the
entitlement gate, and how to add the next vertical. Trust the code paths named
here over the spec.

## The core idea: discriminator, not subclass

A single string column, `posts.store_type`, tells the platform what a store
sells: `restaurant` (the default and only pre-existing value), `retail`,
`cosmetic`, `clothing`, `carparts`, `laundry`, or `pharmacy`. This is
deliberately a **discriminator plus a capability set**, not a schema fork or a
subclass hierarchy — the locked design decision (DECISIONS D5) is that adding
a vertical later is a config entry plus an optional item-form controller and
theme, never a new table hierarchy. `menu`, `menu_categories`, `menu_variants`,
`store_id`, and every `/stores/*` route keep their existing internal
names forever (D4) — a **vocab service** relabels them per vertical in the UI.
Any PR that renames a table, FK, or route for this pack is out of scope by
design.

Every legacy store defaults to `store_type = 'restaurant'` and is completely
unaffected: `VerticalRegistry` falls back to the `restaurant` config entry for
any null/unknown `store_type`, and the restaurant vertical's `fulfillment`,
`food_attrs`, and item-form values are byte-identical to what shipped before
this pack.

## config/verticals.php — the single source of truth

`core/config/verticals.php` is read exclusively through `VerticalRegistry`,
`VerticalVocab`, and `FulfillmentRegistry` (below) — never read directly by a
controller or blade. It has three top-level keys:

- **`types`** — one entry per vertical: `label`, `group` (`food` | `goods` |
  `services`), and the capability flags `food_attrs`, `variant_goods`,
  `fitment`, `age_gate`, `expiry`, `service`, plus `item_form` and `themes`
  (both `food` | `retail` | `service`) and the `fulfillment` array of allowed
  `orders.type` values.
- **`vocab`** — per-`group` noun maps (`item`, `items`, `catalog`, `category`,
  `create_item`, `add_to_cart`, `order`, `orders`, `board`), each value an
  English key resolved through `___()` at render time so all locales
  translate it. `food` keeps the exact pre-pack wording ("Dish", "Menu",
  "Add to order", "Kitchen"); `goods` says "Product"/"Catalog"/"Add to
  cart"/"Fulfilment"; `services` says "Service"/"Book"/"Booking"/"Service
  board". A missing key falls back to the `food` word, then to the key
  itself.
- **`fulfillment`** — the catalog of valid `orders.type` string values, each
  with `label`, `needs_address`, `needs_slot`. The legacy four
  (`on-table`, `takeaway`, `delivery`, `room_service`) keep their exact
  literals; the four new modes are `ship`, `pickup_slot`, `appointment`,
  `in_store`.

### Per-vertical capability matrix

| Vertical | group | variant_goods | fitment | age_gate | expiry | service | item_form | themes | fulfillment |
|---|---|---|---|---|---|---|---|---|---|
| restaurant | food | — | — | — | — | — | food | food | on-table, takeaway, delivery, room_service |
| retail | goods | yes | — | — | — | — | retail | retail | ship, pickup_slot, in_store |
| cosmetic | goods | yes | — | — | yes | — | retail | retail | ship, pickup_slot, in_store |
| clothing | goods | yes | — | — | — | — | retail | retail | ship, pickup_slot, in_store |
| carparts | goods | yes | yes | — | — | — | retail | retail | ship, pickup_slot, in_store |
| laundry | services | — | — | — | — | yes | service | service | pickup_slot, delivery, appointment |
| pharmacy | goods | yes | — | yes | yes | — | retail | retail | ship, pickup_slot, in_store |
| cafe (P3-00) | food | — | — | — | — | — | food | food | on-table, takeaway, delivery |
| grocery (P3-00) | goods | — | — | — | — | — | retail | retail | delivery, pickup_slot |
| services (P3-00) | services | — | — | — | — | yes | service | service | appointment, at_home |

`food_attrs` is `true` only for the food group (`restaurant`, `cafe`) — every other vertical is
`false`, which is how veg/nonveg, allergens, spice, nutrition, and KDS
course/prep stay gated off non-food stores (guardrail G3) rather than merely
CSS-hidden.

## The three registry services (frozen seams)

These live under `App\Services\Verticals\` and are the contract every lane
(and every future vertical) consumes — never fork them:

- **`VerticalRegistry`** — capability lookups. `config(?string $type)` returns
  a vertical's full capability array (falling back to the default vertical for
  an unknown/null type, so a legacy or garbage value never breaks the UI);
  `has()`/`isFood()` read boolean flags; `fulfillmentModes()`, `itemForm()`,
  `themeSet()` read the three multi-value capabilities. `forStore(Post $store)`
  is the per-instance convenience. Resolve via `app(VerticalRegistry::class)`
  — stateless, no global helper (a new `autoload.files` entry would force a
  `composer dump-autoload` in every worktree).
- **`VerticalVocab`** — `for(?string $storeType, string $key)` returns the
  translated noun for a store's group; `raw()` the untranslated English word;
  `map(?string $storeType)` returns the whole noun map at once for a blade to
  read from. Never hardcode "Menu"/"Dish"/"Order" in a new view — call this.
- **`FulfillmentRegistry`** — the source of truth for valid `orders.type`
  values now that the column is a `VARCHAR`. `isValid()`, `meta()`, `label()`
  (translated), `needsAddress()`, `needsSlot()`, and `modesFor(?$storeType)`
  (a vertical's declared modes intersected with the modes actually defined in
  config — so a vertical can never accidentally accept an undefined mode).

`Post` exposes matching convenience methods so most call sites never touch the
registries directly: `Post::verticalConfig()`, `Post::isFoodVertical()`, and
`Post::scopeOfVertical(string $type)` (a query scope filtering by
`store_type`).

## `App\Services\Theme\VerticalThemeSets` — storefront theme gating

A store's vertical also gates which **storefront theme** (`store_templates/*`
slug, Blade or Liquid-marketplace-resolved) it may pick. `VerticalThemeSets`
keeps a small `slug => theme-set` map (`shelf => retail`, `bookla => service`)
— any slug not in that map (i.e. every pre-existing theme, all 45+ of them,
plus every marketplace Liquid theme) defaults to `'food'`. `filterFor()` takes
a `StorefrontThemeService::available()`-shaped list and keeps only the templates
whose set matches `VerticalRegistry->themeSet($storeType)`, so a retail store
only ever sees `shelf` in its template picker, a laundry store only `bookla`,
and a restaurant sees the same 45+ food themes as before this pack
existed — it never even learns `shelf`/`bookla` exist. Consumed by
`StoreController` (the create/edit template picker) and
`ThemeCustomizerController` (the customizer's restaurant dropdown); **not** a
change to the shared `StorefrontThemeService` discovery itself — a small,
additive, wrapping filter.

> **Update (2026-09-18):** this doc's original `shelf`/`bookla` pair was the
> first two sets; the templates loop has since grown `VerticalThemeSets::SET_MAP`
> with premium per-vertical storefront themes (`torque`→carparts, `carely`→
> pharmacy, `zevoa`→retail, `maison`→clothing, `lumiere`→cosmetic, `freshly`→
> laundry) and an eighth vertical, **`hotel`** (group `stays`, theme `aurelia`),
> was added to `config/verticals.php` after this doc was written. Read
> `config/verticals.php` + `VerticalThemeSets::SET_MAP` as the source of truth
> for the current vertical/theme list; the mechanics described here are
> unchanged.

Practical consequence for marketplace theme developers: because every
Liquid-submitted theme lands in the `'food'` set by default, a submitted
theme is currently only ever offered to restaurant stores. There is no
submission path yet for a theme that targets the `retail`/`service` sets —
adding one is a new `VerticalThemeSets::SET_MAP` entry (see
[How to add the next vertical](#how-to-add-the-next-vertical)), not built.

### The two new themes

- **`shelf`** — a grid retail storefront (brand, price, variant swatches,
  add-to-cart) for every `goods`-group vertical (retail, cosmetic, clothing,
  carparts, pharmacy). Adds a Year→Make→Model fitment filter and OEM search
  block when the store is `carparts`, and an age-gate interstitial plus
  disclaimer banner when the store is `pharmacy` (or any vertical with
  `age_gate` true).
- **`bookla`** — a service-booking storefront (service list, slot picker,
  pickup/delivery address) for the `services`-group vertical (laundry today).

Both are vocab-aware (they call `VerticalVocab`, never hardcode a noun) and
were kept as two separate first-party themes rather than one skin-able theme
— `shelf` and `bookla` did not cross the ≥70% shared-markup threshold that
would have justified collapsing them (a recorded open item, resolved: stay
separate).

**Theme discoverability gap:** `StorefrontThemeService::discover()` only lists a
template in the dashboard picker / onboarding wizard step when
`public/assets/store_templates/<slug>/theme-info.txt` exists on disk. Neither
`shelf` nor `bookla` has that file yet (this is not a regression — none of
the 45+ existing themes have it in this checkout either, a pre-existing,
environment-wide gap). Direct rendering
(`?store_template=shelf`/a `StoreOption` write/the wizard's forced pick
for a matching vertical) works today regardless; only the picker **list**
needs the file added on a real deploy.

## New fulfillment modes and the `orders.type` migration

`orders.type` was a MySQL `ENUM('on-table','takeaway','delivery',
'room_service')`. The pack migrates it to `VARCHAR(32)` (MySQL-only; a no-op
on SQLite, which already stores enums as free text) so a new mode is a config
entry in `config('verticals.fulfillment')`, never a schema `ALTER`. The
migration is lossless and idempotent: every existing literal is preserved
byte-for-byte, the default (`'on-table'`) and `NOT NULL` are preserved, and
the `down()` only shrinks back to the enum if every row still uses a legacy
value.

The new modes: `ship` (needs address), `pickup_slot` (needs a time
slot), `appointment` (needs a time slot), `in_store` (neither), and — since P3-00 —
`at_home` (the provider visits: address AND slot). A vertical
declares which modes it allows in `config/verticals.php`; the real checkout
endpoint enforces the ceiling — a mode outside a vertical's declared set is
rejected `422` with no `Order` row created (`VerticalFulfillmentMatrixTest`).
The legacy food ordering endpoint is unaffected: it still only accepts its
original four literals (`room_service` remains validator-accepted, gated only
by the pre-existing, unrelated `hotel_mode` business rule).

## Vertical → surface map

| Vertical | Item-form controller | Storefront theme | Fulfillment | Vertical capability |
|---|---|---|---|---|
| restaurant (baseline) | `StoreController`/`ManagesMenu` (unchanged) | food themes (unchanged) | on-table/takeaway/delivery/room_service | food attrs (veg/nonveg, allergens, KDS) — byte-identical to before |
| retail | `RetailItemController` (brand, price, SKU/barcode, weight/dims, size×color variant grid, free-form `attributes`) | `shelf` | ship / pickup_slot / in_store | — |
| cosmetic | same as retail | `shelf` | ship / pickup_slot / in_store | `expiry` (batch/expiry columns usable; no dedicated UI beyond pharmacy's) |
| clothing | same as retail | `shelf` | ship / pickup_slot / in_store | — |
| carparts | same as retail + `FitmentController` (YMM/submodel/engine, bulk-add, CSV import, OEM/cross-ref numbers) | `shelf` (+ YMM filter, OEM search) | ship / pickup_slot / in_store | `fitment` |
| laundry | `ServiceItemController` (per-each/per-kg pricing, turnaround) | `bookla` (service list, slot picker) | pickup_slot / delivery / appointment | `service` — native booking via `LaundryBookingController`/`ServiceBookingService`, fractional-kg weight, service board, weight-capture-on-pickup repricing |
| pharmacy (OTC only) | same as retail + `PharmacyComplianceController` (age-gate flag, batch/expiry, storefront disclaimer) | `shelf` (+ age-gate interstitial, disclaimer) | ship / pickup_slot / in_store | `age_gate`, `expiry` — **no Rx/prescription anything, ever** (asserted by `PharmacyOtcGuardrailTest`) |

Non-restaurant item-form/manage controllers are deliberately **not** layered
onto `StoreController`/`ManagesMenu` (the shared, food-framed item form) or
onto each other — `RetailItemController`, `ServiceItemController`,
`FitmentController`, and `PharmacyComplianceController` each re-check the
store's vertical shape themselves, so visiting any of these routes on a
restaurant (or the wrong vertical) 404s rather than rendering the wrong form.

### Pharmacy is OTC-only (D3, non-negotiable)

Pharmacy = a retail catalog plus an age-gate flag on individual items plus
batch/expiry tracking. There is no prescription upload, no pharmacist
verification, and no controlled-substance handling anywhere in this pack —
`PharmacyOtcGuardrailTest` exists specifically to assert that. If a future
change needs an Rx-shaped flow, that is a new, explicitly-scoped, regulated
build — never bolted onto this vertical.

## The `multi_vertical` entitlement gate

Every non-restaurant vertical — creating a store of that type **and**,
separately, ongoing management of an existing one's catalog — is gated behind
the `multi_vertical` sellable feature key (`FeatureRegistry`, catalog row
added by `FeaturePlatformCatalogSeeder`: `sellable_via => ['plan', 'addon']`).
Restaurants are never gated, anywhere.

Gating is **subject-gated in the controller**, never a `feature:` route
middleware (guardrail A6/G7 — the platform's general rule for anon-reachable
subjects): every check is
`Entitlements::allows($request->user(), 'multi_vertical')`. Two gate points:

- **Creation** — `StoreController::store()` refuses (with a spotlight-limit
  upsell prompt, not a hard 403) a non-restaurant `store_type` pick when the
  acting user lacks the entitlement.
- **Ongoing management** — all seven vertical manage-surface controllers
  (`RetailItemController`, `BrandController`, `RetailCategoryController`,
  `VariantMatrixController`, `ServiceItemController`, `FitmentController`,
  `PharmacyComplianceController`) call the same check in their
  `authorizeStore()`-style guard, in this exact order: ownership check, then
  vertical-shape check, then the entitlement check. That ordering matters —
  a restaurant (or the wrong vertical, or another owner's store) still 404s
  exactly as before and never even reaches the entitlement check; only a
  right-vertical, rightfully-owned, but unentitled store gets a `403`
  (`VerticalEntitlementGateTest`, 10 tests). This management-side gate was a
  fix found late in the build (Lane V, "Fix 4") — the original brief only
  gated creation, which would have let an owner whose entitlement lapsed keep
  managing a premium catalog indefinitely.

## New and changed tables

All migrations are additive and `Schema::hasColumn()`/`hasTable()`-guarded, so
a re-run or a restaurant-only install is a no-op. None has shipped to
production (see [Status](#status-and-owner-owes)).

- **`posts.store_type`** (new column) — `VARCHAR(32)`, default `'restaurant'`,
  indexed. The discriminator. Backfills any pre-existing `NULL` to
  `'restaurant'` on migrate as a belt-and-suspenders step (the column default
  already covers new rows).
- **`orders.type`** — `ENUM` → `VARCHAR(32)` (MySQL-only; see above).
- **`orders`** gains `packing_at`, `shipped_at`, `collected_at` (nullable
  `dateTime`) — the retail/laundry order-board states with no existing
  timestamp column to reuse. States the board shares with the food flow
  (`ready_at`, `completed_at`) reuse those existing columns unchanged.
- **`brands`** (new table) — **per-store**, not a global catalog (two
  unrelated retail stores each keep their own "Nike" row): `id`,
  `store_id` → `posts` (cascade delete), `name`, `slug`, `logo`,
  `active`, `position`, timestamps.
- **`menu`** gains, from three separate migrations:
  - Retail attributes: `brand_id` (FK → `brands`, null-on-delete), `weight`
    (decimal, shipping weight), `dimensions` (JSON-in-`text`, L×W×H),
    `attributes` (JSON-cast `longText` — the long-tail, per-vertical bucket:
    fabric, ingredients, and car-parts' `oem_numbers`/`cross_refs` all live
    here rather than earning dedicated columns).
  - OTC compliance (pharmacy/D3): `age_restricted` (bool, default false),
    `batch_no`, `expiry_date` (indexed). This is the **entire** pharmacy data
    model — nothing Rx-shaped is added anywhere in this pack.
  - Service fields (laundry/D-A9): `pricing_unit` (`'each'` | `'kg'`,
    nullable — every read site treats a null as `'each'`),
    `turnaround_hours` (unsigned small int, the SLA shown to the customer).
  - `menu.barcode` is **not** a new column — it is an alias for the
    pre-existing `plu` column on the `Menu` model (POS already scans `plu`);
    see `BarcodeResolver`.
- **`menu_variants`** gains `sku`, `stock_qty` (nullable int — null means
  "not stock-tracked", mirroring `menu.stock_qty`'s existing convention),
  `barcode` (indexed). A size×color matrix is the cartesian of two variant
  axes; each cell is one `menu_variants` row carrying its own stock. Parent
  `menu.stock_qty` remains the rollup for simple (non-variant) items only.
  Variant stock is **store-wide** for v1, not per-branch (open item O-INV,
  deferred — mirrors `branch_menu`'s existing availability-only, no-quantity
  model).
- **`vehicle_fitments`** (new table, carparts/A8) — one row per Year/Make/
  Model(/Submodel/Engine) combination a part fits (a part can fit many
  vehicles, hence a child table rather than columns on `menu`): `id`,
  `menu_id` → `menu` (cascade), `store_id` → `posts` (cascade,
  denormalized from the owning item so the storefront YMM filter and the
  dashboard management UI can query/scope without a join, and so tenancy
  never has to trust a request-supplied fitment id), `year`, `make`, `model`,
  `submodel` (nullable), `engine` (nullable), `notes`, timestamps, plus a
  composite `(store_id, year, make, model)` index for the cascading YMM
  lookup. OEM/cross-reference numbers are **not** columns here — they live in
  the owning `menu.attributes` JSON. Only a flat one-part CSV import ships
  (year/make/model/submodel/engine/notes); full ACES/PIES ingest is deferred
  (O-ACES).
- **`shipments`** (new table, B4) — one shipment per order for v1 (a
  split-shipment model is a later open item): `id`, `order_id` → `orders`
  (cascade), `status` (`pending` → `shipped` → `delivered`, indexed),
  `carrier`, `tracking_number`, `shipped_at`, `delivered_at`, timestamps.
  Deliberately **not** a fork of `courier_deliveries` (that table is a
  live-dispatch concept — driver assignment, geo — that a ship-to-address
  goods order doesn't need); this is a much simpler "did it ship, what's the
  tracking number" record.
- **`cart_lines.weight_kg`** / **`order_items.weight_kg`** (new nullable
  `decimal(8,3)` columns, laundry/D2/D4) — a per-kg service line cannot carry
  a fractional weight through the existing integer `quantity` column, so it
  stays fixed at `1` and the weight rides here instead.
  `order_items.unit_price` (pre-existing) remains the sole money authority
  (`theme-totals-contract` unmodified): a kg line snapshots
  `unit_price = price_per_kg * weight_kg` at booking, and the service board's
  weight-capture-on-pickup step overwrites `weight_kg` with the actual
  weighed amount and re-derives `unit_price`, logging the before/after via
  the existing `log_activity()` seam.

None of this is an EAV model (explicitly rejected, DECISIONS A1): core
query-driving attributes are real columns, structured size/color variation is
modeled as real variant rows with real stock, and only genuinely long-tail,
non-query-driving attributes go into the `attributes` JSON bucket.

## Checkout and cart wiring

Two purpose-built pipelines carry non-food orders, parallel to the legacy
food `restaurant.submitOrder` flow — themes must post to the pipeline that
matches their vertical, never to the legacy endpoint:

- **Goods** (`shelf`, retail-shaped verticals) — `GoodsCartController` /
  `GoodsCartService` for the per-item add-line cart, `GoodsCheckoutController`
  / `GoodsOrderService` for ship/pickup/in-store checkout. Routes:
  `storefront.goods.cart.add`, `storefront.goods.cart.line.update`,
  `storefront.checkout.show`/`.place`.
- **Laundry/services** (`bookla`) — `LaundryBookingController` /
  `ServiceBookingService`, decorating `GoodsCartService`'s line/total shape
  with weight-aware pricing. Routes: `laundry.book.show`, `.addLine`,
  `.place`.

Both controllers answer JSON for a `wantsJson()` request (what the themes'
`fetch()` calls send) alongside their original redirect behaviour for
bare-HTML page loads. This native wiring was a late fix (Lane V, "Fix 1") —
the themes originally posted checkout to the legacy `restaurant.submitOrder`
endpoint, mapping their real fulfillment modes down to its 4-value legacy
allowlist and silently rounding fractional kg quantities to integers; that is
now corrected, and `ThemeNativeCheckoutWiringTest` guards against a
regression. A live e2e walk should watch the network tab for these route
names — never `restaurant.submitOrder` — to confirm a vertical is actually
using its own pipeline.

Stock deduction on native goods checkout was a second late fix ("Fix 3"):
`GoodsOrderService::checkout()` originally created the `Order`/`OrderItem`
rows without touching `stock_qty` at all, unlike the shared
`OrderApiService` (POS/legacy storefront path), which deducts both the
item's simple-mode stock and the selected variant's own stock independently.
`GoodsOrderService` now mirrors that exact pair of calls
(`Menu::deductStock()` + `MenuVariant::deductStock()`), both already no-ops
for an untracked/no-stock item (proven harmless for laundry's stock-less
service items).

## Tenancy and isolation

Every vertical surface is scoped by owner/`store_id` exactly like the
rest of the platform (guardrail G2) — no new trust model. Cross-vertical and
cross-tenant isolation is asserted by
`VerticalIsolationMatrixTest` (8 tests): owner A can never read/write owner
B's catalog/brand/category/variant/fitment/pharmacy-field/order across any
retail-shaped vertical plus laundry; a food-only field never lands through a
non-food write surface; `shelf`/`bookla` never render food-only markup.

## Status and owner-owes

**Merged to `main` (PR #32); not yet deployed to production.** The pack was
built on branch `mv/lane-v` (finalizer lane, six build lanes A–F underneath)
and has since landed on `main` — the code in this checkout is that merged
state. Before it is live for real stores, the owner still owes:

- **Prod deploy + migrations** — the full additive migration set listed
  above must actually run once, on a real production deploy (never against
  this shared dev DB), following [prod-deploy-recipe]; the app code itself
  is already on `main` and merely needs shipping.
- **Entitlement flip** — `FeaturePlatformCatalogSeeder` must run in
  production to register the `multi_vertical` key, and the owner must decide
  which plan tier(s)/add-ons grant it by default. Until then every
  non-restaurant surface — create and manage alike — is blocked for every
  owner (correct, but maximally restrictive).
- **Theme registration** — `shelf`/`bookla` need `theme-info.txt` +
  `preview.png` under `public/assets/store_templates/` for the dashboard
  picker and onboarding wizard to list them.
- **Scheduling** — `pharmacy:sweep-expired` (hides expired items for opted-in
  pharmacy stores) exists but is not wired into `Kernel::schedule()`.
- **Demo data** — only pharmacy has a demo seeder
  (`PharmacyDemoSeeder`) plus a broader `VerticalShowcaseSeeder`; a live
  4-role browser walk (V5) covering every vertical was not run by the build
  lane and is still owed.
- **i18n** — 133 new strings registered in `en` with real text and
  placeholder-backfilled into the other 9 locales; professional translation
  of those placeholders is still owed.
- **A pre-existing, unrelated lang-slug collision** was noticed in passing
  (not fixed, out of this pack's scope): `___('Table :number', …)` on the
  dashboard Orders row slugs to `table_number`, which already existed with an
  unrelated value — a documented [lang-case-collision] pattern that pre-dates
  this pack.

Full verification run at lane close: `vendor/bin/phpstan analyse` — 0 errors;
`php artisan test --parallel --exclude-group=platform-e2e` — passing (the
`platform-e2e` group needs a seeded live MySQL env, pre-existing and
unrelated to this pack).

## How to add the next vertical

Per D5, this is meant to stay a config entry plus an item-form partial plus an
optional theme — never a schema fork:

1. **`config/verticals.php`** — add one entry under `types` (label, `group`,
   the capability flags, `item_form`, `themes`, `fulfillment`). A brand-new
   fulfillment mode goes in the top-level `fulfillment` map first (label,
   `needs_address`, `needs_slot`) — `orders.type` is already a `VARCHAR`, so
   this is config-only, never an `ALTER`.
2. **Item form** — if the new vertical's catalog shape matches an existing
   `item_form` value (`retail` or `service`), it gets `RetailItemController`/
   `ServiceItemController` for free by setting `item_form` in step 1. A
   genuinely new shape needs its own controller, its own
   `routes/verticals/lane-<x>.php` entries, and its own `authorizeStore()`
   with the ownership → vertical-capability → `Entitlements::allows(...,
   'multi_vertical')` order this pack established — never edit
   `RetailItemController`/`ServiceItemController` themselves.
3. **Theme (optional)** — reuse `shelf`/`bookla` by setting `themes` to
   `'retail'`/`'service'` in step 1, or add a new `store_templates/<slug>`
   theme plus a `VerticalThemeSets::SET_MAP` entry for a new set. Either way,
   remember the `theme-info.txt`/`preview.png` file the picker needs.
4. **Vocab** — extend the noun map in `config/verticals.php` → `vocab` so
   shared partials say the right words; never hardcode a new label in a
   blade file.
5. **Capability-gated fields** — a vertical needing its own dashboard surface
   (like Fitment/PharmacyCompliance) gets its own controller and its own
   `Schema::hasColumn()`-guarded migration, gated on the same
   `Entitlements::allows($request->user(), 'multi_vertical')` check (after
   ownership + capability) unless a different, per-vertical entitlement key
   is wanted instead.
6. **i18n** — every new string via `___()`; scan new files for `___('...')`,
   diff against `lang/en/lang.php`, backfill the other 9 locales with English
   placeholders, never overwrite an existing translation ([lang-auto-register-trap]).
7. **Tests** — extend `VerticalIsolationMatrixTest`'s retail-shaped-verticals
   list (or add a dedicated method for a non-retail/service shape);
   `VerticalFulfillmentMatrixTest`'s dynamic loop picks up a new vertical
   automatically since it iterates `config('verticals.types')`.

## Related pages

- [Data model](data-model.md) — the pre-existing catalog/order tables this
  pack extends.
- [Architecture overview](architecture.md) — route surfaces and the two
  storefront theme engines these lanes build on.
- [Plans and entitlements internals](entitlements.md) — the `Entitlements`
  service `multi_vertical` is gated through.
- [Theme system internals](theme-system.md) — `post_template` discovery and
  the Liquid marketplace engine `VerticalThemeSets` wraps.
- Owner-facing: [Choosing your store type](../users/store-types.md),
  [Fulfillment methods](../users/fulfillment-methods.md).
