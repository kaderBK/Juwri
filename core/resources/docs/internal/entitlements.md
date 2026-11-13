---
title: Plans and entitlements internals
audience: internal
group: Plans, billing and background work
updated: 2026-09-18
verified_against: e09d26f8
---

# Plans and entitlements internals

Engineering reference for the plans rebuild (spec in `rev/plans/`, shipped P1
through P4 on `feat/plans-rebuild`, merged to `main`). Covers the entitlements
reader, the 999 unlimited sentinel and its add-on collision trap, enforcement
points, the lifecycle tick, the dunning ladder, wallet auto-renew, and plan
webhooks. Trust the code paths named here over the spec.

## Data model in one paragraph

The rebuild moved the source of truth for "what is this customer on" from the
legacy `upgrades` table plus `user.group_id` into a `subscriptions` table (one
live row per user), with `subscription_events` for the audit trail,
`plan_entitlements` for typed per-plan limits/flags, and `plan_prices` for
per-interval pricing. During the dual-write window every legacy writer still
writes `upgrades` + `group_id`; `SubscriptionService` mirrors that into
`subscriptions` and keeps `group_id` synced. See
[data-model.md](data-model.md) for column detail.

## Entitlements service (v2)

`App\Services\Entitlements` (`core/app/Services/Entitlements.php`) is the one
reader for "did this customer pay for it". It resolves each entitlement key
through three sources, in order:

1. The live subscription's entitlement **snapshot** (`subscriptions.meta`,
   under `entitlements`). This is grandfathering: what the customer bought at
   activation wins over any later plan edit.
2. `plan_entitlement` rows for the live plan (typed `int_value` / `bool_value`).
3. The legacy `plan()->settings` blob, for trial/free config plans and plans
   not yet mirrored into the typed rows.

Public surface:

- `Entitlements::limit(User, key): ?int` — the paid count limit for a key
  (`restaurant`, `scan`, `category`, `menu`, ...). `null` means unlimited.
  Bounded limits gain active add-on quantity added arithmetically; unlimited
  short-circuits before add-ons are applied.
- `Entitlements::has(User, key): bool` — whether a boolean feature key is
  granted. A platform kill-switch can veto it (see below).
- `Entitlements::allows(User, key): bool` — the canonical read alias of `has()`
  (SPEC §2); new call sites use `allows()`. The `multi_vertical` gate is its
  main consumer (see [subject-gating](#sellable-feature-keys-and-subject-gating)).
- `Entitlements::remaining(User, key, ?used): ?int` — headroom given usage.
  `null` stays unlimited. For `restaurant` it defaults `used` to
  `$user->stores()->count()`; other keys default to 0 used.

Entitlements are not feature flags (spec decision 6). Global kill-switches
(`settings.admin_allow_{key}` or `settings.feature_{key}` resolving to false in
config) are an operational veto consulted first by `has()` — never the reverse.

## The 999 unlimited sentinel and the add-on collision trap

`999` is the app-wide "unlimited" sentinel. A plan limit stored as exactly
`999` means unlimited.

Inside the service, `normalize()` maps `999` (and the string `'999'`) to
`null`, so the service layer speaks in `null = unlimited` and never in the
sentinel. The trap is at the boundary with add-ons: a bounded plan limit plus
an add-on boost must never *land* on `999`, or enforcement that still compares
`< 999` would read the customer as unlimited. Two guards enforce this:

- `Entitlements::limit()` only adds add-on quantity when the base is a bounded
  int (unlimited short-circuits to `null` first), so add-ons can raise a 500
  scan limit to 10,500 without clamping.
- The legacy `effective_limit()` delegate (below) re-emits the sentinel for the
  transition, and (P0-3) hard-clamps any bounded non-scan total to at most
  `998` — the old guard only handled a total *equal* to `999`, so add-on-boosted
  sums past it (e.g. 500 base + packs = 1100) flipped every `< 999` call site
  to unlimited. `scan` is exempt from the hard clamp (scan packs legitimately
  exceed 999 and scan enforcement compares `!= 999`); only a scan total equal
  to `999` is nudged to `998`.

Note: count-type
add-ons are the collision risk because their quantities are large.

## Legacy delegates: effective_limit() and plan_feature()

`core/app/Http/Helpers/Helper.php` keeps the two global helpers that the rest
of the app (and every theme) calls. Both now delegate to the service while
preserving their old contracts (kickoff rule #7) until the Retire phase:

- `effective_limit(string $type, $user = null): int` — reads a legacy raw
  value first; any legacy value `> 999` still returns `999` (old collision
  semantics). Otherwise it calls `Entitlements::limit()`: `null` unlimited is
  re-emitted as `999` so unaudited `< 999` comparisons keep working, and a
  bounded total is clamped to `min(total, 998)` (`scan` exempt — only nudged
  off exactly `999`). Since WI-H16 (Variant A) the clamp no longer swallows
  paid capacity silently: a bounded total above `998` logs a
  `effective_limit clamped purchased add-on capacity` warning with the
  pre-clamp (`effective`) and `granted` (998) values, and the merchant Add-ons
  page (`AddonController@index` → `addons.blade.php`) compares
  `Entitlements::limit()` (pre-clamp) against `effective_limit()` per count key
  and renders a read-only truncation notice. The return value is unchanged;
  removing the clamp is Variant B (retire the sentinel), a separate run.
  Types: `restaurant`, `scan`, `category`, `menu`. `whatsapp_messages` has its
  own path in `WhatsAppBilling`.
- `plan_feature(string $key, $user = null): bool` — checks the kill-switches, then
  delegates to `Entitlements::has()`. Returns false when there is no user.

The P2 audit found 31 theme files that read limits raw (so add-ons never
applied) and routed them all through `effective_limit()`. When adding
enforcement, always go through these helpers, never read `plan()->settings`
directly.

## Subscription writer

`App\Services\SubscriptionService` (`core/app/Services/SubscriptionService.php`)
is the only writer of `subscriptions`. Invariant: exactly one live row
(`trialing | active | past_due | paused`) per user — `supersedeLive()` expires
any existing live row before creating a new one. Every transition appends a
`subscription_events` row. Key methods:

- `activate(User, Plan, interval, ?Transaction, actorType)` — new purchase or
  plan change. Sets `ends_at` from the interval (`lifetime` = null), clamps
  `anchor_day` to `min(day, 28)`, and snapshots `plan->settings` into
  `meta.entitlements` for grandfathering.
- `startTrial()` / `startFromRegistration()` — trial (`group_id='trial'`) or
  the configured `settings.default_user_plan` (a numeric plan id becomes an
  active no-expiry sub; `free` writes no row).
- `expire()`, `cancel()` (keeps access until `ends_at`), `pause()` / `resume()`
  (P4 admin-only, below), `renew()` (below).
- `resyncFromLegacy(User)` — re-derives the mirror from current legacy state
  via `SubscriptionsBackfill::legacyState()`. Legacy writers that do not go
  through `CheckoutController::updateUserPlan` (admin plan-set, trial checkout,
  free switch, recurring IPN) call this so `subscriptions:verify` stays at zero
  diffs by construction.

## Enforcement points

Enforcement is read-side, at the call sites of the two helpers:

- Restaurant/category/menu-item creation caps: `effective_limit('restaurant')`
  etc., compared against actual counts (`Entitlements::remaining` handles the
  `restaurant` count for you).
- Per-scan / QR-view metering: `effective_limit('scan')`, checked in the theme
  `index.blade.php` / `menu-item.blade.php` files audited in P2.
- Boolean features (ordering, table QR, and similar): `plan_feature('...')` guarded
  by kill-switches.
- WhatsApp message allowance is metered separately in `WhatsAppBilling`, not
  through `effective_limit`, though its add-on type (`whatsapp_messages`) lives
  in the same catalog.

The add-on catalog is table-driven since P3 (feature-platform): read it through
`App\Models\UserAddon::catalog()`, which builds from active `addon_products`
rows (admin CRUD at `admin.addon-products.*`) and memoizes per request
(app-scoped, rebound on `spl_object_id(app())` per the static-memo rule). The
`UserAddon::CATALOG` const is only the empty-table fallback (prices in minor
units of `UserAddon::CATALOG_CURRENCY`, USD — the same rows the Billing
reference data seeds; `catalog()` hands out the major number). Each entry carries `label`, `price`, `quantity`, `period`, plus the
P3/M0-3/M13-1 keys: `kind` (`count` | `boolean` | `bundle`; unrecognised kinds
read as `count`), `feature_key` (what a boolean unlocks), `children` /
`feature_keys` (bundle composition), and `trial_days` (> 0 declares a free
trial). `period 'once'` is a permanent capacity boost; `period 'month'` expires
at end-of-month (`expiryFor()`), matching monthly usage resets.
`UserAddon::totalFor(userId, type)` sums only active (non-expired) rows and is
what `Entitlements::limit()` adds to the base.

## Sellable feature keys and subject-gating

Boolean *sellable feature keys* live in `App\Services\FeatureRegistry` and are
seeded into the platform catalog by `FeaturePlatformCatalogSeeder`. The
**`multi_vertical`** key (`sellable_via => ['plan', 'addon']`) is the one that
unlocks every non-restaurant vertical — creating a store of that `store_type`
and, separately, ongoing management of its catalog. Restaurants are never gated.

`multi_vertical` is **subject-gated in the controller**, never a `feature:`
route middleware: vertical surfaces are partly anon-reachable, and the platform
rule (guardrail G7/A6) forbids `feature:` middleware on such subjects. Every
check is `Entitlements::allows($request->user(), 'multi_vertical')`:

- **Creation** — `User\StoreController::store()` refuses a non-restaurant
  `store_type` pick with a spotlight-limit upsell (not a hard 403) when the
  acting user lacks the entitlement.
- **Management** — the seven vertical controllers (`RetailItemController`,
  `BrandController`, `RetailCategoryController`, `VariantMatrixController`,
  `ServiceItemController`, `FitmentController`, `PharmacyComplianceController`)
  `abort_unless(Entitlements::allows(...), 403)` in their store guard, in the
  order ownership → vertical-shape → entitlement. A wrong-owner or wrong-vertical
  request 404s before ever reaching the entitlement check; only a rightful,
  right-vertical, unentitled store gets a `403`.

`FeatureAccess::storeAllows($storeId, $key)` is the parallel *store-scoped*
gate most storefront/dashboard features use; `multi_vertical` instead checks the
acting **user** subject, since the entitlement is bought at the account level.
See [multi-vertical.md](multi-vertical.md#the-multi_vertical-entitlement-gate).

## Lifecycle tick

`php artisan subscriptions:tick`
(`core/app/Console/Commands/SubscriptionsTick.php`) runs hourly (registered in
`core/app/Console/Kernel.php`). It is idempotent — safe to run at any time. In
order, per run:

1. `trialing` past `ends_at` → `expired` (+ `subscription.expired` webhook,
   `trial: true`).
2. `canceled` past `ends_at` → `expired`.
3. `active` past `ends_at`: apply any scheduled plan change (`pending_plan_id`
   → `plan_id` at period end), then attempt wallet auto-renew; on failure move
   to `past_due` with `grace_until = ends_at + grace_days`.
4. `past_due` past `grace_until` → `expired`.
5. The dunning ladder (below).

Grace length is `settings.subscription_grace_days` (default 3, min 1).

## Dunning ladder

Reminders are stamped once each under `subscriptions.meta.dunning[stage]`, so
no stage sends twice, and `renew()` clears the `dunning` stamps so the ladder
resets naturally on renewal. Stages:

- Pre-expiry for live paid subs: `expiring_7d`, `expiring_3d`, `expiring_1d`
  (only the tightest applicable window fires per run) → `subscription.expiring`
  webhook.
- Trial ending: `trial_ending` at T-3 → `subscription.trial_ending`.
- `past_due`: `expired` on the expiry day and `grace_mid` at the grace
  midpoint.
- `grace_end` when a `past_due` sub finally expires.

Each stage sends `SubscriptionReminderMail` to the user (linking `route(
'subscription')` when defined), reported and skipped on mail failure so the
stamp is not written.

## Wallet auto-renew

Opt-in (spec decision B), gated on `user_options(userId, 'wallet_auto_renew')`.
In the tick, `tryWalletRenew()`:

1. Skips unless the flag is set.
2. Resolves `SubscriptionService::renewalPrice()` (a `plan_prices` row for the
   interval, falling back to the plan's `monthly_price` / `annual_price`;
   `null` for lifetime or unpriced).
3. Debits the wallet via `WalletService::debit()` with ref
   `subrenew:{sub_id}:{period}`. The ref makes the debit idempotent —
   `WalletService::debit` no-ops if a `WalletTransaction` with that ref already
   exists, and throws on insufficient balance.
4. On success calls `SubscriptionService::renew()`; on any throw returns false
   so the sub falls to the normal `past_due` path.

`renew()` extends `ends_at` by one interval using `addMonthNoOverflow()` and
re-clamps to `anchor_day` for monthly, clears `grace_until`, resets the dunning
stamps, and fires `subscription.renewed`.

## Plan webhooks

Subscription events ride the existing Standard-Webhooks infrastructure via
`WebhookService::dispatch(userId, event, payload)` (queued delivery, retries,
signing — see [webhooks](../dev/api/webhooks.md) notes). Events emitted:

- `subscription.renewed` (from `renew()`)
- `subscription.past_due`, `subscription.expired` (tick)
- `subscription.expiring`, `subscription.trial_ending` (dunning ladder)
- `subscription.plan_changed` (scheduled downgrade applied by the tick)
- `subscription.paused`, `subscription.resumed` (admin pause/resume)

Payloads carry `subscription_id`, `user_id`, `plan_id`, `status`, `ends_at`
(plus stage-specific fields such as `days_left` or `trial: true`).

## Admin polish (P4)

- Plan archive (status 2, invisible to the plan picker) and duplicate (mirrors
  price + entitlement rows) — `Admin\PlanController`. Plan saves now
  write-through to `plan_prices` and `plan_entitlements`.
- Editable Feature Matrix — `Admin\FeatureMatrixController` writes plan settings
  and the typed mirror together.
- Coupon interval scoping + first-period flag, enforced at checkout
  (`Admin\CouponController`, `User\CheckoutController`).
- Admin-only seasonal pause/resume (spec decision E) —
  `Admin\UserController::pauseSubscription` / `resumeSubscription`, routes
  `users.subscription.pause` / `users.subscription.resume`. Paused subs stay
  live (keep their slot and group) but the tick never renews or expires them;
  `resume()` pushes `ends_at` forward by the paused duration.
  `subscriptions:verify` treats paused as legacy-active.

## Verification and Retire

`php artisan subscriptions:verify`
(`core/app/Console/Commands/SubscriptionsVerify.php`) diffs the legacy-derived
state against the `subscriptions` table for every non-admin user and exits 1 on
any diff, so CI gates on it. Any nonzero diff during dual-write is a P0 bug.

The bare command is read-only, so it also runs unattended: `Kernel::schedule()`
invokes it daily at 04:10 (`->withoutOverlapping()`), which is what supplies the
consecutive clean-verify days below. The `--backfill-resellers` variant *writes*
(it calls `reseller:backfill-subscriptions`) and stays **manual-only** — it is
never scheduled.

The Retire phase (drop the legacy `upgrades`/`group_id` reads, flip the 999
sentinel to `PHP_INT_MAX`, remove the `effective_limit` re-emit) is gated on
seven consecutive clean verify days plus explicit owner sign-off (spec decision
2) and had not shipped as of this writing.

## Gotchas

- Never read `plan()->settings` for enforcement — go through `plan_feature()` /
  `effective_limit()` so add-ons and grandfathering snapshots apply.
- A bounded non-scan limit is intentionally clamped to at most 998 during the
  transition (and a bounded scan total equal to 999 nudged to 998); do not
  "fix" it — the shortfall is logged and surfaced on the Add-ons page instead
  (WI-H16).
- Wallet renewal idempotency depends entirely on the `subrenew:{id}:{period}`
  ref. Do not change the ref format without accounting for double-debits.
- `pending_plan_id` / `pending_interval` columns exist and the tick applies
  them at period end, but no shipped writer sets them yet — scheduled
  downgrades are supported by the tick and not currently wired to a UI or
  checkout path (planned, not yet available).
