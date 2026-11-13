---
title: Multi-currency & auto-locale — operator controls
audience: internal
group: Platform
updated: 2026-08-08
verified_against: b462672a
---

# Multi-currency & auto-locale controls (UW-01 wiring audit)

Every operator- or visitor-facing control added by the currency-locale run, where
it lives, and how it was verified. "Live" = exercised in real Chrome on
`template.test`; "test" = covered by an automated test; "owner-verify" = an
auth-gated admin/dashboard surface whose pixel render an operator should confirm
once (functional wiring is test-proven).

## Merchant / admin controls

| Control | Where to click | WI | Verified |
|---|---|---|---|
| **Currency-change guard** — a confirm checkbox appears on the store settings form only when the store already has orders; changing the currency without it is rejected and logs `store.currency_changed`. | Dashboard → store settings (edit) | MC-12 | test; owner-verify pixel |
| **Rates source** (Manual / Auto) + **Display currencies** — sets `currency_rates_mode` + `display_currencies`; Auto pulls the daily FX feed, manual lines always pin. | Store settings, beside the existing multi-currency toggle | MC-33 | test; owner-verify pixel |
| **FX feed health** — last-fetch time + a stale flag on the admin ops card, so the daily `currency:refresh-rates` feed can be seen alive without artisan. | Admin dashboard → operations/health card | MC-30 | test; owner-verify pixel |
| **Charge history (billing receipts)** — durable per-charge ledger; find a past charge by clicking, not SQL. | Admin → Transactions → "Charge history" (`/admin/receipts`) | MC-02 | test; route 302→login (auth-gated, correct); owner-verify pixel |
| **Per-currency reporting chips** — owner dashboard fleet strip, analytics + CSV "Revenue by Currency", Fusion GMV, admin revenue charts (foreign chips), peer benchmarks (per-currency), driver earnings map. Render ONLY when a result spans >1 currency; single-currency is byte-identical. | Respective dashboards / CSV export | MC-40..43 | test; owner-verify pixel |

## Storefront (diner) controls

| Control | Where | WI | Verified |
|---|---|---|---|
| **Display-currency switcher** — the `[data-cur]` buttons in all 16 modern themes now parse rates through the unified `DisplayRates`. NOTE: only **aurora** reprices client-side; the other 15 render the switcher but do not convert (pre-existing gap — owner action). | Storefront preferences / currency chip | MC-32 | live (curl-all-14 byte-identical; aurora reprice live) |
| **Approximate-price guardrail** — in aurora, converted prices show `≈` and a sticky banner "Prices in {CUR} are estimates. You will be charged in {BASE}." | Aurora storefront, after switching currency | MC-34 | live (EUR → ≈ EUR 4.60 + banner) |
| **First-visit language banner** — suggests an offered language matching `navigator.languages`; CTA relays through the existing switch; remembers choice/dismissal. Client-only, SEO-safe. | Bottom of storefront + legacy landing | AL-10/11/12 | live (accept→/fr/, dismiss, suppress; Googlebot byte-identical) |
| **First-visit currency default** — region (geo or `navigator.language`) → an offered currency → auto-clicks the theme's own switcher; records `qc_cur_choice_{slug}`. No-op on no-rates stores / switcher-less themes; never touches a charge/total/JSON-LD. | Storefront, first load | AL-20 | live (DE visitor → EUR auto-select) |

## Theme (light/dark/system)

| Control | Where | WI | Verified |
|---|---|---|---|
| **Landing theme toggle** — now tri-state light → dark → **system** (system follows the OS live). | Marketing site header sun/moon | AL-30 | live (cycle confirmed) |
| **Dashboard theme toggle** — tri-state; "system" clears the stored choice so pre-paint + a matchMedia listener follow the OS. | Dashboard topbar | AL-31 | head-logic live; owner-verify on a logged-in dashboard |

## Ships dark (owner provisions)

- **Geo** (`config/geoip.php`): `GEOIP_TRUST_CF_COUNTRY` (only behind Cloudflare + TRUSTED_PROXIES) or `GEOIP_MMDB_PATH` (local GeoLite2). Off by default → `visitor_country()` returns null, `QC_GEO` is not emitted, AL-20 falls back to `navigator.language`. IP never sent to a third party.
- **FX cron**: schedule `currency:refresh-rates` daily per install.

## Owner actions still owed

1. FX daily cron — **no code owed**: `currency:refresh-rates` is already registered in
   `app/Console/Kernel.php` (`->dailyAt('04:10')`). The only owner step is the standard
   OS cron every install needs: `* * * * * php artisan schedule:run`.
2. ~~Gulf-gateway 3-decimal fils truncation.~~ **DONE 2026-08-08** — the redirect/hosted-checkout
   drivers (Tap, Paytabs, Telr, Tabby, Tamara) sent the charge amount at a hardcoded 2
   decimals, truncating the 3rd (fils) digit for KWD/BHD/OMR/JOD/TND/LYD/IQD — under-billing
   every order. New `GatewayMinorUnits::toMajorFloat/toMajorString` format the major-unit
   amount at the currency's real precision; all five drivers (charge + Tabby/Tamara capture)
   route through it. Unit tests in `GatewayMinorUnitsTest`. (Stripe/Lemon Squeezy were already
   correct via `toMinor`.)
3. Display-currency switcher — **NO ACTION NEEDED (misdiagnosis corrected 2026-08-08).**
   The switcher is NOT inert: every modern theme's own
   `assets/store_templates/<theme>/js/script.js` already reprices on switch —
   `money(base) = base * S.curRate` with display-currency decimals (crave, matcha,
   glow, dough, plate, lime, orbit, velora, soiree, ember, crumb), and vivid's
   `fmt()` / `.vd-amt[data-vd-base]` handler. The reprice JS lives in script.js, not
   the blade, which is why an earlier blade-only scan wrongly flagged it "cosmetic"
   (the run note had said "confirm live-vs-cosmetic first"). Browser-verified live on
   crave (OMR→EUR reprices menu prices, base restores) and vivid. A short-lived commit
   that hid the switcher on 12 themes was reverted. Regression guard:
   `CurrencySwitcherRendersTest` asserts a multi-currency store renders the switcher on
   all 13 themes. **Decimal-fallback bug — FIXED 2026-08-08 (3ea79207):** `crave`'s
   `money()` was the ONLY theme that fell back to the STORE's decimals for a converted
   currency absent from `decimalsByCode`, so a 3-decimal-base store showed USD/EUR with
   3 decimals. Now defaults to 2 for non-base (only the base currency uses store
   decimals); browser-verified `USD 143.000`→`USD 143.00`. The other 11 themes already
   handled it correctly (moneyDp / decimalsFor / curDp with a `?? 2` default).
4. **AL-42 — add the cookie/geo disclosure to the privacy policy** (privacy is an
   owner-edited CMS page, so it cannot be injected in code). Paste this:
   - **Cookies (functional, client-set, non-tracking):** `qc_lang_choice` +
     `qc_lang_dismissed` (auto-locale banner memory, ~1 year); `qc_cur_choice_{store}`
     (chosen display currency, localStorage); `Quick_user_lang_code` / `Quick_lang` /
     `Quick_user_lang` (language switch); `landing-theme` / `dash.theme` + the `theme`
     cookie (light/dark/system preference); `mk_ctx`, `reseller_ref`.
   - **Geolocation posture:** an approximate country may be derived from a
     trusted-proxy Cloudflare header or a local GeoLite2 database *only when the
     operator enables it*; the visitor's IP address is never sent to a third party
     for this. Off by default.
5. ~~`user_ip_lookup()` raw-IP forever-cache / ip-api leak (`task_6ea4998c`).~~
   **DONE 2026-08-08** — `user_ip_lookup()` (Helper.php) no longer POSTs the raw IP
   to `http://ip-api.com` and no longer `Cache::forever($ip, …)`. Country now comes
   from `visitor_country()` (trusted-proxy CF header or local GeoLite2 only; dark by
   default); the country name resolves locally from the `countries` table;
   city/postal/lat/lon/timezone return the pre-existing `Other`/`Unknown` sentinels.
   Regression tests in `AutoLocaleBackendTest` (no-cache, geo-dark, local-name-resolve).
6. Live-dashboard pixel checks for the auth-gated admin/dashboard controls above.
