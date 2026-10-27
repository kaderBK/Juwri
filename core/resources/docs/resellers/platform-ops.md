---
title: Platform operations
audience: reseller
group: Marketplace and operations
updated: 2026-08-06
verified_against: e09d26f8
---

# Platform operations

This guide covers the day-to-day platform screens for operators: publishing
announcements to your merchants, controlling which capabilities each plan unlocks,
the daily platform health counters, and the restaurant menu-template admin
(fallback template, enable/disable, bulk reassign).

All routes below live under the admin panel prefix (`admin_url()`, which defaults to
`admin` and is set by the `APP_ADMIN` env var) and carry the `admin.` route-name
prefix. Access requires the `admin` middleware.

## Announcements

Announcements are admin-published banners shown on the merchant dashboard. They
replace the old hardcoded promo blocks with a data-driven system you manage from the
panel.

Manage them at **Announcements** in the admin sidebar (`admin.announcements.index`).
The list is a standard resource (`admin.announcements.*`) with create, edit, delete,
and a toggle action (`admin.announcements.toggle`) that flips active status inline.

Each announcement has these fields:

- **Title** (required, up to 255 chars) and **Body** (optional, up to 1000 chars).
- **Type** — one of `info`, `success`, `warning`, `danger`. This picks the banner
  colour and icon.
- **Link URL** and **Link label** — optional call-to-action. External links (starting
  `http`) open in a new tab; the label defaults to "Learn more".
- **Active** — defaults to on when saving.
- **Starts at / Ends at** — optional date window. `Ends at` must be on or after
  `Starts at`.
- **Target** — `all`, `free`, or `paid`. Free vs paid is decided by whether the
  merchant has a live paid plan (an `upgrade` row with a `plan_id`).

### How merchants see them

The dashboard renders every announcement that is active, within its date window, and
matches the viewer's audience (`Announcement::activeForCurrentUser`), newest first.
Each banner has a dismiss button.

Dismissal is stored per-browser in `localStorage` (key `mb_ann_dismissed`), not in the
database. There is no per-user dismissal table, so a merchant who dismisses a banner
will see it again on a different device or after clearing browser storage. Editing an
announcement does not reset dismissals, because the dismissed set is keyed by
announcement id.

Reseller sub-operators have their own separate announcement channel in the reseller
portal (`Reseller\PortalController`); that is distinct from these platform-wide
announcements.

## Feature flags and gating

Gating is driven by plan settings, not a separate global flag table. Every gateable
capability is a key on a plan, and the admin edits them all from one grid: the
**Feature Matrix** (`admin.feature-matrix.index`, saved via
`admin.feature-matrix.save`).

The catalog of gateable keys is driven by the unified `features` table
(plan-sellable rows; `FeatureMatrixController::FEATURES` remains only the
migration seed and the empty-table fallback) and splits into two kinds:

- **Limits** (numeric) — `restaurant_limit`, `category_limit`, `menu_limit`,
  `scan_limit`, where the value `999` means unlimited (a platform-wide
  sentinel) — plus `whatsapp_messages_per_month`, the odd one out: it belongs
  to the separate WhatsApp billing domain where **`0` means unlimited**
  (`WhatsAppBilling::monthlyLimit()` and
  `FeatureMatrixController::ZERO_UNLIMITED_LIMITS`), so typing `999` there
  sells 999 messages, not an unlimited allowance. Each limit row in the grid
  restates its own unlimited value under the label so the two sentinels are
  never confused.
- **Boolean flags** — `allow_ordering`, `table_qr`, `hide_branding`, `advertisements`,
  `allow_advanced`, `allow_pro`, the public-template access flags (`allow_on_free`,
  `allow_on_try`, `allow_on_main`, `allow_viewonly`, `allow_custom`,
  `allow_onlybio`), and the runtime-gated keys the feature platform added
  (`pos_enabled`, `storefront_api`, `agent_api`, `ai_chatbot`, `hotel`).

Saving the matrix writes each value into `plans.settings` and mirrors it into the
`plan_entitlements` rows (`PlanController::mirrorPlanRows`). Unchecked boolean boxes
are not submitted by the browser, so the controller explicitly defaults any missing
bool to `0` — an unchecked box means the capability is off, not unchanged.

Enforcement across the app reads these values through the entitlements layer
(`App\Services\Entitlements` / `PlanEntitlement`), so toggling a capability here both
hides it in the merchant UI and blocks it server-side. There is no per-tenant override
and no global beta kill-switch; gating is entirely per plan.

The per-plan editor and the same matrix are documented in more detail in
[Configuring plans and pricing](plans-config.md). This section is the operations view
of the same mechanism.

## Platform metrics

The platform records lightweight daily counters so you can monitor render health and
abuse signals without an external metrics stack. This is a backend-only facility:
there is no admin dashboard page for it — the data lives in the `platform_metrics`
table and alerts surface in the application log.

Counters are stored one row per `(day, metric, label)` and incremented atomically
(`PlatformMetrics::record`). Recording is fire-and-forget: a metrics write that fails
is reported but never breaks the request that triggered it. Each row carries a `count`
and a `sum_ms` (latency total, for computing averages).

Metrics currently recorded include:

- `render.ok` / `render.fail` — marketplace theme renders, labelled by theme slug;
  `render.ok` also stores render time in `sum_ms`.
- `app.auth_fail` — failed app session-token checks (labelled by app slug or reason).
- `app.payment_fail` — failed app payments, labelled by app slug.
- `csp.violation` — Content-Security-Policy violation reports, labelled by host.

### Alerts

A daily scheduled job at 07:00 (`platform:metrics-alerts`, in the console kernel)
calls `PlatformMetrics::evaluateAlerts`, which checks today's counters against
thresholds and logs any breach as an error line prefixed `[platform-alert]`. On
cron-only or shared hosting, that log line is the monitoring hook — wire your log
alerting to it.

Thresholds come from `config/platform.php` (`platform.alerts.*`), overridable by env:

- `max_render_failure_rate` (`ALERT_MAX_RENDER_FAIL_RATE`, default `0.05`) — alerts
  when at least 20 renders occurred and the failure rate exceeds 5%.
- `max_app_auth_fails` (`ALERT_MAX_APP_AUTH_FAILS`, default `100`) — auth failures per
  day.
- `max_app_payment_fails` (`ALERT_MAX_APP_PAYMENT_FAILS`, default `50`) — payment
  failures per day.

Related abuse thresholds (`max_theme_render_fails`, `auto_delist_breaching_themes`,
`max_csp_violations_per_host`) feed the separate abuse scan
(`platform:abuse-scan`, daily at 07:10).

## Menu template admin

Restaurant menu templates (the `store_templates` that render a restaurant's public
menu) are managed at **Menu Templates** (`admin.menu-templates.index`). Template code
lives on disk and is discovered automatically; this screen edits a database overlay
(`post_template_meta`) plus the fallback setting and bulk moves — it never edits the
template code itself.

`StorefrontThemeService` is the single source of truth: it discovers templates on disk,
merges the overlay, and reports usage counts.

### Per-template settings

Open a template (`admin.menu-templates.edit`, saved via `admin.menu-templates.update`)
to set its overlay fields:

- **Display name** and **Description**.
- **Preview image** — png/jpg/webp, up to 2 MB.
- **Featured** — highlight in the picker.
- **Enabled** — whether merchants can pick it.
- **Sort order** — integer, controls picker ordering.

Disabling a template hides it from the picker but does not break restaurants already
on it — they are grandfathered and keep rendering. A quick enable/disable toggle is
also available per template (`admin.menu-templates.toggle`).

### Bulk actions

Select template cards with checkboxes and apply a **bulk action**
(`admin.menu-templates.bulk`): `enable`, `disable`, `feature`, or `unfeature`. The
controller refuses a bulk disable that would leave zero enabled templates, so the
picker and new-restaurant validation always have at least one option.

### Fallback template

Set the **fallback template** (`admin.menu-templates.fallback`, stored in the
`fallback_store_theme` option). A restaurant whose template was deleted from disk
renders this fallback instead of erroring. The fallback must itself be an enabled
template.

### Deleted and disabled templates still in use

The index surfaces two problem groups so you can migrate stragglers:

- **Deleted templates still in use** — a slug still referenced by restaurants but no
  longer on disk. These render the fallback until reassigned.
- **Disabled templates still in use** — grandfathered restaurants on a template you
  have disabled.

For either group, open the template's restaurants list
(`admin.menu-templates.restaurants`) to see who is on it, then bulk **reassign**
(`admin.menu-templates.reassign`) every restaurant from one template to another
enabled one. Reassignment updates the `store_template` post option in place and
works even for deleted slugs, since the slug only lives in the restaurants' options.

## Related

- [Configuring plans and pricing](plans-config.md) — the plan editor and full feature
  matrix reference.
- [Managing tenants](tenants.md) — per-restaurant administration.
- [Branding and white-label](branding.md) — panel identity and the `hide_branding`
  capability.
