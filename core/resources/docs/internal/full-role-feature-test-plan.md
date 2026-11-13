---
title: "Full role and feature test plan"
audience: internal
group: Internal
updated: 2026-07-31
---

# Full role and feature test plan

This is the comprehensive behavioral test plan for juwri. It is broader
than the production-readiness smoke: testers act like real public visitors,
customers, owners, staff, resellers, and platform administrators, complete
multi-step goals, and verify data, permissions, notifications, jobs, payments,
files, and audit records.

This plan is an execution backlog, not a claim that every scenario passed.
Record `PASS`, `FAIL`, `BLOCKED`, or `NE` with evidence for every run.

Execution evidence is maintained in
`full-role-feature-test-results.md` (kept in the private repository).

## What has actually been tested

Executed against `https://juwri.test` on 2026-07-29/30:

| actor | executed behavior | depth |
|---|---|---|
| Public | Landing/auth pages plus Crave and Orbit storefronts | Render/navigation smoke except the transactional journeys below |
| Owner | Registration, trial dashboard, preferred-language change, Orbit store with real uploads, one category/item, table 73/QR 23, public storefront | One onboarding golden path |
| Owner matrix | Dashboard, restaurants, orders, customers, settings | Page/auth/500 smoke, not full behavior |
| Staff | Owner-created cashier, PWA login, Tasks restriction, compose/add item/place order `#99806` | One cashier operational path |
| Customer | Crave registration/session/account, cart, takeaway/cashier order `#99807`, tracking | One registration-to-order golden path |
| Reseller | Partner registration, Free workspace, portal pages, visible customer creation, scoped detail | One onboarding/provisioning path |
| Admin | Dedicated admin opened dashboard, orders, settings | Page/auth/500 smoke only; no meaningful mutation |
| Cross-role | Isolated owner/staff/reseller/admin/customer matrix | Passed in 131,931 ms with `not-configured=none` |
| Regression | Owner/staff/reseller/customer feature and UI-contract suites | 102 tests, 378 assertions |

Defects found and fixed:

1. Crave/Matcha/Glow registration omitted password confirmation.
2. Customer account navigation lost session state due to inconsistent
   `store_id` types.
3. Owner store review allowed missing logo/cover files.
4. Table UI accepted values above the server maximum of 255.
5. Partner signup could create an orphan user and misleading 404 when the Free
   reseller tier was missing.
6. Reseller customer creation existed as an endpoint but had no UI.

## What has not been fully tested

Opening a page is not equivalent to testing its feature.

| actor | missing behavioral coverage |
|---|---|
| Public | Pricing conversion, forms, content/search, directory/marketplace, accessibility, SEO, responsive layouts, abuse controls, enforced CSP |
| Customer | OTP/social auth, addresses, delivery, online gateways, coupons/tips/variants, loyalty/wallet/gift cards, reviews/follows, reorder, refunds, notifications and browser-tested cross-store isolation |
| Owner | Subscriptions/add-ons, multi-store/branch, advanced catalog, theme customization, order/refund operations, CRM, POS/KDS, inventory/procurement, HR/payroll, accounting, delivery, integrations, reports, backups/imports |
| Staff | Complete permission matrix, PIN/offline flows, time/tasks/messages, waiter/KDS/POS advanced actions, inventory/food safety/tips and branch isolation |
| Reseller | Tier/billing lifecycle, branding/domain/builder, plans, wallet/invoices, gateways/SMTP, team/2FA, API/webhook, referrals, impersonation/audit and cross-reseller browser tests |
| Admin | Owner/store/order mutations, plan/add-on/entitlement operations, providers, reseller operations, catalogs/content/directory, security/jobs/support, finance, impersonation and updater |
| Platform | Provider sandboxes, enforced CSP/abuse, load/concurrency, scheduler/queues, actual notifications, accessibility/device matrix, backup restore and deploy/recovery |

## Test method

Every feature scenario checks:

1. **User goal:** can the actor complete the real task through the UI?
2. **Data result:** are database, ledger, queue, file, notification and audit
   changes correct and exactly once?
3. **Recovery:** do invalid input, retries, refresh, back navigation and
   interrupted flows recover safely?
4. **Authorization:** are actor, permission, owner, restaurant, branch,
   customer and reseller scopes enforced?
5. **Experience:** desktop/mobile, EN/AR, accessibility, console/network, CSP
   and useful loading/empty/error/success states.

Financial, destructive, provider, import and recovery cases run only in a
disposable environment with a snapshot and sandbox credentials.

## Required fixtures

- Two unrelated owners, each with two stores and two branches.
- Trial, paid, past-due and canceled owners.
- Manager, cashier, waiter, kitchen, inventory, driver and restricted custom
  staff roles.
- Customers in two stores, including the same email in both stores.
- Two resellers with different tiers and two merchants each; one suspended
  reseller.
- Normal and least-privilege operations/support admins.
- Simple, variant, extra, taxed, discounted, scheduled, sold-out and
  inventory-tracked products.
- Dine-in, takeaway, delivery, room-service, held, split, refunded, disputed
  and offline-replayed orders.
- Sandbox payment, SMS, email, push, delivery, accounting, POS bridge and AI
  credentials for every enabled provider.

Use a unique run ID on all disposable records and define cleanup before testing.

## Cross-role golden journeys

| ID | story | expected completion |
|---|---|---|
| E2E-001 | New restaurant launch | Owner registers/subscribes, creates store/menu/staff/table; customer orders; staff accepts/prepares/completes; owner sees customer/revenue/report |
| E2E-002 | Plan and add-on lifecycle | Trial, plan purchase, add-on, renewal, upgrade/downgrade, cancellation, grace/dunning, invoices/events/entitlements reconcile |
| E2E-003 | Dine-in service | QR binds table/branch; waiter handles call/order; KDS prepares courses; cashier splits/tips/settles; table and reports reconcile |
| E2E-004 | Delivery order | Address/zone/fee/payment validated, driver assigned, notifications/status/proof progress to completion |
| E2E-005 | Customer retention | Owner segments and campaigns with consent; customer redeems coupon/loyalty/credit; attribution appears |
| E2E-006 | Reseller merchant lifecycle | Partner configures brand/plan/payment, provisions merchant, merchant subscribes/launches, customer orders, reseller billing/analytics update |
| E2E-007 | Incident/refund | Staff/owner correct or refund exactly once; stock, ledger, loyalty, credit, customer status and audit reconcile |
| E2E-008 | Multi-store/branch | Context stays synchronized across tabs/reloads and all menu/staff/order/stock/report/QR data remains scoped |
| E2E-009 | Provider outage/retry | Timeout never duplicates order/charge; retry is idempotent; user/owner/admin get actionable state |
| E2E-010 | Backup/recovery | Backup and isolated restore match critical counts/files/checksums and recover the platform |

## Public scenarios

| ID | user behavior |
|---|---|
| PUB-001 | Visit landing, pricing, FAQ, blog, contact, testimonials, marketplace, app store, directory and legal pages on desktop/mobile. |
| PUB-002 | Switch supported locales; verify URL, translation, RTL/LTR, numbers and focus order. |
| PUB-003 | Navigate pricing to signup/checkout; selected plan and interval survive authentication. |
| PUB-004 | Submit contact/newsletter/lead forms with valid, invalid, duplicate, abusive and rate-limited data. |
| PUB-005 | Search/filter/paginate public content, directory, marketplace and menus; verify empty states. |
| PUB-006 | Open store by slug, custom/reseller domain and QR; inactive/foreign/invalid targets fail safely. |
| PUB-007 | Verify consent choices and optional tracking behavior. |
| PUB-008 | Verify canonical URL, redirects, sitemap/robots, Open Graph and structured data. |
| PUB-009 | Keyboard/screen-reader pass: skip link, landmarks, headings, forms, errors, dialogs, contrast and zoom. |
| PUB-010 | Enforced CSP, mixed-content, third-party failure, offline and slow-network behavior. |

## Customer scenarios

| ID | user behavior |
|---|---|
| CUS-001 | Register with policy-compliant password; duplicate identity remains store-scoped. |
| CUS-002 | Login/logout/session expiry/back/multiple tabs; protected data never returns after logout. |
| CUS-003 | OTP request/verify with wrong, expired, reused and throttled codes. |
| CUS-004 | Social login safely creates/links the correct store customer. |
| CUS-005 | Update profile, phone, preferences, allergies, birthday and consent. |
| CUS-006 | Add/edit/default/delete addresses; invalid, foreign and out-of-zone addresses fail. |
| CUS-007 | View history/detail/tracking; foreign customer/store/order IDs reject. |
| CUS-008 | Account export/deletion where enabled, including retention constraints. |
| CUS-009 | Browse categories/search/diet/allergen/scheduled/sold-out states. |
| CUS-010 | Add simple/variant/extra items and notes; server and display totals agree. |
| CUS-011 | Edit cart, refresh, change branch/type, and handle unavailable/changed items. |
| CUS-012 | Dine-in QR binds correct restaurant/branch/table; tampering rejects. |
| CUS-013 | Takeaway validates time, contact, minimum, tax, fee, tip and hours. |
| CUS-014 | Delivery validates address, zone, fee, minimum and ETA. |
| CUS-015 | Test valid/expired/future/limited/wrong-store coupons and stacking. |
| CUS-016 | Earn/redeem loyalty, gift card, wallet/store credit and partial payment. |
| CUS-017 | Test cash and every sandbox gateway: success, decline, cancel, timeout and replay. |
| CUS-018 | Double-click/retry/back/refresh order submission; one order/charge exists. |
| CUS-019 | Confirmation/tracking/notifications update and respect consent. |
| CUS-020 | Reorder with unavailable or changed-price components. |
| CUS-021 | Cancel/request help/refund; balances, stock and audit reconcile. |
| CUS-022 | Follow/favorite, verified review/NPS/feedback and moderation paths. |
| CUS-023 | Call waiter/request bill/voice note with table authorization/rate limits. |
| CUS-024 | Customer API/app/MCP with valid, expired, revoked and wrong-store tokens. |
| CUS-025 | Core journey on all 17 retained templates, mobile/desktop, EN/AR. |

## Owner scenarios

### Registration, plans, billing and add-ons

| ID | user behavior |
|---|---|
| OWN-001 | Register/verify/login/logout/reset password/2FA/recovery codes. |
| OWN-002 | Start free/trial plan from pricing and verify duration, limits and onboarding. |
| OWN-003 | Subscribe monthly/annual through each gateway; charge/subscription/invoice/entitlement/event occur once. |
| OWN-004 | Upgrade/downgrade with correct proration, limits and pending change. |
| OWN-005 | Cancel/reactivate, expire trial, grace/past-due/suspension and payment recovery without data loss. |
| OWN-006 | Buy/trial/renew/cancel/expire an add-on and verify entitlement timing. |
| OWN-007 | Retry payment return/webhook with no duplicate financial record. |
| OWN-008 | View billing history, invoice, currency/tax/receipt and failure guidance. |
| OWN-009 | Exceed limits; upgrade gate is clear while existing data stays safe. |

### Store, branch, template and publishing

| ID | user behavior |
|---|---|
| OWN-010 | Create first/additional stores with valid/invalid/duplicate slug and required files. |
| OWN-011 | Edit identity/contact/hours/cuisines/about/order settings; public view matches. |
| OWN-012 | Duplicate/archive/suspend/delete/restore store safely. |
| OWN-013 | Create/edit/delete branches, hours, branch menus and branch QR. |
| OWN-014 | Switch store/branch across tabs/reload; sidebar/customizer/context stay synchronized. |
| OWN-015 | Change slug; canonical URL and redirect work and old slug cannot be hijacked. |
| OWN-016 | Configure custom domain through pending/active/failure/renewal. |
| OWN-017 | Customize retained templates; preview/draft/publish/discard target correct store. |
| OWN-018 | Design/download QR PNG/sheet/ZIP, rotate tokens and invalidate old links. |
| OWN-019 | Publish/draft/closed-hours/plan/suspension visibility is correct. |

### Catalog and media

| ID | user behavior |
|---|---|
| OWN-020 | Category/subcategory create/edit/reorder/delete/restore/translation/limits. |
| OWN-021 | Menu item create/edit/duplicate/reorder/activate/86/restore with cost/nutrition/allergens/schedule. |
| OWN-022 | Variants, required options, extras/modifiers and pricing agree on storefront/POS/API. |
| OWN-023 | Combos, schedules and branch overrides handle conflicts/unavailable parts. |
| OWN-024 | Bulk price/status/tag/move operations and partial failure. |
| OWN-025 | CSV/GloriaFood/POS import/export preview, duplicate, malformed and rollback cases. |
| OWN-026 | Media upload, stock/public/AI copy, replace/delete/orphan and file validation. |
| OWN-027 | Banners/stories reorder/schedule/expire and template visibility. |
| OWN-028 | AI generation/merge/translation/images with entitlement, metering, failure and review. |

### Orders, customers and service

| ID | user behavior |
|---|---|
| OWN-029 | Receive each order type with correct branch, notification and sound. |
| OWN-030 | Accept/reject/status/complete/delete per state machine; tracking updates. |
| OWN-031 | Add/replace/transfer/hold/split/coupon/discount/tip/settle. |
| OWN-032 | Full/partial refund to method/credit; ledger, inventory, loyalty, tax and audit reconcile. |
| OWN-033 | Voice notes and waiter/customer requests honor authorization. |
| OWN-034 | Customer directory CRUD/export/tags/notes/history/consent and store isolation. |
| OWN-035 | CRM audience/campaign/unsubscribe/win-back/birthday consent and attribution. |
| OWN-036 | Loyalty/coupons/gift cards/store credit issue/adjust/void/redeem limits. |
| OWN-037 | Reservations create/confirm/seat/cancel/no-show/conflict/capacity. |
| OWN-038 | Reviews/feedback/disputes response/close/evidence/privacy. |

### Staff, operations and finance

| ID | user behavior |
|---|---|
| OWN-039 | Create all staff roles/branches/email/password/PIN/custom permissions. |
| OWN-040 | Update/suspend/delete staff and revoke sessions/tokens/devices. |
| OWN-041 | HR profile/documents/onboarding/rates/leave lifecycle and privacy. |
| OWN-042 | Payroll/tips generation, adjustment, approval, bank file and payment. |
| OWN-043 | POS shift and cash/card/mixed/split/refund/void/approval/Z-report. |
| OWN-044 | KDS modes/courses/bump/recall/release/all-day/scheduled. |
| OWN-045 | Printing/display jobs, retry/ack and branch scoping. |
| OWN-046 | Inventory mode, ingredients, recipes, counts, adjustment, waste/history. |
| OWN-047 | Supplier/PO ordered/partial receive/invoice/cancel and COGS/stock. |
| OWN-048 | Food-safety checks/labels/incidents/overdue/permissions. |
| OWN-049 | Drivers/dispatch/assignment/zones/fees/completion proof. |
| OWN-050 | Catering quote/accept/decline/calendar/fulfill. |
| OWN-051 | Automations/rules conditions/actions/retries/deduplication/audit. |
| OWN-052 | Accounting expenses/journals/period lock/reports/connect/retry. |
| OWN-053 | Analytics/menu engineering/reports/exports against known totals. |
| OWN-054 | Backup create/download/delete/schedule and isolated import/restore. |

### Integrations and advanced features

| ID | user behavior |
|---|---|
| OWN-055 | Configure/test/revoke payment/SMS/email/push/AI credentials without leaks. |
| OWN-056 | Connect/disconnect/push/pause aggregators/POS bridges; imports are idempotent. |
| OWN-057 | Phone agent, push, kiosk, signage, pay-link, directory/GBP where entitled. |
| OWN-058 | App install/trial/configure/use/uninstall with scopes, billing and retained data. |
| OWN-059 | API tokens and owner/storefront/catalog API/MCP scopes and isolation. |
| OWN-060 | Feature locks, kill switches and entitlement cache invalidation. |

## Staff scenarios

| ID | user behavior |
|---|---|
| STF-001 | Email/password and PIN login; invalid/throttled/suspended/wrong-branch fail. |
| STF-002 | Manager/cashier/waiter/kitchen/inventory/driver/custom navigation and API scopes. |
| STF-003 | Assigned branch switch and strict unassigned-branch isolation. |
| STF-004 | Clock in/out, duplicate/offline replay and manager correction. |
| STF-005 | Task create/complete/uncomplete by permission and assignment. |
| STF-006 | Staff messages/broadcasts scoped to intended recipients. |
| STF-007 | Receive/accept/reject/status order and customer tracking update. |
| STF-008 | Waiter call/floor/table order/add items/fire/request bill. |
| STF-009 | Transfer/merge tables and split seats/bills under concurrency. |
| STF-010 | KDS station/course/bump/recall/release/all-day correctness. |
| STF-011 | POS simple/variant/combo sale by cash/card/mixed tender. |
| STF-012 | POS customer/coupon/loyalty/credit/tax/tip/receipt. |
| STF-013 | POS hold/resume/transfer/replace/split/void/refund/approval. |
| STF-014 | Incoming online order approval/rejection and duplicate handling. |
| STF-015 | Shift close/Z-report reconciliation. |
| STF-016 | POS customer CRUD and duplicate/cross-store prevention. |
| STF-017 | Catalog/sold-out mutations only with permission. |
| STF-018 | Inventory count/adjust/receive/waste by permission/branch. |
| STF-019 | Food-safety check/incident identity/timestamp/attachments. |
| STF-020 | Tips/payroll/HR privacy boundaries. |
| STF-021 | Push device registration/revocation and duplicate notification prevention. |
| STF-022 | Service worker upgrade/offline queue/reconnect exactly-once behavior. |
| STF-023 | Owner billing/settings/staff, admin and reseller routes reject. |
| STF-024 | Logout/revocation clears session, token and cached PWA data. |

## Reseller scenarios

| ID | user behavior |
|---|---|
| RSL-001 | Free/paid-trial partner signup, verification, qualification/referral and duplicate. |
| RSL-002 | Missing/inactive tier and retry create no orphan/duplicate. |
| RSL-003 | Onboarding brand/2FA/domain/gateway/plans/first customer. |
| RSL-004 | 2FA enable/challenge/recovery/disable/session revocation. |
| RSL-005 | Brand/landing/builder/pages/footer/blog publish/preview/translate. |
| RSL-006 | Custom domain request/verify/failure/approval/renewal. |
| RSL-007 | Payment/AI keys, SMTP, email template and test-send secret safety. |
| RSL-008 | Reseller plan/pricing/rebilling CRUD and limits. |
| RSL-009 | Sale link onboards merchant under correct plan/brand. |
| RSL-010 | Merchant creation/password/welcome email/scoped detail. |
| RSL-011 | Search/filter/export/bulk/suspend/delete merchant with audit. |
| RSL-012 | Impersonation reason/timeout/exit/protected actions/audit. |
| RSL-013 | Fund merchant/coupon and wallet/ledger reconciliation. |
| RSL-014 | Merchant subscription trial/renew/past-due/cancel/reactivate. |
| RSL-015 | Invoice pay by gateway/wallet and idempotent retry/callback. |
| RSL-016 | Wallet top-up/auto-recharge threshold/cap/failure/duplicate. |
| RSL-017 | Tier customer/seat/team/plan limits and suspended wall. |
| RSL-018 | Team add/remove/least privilege/2FA. |
| RSL-019 | Announcement creation/visibility/limits. |
| RSL-020 | Analytics/stats/subscriptions/seat/referral totals. |
| RSL-021 | API token lifecycle and own-merchant isolation. |
| RSL-022 | Signed webhook accepted once; invalid/replay/foreign reject. |
| RSL-023 | Audit log covers security/billing/customer/destructive actions. |
| RSL-024 | Cross-reseller IDs and all admin routes reject. |
| RSL-025 | Portal/custom domain mobile/EN/AR/accessibility/enforced CSP. |

## Administrator scenarios

| ID | user behavior |
|---|---|
| ADM-001 | Login/logout/reset/2FA/password confirmation/session expiry/least privilege. |
| ADM-002 | Dashboard metrics/charts/alerts match fixtures/date filters. |
| ADM-003 | Owner search/view/create/update/suspend/delete/impersonate/audit. |
| ADM-004 | Store approve/reject/suspend/verify/feature grant/force transfer. |
| ADM-005 | Order search/export/detail and permitted incident/refund actions. |
| ADM-006 | Plan/price/archive/duplicate/reorder/resync entitlements safely. |
| ADM-007 | Add-on CRUD/toggle/date and purchaser entitlement behavior. |
| ADM-008 | Feature catalog/matrix and per-owner grants/revokes invalidate cache. |
| ADM-009 | Gateways/social/AI/media/integration configure/test/toggle with masked secrets. |
| ADM-010 | Reseller tier/approval/go-live/domain/report/resync/delete/wallet grant. |
| ADM-011 | Reseller funnel/ops/referrals/wholesale balances. |
| ADM-012 | Coupons/wallet overview/taxes/commission/fiscal compliance. |
| ADM-013 | Retained-template activation/fallback/reassignment before deletion. |
| ADM-014 | App/plugin review/grant/reject/delist/install/enable/disable/rollback. |
| ADM-015 | Marketplace reviews/public media/ads/announcements/testimonials. |
| ADM-016 | Pages/blog/comments/FAQ/preview/publish/SEO/XSS sanitization. |
| ADM-017 | Languages/reordering/translations without user-owned edit loss. |
| ADM-018 | Directory listing/claim/evidence/review/takedown/cuisine/geo moderation. |
| ADM-019 | Delivery/driver/reassignment/rules/zones/fees/feed. |
| ADM-020 | Abuse guard/rate/signup/AI alerts and safe enforcement changes. |
| ADM-021 | Alerts and failed-job retry/forget/observability. |
| ADM-022 | Support ticket assign/reply/resolve and private-note boundaries. |
| ADM-023 | Activity/audit contains actor/target/reason for sensitive actions. |
| ADM-024 | Global brand/storefront/wallet/provider/maintenance setting and rollback. |
| ADM-025 | Updater in disposable clone after re-auth; maintenance exits on failure. |
| ADM-026 | Destructive media/bulk operations require dry-run/confirmation/trash/restore. |
| ADM-027 | Admin CSRF/2FA/re-auth/API boundaries; no owner/reseller substitution. |
| ADM-028 | Admin desktop/mobile/EN/AR/accessibility/enforced CSP. |

## Platform-wide scenarios

| ID | scenario |
|---|---|
| NFR-001 | Current Chrome/Safari/Firefox/Edge and iOS/Android viewport/touch. |
| NFR-002 | Accessibility: keyboard, focus, labels, announcements, contrast, zoom and reduced motion. |
| NFR-003 | Performance budgets and N+1 profiling for critical pages/large lists. |
| NFR-004 | Load/concurrency for auth, menu, order, POS/KDS polling, webhooks, exports and queues. |
| NFR-005 | OWASP-oriented auth/session/input/upload/IDOR/CSRF/CSP/rate/secret/log tests. |
| NFR-006 | Enforced CSP and abuse modes on retained templates and privileged shells. |
| NFR-007 | Queue retry/dead-letter/duplicate and scheduler single-run behavior. |
| NFR-008 | Email/SMS/push/voice localization, consent, retry and unsubscribe. |
| NFR-009 | Upload validation, storage permissions, orphan cleanup, caching and private downloads. |
| NFR-010 | Database/cache/session/Redis outage and duplicate-free recovery. |
| NFR-011 | Backup/restore/deploy failure/health/workers/scheduler/migration recovery. |
| NFR-012 | Retention/export/deletion/privacy/redaction in logs/analytics/backups. |

## Supported storefront-template matrix

Release scope is limited to Crave, Matcha, Glow, Aurora, Vivid, Feast, Velvet,
Dough, Plate, Lime, Picnic, Orbit, Velora, Crumb, Soiree, Scoop, and Ember.

For every retained template test:

1. home/category/search/product detail;
2. variants/extras/allergens/sold-out/scheduled items;
3. cart edit and enabled order types;
4. register/login/account/logout;
5. coupon/tip/payment/confirmation/tracking;
6. EN/AR and LTR/RTL;
7. 360 px/tablet/desktop;
8. keyboard/focus/dialog accessibility;
9. service-worker/cache refresh where present;
10. console/network/CSP/mixed-content.

Other templates are scheduled for removal. Before deletion, admin reassignment
must move every restaurant using them to a retained template.

## Execution waves

| wave | objective |
|---|---|
| 0 | Environment, fixtures, provider sandboxes, snapshot and evidence folders |
| 1 | Authentication, authorization and tenant isolation for every actor |
| 2 | Owner launch and customer-order golden path |
| 3 | Staff operations and order completion |
| 4 | Billing, plans, add-ons and financial reconciliation |
| 5 | Complete reseller merchant lifecycle |
| 6 | Admin operations, moderation and incident response |
| 7 | Advanced owner modules and integrations |
| 8 | Template, accessibility, locale and device matrix |
| 9 | Providers, load, failure injection and recovery |

Financial/provider/recovery waves must not run in parallel against the same
database or credentials. Read-only role, accessibility and template lanes may
run in parallel only with isolated actors and artifact directories.

## Automation order

1. Expand browser journeys per role, each in an isolated context.
2. Add feature tests for each defect, financial/idempotency rule and tenant
   boundary.
3. Add API contract cases for the same user goals.
4. Run a reduced retained-template browser matrix on pull requests and the full
   17-template/device/locale matrix nightly.
5. Keep provider sandboxes, accessibility review, load, recovery and visual
   review as release-candidate evidence lanes.

Each automated journey reports actor, tenant, created IDs, assertions, duration,
console/network failures and artifact paths.

## Exit criteria

A full-feature test release is complete only when:

- every applicable scenario is `PASS`, or an explicitly accepted `NE` with
  owner and reason;
- there are no unexplained `BLOCKED` or release-blocking `FAIL` results;
- all role boundaries and two-tenant isolation cases pass;
- enabled plan/add-on/payment/provider combinations have sandbox evidence;
- every retained template passes its matrix;
- financial/order/provider retries are idempotent;
- accessibility, EN/AR, mobile and enforced CSP/abuse checks pass;
- backup restore and deployment failure recovery are demonstrated;
- fixtures are removed or documented;
- final sign-off links evidence rather than relying on verbal confirmation.
