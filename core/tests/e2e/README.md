# Browser e2e smoke (T8)

Puppeteer-driven journeys that click **real controls** in a real browser — the
net for client-side breakage a green HTTP suite is blind to (the fusion-crave
run shipped 8 broken storefront controls under a fully-green suite).

No new dependency: reuses the `puppeteer` already locked in `package.json`.

## Journeys

| name | what it clicks | data |
|------|----------------|------|
| `storefront-checkout` | crave (legacy) storefront: open product → add to cart → checkout view | `ThemePackDemoSeeder` |
| `dashboard-login` | merchant login form → dashboard | `ThemePackDemoSeeder` owner |
| `staff-pwa-login` | staff PWA entry: serve manifest+SW, clear SW/cache, submit login reliably | `ThemePackDemoSeeder` owner |
| `role-pages-smoke` | owner dashboard pages + customer session/account; optionally true staff, reseller, and admin page matrices | seeded owner/customer + role env credentials |
| `retained-template-csp` (opt-in) | all 17 retained storefronts: real fixture search/result → product/modifiers → cart/minimum order → checkout; additionally audits Orbit, Velora, and Crumb language, stories, linked offers, selectable categories, home order/address controls, product navigation/reviews, and Crumb modifier-state preservation; supports 390×844 and 1600×1000 layout assertions; rejects inline handlers, page errors and strict-mode CSP refusals | retained `*-demo` stores |
| `pos-sale` (opt-in) | POS register: add item → tender sale | `PosV2DemoSeeder` + seeded staff |
| `bookings-services` (opt-in, `E2E_RUN_P310=1`) | services store: owner checklist → service, staff, hours forms → customer books a held time → .ics → reschedule → cancel → owner list; another owner 403 / foreign slug 404 | ThemePackDemoSeeder + showcase owners; the store is a tinker fixture |
| `book-theme` (opt-in, `E2E_RUN_P311=1`) | the `book` theme: Sidra Salon (`BookDemoSeeder`) services booking (tick → quoted total → time → hold → confirm → .ics → cancel; deposit service → pay page) and a laundry store on `book` (pieces + kilos → ONE hold for pickup and delivery → confirm → order total == quote) | `BookDemoSeeder`, a fixture laundry store |
| `fitment-import` (opt-in, `E2E_RUN_P308B=1`) | car-parts store: owner uploads one fitment CSV (range, foreign store_id column, unknown SKU, bad year) → preview adds nothing → confirm → rows exist for this store only → storefront API `vehicle` filter (confirmed fits, `fits_vehicle`, VIN prefix, 422) → another owner 404 | a fixture car-parts store + owners of the other journeys |
| `p4-hr` (opt-in, `E2E_RUN_P4=1`) | rota + clock in/out: owner adds a shift for Sam and publishes the week → Sam signs in to the staff app (draft invisible, published shift visible, double clock-in refused, clock-out minutes) → owner payroll page + CSV show his worked hours → another owner / the other store's staff: 403 / 404 on rota, payroll, CSV, delete-shift, employee file, cross-store scheduling; Zed's clock entry stays in store B. Runs on the Enterprise tree and on the Community mirror | fixture stores + staff made by tinker (`lib/p4.js`), ThemePackDemoSeeder + showcase owners |
| `p4-inventory` (opt-in, `E2E_RUN_P4=1`) | stock: owner tracks Latte (5) + Muffin (1) → guest orders online (stock follows) → cashier sells at the POS (same stock, item reads Low) → more than is left is refused online and takes nothing, the POS records it and the owner's screen counts the oversell → the last Muffin goes Sold out and cannot be ordered → dashboard flags low / sold-out + the daily digest → owner restocks from the stock screen and it sells again → another owner / store B: 403 / 404 on the screen and every write, a foreign item id refused on both storefront and register | the owners' first restaurant stores + fixture items and staff (`lib/p4.js`) |
| `p4-preorders` (opt-in, `E2E_RUN_P4=1`) | pre-orders: owner switches pre-orders on in the store settings screen (lead 15 / slot 15 / 3 days / prep 20 / 2 per slot), the branch opens 10:00-18:00 → guest books tomorrow 12:00 (scheduled_at 12:00, fire_at 11:40, not released) → refused: inside the lead window, beyond the horizon, outside opening hours, a full slot; the manual closed switch blocks an order for now but not a pre-order → kitchen staff: not on the live board, listed as upcoming → the clock moves + the release tick → released, owner accepts it in the order list, now on the board (tomorrow 13:00 still not) → another owner / store B: 403 on board + upcoming list, 404 on release, no status change | the owners' first restaurant stores, their auto-created Main branch (hours set and restored), fixture item + staff |
| `p4-receipts` (opt-in, `E2E_RUN_P4=1`) | receipts/printing: owner pays a POS order → browser receipt (lines, total, type, payment) and the price-free kitchen ticket → a customer's Arabic order prints an Arabic receipt and kitchen ticket (no English label, same total) → ESC/POS receipt + kitchen jobs queue and the register collects the bytes → another store's cashier/owner: 404 on receipt, ticket, print jobs and printer profile (A order onto B printer, B order from A staff) | owner's first restaurant store, fixture items, cashier staff, printer profile |
| `p4-webhooks` (opt-in, `E2E_RUN_P4=1`; runs through `the webhook_e2e.sh wrapper`, which gives it a public https receiver) | webhooks: owner adds endpoints in the API & webhooks screen (http / loopback / localhost URLs refused) → an order fires `order.created` only to the endpoint subscribed to it, with a verifying Standard-Webhooks signature and the store's data → receiver answers 500: logged failed, streak counts → receiver recovers, owner re-delivers from the log (fresh message id, streak resets) → paused endpoint gets nothing, resumed one does → 15 failures auto-disable it, resume starts a clean streak → another owner: 404 on toggle / test / delete / re-deliver, cannot see it, their store's orders never reach it | the owners' first restaurant stores, fixture item |
| `p4-marketing` (opt-in, `E2E_RUN_P4=1`; the SMS leg needs `E2E_RUN_PLATFORM_V2=1`) | marketing: customers from real checkouts with different consent → an e-mail campaign from the screen reaches exactly the consenting customers (not the unconsented one, not the other store's) → an SMS campaign only those who also consented to SMS → the signed unsubscribe link works (tampered link 401, only that customer changes) and the next campaign skips them → the public unsubscribe form withdraws both channels for that store only → ticking consent again opts back in → another owner: 404 on the campaign, cannot aim a campaign at this store | the owners' first restaurant stores, fixture item, customers made by checkout |
| `p4-agents` (opt-in, `E2E_RUN_P4=1`; needs the stub AI, `E2E_RUN_PLATFORM_V2=1` on the runner) | AI agents: the owner agent (MCP over an API token) lists only its own stores; every call naming store B's store / order / item / category is refused and B's data is unchanged; the storefront agent bound to store A cannot read or order store B's item; the on-menu chat of A answers and logs in A only, a store with chat off answers 403. Spend caps and prepaid-credit / own-key rules are PHP-tested (`AgentSpendCapTest`, `AgentStoreIsolationTest`, ee `CreditUsagePathsTest`): the E2E stub replaces the metered AI service | the owners' first restaurant stores, fixture items + category, an API token and a storefront token |
| `p4-webkit` (opt-in, `E2E_RUN_P4=1` + `E2E_WEBKIT=1`; runs through the webkit_e2e.sh wrapper on the runner) | Safari engine (Playwright WebKit, libraries unpacked in user space, no root): the five kit themes on their demo stores in an iPhone-14 context (loads, no script error, no overflow, a checkout from the WebKit page, tracking page), dashboard sign-in, staff sign-in + POS sale. **Manual iPhone check** (WebKit cannot show these headless on Linux): Apple Pay, camera / QR scan, push notifications, PWA install, standalone mode and the service worker (Playwright WebKit stalls the dashboard page while it registers; service workers are blocked in this run), safe-area and keyboard behaviour on a real phone, real touch gestures / momentum scrolling, https fonts (the Linux WebKit build has no TLS) | demo stores of ThemeShowcase / KitDemo / BookDemo seeders, Dune & Co. fixture |
| `p6108-onboarding-sa` / `-ae` / `-jo` / `-eg` / `-ly` / `-eg-paymob` (uncovered group, `E2E_RUN_UNCOVERED=1` or named) | owner onboarding per launch country: signup + mailed-link verification → store → country defaults (country and language set; the currency follows the country with no manual step (P6-108b), J32 tax default; the tax leg logs SKIPPED on a tree without P6-116) → menu with 2 items → the country's gateway in TEST mode (SA Stripe, AE Telr, JO Stripe, EG Fawry, LY T-Lync, EG Paymob on the Egypt host by country; invented keys; secret encrypted and never printed) → first storefront order (currency, minor-unit totals, tax) → owner accepts → payout settings (bank transfer IBAN, gateway survives the second save). DB rows asserted at each step | fresh owner per run, seeders only; steps in `lib/onboarding.js` |
| `docs-screenshots` (opt-in) | owner login → captures the 5 top help-center screens (dashboard, menu, qr-designer, themes, POS) to committed `assets/docs/img/*.png` | `ThemePackDemoSeeder` owner |

Each journey runs in its own isolated browser context (no cookie/SW leakage
between journeys). Screenshots are written to `__screenshots__/` (gitignored) —
**except** `docs-screenshots`, whose PNGs are product content committed under
`assets/docs/img/` and embedded by the matching guides.

## Regenerating the docs screenshots (D4-7)

The five help-center guides (getting-started, menu-items, qr-codes, themes, pos)
embed committed screenshots served at `/assets/docs/img/`. One set per locale:
English at `assets/docs/img/*.png` (referenced by `docs/users/*.md`) and Arabic
at `assets/docs/img/ar/*.png` (referenced by `docs/users/ar/*.md`). Regenerate
after a dashboard UI change:

```bash
cd core
php artisan db:seed --class=ThemePackDemoSeeder --force                    # owner + dashboard store
php artisan db:seed --class=Database\\Seeders\\ThemeShowcaseSeeder --force # flagship crave-demo (100+ photo'd items)
php artisan db:seed --class=DocsScreenshotSeeder --force                   # 2 weeks of paid orders -> live KPIs
E2E_BASE_URL=https://your-site.test E2E_RUN_DOCS=1 \
  npm run test:e2e -- docs-screenshots
git diff --stat ../assets/docs/img   # review, then commit the changed PNGs
```

**Data.** getting-started shoots the ThemePackDemoSeeder dashboard (the
"Get started" checklist); `DocsScreenshotSeeder` adds order history so the KPI
cards/charts show a living store. The other four screens shoot the
`ThemeShowcaseSeeder` flagship (`crave-demo`, 100+ items with real food
photography). Overrides: `E2E_DOCS_RICH_EMAIL` / `_PASS` / `_SLUG`.

**White-label.** The PNGs are shared, but the owner Help Center renders on
reseller hosts too, so the journey scrubs the PLATFORM brand before each
dashboard shot — the sidebar logo is swapped for a neutral tile and the brand
wordmark (read from the logo `alt` / `og:site_name`) is rewritten to "Demo". POS
has no platform chrome, so it is not scrubbed.

**Framing.** Locales come from `E2E_DOCS_LOCALES` (default `en,ar`). Each screen
is the **whole 1500×1500 square viewport at deviceScaleFactor 2** — full width
(nothing cut horizontally), square + top-anchored (focused, not an endless
scroll), and retina-sharp (the 3000² PNG stays crisp when the doc scales it
down). The app localizes by URL prefix (`/{locale}/...`; `hideDefaultLocaleInURL`
makes an unprefixed URL the site default). NOTE: the POS register renders its
chrome in the store's operating language regardless of the URL locale (it sits
outside the localization group), so both `pos.png` sets show the same register.
The nightly `e2e` job sets `E2E_RUN_DOCS=1` and uploads the regenerated set as
the `docs-screenshots` artifact; download it to re-baseline.
`DocsPlatformDriftTest::test_embedded_doc_screenshots_exist` fails CI if a guide
references a PNG that is not committed.

## Run locally

```bash
cd core
composer install && npm install            # puppeteer downloads Chromium once
php artisan migrate --force
php artisan db:seed --class=ThemePackDemoSeeder --force
E2E_BASE_URL=https://your-site.test npm run test:e2e
```

Subset / opt-in POS:

```bash
npm run test:e2e -- storefront-checkout
E2E_RUN_POS=1 E2E_STAFF_USER=... E2E_STAFF_PASS=... npm run test:e2e
E2E_RUN_RETAINED_CSP=1 E2E_REQUIRE_CSP=1 npm run test:e2e -- retained-template-csp
E2E_RUN_RETAINED_CSP=1 E2E_RETAINED_MOBILE=1 npm run test:e2e -- retained-template-csp
E2E_RUN_RETAINED_CSP=1 E2E_RETAINED_WIDE=1 E2E_RETAINED_TEMPLATES=orbit npm run test:e2e -- retained-template-csp
```

Wave-10 opt-in journeys (P1 continuation):

```bash
# STF-013: POS void/refund corrections through the orders-hub UI.
# Staff needs pos + pos_refund permissions.
E2E_RUN_POS_REFUND=1 E2E_STAFF_USER=... E2E_STAFF_PASS=... npm run test:e2e -- pos-refund-void

# E2E-005: coupon branches at storefront checkout. The store's owner needs an
# active percentage coupon (default code P1TEST10) and allow_coupons=1.
E2E_RUN_RETENTION=1 E2E_STORE_SLUG=crave-demo npm run test:e2e -- cus-retention-coupon

# E2E-008: multi-tab store context isolation. Owner with two stores plus the
# id and public slug of a store they do NOT own.
E2E_RUN_MULTITAB=1 E2E_OWNER_EMAIL=... E2E_OWNER_PASS=... \
E2E_STORE_A=... E2E_STORE_B=... E2E_FOREIGN_STORE=... E2E_FOREIGN_SLUG=... \
npm run test:e2e -- multi-tab-context
```

True privileged-role coverage is credential-driven. If credentials are absent,
the role is logged as `not-configured` and is not counted as a pass:

```bash
E2E_BASE_URL=https://your-site.test \
E2E_STAFF_USER=... E2E_STAFF_PASS=... \
E2E_RESELLER_EMAIL=... E2E_RESELLER_PASS=... \
E2E_ADMIN_EMAIL=... E2E_ADMIN_PASS=... \
npm run test:e2e -- role-pages-smoke
```

The journey isolates every actor in its own browser context. Its customer check
performs a real password login, verifies that an authenticated account request
does not redirect, and requires the active account/logout UI. Use a storefront
whose `customer_auth_mode` is `optional` or `required`; a `guest_only` store
cannot provide customer-account coverage.

## Config (env)

| var | default | meaning |
|-----|---------|---------|
| `E2E_BASE_URL` | `https://imp-a.test` | base URL of the seeded instance |
| `E2E_STORE_SLUG` | `themepack-demo` | crave storefront slug |
| `E2E_OWNER_EMAIL` / `E2E_OWNER_PASS` | seeder owner | merchant login |
| `E2E_STAFF_USER` / `E2E_STAFF_PASS` | owner fallback | staff/PWA login |
| `E2E_DINER_EMAIL` / `E2E_DINER_PASS` | seeder customer | customer session/account smoke |
| `E2E_OWNER_PATHS` | `/dashboard,/stores,/orders,/customers,/settings` | comma-separated owner page matrix |
| `E2E_STAFF_PATHS` | `/staff-app` | comma-separated staff page matrix; the PWA workspaces such as Orders and Kitchen are in-app tabs, not nested URL routes |
| `E2E_RESELLER_EMAIL` / `E2E_RESELLER_PASS` | unset | enables reseller role smoke |
| `E2E_RESELLER_PATHS` | `/reseller,/reseller/customers,/reseller/security` | comma-separated reseller page matrix |
| `E2E_ADMIN_EMAIL` / `E2E_ADMIN_PASS` | unset (set by the nightly workflow; user minted by `E2eAdminSeeder`) | enables admin role smoke — H14 wires these into `.github/workflows/nightly.yml` so the driver-forked `/admin/users` page is smoked |
| `E2E_ADMIN_PREFIX` | `/admin` | customized `admin_url()` prefix |
| `E2E_ADMIN_PATHS` | dashboard, orders, settings under admin prefix | comma-separated admin page matrix |
| `E2E_RUN_POS` | unset | `1` to include the POS journey |
| `E2E_RUN_RETAINED_CSP` | unset | `1` to register the 17-template customer interaction journey |
| `E2E_REQUIRE_CSP` | unset | `1` makes the retained-template journey require the enforcing storefront CSP header |
| `E2E_RETAINED_TEMPLATES` | all 17 retained templates | optional comma-separated subset for focused reruns |
| `E2E_RETAINED_MOBILE` | unset | `1` runs the retained journey at 390×844 and rejects horizontal overflow |
| `E2E_RETAINED_WIDE` | unset | `1` runs the retained journey at 1600×1000; use with Orbit for its large-screen regression |
| `E2E_IGNORE_HTTPS` | `1` | ignore self-signed Herd certs |
| `E2E_HEADLESS` | `1` | `0` to watch the run |

## CI

Runs **nightly only** (`.github/workflows/nightly.yml`) — slow and flake-prone,
so it never gates a PR merge. The seed supplies owner/customer coverage; H14
adds the `admin` role (see `E2E_ADMIN_*` above, minted by `E2eAdminSeeder`).
Add further role secrets to the nightly job only when dedicated non-production
staff/reseller accounts are available. Screenshots upload as a workflow
artifact for triage. Per-PR latency stays on the sqlite suite.

## The `@group mysql` PHPUnit lane (H14)

Prod is MySQL 8, but `core/phpunit.xml` pins sqlite `:memory:`, so a few
production paths never executed under test: the driver-forked SQL in
`Admin\UserController` (`UNIX_TIMESTAMP` / `JSON_UNQUOTE` / `DATE_FORMAT`),
`PlatformMetrics`' mysql-gated backup alert, and `PosService::refund()`'s
`lockForUpdate` (a no-op on sqlite, so refund-cap tests proved arithmetic and
never mutual exclusion). Those tests carry `#[Group('mysql')]` and are run by
the nightly **PHPUnit Tests (MySQL 8, @group mysql)** job.

`Tests\TestCase` honours the group at runtime: on any non-MySQL connection a
`#[Group('mysql')]` test **skips itself**, so a plain `php artisan test`
(sqlite) stays green with no `--exclude-group` flag. Run the lane locally with:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=<throwaway_schema> \
  DB_USERNAME=root DB_PASSWORD= php artisan test --group mysql
```

Use a throwaway schema: `MysqlLanePosRefundLockTest` runs without a per-test
transaction (`$connectionsToTransact = []`) so a second connection can observe
the row lock, and `RefreshDatabase` will `migrate:fresh` (drop everything).
