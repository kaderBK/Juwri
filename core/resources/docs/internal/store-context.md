---
title: "Multi-tenancy and dashboard store context"
audience: internal
group: Internal
updated: 2026-07-25
---

# Multi-tenancy and dashboard store context

The dashboard is multi-tenant on two axes: an owner can own several restaurants
(stores), and each store can have several branches. Almost no dashboard URL
carries the tenant in its path, so the "current store" and "current branch" are
resolved per request from a mix of the route, the query string, the session, and
the actor's saved defaults. This page documents that resolution chain and the
pieces that keep it consistent.

The load-bearing files:

- `core/app/Http/Middleware/SyncDashboardStore.php` (alias `dashboard.sync`)
- `core/app/Http/Helpers/Helper.php` (the `dashboard_*` resolver helpers)
- `core/app/Http/Controllers/User/DashboardController.php` (`switchContext`)
- `core/app/Http/Middleware/EnsureStaffDashboardPermission.php` (alias `staff.dashboard`)
- `core/resources/views/templates/old-theme/partials/context-store-select.blade.php` (the switcher UI, included by `partials/topbar.blade.php`)

## Actor: owner vs staff

Every dashboard request has an *actor*, resolved by `dashboard_actor()` in
`Helper.php`: it returns the staff session if one exists, otherwise the
authenticated `User`. A staff member is a `StaffMember` row belonging to an
owner; `staff_session()` reads `session('staff_session_id')` (preferring the
`staff` auth guard user) and `is_staff_dashboard()` is the boolean form. Staff
run owner-shaped screens through `dashboard_owner()` / `dashboard_owner_id()`,
which resolve back to the staff member's owning `User`.

The accessible-store set comes from `dashboard_store_ids()`:

- **Owner** — every `Post` (restaurant) they own: `$owner->stores()->pluck('id')`.
- **Staff** — exactly one store, `[$staff->store_id]` (empty if unset).

So an owner can switch between many stores; a staff member is bound to one. Both
`dashboard_can_access_store($id)` and `dashboard_can_access_order($order)`
are membership checks against this id set, and are the guard used before any
store selection is honoured.

## Resolving the current store

`dashboard_current_store()` picks the active store from a candidate list,
returning the first candidate that is still in `dashboard_store_ids()`:

1. `session('dashboard_store_id')` — the sidebar switcher selection.
2. For owners only, `UserOption` `default_store_id` — the saved login default.
   (Staff are bound to one store, so no default is consulted.)
3. Fallback: `dashboard_stores()->first()`.

Because each candidate is validated against the accessible set, a stale or
now-inaccessible selection (store deleted, access revoked) silently falls
through to the next candidate — there is always a working store as long as the
actor owns at least one. `dashboard_current_store()` is aliased by
`dashboard_first_store()`.

## Resolving the current branch

`dashboard_current_branch()` returns the active `Branch`, or `null` meaning
"all branches". Order of precedence:

1. **Branch-pinned staff** — if `staff_session()->branch_id` is set, the staff
   member is locked to that branch (validated against the current store). This
   cannot be overridden by session or query.
2. **Explicit session selection** — if `session('dashboard_branch_id')` is
   *present*: a truthy id resolves to that branch (scoped to the current store);
   a value of `0` means the user explicitly chose "all branches".
3. **Saved default** — if the session key is absent (no choice made yet): staff
   use `restaurant_staff.default_branch_id`, owners use `UserOption`
   `default_branch_id`. A default that no longer belongs to the current store
   falls back to all branches.

Every branch lookup is `Branch::where('id', ...)->where('store_id', $store->id)`,
so a branch id from another store never leaks across a store switch.

Note the `0` vs absent distinction: `0` is a deliberate "all branches" choice
that beats a saved default; an absent key means "not decided", so the default
applies. `SyncDashboardStore` forgets `dashboard_branch_id` on a store switch
precisely to re-open that "not decided" state for the new store.

### Page-level branch filtering

Controllers that filter by branch call `dashboard_request_branch_id($storeId)`:
an explicit `?branch_id=` query wins (empty/`0` = all branches), otherwise it
uses the session selection from `dashboard_current_branch()`. Passing the page's
restaurant id makes it ignore a session branch that belongs to another store.

## SyncDashboardStore (`dashboard.sync`)

Registered in `core/app/Http/Kernel.php` as `dashboard.sync` and applied to the
`User`-namespace dashboard route group in `core/routes/web.php`
(`['auth', 'staff.dashboard', 'dashboard.sync']`). Its job is to keep the
sidebar selection in step with whatever page the user navigated to.

On each request it:

- **Skips AJAX** (`$request->ajax()`) entirely. Background polls (orders
  heartbeat, KDS board) must not stomp a store/branch switch made in another
  tab, so sync happens on full page loads only.
- **Derives a store id** from the route (`restaurant`, `storeId`, or
  `storeId` parameters — a `Post`, numeric id, or slug), falling back to a
  numeric `?store_id=` query on GET requests.
- If that id differs from `session('dashboard_store_id')` and passes
  `dashboard_can_access_store()`, it sets the new `dashboard_store_id` and
  **forgets `dashboard_branch_id`** (new store, no branch choice yet).
- **Applies `?branch_id=`** on GET: `0`/empty stores `dashboard_branch_id = 0`
  (explicit all-branches); a valid branch belonging to the current store is
  stored as the selection.

The effect: visiting any store-scoped URL (e.g. `/stores/{store}/...`)
makes that store the session-selected one, so every other non-scoped dashboard
page follows it.

## The topbar switcher (`dashboard.context`)

The visible control is `templates/old-theme/partials/context-store-select.blade.php`,
included by `partials/topbar.blade.php`. It posts to
`route('dashboard.context')` → `POST dashboard/context` →
`DashboardController@switchContext`, carrying a `redirect_to` hidden field so the
user lands back on the current page. The form has a store `<select>` and a branch
`<select>` (rendered only when the store has branches, with an "All branches"
option); each auto-submits on change. The whole block is skipped when the actor
has no stores or the route is missing.

`switchContext()`:

1. Rejects the store id if `dashboard_can_access_store()` fails (redirect
   back, no change).
2. Sets `dashboard_store_id`. For the branch: a valid branch id is stored; if
   not and the store changed, it forgets `dashboard_branch_id` (defaults apply);
   otherwise it stores `0` (explicit all-branches on the same store).
3. If `save_default` is checked, persists the choice via `saveContextDefault()`.
4. Redirects via `contextRedirectUrl()`.

### Saving defaults

`saveContextDefault()` splits by actor:

- **Staff** (not branch-pinned) — save the branch only, into
  `restaurant_staff.default_branch_id` (`null` for all-branches). Staff have a
  single store, so no store default is stored. Branch-pinned staff save nothing.
- **Owner** — save both `default_store_id` and `default_branch_id` as
  `UserOption` rows.

Defaults are validated on read (see the resolver helpers), so a later loss of
access degrades gracefully to the first store / all branches rather than
erroring.

### Redirect after switch

`contextRedirectUrl()` tries to keep the user on the same page under the new
store. It matches the previous URL to a route; if that route carries only a
store parameter it rebuilds the URL for the new store, and if the page is not
store-scoped it just returns to it (those pages read the session). Nested
resources (a specific menu, order, etc.) cannot be remapped to another store, so
those fall back to `route('dashboard')`.

## Staff default branch column

Migration `2026_07_07_194344_add_default_branch_id_to_restaurant_staff.php` adds
a nullable `default_branch_id` to `restaurant_staff`, after `branch_id`. It has
no foreign key by design — the value is validated on read (scoped to the current
store) rather than enforced in the schema, so a deleted branch does not orphan
the row. Two related columns on the staff record:

- `branch_id` — a *hard pin*. If set, the staff member is locked to this branch
  everywhere and cannot switch.
- `default_branch_id` — a *soft default*. Used only when the staff member is not
  hard-pinned and has made no explicit branch choice in the session. Also read
  by `Staff\PosController` when opening the POS.

## Permission scoping (`staff.dashboard`)

`EnsureStaffDashboardPermission` (alias `staff.dashboard`) sits alongside
`dashboard.sync` on the dashboard group and is a no-op for owners
(`is_staff_dashboard()` false). For staff it enforces two things per route:

- **Ability** — a `ROUTE_PERMISSION_MAP` maps route names to a required
  permission (e.g. `restaurants.orders` → `orders`, menu edit routes →
  `menu_edit`, `restaurants.coupons.*` → `coupons`). A route absent from the map
  returns `403` (deny by default); a `null` value means "any staff" (e.g.
  `dashboard`). Ability is checked via `dashboard_allows($permission)` →
  `StaffMember::hasPermission()`.
- **Tenant scope** — `routeTargetsAccessibleRestaurant()` rejects (`403`) any
  route whose `restaurant` / `storeId` parameter or `order` fails the
  `dashboard_can_access_*` membership check.

So an owner sees all their stores and every screen; a staff member is confined
to one store, optionally one branch, and only the abilities granted on their
`StaffMember` record.

## Related pages

- [Architecture overview](architecture.md) — route surfaces, guards, actor model.
- [Data model](data-model.md) — `Post`, `Branch`, `StaffMember` relations.
- [Security](security.md) — token guards and tenant isolation.
