---
title: "Production release test scenarios"
audience: internal
group: Internal
updated: 2026-07-31
---

# Production release test scenarios

This is the durable release-candidate test record for juwri. It combines
automated gates with browser and infrastructure checks that cannot be proven by
the SQLite feature suite. Copy the sign-off section for each release; do not
replace an unexecuted role or provider with a pass.

For the exhaustive real-user feature backlog—plans, add-ons, catalog,
operations, finance, providers, every role, and the retained-template
matrix—use [Full role and feature test plan](full-role-feature-test-plan.md).

## Release record and prerequisites

Record these before testing:

| field | value |
|---|---|
| Release/tag/commit | |
| Candidate environment and URL | |
| Tester and date (UTC) | |
| Database snapshot/restore point | |
| Browser + viewport(s) | |
| Enabled themes and payment providers | |
| Known feature flags | |

Prerequisites:

1. Use production-like PHP, extensions, database, cache, session, queue, proxy,
   web-server, TLS, and environment settings. Never point destructive scenarios
   at live customer data.
2. Seed or provision separate public, customer, owner, staff, reseller, and
   admin actors. Give each the least privilege needed. Record absent roles as
   `NE` (not executed), not `PASS`.
3. Create at least two owners, two stores, and two branches so cross-tenant
   access can be tested. Provision a product, table, customer, and order.
4. Configure sandbox provider credentials and signing secrets for each enabled
   payment, delivery, SMS, and voice provider. Keep unsigned callbacks disabled.
5. Set `APP_ENV=production`, `APP_DEBUG=false`,
   `TOKEN_QUERY_FALLBACK=false`, `CSP_ENFORCE=true`,
   `ABUSE_MODE_*` enforcement values, a non-empty `HEALTHZ_TOKEN`, and explicit
   `TRUSTED_PROXIES`. Set `MCP_ALLOWED_ORIGINS` only for browser origins that
   genuinely host an MCP client.
6. Capture browser console errors, same-origin responses with status 500+, queue
   failures, application logs, and screenshots while executing browser cases.

Status values:

- `PASS` — the exact scenario was executed against the recorded candidate with
  the required actor, configuration, and retained evidence.
- `FAIL` — the scenario executed and an expected result was not met. A release
  cannot be signed off while a release-blocking failure remains open.
- `BLOCKED` — execution started or was ready to start, but an environment,
  account, 2FA, provider, or infrastructure dependency prevented a result.
- `NE` — not executed, including cases where credentials or provider sandboxes
  were not supplied.

An automated pass does not turn a related manual, sandbox, or true-role case
into a pass. Roll-up results must list `BLOCKED` and `NE` case IDs explicitly;
never average or silently omit them.

## Role and platform matrix

| role | authentication | minimum surfaces | forbidden proof |
|---|---|---|---|
| Public | none | landing, pricing, blog, store menu, cart, checkout entry | dashboard/admin/reseller pages redirect or reject |
| Customer | store-scoped session/token | login/register, account, addresses, order history/reorder | another store's customer/account/order data rejected |
| Owner | web session + re-auth where required | dashboard, store context, menu, orders, customers, settings | admin and unrelated tenant objects rejected |
| Staff | staff session/token + permissions | staff PWA, assigned store/branch, allowed order/POS actions | owner settings and disallowed permissions rejected |
| Reseller | web session + reseller-owner gate | portal, customers, wallet, security, configuration | other reseller records and admin rejected |
| Admin | web session + admin gate | admin dashboard, users, orders, settings, operations | password-confirmed sensitive actions still required |

## Automated release gates

| ID | scenario | command/evidence | expected |
|---|---|---|---|
| AUTO-001 | PHP feature suite | `cd core && php artisan test` | Zero failures; skipped tests explained |
| AUTO-002 | Production security regression set | `php artisan test tests/Feature/ProductionSecurityRoutesTest.php tests/Feature/HealthCheckTest.php tests/Feature/StorefrontApiTest.php` | Update/CSRF, health, and token rules pass |
| AUTO-003 | MCP regression set | `php artisan test tests/Feature/McpProtocolTest.php tests/Feature/DevMcpTest.php tests/Feature/CatalogMcpTest.php tests/Feature/CustomerMcpTest.php` | All five endpoints and negotiated versions pass |
| AUTO-004 | Provider-signature regression set | `php artisan test tests/Feature/HungerStationOrderIngestTest.php tests/Feature/AgentSmsTest.php` | Missing/invalid secrets fail closed; valid signed fixture passes |
| AUTO-005 | PHP dependency integrity/security | `composer validate --strict && composer audit --locked` | Valid lock and zero blocking advisories |
| AUTO-006 | JavaScript production dependency audit | `npm audit --omit=dev` in `core/` and `desktop/` | Zero production advisories |
| AUTO-007 | PHP syntax/style | `vendor/bin/pint --test <touched PHP files>` plus `php -l` where needed | Touched code is formatted and valid |
| AUTO-008 | JavaScript syntax | `node --check` for touched runtime and E2E files | Zero syntax failures |
| AUTO-009 | Laravel optimized boot | run `config:cache`, `route:cache`, and `view:cache`, then clear them | All caches build without closure/config/view errors |
| AUTO-010 | Diff integrity | `git diff --check` and explicit review of user-owned translations | No whitespace errors or accidental file churn |
| AUTO-011 | Blocking static analysis and debt ratchet | `vendor/bin/phpstan analyse --no-progress --memory-limit=2G` plus `.github/scripts/phpstan-baseline-ratchet.sh` | Level 6 reports zero unsuppressed errors; the committed baseline contains 5,696 suppressions, down 480 from 6,176, and cannot increase |
| AUTO-012 | Browser journeys | `E2E_BASE_URL=... npm run test:e2e` and the executed-role summary | Default seeded journeys pass; screenshots are retained; credential-gated roles remain `NE` unless actually executed |
| AUTO-013 | Retained storefront interaction/CSP/mobile matrix | `E2E_RUN_RETAINED_CSP=1 E2E_REQUIRE_CSP=1 E2E_RETAINED_MOBILE=1 E2E_BASE_URL=... npm run test:e2e -- retained-template-csp` | All 17 retained themes derive a real product query, return a visible search result, open the product, satisfy modifiers/minimum order, add to cart, then expose an enabled final action, rendered total, fulfillment and contact/auth checkout controls; mobile mode requires no checkout overflow and an in-viewport final action at 390×844; a submission guard prevents order placement; strict mode requires the enforcing header with zero CSP refusal or inline event handler |

### Current automation and manual boundary

The repository currently provides the following coverage. This is a mapping of
available evidence, not a release result; record candidate-specific outcomes in
the sign-off table.

| area | automated evidence available | still requires release-candidate execution |
|---|---|---|
| PHP/static quality | Feature suites, blocking PHPStan level 6 with zero unsuppressed errors, and a 5,696-entry non-increasing baseline | Continue reducing baseline debt; investigate every new or changed suppression |
| Public/storefront | Storefront API tests, `storefront-checkout`, and the 17-theme search-to-checkout-contract `retained-template-csp` journey, including a 390×844 real-interaction pass | Repeat on exact candidate; controlled final order submission, broader responsive/assistive-device and provider choices |
| Customer | API/customer tests and the 2026-07-29 real login/account browser pass | Repeat with release-candidate data; exercise destructive profile/address/order mutations separately |
| Owner | Auth/tenant tests and the 2026-07-29 dashboard page matrix | Store/branch context synchronization, customizer save, mobile/AR, and enforced CSP |
| Staff | Staff tests, `staff-pwa-login`, and the 2026-07-29 local dedicated-manager smoke | Repeat the least-privilege matrix against the release candidate; `UI-010` POS remains destructive/manual |
| Reseller | Reseller feature tests and the 2026-07-29 local dedicated-reseller smoke | Repeat `UI-011` against the release candidate with its production-like 2FA and CSP configuration |
| Admin | Admin/security feature tests and the 2026-07-29 local dedicated-admin smoke | Repeat `UI-012` against the release candidate; updater rehearsal, re-authentication, and sensitive mutations remain manual |
| Providers | HungerStation and SMS signature/fail-closed fixtures | Sandbox UAT for every enabled payment, delivery, SMS, and voice provider |
| CSP and abuse enforcement | Policy/cache ratchets plus all 17 retained product/cart/checkout journeys passed under `CSP_ENFORCE=true` on 2026-07-30 | Repeat against exact candidate; dashboard CSP and load/abuse checks with production `ABUSE_MODE_*` values remain |
| Deploy and recovery | Optimized-boot gates and deploy-script assertions | Disposable-environment failure injection, worker/scheduler checks, backup restore, and recovery drill |

The local dedicated-role smoke below does not replace release-candidate proof.
Until candidate evidence is attached, the corresponding production run,
provider sandbox UAT, enforced-CSP/abuse checks, and recovery drills remain
`NE` or `BLOCKED`, never inferred as `PASS` from local or automated coverage.

### Safe parallel execution for local shells or agents

Parallelize independent evidence collection, not shared mutable state. Assign
one owner per lane and agree on file ownership before editing:

| lane | safe scope | example command/evidence |
|---|---|---|
| A — security/API | updater, CSRF, health, and token regression | `php artisan test tests/Feature/ProductionSecurityRoutesTest.php tests/Feature/HealthCheckTest.php tests/Feature/StorefrontApiTest.php` |
| B — MCP | protocol and endpoint regression | `php artisan test tests/Feature/McpProtocolTest.php tests/Feature/DevMcpTest.php tests/Feature/CatalogMcpTest.php tests/Feature/CustomerMcpTest.php` |
| C — providers | signed-callback and fail-closed fixtures | `php artisan test tests/Feature/HungerStationOrderIngestTest.php tests/Feature/AgentSmsTest.php` |
| D — static/dependencies | PHPStan, Composer, and npm gates | Run `AUTO-005`, `AUTO-006`, and `AUTO-011`; retain complete logs |
| E — browser roles | one dedicated actor/store per worker | Run one journey or true-role matrix per worker and retain its role summary/screenshots |
| F — operations | isolated deploy, recovery, and restore rehearsal | Use a disposable environment with its own database, cache, queue, and storage |

Each concurrent PHP lane needs an isolated test database when the configured
test driver is not in-memory. Each browser/operations lane needs distinct
accounts, tenant data, cache/session namespace, queues, and screenshot/artifact
directory. Do not concurrently run migrations, seeders, cache-build commands,
POS/payment mutations, or recovery drills against the same candidate. If
isolation cannot be proven, run those cases serially.

At lane completion, record the commit, environment, command, result, duration,
log or screenshot path, executed role, and blocked/NE IDs. One release owner
then merges the evidence into the sign-off table and checks that no lane called
an unexecuted credential-dependent scenario a pass.

## Security and authentication scenarios

| ID | actor | scenario | expected | automation |
|---|---|---|---|---|
| SEC-001 | Guest | GET/POST every `/update/*` route | Redirect to login; no maintenance mode, file, cache, or version mutation | `ProductionSecurityRoutesTest` |
| SEC-002 | Owner/staff/reseller | attempt `/update/*` | 403/redirect; no update side effects | `ProductionSecurityRoutesTest` |
| SEC-003 | Admin | access updater without recent password confirmation | Redirect to password confirmation | `ProductionSecurityRoutesTest` |
| SEC-004 | Confirmed admin | execute updater in a disposable clone with valid package | Only intended version changes; app returns from maintenance on success/failure | Manual |
| SEC-005 | Owner | POST `/api/webhooks` without CSRF | 419; authenticated webhook-management writes are not exempt | `ProductionSecurityRoutesTest` |
| SEC-006 | Provider | POST explicit `webhook/*`, `webhooks/reseller/*`, `ipn/*`, Stripe Studio, or voice callback | Passes CSRF layer, then provider auth/signature decides result | `ProductionSecurityRoutesTest` + provider tests |
| SEC-007 | Public | request customer login repeatedly | Named login limiter returns 429 at configured threshold | Route test + manual |
| SEC-008 | Public | register weak/common password | Validation rejects it consistently on session and API surfaces | `ApiCustomerAuthTest` |
| SEC-009 | API client | send bearer token in `Authorization` header | Valid token accepted; secrets absent from URL/access logs | `StorefrontApiTest` |
| SEC-010 | API client | send `?token=` or `?storefront_token=` in production | Rejected while `TOKEN_QUERY_FALLBACK=false` | `StorefrontApiTest` |
| SEC-011 | Legacy local client | enable query fallback explicitly | Works with deprecation headers/log; migration deadline visible | `StorefrontApiTest` |
| SEC-012 | Unauthenticated client | call production `/healthz` without configured health token | Only generic status is exposed; component/version detail hidden | `HealthCheckTest` |
| SEC-013 | Monitor | call `/healthz` with correct and incorrect token | Correct token gets detailed checks; incorrect token rejected | `HealthCheckTest` + manual |
| SEC-014 | Proxy client | spoof `X-Forwarded-*` from untrusted address | Host/scheme/client IP are not trusted | Config test + infrastructure manual |
| SEC-015 | Trusted proxy | forward real HTTPS request | Laravel resolves HTTPS/host/client IP correctly | Infrastructure manual |
| SEC-016 | Cross-tenant owner/staff/customer/reseller | request another tenant's IDs and exports | 403/404 with no metadata leak or mutation | Existing tenant/IDOR tests + manual |
| SEC-017 | Browser | exercise enforced dashboard/storefront CSP | No required resource blocked; injected inline script without nonce is blocked | Storefront: `retained-template-csp`; dashboard: browser manual |
| SEC-018 | Attacker | exceed public-form/order/webhook/API budgets | Enforcement returns 429 and produces observable logs/metrics | Existing limiter tests + manual |
| SEC-019 | Admin/reseller | invoke impersonation or other sensitive action with stale session | Password confirmation (and reseller 2FA where enabled) required; audit entry written | Existing tests + manual |
| SEC-020 | All roles | logout then replay protected URL/back navigation | Session invalidated; protected data not rendered from cache | Browser manual |

## API and webhook scenarios

| ID | scenario | expected | automation/evidence |
|---|---|---|---|
| API-001 | Missing, malformed, expired, revoked, and wrong-scope API token | Consistent 401/403 JSON envelope; no HTML redirect | API feature suites |
| API-002 | Valid owner/storefront/customer/staff/reseller token | Only documented store/role resources returned | API feature suites + role credentials |
| API-003 | Pagination/filter/sort boundaries and oversized page request | Stable schema; documented cap enforced; no unbounded query | API feature suites/manual |
| API-004 | Validation errors, unsupported media type, and malformed JSON | Documented status/code/message; no stack trace | API feature suites |
| API-005 | Rate-limit exhaustion | 429 plus usable retry headers; bucket scoped to actor/token | Feature/manual |
| API-006 | Idempotent order/payment retry | No duplicate order, charge, ledger entry, or job | Critical/payment tests |
| API-007 | HungerStation callback without configured secret | 401 and no ingest job | `HungerStationOrderIngestTest` |
| API-008 | HungerStation callback with invalid/valid HMAC | Invalid rejected; valid accepted once | `HungerStationOrderIngestTest` |
| API-009 | SMS STOP/START without signature/secret | 403; consent state unchanged | `AgentSmsTest` |
| API-010 | Valid Twilio SMS STOP/START | Consent state changes once and response is provider-compatible | `AgentSmsTest` + sandbox callback |
| API-011 | Each enabled payment/delivery/voice callback | Valid provider fixture accepted; tampered body/timestamp/replay rejected | Provider tests + sandbox |
| API-012 | Webhook processing failure | Provider receives retry-safe response; failure is queued/logged/observable without secret leakage | Manual fault injection |

## MCP scenarios

Run every case against `/mcp`, `/mcp/dev`, `/mcp/storefront`, `/mcp/catalog`,
and `/mcp/customer` with the authentication appropriate to that server.

| ID | scenario | expected | automation |
|---|---|---|---|
| MCP-001 | `initialize` without version header | Negotiates default `2025-06-18` and returns protocol header | `McpProtocolTest` |
| MCP-002 | Negotiate `2024-11-05`, `2025-03-26`, `2025-06-18` | Supported legacy/current version echoed; capabilities valid | `McpProtocolTest` |
| MCP-003 | Unsupported protocol version | JSON-RPC error with supported-version guidance | `McpProtocolTest` |
| MCP-004 | POST without `Origin` from non-browser client | Allowed, subject to endpoint auth | `McpProtocolTest` |
| MCP-005 | POST with same-origin `Origin` | Allowed | `McpProtocolTest` |
| MCP-006 | POST with exact `MCP_ALLOWED_ORIGINS` entry | Allowed | `McpProtocolTest` |
| MCP-007 | POST with foreign/malformed origin | HTTP 403 before method execution | `McpProtocolTest` |
| MCP-008 | Single or batch notification | HTTP 202 with no JSON body | `McpProtocolTest` |
| MCP-009 | Mixed batch | Responses only for entries with IDs; notification omitted | `McpProtocolTest` |
| MCP-010 | GET request | 405 with `Allow: POST`; no SSE stream | `McpProtocolTest` |
| MCP-011 | Recommended JSON/SSE `Accept` values | JSON response remains usable; protocol header present | `McpProtocolTest` |
| MCP-012 | Missing/wrong endpoint token or cross-store resource | 401/403/404 without tool/schema/data leak | Endpoint feature suites |
| MCP-013 | Unknown method/tool and invalid parameters | Correct JSON-RPC error codes; process stays healthy | Endpoint feature suites |
| MCP-014 | Tool mutation retried | Authz, validation, tenant scope, and idempotency rules hold | Manual against sandbox store |

## Browser journeys

| ID | actor | path/journey | expected | automation |
|---|---|---|---|---|
| UI-001 | Public | landing, pricing, blog, contact | Responsive render, working navigation/forms, no 500/console exception | Manual |
| UI-002 | Public | seeded Crave storefront add item through checkout entry | Cart badge increments and checkout view opens | `storefront-checkout` |
| UI-003 | Customer | seeded storefront session login and account | Account/order/address data renders for the correct store | `role-pages-smoke` |
| UI-004 | Owner | login form | Lands on dashboard with authenticated navigation/logout | `dashboard-login` |
| UI-005 | Owner | dashboard, restaurants, orders, customers, settings | Pages render without auth bounce, exception page, or 500 | `role-pages-smoke` |
| UI-006 | Owner | switch store and branch, reload, open another dashboard tab | Selected context remains synchronized; no stale sidebar/customizer context | Manual |
| UI-007 | Owner | open theme customizer, change store context and save | Customizer targets the selected store; preview and persisted values agree | Manual |
| UI-008 | Staff | PWA manifest/service worker/login | Assets served; stale caches cleared; login succeeds | `staff-pwa-login` |
| UI-009 | True staff | `/staff-app` Orders and in-app workspaces such as Kitchen; owner/admin boundaries | Dedicated staff credentials; unauthorized owner settings return 403 and admin routes reject/redirect. The PWA tabs are not nested URL routes. | Optional `role-pages-smoke` plus browser tab checks |
| UI-010 | Staff | POS add item/tender | One sale and ledger/order side effects | Optional `pos-sale` |
| UI-011 | Reseller | portal, customers, security | Dedicated reseller credentials; no owner/admin substitution | Optional `role-pages-smoke` |
| UI-012 | Admin | dashboard, orders, settings | Dedicated admin credentials; admin prefix honored | Optional `role-pages-smoke` |
| UI-013 | All authenticated roles | desktop, tablet, 360–430px mobile | No unreachable controls, horizontal overflow, or unreadable dialogs | Manual |
| UI-014 | EN + AR | core checkout and dashboard paths | Correct translations, direction, focus order, and numeric controls | Manual |
| UI-015 | Browser console/network | all executed paths | No same-origin 500, uncaught exception, failed critical JS/CSS, mixed content, or CSP violation | Runner logs + DevTools |

Browser evidence must list the roles actually executed. Seeded owner/customer
coverage is not true staff, reseller, or admin coverage. Use `BLOCKED` when an
account exists but a 2FA challenge, missing seed, or environment failure prevents
the path. Use `NE` when credentials were not supplied.

### Local dedicated-role browser evidence — 2026-07-29

This evidence was captured against `https://juwri.test` using existing
dedicated local database records. It proves the local build and role boundaries;
it is not production-environment sign-off.

| role | executed surfaces | result | retained observation |
|---|---|---|---|
| Owner | `/dashboard`, `/stores`, `/orders`, `/customers`, `/settings` | `PASS` | Dedicated owner authenticated; all five tenant surfaces rendered without an auth bounce, exception page, or same-origin 500 |
| Admin | `/admin/dashboard`, `/admin/orders`, `/admin/settings` | `PASS` | Dedicated admin authenticated; all three pages rendered at their protected URLs with no exception or console error |
| Reseller | `/reseller`, `/reseller/customers`, `/reseller/security` | `PASS` | Dedicated active reseller owner authenticated; Demo Agency overview, stores/customers, and security rendered with no exception or console error |
| Staff | `/staff/login`, `/staff-app`, Orders, Kitchen, `/settings`, `/admin/dashboard` | `PASS` for the executed manager smoke | Dedicated active manager authenticated; PWA Orders and Kitchen rendered; owner settings returned 403; admin URL redirected to the staff dashboard |
| Customer | `/crave-demo`, password login, `/crave-demo/customer/account` | `PASS` | Dedicated store-scoped customer authenticated; a session probe and account DOM assertion proved the account page rather than accepting a storefront redirect |

The final isolated-context `role-pages-smoke` executed owner, staff, reseller,
admin, and customer with `not-configured=none` and passed in 91,400 ms. The
supporting role/auth/tenant/IDOR suite passed 171 tests with 565 assertions.
After the browser run exposed a real customer account failure, the
`Customer.store_id` cast was corrected and a login-to-account regression
was added; the focused customer suite then passed 33 tests with 114 assertions.

The staff run exposed and corrected an E2E mapping defect:
`/staff-app/orders` is not a route. The staff experience is a single-page
application whose Orders, Kitchen, Tasks, and other workspaces are internal
tabs, so the default `E2E_STAFF_PATHS` value is now `/staff-app`.

The only ordinary browser warning observed during the combined login sequence
was the existing legacy landing-page Swiper warning about too few slides for
loop mode. No same-origin application error or 500 response was observed on
the executed role surfaces. CSP is a separate release blocker: report-only
logging captured inline style/script and third-party widget violations on
dashboard/staff/storefront traffic, so enforcement has not passed.

### Local account-lifecycle browser evidence — 2026-07-29 to 2026-07-30

This pass exercises account creation and real mutations rather than accepting
page access as user coverage. All identities and orders are disposable local
fixtures.

| actor | lifecycle exercised | result | retained evidence |
|---|---|---|---|
| Owner | anonymous signup, automatic trial, authenticated dashboard, preferred-language update, first-store wizard with real uploads, category/item creation, table QR, public storefront | `PASS after fixes` | User `codex.lifecycle.owner.20260729@example.test` created Orbit store `3448`, menu item `63498` (“Lifecycle Bowl”, `$24.50`), and table QR `23`. The public `/codex-lifecycle-kitchen-730` storefront rendered the category and item. The wizard now blocks review until logo/cover files exist, and the table form now exposes the server's `1..255` range. |
| Staff | owner creates cashier, cashier password login, PWA boot, Tasks permission view, compose order, add item, place order | `PASS` | Staff `codex.lifecycle.staff.20260729@example.test` was created for restaurant `3`; local order `#99806` was created through the staff PWA. Cashier correctly saw no manager task-creation control. |
| Reseller | anonymous partner signup, instant workspace provisioning, portal list/search/filter/status/export, visible customer creation form, customer detail | `PASS after fixes` | Partner user `10013` provisioned reseller `4` on the seeded Free tier. The new portal form created customer `10014` and redirected to its scoped detail page. Signup is transactional and returns a clear 503 before creating a user if the required Free tier reference row is absent. |
| Customer | guest storefront, registration, persistent session, account view, cart, takeaway checkout, cashier payment, order confirmation, live tracking | `PASS after fix` | The browser exposed that Crave/Matcha/Glow omitted `password_confirmation`. All three forms now collect and submit it. Customer `codex.lifecycle.customer.20260729@example.test` created tracked order `#99807` for Espresso, `$12.00`. |

The supporting lifecycle suite passed **102 tests / 378 assertions**, covering
owner/staff authentication throttling, staff tenant/permission operations,
partner provisioning, reseller customer scoping, customer registration/account
behavior, API authentication, and the owner/customer/table UI contracts. The
final isolated browser role matrix passed owner, staff, reseller, admin, and
customer with `not-configured=none` in **131,931 ms**.

Retained local mutations:

- owner user ID `10011`, Orbit store ID `3448`, menu item ID `63498`, and
  table/QR target ID `23`;
- cashier staff ID `19`, restaurant ID `3`, PIN `7291`;
- customer ID `33`, restaurant ID `3425`;
- partner user ID `10013`, reseller ID `4`, and reseller customer ID `10014`;
- staff order `#99806` and customer order `#99807`;
- local test password `CodexLifecycle!2026` for the lifecycle identities.

The failed pre-fix signup orphan (user ID `10012`) was removed. The remaining
fixtures are intentionally retained and clearly labeled for the next lifecycle
run. They must never be copied to a production database.

### Supported template validation scope

Browser and release-candidate template validation is intentionally limited to:
**Crave, Matcha, Glow, Aurora, Vivid, Feast, Velvet, Dough, Plate, Lime,
Picnic, Orbit, Velora, Crumb, Soiree, Scoop, and Ember**. Do not spend release
test time on other bundled templates; they are scheduled for later removal.

### User-reported Orbit, Velora, and Crumb acceptance cases

| ID | scenario | expected | automated / live evidence |
|---|---|---|---|
| `TPL-RPT-001` | Open each home page as a guest | Language, stories, linked owner offers/CTA, selectable categories, order types, and address/location action are visible and interactive | Focused retained-template browser journey |
| `TPL-RPT-002` | Open Orbit and Velora products | Explicit Back to menu and Reviews/true empty-state section render | Focused retained-template browser journey |
| `TPL-RPT-003` | Orbit product and checkout at `1600×1000` | No horizontal overflow; product/checkout actions remain usable | Wide retained-template browser journey |
| `TPL-RPT-004` | Crumb: select Cultured butter, then change Serve to As is | Extra stays visibly selected and `$17.00` total remains | Live browser reproduction plus automated modifier assertion |
| `TPL-RPT-005` | Type a new Crumb address and select the generated result | Address updates on the storefront and is available to checkout | Live browser |
| `TPL-RPT-006` | Guest opens account/order discovery | Crumb Login / Register opens both forms; Profile exposes phone/email order lookup | Live browser plus DOM/feature assertions |
| `TPL-RPT-007` | Place Pay-on-counter order, return to menu, open history | Real order ID and tracking URL render; new order remains discoverable | Orbit `#99814`, Velora `#99815`, Crumb `#99816` |
| `TPL-RPT-008` | Open original and newly generated Crumb tracking tokens | Tracking page renders real order status/summary and Back to menu | Original token plus `#99816` token verified |

## Deployment, recovery, and operations

| ID | scenario | expected | evidence |
|---|---|---|---|
| DEP-001 | CI security-audit job | Composer and production npm audits block advisories; dev audit is visible | Workflow run |
| DEP-002 | Deploy with one required secret absent | Workflow fails before SSH or maintenance mode | Workflow test |
| DEP-003 | Successful deploy | Maintenance secret used, `--ff-only` pull succeeds, migrations/caches complete, mandatory health smoke passes, app is up | Workflow/log/health |
| DEP-004 | Force failure after `artisan down` | EXIT trap runs `artisan up`; deploy fails visibly | Disposable environment |
| DEP-005 | Health endpoint returns non-2xx | Deploy is failed and app is restored; no unsafe source reset after forward migration | Disposable environment |
| DEP-006 | Route/config/view cache build on release artifact | All caches build and application boots using them | Automated gate |
| DEP-007 | Queue and scheduler after deploy | Worker restarted on new code; scheduled commands run once; failed-job alert works | Supervisor/scheduler logs |
| DEP-008 | Log volume | Worker/app logs rotate within disk budget and retain incident window | Supervisor/logrotate config |
| DEP-009 | Backward-compatible migration | Old and new application revisions can run during rolling/atomic switch | Migration review + staging |
| DEP-010 | Rollback required after forward migration | Use documented application/data recovery plan; do not assume `git reset` reverses schema | Incident drill |
| DEP-011 | Backup restore | Restore database and uploaded assets to isolated environment; checksums and key access verified | Restore record |
| DEP-012 | Observability | health, queue, failed jobs, 5xx, auth abuse, webhook failures, disk, DB, and certificate alerts reach on-call | Alert test |

## Release sign-off

| area | result | evidence/link | owner | notes/blockers |
|---|---|---|---|---|
| Automated PHP/JS/dependency gates | | | | |
| Public + customer | | | | |
| Owner | | | | |
| Staff | | | | |
| Reseller | | | | |
| Admin | | | | |
| API + webhooks | | | | |
| MCP | | | | |
| Deploy/recovery/operations | | | | |
| Security/configuration review | | | | |

Release decision: `GO / NO-GO / CONDITIONAL`

Approver, date, conditions, and follow-up tickets:

---
