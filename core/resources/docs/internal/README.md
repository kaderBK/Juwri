# Internal engineering docs

Engineering-facing reference for the people who work on juwri itself, not
the people who build on top of it. Where the [dev](../dev/README.md) pillar
documents the stable public surfaces (REST APIs, Liquid themes, MCP), these
pages document the inside of the monolith: how the multi-tenant model is
resolved, where the money choke points are, which services own which logic, the
gotchas that waste the most time, and the seams where a feature is specced or
half-wired rather than done. Every claim is grounded in code under
`core/app/`, `core/database/`, and `core/config/`; anything specced but not yet
built is called out as such. Pages are dense on purpose — file paths, class
names, and the traps live here.

## Orientation

- [Architecture overview](architecture.md) — the Laravel monolith, the
  multi-tenant model, actors and auth, route surfaces, the two theme engines,
  and rate limiting. Start here.
- [Data model](data-model.md) — the core tables and how they relate: accounts
  and tenancy, catalog, orders, customers, plans/billing, staff, inventory,
  kitchen/floor, WhatsApp, marketplace, plus schema conventions and gotchas.
- [Services map](services-map.md) — a directory of `core/app/Services/`: what
  each service owns, verified constants and behaviour, and which drivers are
  deferred (test-mode or Null default) rather than live-wired.
- [Multi-tenancy and store context](store-context.md) — how the current store
  and branch are resolved per request, the `dashboard.sync` middleware, the
  sidebar switcher, and staff permission scoping.
- [Multi-vertical commerce internals](multi-vertical.md) — the `store_type`
  discriminator, the `VerticalRegistry`/`VerticalVocab`/`FulfillmentRegistry`
  seams, the `multi_vertical` entitlement gate, the new tables, and the
  `shelf`/`bookla` theme sets. **Merged to `main`, not yet deployed** — see
  its Status section.

## Plans, billing and background work

- [Plans and entitlements internals](entitlements.md) — the Entitlements v2
  service, the 999 unlimited sentinel and its add-on collision trap, legacy
  `effective_limit()`/`plan_feature()` delegates, enforcement points, the lifecycle
  tick, dunning ladder, wallet auto-renew, and plan webhooks.
- [Feature spotlight internals](feature-spotlight.md) — the upsell and adoption
  system: the state machine, the guardrails that stop it nagging, the event
  table, the kill-switch, trial reminders and the conversion report.
- [Payments internals](payments.md) — the `Transaction` model, the two choke
  points every gateway funnels through, driver dispatch, the wallet top-up
  flow, webhook verification patterns, and the TDSP / T-Lync gateway.
- [Ledger, wallets, coupons and reconciliation](ledger-and-reconciliation.md) —
  the unified double-entry ledger behind all three wallet tiers, the one-table
  coupon model across tiers, superadmin oversight, and `ledger:reconcile`.
- [Queues and jobs](queues-jobs.md) — the queue driver, the no-worker fallback
  that keeps side effects flowing on cron-only hosts, `DeliverWebhookJob`,
  campaign/broadcast jobs, and the scheduled commands.

## Storefront, Studio and AI

- [Theme system internals](theme-system.md) — the two theme engines, Blade
  `post_template` auto-discovery, marketplace resolution, the drops whitelist,
  the rendering sandbox, package validation, the install pipeline, CSP, and the
  commerce runtime and seller ledger.
- [Menu Studio internals](studio-internals.md) — guest sessions and cookies,
  `share_token` vs `guest_token`, the credits ledger, the two output flows, the
  HTML template engine and manifest format, and the Browsershot export path.
- [AI provider layer](ai-providers.md) — the provider-agnostic fallback chain
  (mimo → kimi → deepseek → openai), vision routing, per-provider quirks in
  config, where AI is used, BYOK key management, and metering.
- [Internationalisation and RTL](i18n-rtl.md) — shipped languages, the
  `languages` table, the `___()` helper, the two locale-selection modes and the
  middleware overwrite trap, the session-locale SEO problem, and the
  logical-properties RTL rule.

## Cross-cutting

- [Repo conventions](conventions.md) — the non-negotiable rules that have each already shipped a
  production bug: sqlite-vs-MySQL and the `qrtest_` prefix, the 999 unlimited sentinel, the
  theme-totals contract, 3-decimal currencies, media ref-counting, Blade compile-pairing, `___()`
  auto-registration, and the CI/merge gate. Cited by `§` anchor from `Order.php`,
  `StoreController.php`, `TestCase.php` and `LegacyTemplateStorefrontSweepTest.php`.
- [Security posture](security.md) — authentication surfaces, two-factor auth,
  rate limiting and captcha, rich-text sanitization, the storefront CSP, the
  BYOK credentials vault, impersonation auditing, and webhook signing.
- [Testing guide](testing.md) — suite layout, the test environment, how to run
  it, and the traps (never disable middleware wholesale, plan settings must be
  an array, the CA-bundle CLI gotcha, Browsershot/FPM for exports, shared
  throttle buckets).
- [Production readiness handbook](production-readiness-handbook.html) —
  self-contained visual implementation record, configuration guide, test
  evidence, browser coverage, remaining work, and printable release sign-off.
- [Production release test scenarios](production-test-scenarios.md) — durable
  automated, role/browser, API, MCP, webhook, deploy, recovery, and release
  sign-off matrix, including safe parallel local/agent execution lanes and the
  boundary between automated evidence and credential-dependent manual UAT.
- [Full role and feature test plan](full-role-feature-test-plan.md) —
  exhaustive real-user journeys for public, customer, owner, staff, reseller,
  and admin roles, including billing, operations, retained templates,
  providers, and recovery.
- Full role and feature test execution ledger (`full-role-feature-test-results.md`, kept in the private repository) —
  run identity, authoritative evidence, wave status, environment findings,
  blockers, and release sign-off.
- [Deploy and backup runbook](deploy-runbook.md) — repo layout and document
  root, production deploy steps, mandatory post-pull steps, the local Herd +
  DBngin environment, production hardening invariants, and backup/restore.

## Coverage gaps

These are the material gaps between what these docs describe and what is wired
today. Trivia and machine-specific notes are omitted.

- **Framework version.** These pages document Laravel 12 (`core/composer.json`
  pins `laravel/framework ^12.61.1`) despite Laravel-10-era code lineage. The
  installed vendor version was not confirmed by running the app. See
  [architecture](architecture.md).
- **Two storefront engines coexist.** Built-in Blade `post_template`s
  (Crave/Glow/Matcha) and Liquid marketplace themes under
  `resources/liquid-themes/` are both live; neither fully replaces the other.
  The versioned `liquid-themes/<theme>/<version>/` layout is inferred from
  `ThemePackageInstaller` (`mp:crave@1`), not confirmed by directory listing.
  See [theme-system](theme-system.md).
- **CSP is report-only in production.** `CSP_ENFORCE` is unset; the enforce
  flip, per-theme browser pass, and third-party embed allowlisting remain owner
  actions, not built. See [security](security.md) and
  [theme-system](theme-system.md).
- **Deferred drivers ship as Null/Fake/test-mode defaults.** Confirmed in
  docblocks, not just specs: `CloudflareDomainProvisioner` (returns
  `provisioning`, needs a CF token/zone; `ManualDomainProvisioner` is the only
  wired driver), `StripeResellerDriver`/`PaypalResellerDriver`,
  `NullWebPushSender`, `FakeHungerStationClient`, `LiveStripeConnectGateway`
  (only when the Stripe SDK+key are present), and Studio's
  `StudioTemplateExportService` (501 when the node/puppeteer Chrome toolchain is
  missing). See [services-map](services-map.md).
- **TDSP is built but disabled by default.** Contrary to the "not built"
  memory note, `TdspController`, the admin form, and the migration all exist and
  TDSP appears in the enabled-gateway loop; it ships off (`payment_install` `0`).
  Remaining open items are live UAT/prod credentials and LYD currency
  confirmation, neither verifiable in code. See [payments](payments.md).
- **The Retire phase of the plans rebuild is not shipped.** The 999-sentinel →
  `PHP_INT_MAX` flip and the drop of legacy `effective_limit()`/`plan_feature()`
  reads are described from code comments and spec intent; the gating criteria
  (clean verify days, owner sign-off) come from comments/memory, not an enforced
  check. See [entitlements](entitlements.md).
- **Scheduled plan downgrades are read-only.** `pending_plan_id`/
  `pending_interval` columns exist and the lifecycle tick applies them at period
  end, but no shipped app code writes them (`CheckoutController` does not). See
  [entitlements](entitlements.md).
- **The remaining nine languages are seeded but not activated by default.** The
  initial migration seeds only `en`/`fr`, so a fresh install exposes two
  locales; production DBs may have more rows. A hard-coded `$isRtl` list
  (`ar,ur,fa,he,ps,sd,dv`) in auth/landing layouts can drift from the
  `languages.direction` column and is not reconciled in code. The `rev/seo/`
  remediation (R1–R23) is not present in this worktree — specced, not built. See
  [i18n-rtl](i18n-rtl.md).
- **Admin impersonation has no re-auth or 2FA step.**
  `UserController::loginAsUser` writes the audit log but performs no re-auth
  before the auth switch (MEMORY RP9 remains unbuilt). Reseller API-key
  middleware (RP10) is likewise noted as remaining. See [security](security.md).
- **BYOK does not bypass platform metering.** The spec asks for it, but
  `AiService` counts every `ai_usage` row regardless of key ownership, and
  per-plan AI limit overrides are a config comment, not wiring — only the global
  `AI_MONTHLY_CALL_LIMIT` ceiling exists. See [ai-providers](ai-providers.md).
- **Queue default disagrees with env.** `config/queue.php` defaults to `sync`,
  while `.env`/`.env.example` set `QUEUE_CONNECTION=database`; both are
  documented. The P3 subscription tick (`subscriptions:tick`) drives the live
  dunning lifecycle (past_due + grace); the merchant sees a Renew-now banner
  (M5). `addons:auto-renew` (#67) runs alongside it. The old `dunning:check`
  (#66) command keyed off the never-written `Upgrade` past_due status and was
  removed as dead code (M5). See [queues-jobs](queues-jobs.md).
