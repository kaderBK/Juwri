---
title: "Feature spotlight internals"
audience: internal
group: Internal
updated: 2026-07-27
---

# Feature spotlight internals

Engineering and operator reference for the upsell/adoption system (spec in
`rev/upsell-visibility/`, built on `feat/spotlight-foundation`). Covers the
state machine, the guardrails, the event table, the kill-switch, the trial
reminder job and the conversion report. Trust the code paths named here over
the spec.

## What problem it solves

Gated features were invisible in both directions. Someone without a feature saw
a grey padlock with no price and no reason to click; someone *with* it was told
nothing, so a feature included in their plan looked identical to one they never
bought and often never got switched on. Both halves are now served by one
service, and every surface reports what it did — before this, nobody could say
whether a single upsell had ever converted.

## One service, one gate

`App\Services\FeatureSpotlight` is the only thing that decides what a merchant
is told about a key. `FeatureSpotlight::for($key, $owner)` returns a state:

| State | Meaning |
|---|---|
| `locked` | Not entitled, and something actually sells it. |
| `trialing` | Held through an app trial that has not ended. |
| `new` | Entitlement gained within `FeatureGrant::NEW_FOR_DAYS` (14). |
| `included` | Held, not new, and the ribbon has not been dismissed. |
| `none` | Nothing to say. |

`trialing` outranks `new` on purpose: a trial is the only state with a deadline
attached.

`FeatureSpotlight::gateState()` is the tri-state gate (`ok` / `locked` / `off`)
that used to live as a closure inside `juwri_nav_model()`. The sidebar calls it
from there now, so the nav and the spotlight can never disagree about whether
someone holds a key. See [entitlements](entitlements.md) for the gate itself.

## Guardrails (all in the service, never at a call site)

- **One proactive nudge per session.** Toasts and ribbons spend it; nav locks
  and inline teasers are page furniture and do not. Enforced twice: a session
  key, plus a per-request claim, because the session value is only spent when
  the client reports an impression — by then the page is already built.
- **Dismissal is remembered** per owner, per key, per surface.
- **Once-ever surfaces** (`ONCE_SURFACES`) fire a single time. The ribbon is
  deliberately *not* one: seeing it is not reading it, and a page that reloads
  after a save would otherwise consume the moment.
- **No upsell during onboarding** (`ONBOARDING_DAYS`, 7). The first-week
  checklist owns that space.
- **Kill-switch** — `admin_allow_feature_spotlight`, default ON.
- **White-label** — nothing platform-branded renders under a reseller brand,
  using the same `BrandContext` the storefront items use.

A surface added later inherits every one of these for free, which is the only
way a kill-switch stays true as a system grows.

## Tables

- `feature_spotlight_events` — `user_id, feature_key, surface, action, meta`.
  Append-only. Actions: `impression | click | dismiss | convert`. Dismissal
  memory *is* a row here rather than a separate flag.
- `feature_grants` — one row per owner per key: when entitlement was gained,
  from where (`plan|addon|app|admin`), and `last_used_at`.

Index names on `feature_spotlight_events` are given explicitly. The generated
name for the three-column index is 65 characters once the production `qrtest_`
prefix is applied, and MySQL's limit is 64 — sqlite accepts it silently, so the
test suite cannot catch it.

## Conversions

`App\Listeners\TrackFeatureGrant` records a `convert` event when entitlement is
gained **and the owner was shown the key beforehand**, attributed to the surface
of their last impression. Without that condition every admin grant and every
plan migration would inflate the number and the report would be a flattering
lie. A renewal does not count twice.

## Trial reminders

`php artisan apps:trial-reminders` (scheduled daily at 09:00) sends T-3, T-1 and
expiry mail for app trials. Each stage is stamped on
`app_installations.trial_reminders`, so running it twice sends nothing twice.
Unsubscribing **silences** rather than defers — the stage is stamped even when
suppressed, so re-subscribing never delivers a backlog. The opt-out is a signed
URL (`spotlight.trial-emails.unsubscribe`) because it is clicked from an inbox
on a device with no session.

Copy is admin-editable at **Admin → Email Templates → App Trial Reminder**.

## Preview mode

`FeatureSpotlight::PREVIEWABLE` maps keys to read-only dashboards an unentitled
owner may see blurred, with their own aggregates behind the blur. The blur is
CSS and therefore **not a security boundary** — the controller decides what is
safe to send:

- RFM drops the entire per-guest table (every row is a name or phone number);
- delivery insights drops the courier scatter (raw lat/lng) and the area tokens.

Every write and every per-guest route stays gated. If you add a previewable key,
withhold row-level data in the controller, not in the stylesheet.

## Operator surfaces

- **Admin → Settings → Feature Controls → Feature Spotlight** — the kill-switch.
  Off silences every surface at once; entitlements are untouched.
- **Admin → Feature Catalog** — per-key upsell copy. This copy is *content* and
  never passes through `___()`, which would mint a translation key in all ten
  locales on every save.
- **Admin → Apps, Themes & Add-ons → Spotlight Report** — impressions, CTR,
  conversions, dismissal rate, 30-day retention, and the dormancy list.
- **Admin → A/B Experiments** — `spotlight_locked_cta`. Spotlight experiments
  assign per **user**, not per session, so a merchant sees the same arm on the
  till and on their laptop.

## Dormancy

`FeatureDormancy` stamps `feature_grants.last_used_at` when a gated page is
opened. Reporting only: the settled owner decision is **collect, do not
prompt**, and there is no owner-facing `dormant` state.

Grants created before `FeatureDormancy::TRACKING_SINCE` report as *unknown*
rather than *never used* — they predate the tracking, and the F3 backfill would
otherwise fill the report with false dormancy.

## Traps worth knowing

- `StoreOption::getStoreOption()` returns **`false`** for a missing option, and
  `blank(false)` is false. Compare the value explicitly.
- Blade pairs a component's opening and closing tags at **compile** time.
  Wrapping a page in `<x-spotlight.preview>` inside `@if` and closing it inside
  a second `@if` does not conditionally wrap: everything between becomes the
  slot either way and the page renders blank when the condition is false. Always
  compile the wrapper and decide at runtime with a prop.
- `FeatureUpsell` cannot sell a **limit**: its add-on lookup matches
  `kind='boolean'` on `feature_key`, while the packs that raise a ceiling are
  `kind='count'` matched by SKU. `FeatureSpotlight::limitOffer()` resolves those.
