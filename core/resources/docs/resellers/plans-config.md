---
title: Configuring plans and pricing
audience: reseller
group: Plans, tenants, and billing
updated: 2026-08-06
verified_against: e09d26f8
---

# Configuring plans and pricing

This guide covers the admin screens that define your subscription plans: the plan
list, the per-plan editor, the editable feature matrix, limits and the unlimited
sentinel, coupons, add-ons, and the archive / duplicate / pause actions.

All routes below live under the admin panel prefix (`admin_url()`, configurable per
install) and carry the `admin.` route-name prefix. Access requires the `admin`
middleware.

## Where plans live

The plan list is at `admin.plans.index`. It shows a stats strip (total plans,
active plans, paid subscribers, most popular plan), the two built-in plans (Free and
Trial), and your paid plans below them in a drag-sortable table.

- Built-in Free and Trial plans are stored as options (`free_membership_plan`,
  `trial_membership_plan`), not as `plans` rows. They cannot be reordered or deleted;
  you can only edit them. The Trial plan additionally has a Days field (how long the
  trial lasts).
- Paid plans are `plans` rows. Drag the reorder handle to change display order
  (`admin.plans.reorder`); the position controls how plans appear on public pricing.
- Each paid plan row shows monthly, annual, and lifetime price, live subscriber count,
  and a status badge.

The subscriber count is the number of live subscriptions per plan (see
`Subscription::LIVE_STATUSES`), not a raw user-group count, so expired subscriptions
are excluded.

## Editing a plan

Open a plan from the list (Edit opens a slide panel). The editor (`admin.plans.edit`,
saved via `admin.plans.update`) has these groups:

- **Name / Description** — both translatable per language.
- **Status** — Active (`1`), Inactive (`0`), or Hidden (`2`). See
  [Status and archive](#status-archive-duplicate-and-pause).
- **Prices** — Monthly, Annual, Lifetime. Enter `0` to disable that billing interval
  for the plan. Prices are shown in the platform currency (`currency_code`). These
  fields are hidden for the Free and Trial plans.
- **Recommended** — a toggle that flags the plan (star badge) on the list and public
  pricing.
- **Plan Settings** — the limits and capability toggles (below).
- **Custom Settings** — any custom plan options you defined (see
  [Add-ons and custom options](#add-ons-and-custom-options)).
- **Taxes** — multi-select of taxes to apply to the plan (paid plans only).

On save the controller writes the price columns and a `settings` object on the plan,
then mirrors those into the `plan_prices` and `plan_entitlements` tables via
`PlanController::mirrorPlanRows()`. During the current transition, `plans.settings`
remains the canonical source; the mirror rows are kept in sync automatically.

## Limits and the unlimited sentinel

Four numeric limits live in plan settings:

| Setting | Meaning |
| --- | --- |
| `restaurant_limit` | Max restaurants per owner |
| `category_limit` | Max categories per restaurant |
| `menu_limit` | Max items per category |
| `scan_limit` | QR scans per month |

Enter `999` to mean unlimited. `999` is the app-wide unlimited sentinel: any stored
limit at or above it is treated as no cap. The plan editor labels every limit field
"For unlimited, enter 999." Legacy values above `999` are also treated as unlimited
during the transition.

At enforcement time limits are read through the `effective_limit($type, $user)`
helper, which returns the plan limit plus any active add-on boosts (see
[Add-ons](#add-ons-and-custom-options)). To keep the sentinel unambiguous, a bounded
total (plan base plus add-ons) is hard-clamped to at most `998` — a total that
reaches or passes `999` would otherwise read as unlimited — while true-unlimited
returns `999`. Do not rely on a limit of exactly `999` being a real cap.

Two refinements to that clamp:

- **Scans are exempt.** Scan packs legitimately push totals far past 999
  (+10,000 per pack), so `scan` is not hard-clamped; only a bounded scan total
  landing on exactly `999` is nudged to `998`.
- **Truncated capacity is surfaced, not swallowed.** For the other count types,
  any paid capacity above `998` is still enforced at `998`, but the shortfall
  is logged (a warning naming the pre-clamp and granted values) and shown to
  the merchant as a notice on their Add-ons page, so support and the buyer can
  see paid-but-ungranted capacity instead of it disappearing silently.

The toggle capabilities in Plan Settings are stored as `0`/`1` flags:

- `allow_ordering` — restaurant can accept customer orders
- `table_qr` — per-table QR codes
- `hide_branding` — remove platform branding from the public menu
- `advertisements` — show ads on the public menu
- `allow_advanced`, `allow_pro` — unlock advanced / pro settings
- `allow_on_free`, `allow_on_try`, `allow_on_main`, `allow_viewonly`, `allow_custom`,
  `allow_onlybio` — which public menu templates the plan may use

## The editable feature matrix

For editing every plan at once, use the Feature Matrix at `admin.feature-matrix.index`
(saved via `admin.feature-matrix.save`). It renders one column per plan and one row
per gateable capability, mixing the numeric limits and the boolean flags listed above.

- Limits are number inputs; booleans are checkboxes.
- Editing here writes the same `plans.settings` keys as the plan editor and mirrors the
  `plan_entitlements` rows, so the two screens stay consistent.
- The canonical catalog of matrix keys, labels, and descriptions is
  `FeatureMatrixController::FEATURES`. Every limit row restates its own "unlimited"
  value under the label and on the input. That value is `999` for all plan limits
  **except** `whatsapp_messages_per_month`, which belongs to the separate WhatsApp
  billing domain where `0 = unlimited` (see `WhatsAppBilling::monthlyLimit()` and
  `FeatureMatrixController::ZERO_UNLIMITED_LIMITS`). Typing `999` there buys 999
  messages, not an unlimited allowance.

Unchecked boxes do not submit, so a cleared boolean is saved as `0`. Limit inputs are
floored at `0`.

## Coupons

Coupons discount subscription checkout (percentage by default), managed at
`admin.coupons.index` (create `admin.coupons.store`, edit `admin.coupons.update`).

Fields when creating a coupon:

- **Coupon code** — 3–20 alphanumeric characters, unique.
- **Discount** — an integer percentage, 1–100.
- **Uses Limit** — how many times the coupon may be redeemed (minimum 1).
- **Expiry at** — must be a future date/time.

### Coupon flags

Two scoping flags control where a coupon applies:

- **Applies to billing interval** (`applies_interval`) — one of `any`, `monthly`,
  `yearly`, or `lifetime`. Restricts the coupon to a single billing interval; `any`
  (the default) allows all. An invalid value falls back to `any`. This is enforced at
  checkout: a coupon whose interval does not match the selected billing interval is
  rejected.
- **First period only** (`first_period_only`) — intended to limit the discount to the
  first billing period for recurring subscriptions. The flag is stored, but checkout
  and renewal do not yet read it, so it currently has no effect (planned).

On the edit screen the code and discount fields are locked (disabled); you can still
change the uses limit, expiry, coupon type, interval scope, and first-period flag.
`coupon_type` is stored as `percentage` (the create flow always sets `percentage`);
the edit screen exposes a `percentage` / `fixed` selector. At checkout a `percentage`
coupon deducts that percent of the price, while a `fixed` coupon subtracts the stored
number as a flat amount in the platform currency.

## Add-ons and custom options

There are two distinct "add-on"-style concepts.

**Custom plan options** are admin-defined feature toggles you attach to plans. Manage
them at `admin.planoption.index`; active options appear under "Custom Settings" in the
plan editor as per-plan on/off switches, stored under `settings.custom_features`.

**Purchasable add-ons** are boosts an owner buys without changing plans
(feature #67). The catalog is table-driven: it lives in the `addon_products`
table with full admin CRUD at `admin.addon-products.index` (create, edit,
enable/disable per SKU), so labels, prices, quantities, and billing periods are
admin configuration, not code edits. `UserAddon::CATALOG` in code remains only
as the fallback when the table is empty (prices in USD minor units). Each catalog
entry has a **kind**:

- `count` — a capacity boost (the pre-P3 behaviour; anything the catalog does
  not recognise reads as `count`). One-off (`once`) grants are permanent;
  `month` grants expire at end-of-month, matching monthly usage resets.
- `boolean` — unlocks a single feature key rather than raising a limit. The
  store hides a boolean add-on the owner already holds through their plan, an
  override, or another add-on, so nothing is double-sold.
- `bundle` — a parent SKU granting a set of child SKUs' feature keys; the store
  card shows how many of the bundle's features are already owned.

A SKU can also declare `trial_days` (> 0) to offer a free trial: the add-on is
granted immediately and charged only when the trial ends.

The default seed catalog (what a fresh install starts with — live values come
from the admin screen):

| Type | Grants | Seed price | Period |
| --- | --- | --- | --- |
| `restaurant` | +1 restaurant slot | 9.99 | permanent |
| `scan` | +10,000 scans/month | 4.99 | monthly |
| `category` | +10 categories | 2.99 | permanent |
| `menu` | +20 items per category | 2.99 | permanent |
| `whatsapp_messages` | +1,000 WhatsApp messages/month | 4.99 | monthly |

Owners buy add-ons from the storefront (`addons.index` → `addons.checkout` →
`addons.pay`) using wallet balance or an enabled gateway; monthly packs can auto-renew
via Stripe, PayPal, or the wallet. Nothing is granted until payment confirms.
Purchased count add-ons are summed into `effective_limit()`, so every enforcement
point sees the boosted limit — subject to the `998` clamp and its scan exemption
described in [Limits and the unlimited sentinel](#limits-and-the-unlimited-sentinel).

## Status, archive, duplicate, and pause

**Status** on a plan is Active (`1`), Inactive (`0`), or Hidden (`2`). Only Active
plans appear in public pickers; Inactive and Hidden plans are withheld while leaving
existing subscribers untouched.

**Archive / unarchive** (`admin.plans.archive`) is a shortcut on each plan row. It
toggles a plan between Hidden (`2`) and Inactive (`0`): archiving hides the plan from
every picker while keeping its history intact; unarchiving returns it to Inactive so
you can review and re-enable it.

**Duplicate** (`admin.plans.duplicate`) makes a full copy of a plan, including its
settings and mirrored price / entitlement rows. The copy is named "… (copy)", created
Inactive, and opens in the editor so you can review it before enabling.

**Delete** is blocked for any plan that has assigned users or any subscription row
(live or historical) referencing it — you must move those users to another plan first.
Built-in Free and Trial plans cannot be deleted.

**Pause / resume a subscriber** is a per-user action, not a plan-level one. From the
admin user screen you can pause (`admin.users.subscription.pause`) or resume
(`admin.users.subscription.resume`) a single owner's live subscription via
`SubscriptionService`. Pausing a subscription does not change the plan definition.

## Related pages

- [Reseller overview](overview.md)
