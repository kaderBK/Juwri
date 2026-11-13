# routes/verticals — per-lane route files (rev/multi-vertical F0-7)

These files exist so the parallel build lanes (A–F) never edit `routes/web.php` or each
other's files — every lane owns its own route file, so merges are conflict-free (G5).

## Files
- `lane-{a..f}.php` — **dashboard** routes. `require`d inside the User dashboard group in
  `routes/web.php` (right after the group opens). They inherit the `User` controller
  namespace and the dashboard middleware stack (`auth`, `staff.dashboard`, `dashboard.sync`,
  `reseller.suspension`, `abuse.hold`, `csp.dashboard`). Use short controller strings
  (`'BrandController@index'`).
- `public/lane-{a..f}.php` — **public storefront** routes. `require`d inside the
  storefront-localized group, **before** the `{slug}` catch-all so they aren't shadowed.
  Use full controller namespaces (`'User\FitmentController@filter'`). Keep every path
  specific so it can't collide with a store slug.

## Rules
1. A lane edits ONLY its own two files (dashboard + public). Never web.php, never another lane's file.
2. Shared seams are frozen by F0 — consume them, don't fork:
   - `App\Services\Verticals\VerticalRegistry` — capability lookups (`has`, `isFood`, `fulfillmentModes`, `itemForm`, `themeSet`).
   - `App\Services\Verticals\VerticalVocab` — UI wording (`for`, `raw`, `map`).
   - `App\Services\Verticals\FulfillmentRegistry` — valid `orders.type` values (`isValid`, `modesFor`, `needsAddress`, `needsSlot`).
   - `posts.store_type` discriminator; `Post::verticalConfig()`, `Post::isFoodVertical()`, `Post::scopeOfVertical()`.
   - Store-write seam `App\Services\Store\StoreSettingsService` (reuse, never fork — from the reseller pack).
3. New item attributes go on `menu` / `menu_variants` via additive, guarded migrations (Schema::hasColumn).
4. Gate non-restaurant surfaces on the `multi_vertical` entitlement (subject-gate in the controller); never gate a shared route with `feature:` middleware.
5. i18n every string via `___()`; revert the en auto-register before commit ([lang-auto-register-trap]).

## Lane ownership (see WORK-ITEMS.md)
A = catalog & attributes · B = fulfillment & checkout · C = storefront themes ·
D = laundry/services · E = car-parts fitment · F = pharmacy OTC.
