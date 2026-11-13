---
title: "Services map"
audience: internal
group: Internal
updated: 2026-09-18
---

# Services map

Engineering directory of `core/app/Services/`. Services hold the business logic
that controllers, jobs and console commands share, so the same rule is enforced
whether an action comes from the dashboard, the public API, the staff app or a
webhook. This page lists each service with its one-paragraph purpose. Trust the
class docblocks and code over the `rev/` specs — several specs describe work that
was later cut or changed.

Conventions worth knowing:

- A restaurant is a `Post`; a menu item is a `Menu`. See
  [data model](data-model.md).
- Most external integrations follow a **driver + factory + interface** pattern
  with a fake default (AI, WhatsApp, HungerStation, reseller gateways, payouts,
  domains, push). The fake keeps the whole pipeline drivable and testable with no
  live credentials, and a real driver swaps in via config or a container binding.
- Money-moving actions funnel through one choke point (`WalletService`) so every
  charge is ledgered and idempotent by `$ref`.
- "DEFERRED" in a docblock means the surrounding flow is built and tested but the
  final live API call is not wired (needs keys); it runs in test/simulated mode
  until then.

## AI and language (`Ai/`)

- **`AiService`** — orchestrates completions across the provider fallback chain.
  Handles JSON tasks (`response_format=json_object` + validate + retry-with-error
  + fall back to the next provider), meters usage into `ai_usage`, and enforces
  the monthly plan gate (`AiLimitExceededException`). Untrusted content is passed
  only in user-role messages, never folded into the system prompt.
- **`AiProviderFactory`** — builds provider instances from config, applying
  per-restaurant BYOK overrides from the Credential vault (`feature='ai'`).
  Accepts flat or per-provider secret shapes.
- **`AiProviderInterface`** + `Drivers/OpenAiCompatibleProvider` (MiMo, Kimi,
  DeepSeek, OpenAI — one driver, only base URL/key/model differ) and
  `Drivers/AnthropicProvider` (Messages API, dormant unless `ANTHROPIC_API_KEY`).
  `AiResult` normalises a single call's output and token counts.
- **`MenuGeneratorService`** — turns pasted menu text or a photo into a normalised
  draft (`categories → items`). Input is untrusted and prompt-injection-hardened;
  output is a draft the owner reviews before any DB write.
- **`TranslatorService`** — translates short menu strings into one target
  language; dedupes identical strings, uses an owner-approved translation memory
  plus a 30-day cache, honours a per-restaurant glossary/tone, flags low
  confidence.
- **`MenuTranslationService`** — fills missing or stale translations across a
  restaurant's supported languages, writing into the model `translations` JSON
  with `_machine`/`_src_hash` review metadata. Re-translates on source edit,
  never touches owner-edited text.

## Menu Studio (`Studio/`)

The public no-signup AI menu studio.

- **`StudioTemplateService`** — discovers HTML/CSS menu template packages
  (`{slug}/template.blade.php` + `style.css` + `manifest.json`) and binds them to
  the session's `draft.json`. Slug whitelist comes from discovery.
- **`StudioDesignService`** — generates a PDF-styled design image via Nano Banana
  from the extracted draft + described look, wrapped in a credit `spend()` guard
  (debit first, refund on failure).
- **`StudioFitService`** — one text-model pass proposing display trims, featured
  picks and an accent colour for template mode. Falls back to deterministic
  word-boundary clamping when the provider chain is down.
- **`GeminiImageProvider`** — the Nano Banana (Google Gemini image model) client,
  standalone from the chat `AiProviderInterface`.
- **`StudioCreditService`** — append-only server-side credit ledger; debits are
  locked-transaction checked so the client can never overspend
  (`InsufficientCreditsException`).
- **`StudioPdfService`** — wraps a raster design into a one-page PDF with GD only,
  no PDF library.
- **`StudioTemplateExportService`** — prints template-mode HTML through headless
  Chrome (`HeadlessChrome`, node + puppeteer); answers 501 and lets the client fall back to
  `window.print()` when the toolchain is missing.
- **`StudioAnalyticsService`** — studio funnel analytics from the session status
  ladder (exploring → onboarding → extracted → designed/published).

## WhatsApp (`WhatsApp/`)

Multi-provider WhatsApp channel with spec in `rev/whatsapp/`.

- **`WhatsAppService`** — orchestrates a send across the resolved driver with
  logging and automatic `wa.me` link fallback.
- **`WhatsAppProviderFactory`** — resolves the driver for a restaurant, applying
  BYOK-vs-platform secrets (`feature='whatsapp'`); the `link` driver is the
  guaranteed built-in fallback. `WhatsAppProviderInterface` /
  `AbstractWhatsAppProvider` default every capability to unsupported.
- Drivers: **`LinkProvider`** (`wa.me` click-to-chat, no credentials/cost),
  **`MetaProvider`** (WhatsApp Cloud API — text, templates, interactive, catalog,
  Flows), **`TwilioProvider`** (Twilio Messages API, `whatsapp:` channel).
- **`WhatsAppBilling`** — plan quota + wallet billing for platform-key sends;
  BYOK, the `link` provider and free 24h-window service replies are never billed.
- **`WhatsAppOtp`** — OTP send/verify (cached codes, authentication category).
- **`WhatsAppBroadcast`** — marketing broadcast to opted-in audience; re-checks
  consent per recipient, pauses on low sender quality.
- **`WhatsAppChatbot`** — optional inbound auto-reply through `AiService` inside
  the free 24h window; swallows every error so webhook processing never breaks.
- **`WhatsAppWebhookProcessor`** — idempotently applies delivery/read status and
  inbound messages from a decoded payload (runs in the queued job).
- `WhatsAppResult`, `WhatsAppProviderException` (retryable flag),
  `WhatsAppLimitExceededException` (`quota`/`funds`/`disabled`) round out the
  contract.

## Agentic ordering (`Agents/`)

The AI ordering agent shared by the chat widget and external MCP clients.

- **`AgentToolsService`** — the one tool implementation. Every method takes a
  server-resolved restaurant, returns JSON-safe arrays, prices server-side, and
  the only mutating tool is `createOrder` (unpaid, hard-capped, takeaway/table
  only, gated on plan).
- **`AgentChatService`** — drives the chat loop; `dispatchTool()` is the only
  place a tool runs. Customer-history tools key off the trusted session phone,
  never a model-supplied one (PII boundary).
- **`AgentSafety`** — deterministic pre-model gates (abuse/spam block, human
  escalation) so bad input never reaches the model.
- **`PiiRedactor`** — redacts PII from message text before persisting to
  `agent_messages`; the live turn still sees the original.
- **`AgentLanguage`** — script-based language auto-detect (no AI call) so the
  agent replies in the customer's language.
- **`AgentAnalyticsService`** — chat-to-order conversion analytics per channel.

## Messaging and OTP

- **`SmsService`** — sends SMS via the restaurant's or platform's credentials;
  honours marketing opt-outs; logs per feature (`otp`/`order`/`status`/
  `campaign`/`general`).
- **`SmsOtpService`** — customer-login OTP over SMS: 6-digit hashed code,
  10-minute expiry, 3 requests/10 min, 5 verify attempts. Code, attempts and
  rate limit are per `OtpScope` + phone (one store, one marketplace tenant,
  the driver app, the platform checkout); callers build the scope from the
  route / host, never the request body. Plus 10 requests/10 min per phone
  across all scopes.
- **`BirthdayOfferService`** (#63) — sends each opted-in customer a
  one-per-year birthday coupon; idempotent by year so the daily command never
  double-sends.

## Catalog and orders

- **`MenuAdminService`** — menu create/update/delete persistence and validation,
  shared by the owner API and the staff app so both edit identically.
- **`OrderApiService`** — session-free order builder for the public API; builds
  the same `Order`/`OrderItem`/`OrderItemExtra` rows the dashboard does and fires
  `order.created`, with no request/session dependency. Prices re-derive from the
  live `Menu` (not snapshotted).
- **`CouponService`** — validates a coupon code and computes the discount.
- **`StorefrontThemeService`** — single source of truth for menu templates in
  `resources/views/store_templates/`. Filesystem is the source for template code +
  default metadata; `post_template_meta` is an admin display overlay. Missing
  templates fall back to the admin-configured fallback; disabling only hides from
  the picker (existing users grandfathered).

## Verticals (`Verticals/`, `Fulfillment/`, `Laundry/`)

The multi-vertical layer over the shared catalog/order services (spec in
`rev/multi-vertical/`). The three registries are the frozen seams every vertical
consumes — read, never fork. See [multi-vertical.md](multi-vertical.md).

- **`Verticals/VerticalRegistry`** — capability lookups for a store's
  `posts.store_type`, read from `config/verticals.php`. `config()` returns a
  vertical's full capability array (falling back to `restaurant` for an
  unknown/null type); `has()`/`isFood()` read flags; `fulfillmentModes()`,
  `itemForm()`, `themeSet()` read the multi-value capabilities; `forStore(Post)`
  is the per-instance convenience. Stateless — resolve via `app()`.
- **`Verticals/VerticalVocab`** — per-group noun relabelling (food says
  "Dish"/"Menu", goods "Product"/"Catalog", services "Service"/"Booking"), each
  resolved through `___()` so every locale translates. `for($storeType, $key)`
  returns the translated noun, `map($storeType)` the whole set. Never hardcode
  "Menu"/"Dish"/"Order" in a vertical view — call this.
- **`Verticals/FulfillmentRegistry`** — the source of truth for valid
  `orders.type` values now the column is a VARCHAR. `isValid()`, `meta()`,
  `label()` (translated), `needsAddress()`, `needsSlot()`, and
  `modesFor($storeType)` (a vertical's declared modes intersected with the modes
  actually defined in config, so a vertical can never accept an undefined mode).
- **`Fulfillment/GoodsCartService`** — the per-item add-line cart for the
  goods/retail verticals (`shelf` themes), parallel to the legacy food cart.
- **`Fulfillment/GoodsOrderService`** — ship/pickup/in-store checkout for goods
  orders; builds the `Order`/`OrderItem` rows and deducts item + variant stock
  (`Menu::deductStock()` + `MenuVariant::deductStock()`, mirroring
  `OrderApiService`) rather than posting to the legacy `restaurant.submitOrder`.
- **`Laundry/ServiceBookingService`** — service-booking checkout for the `bookla`
  (laundry) storefront; wraps `GoodsCartService`/`GoodsOrderService`, pinning a
  per-kg line's `quantity` to 1 and snapshotting `order_items.unit_price` to
  `price_per_kg × weight_kg` (with weight-capture-on-pickup repricing).

`Theme/VerticalThemeSets` gates the storefront theme picker per vertical — see
[theme system](theme-system.md#vertical-aware-theme-sets).

## Front-of-house: KDS, POS, delivery, reservations, split bill

- **`KdsService`** — Kitchen Display System: station routing (branch override →
  menu default → none), item-level bumping, firing held courses, and keeping the
  order status in sync with its items.
- **`PosService`** — staff register operations; delegates order assembly/pricing
  to `OrderApiService` and adds POS concerns (send-to-kitchen vs hold, cash
  tender/change, resume of parked orders).
- **`DeliveryService`** — delivery-status transitions shared by the owner API and
  driver app; centralises timestamp-stamping and webhook firing.
- **`ReservationBookingService`** — assigns a reservation to a table with a
  server-side double-book guard within a +/- window.
- **`SplitBillService`** — pay-by-seat: splits an order into N even shares,
  absorbing the rounding remainder into the last share.

## Inventory

- **`InventoryService`** — the single writer for the inventory ledger. Every stock
  change goes through `recordMovement()` so the immutable `stock_movements` ledger
  and the cached `ingredients.current_stock` never drift.

## Analytics

- **`SalesAnalyticsService`** — sales summary over a date range (revenue summed
  from the live `Order->total`, so a bounded range is required).
- **`MenuEngineeringService`** (SA1) — the Stars/Plowhorses/Puzzles/Dogs matrix
  by popularity × margin, plus view-to-order ratio flags; degrades to
  popularity-only when cost is unset.
- **`BehaviorAnalyticsService`** (SA2) — anonymous scan-behaviour aggregates
  (languages, time-on-menu, drop-off), no PII.
- **`RevenueDepthService`** (SA4) — AOV, revenue by daypart and by branch.
- **`RetentionService`** (SA5) — repeat-customer retention / RFM from
  `orders.customer_id`.
- **`AdminDashboardService`** — cached platform-wide numbers behind the superadmin
  dashboard; rolling 30-day windows for stable deltas.
- **`PlatformMetrics`** (P5-3) — fire-and-forget per-render/per-app daily
  counters; alert evaluation runs on the daily schedule.
- **`AbuseMonitor`** (P5-5) — flags cap-breach sellers (renders dying by budget)
  and phone-home CSP violations; auto-delist is behind a config switch, default
  off.

## Plans, entitlements, subscriptions, wallet

See [data model](data-model.md).

- **`Entitlements`** (P2) — the one reader for "did this customer pay for it".
  Sources in order: the live subscription's entitlement snapshot (grandfathering),
  `plan_entitlement` rows, then the legacy `plan()->settings` blob, plus active
  add-ons added arithmetically. NULL = unlimited (the 999 sentinel is normalised
  away; the legacy `effective_limit()` delegate re-emits 999 until Retire). Global
  kill-switches remain an operational veto in `has()`.
- **`SubscriptionService`** (P1) — the only writer of `subscriptions`. Dual-writes
  alongside the legacy `upgrades` + `user.group_id` path, snapshots plan
  entitlements into `meta` on activation, appends a `subscription_events` row per
  transition, and keeps exactly one live row per user.
- **`WalletService`** — credit/debit the wallet balance; idempotent by `$ref`
  (duplicate ref is a no-op). The choke point for every ledgered charge.
- **`SubscriptionService`** covers plan changes; wallet top-up itself flows
  through the checkout/`updateUserPlan` path.

## Marketplace: themes, rendering, payouts

Theme marketplace (OS 2.0 model); spec in `rev/platform-v2/`.

- **`ThemePurchaseService`** (P4-1) — buys a theme license via `WalletService`;
  one purchase licenses the whole account. Entitlement checks fail open (an
  outage never blanks a storefront).
- **`ThemeUpdateService`** (P4-7) — safe theme updates on the immutable version
  ledger; restaurants pin `mp:<slug>@<semver>` and choose when to move; settings
  live under the versionless key so switching versions never wipes them.
- **`ThemeCustomizerService`** (P3) — schema-driven customizer; the schema is data
  (`theme.json`), so a restaurant restyles with no code execution. Draft →
  publish. Marketplace themes store settings under `mp:<slug>` so they survive
  version updates.
- **`Theme/`** (customizer v2, PRs #407–#413) — the schema/token layer under the
  customizer: **`SchemaV2`** (v1/v2 theme.json normalisation, shared fragments,
  `visible_if`), **`ThemeKeys`** (canonical keys + `config/theme-aliases.php`),
  **`Tokens`** (design-token var map, overrides-only emission),
  **`ContrastGuard`** (warn-only WCAG + nearest-passing shade),
  **`FontPairs`** (self-hosted woff2 pairs, no Google CDN),
  **`PaletteFromLogo`** (GD dominant-colour palettes). See
  [theme system](theme-system.md#theme-customizer-v2-blade-themes).
- **`Rendering/`** — the untrusted-theme render sandbox:
  - **`RenderClient`** (P1-2) — the single seam every untrusted render passes
    through, running under a `RenderBudget` (output size + wall-clock). On breach
    it throws `RenderFailedException`; the caller applies a safe fallback (never
    blank). Isolation strategy is pluggable (inprocess/subprocess/jailed).
  - **`LiquidRenderer`** / **`LiquidEnvironmentFactory`** (P2) — render a Liquid
    theme string against Drop presenters/scalars only, under the render budget +
    Liquid `ResourceLimits`. No filesystem access; strict at upload, lenient at
    live so a diner's menu never 500s.
- **`SellerPayoutService`** (P4-6, Option A) — marketplace revenue share on wallet
  rails; credits the seller's wallet with the configured share (default 70/30),
  idempotent per sale ref, no-op for platform-owned assets.
- **`StripeConnectService`** (P9-5b) — the Stripe Connect Express cash-out rail
  (Option B), selected via `platform.marketplace.payout_rail`. Settles a pending
  withdrawal by transferring to the seller's connected account behind
  `Payouts/StripeConnectGateway` (interface). `Payouts/LiveStripeConnectGateway`
  is the real implementation, only instantiated when the Stripe SDK + key are
  present.

## Apps platform (embedded apps)

Shopify-style app store (Platform P3); spec in `rev/platform/`.

- **`AppInstallService`** (P3-2) — install/uninstall lifecycle: grants requested
  scopes, wires the app's webhook subscription through the #99 delivery rails, and
  on uninstall revokes OAuth tokens and fires `app/uninstalled`.
- **`AppBillingService`** (P3-4) — per-app charges through `WalletService`
  (recurring per period, usage apps metered per unit); revenue share rides
  `SellerPayoutService`.
- **`AppSessionToken`** (P3-3) — mints a 60-second JWT (App-Bridge pattern) so an
  embedded iframe app authenticates API calls without a long-lived secret in the
  browser.

## Reseller / white-label (`Reseller/`, plus top-level)

The operator guide ships with the edition package. It is not in this tree.

- **`ResellerBillingService`** (P3) — platform→reseller wholesale billing: monthly
  seat invoices, paid marking, dunning (past_due → suspended), proration.
- **`ResellerSubscriptionService`** — recurring reseller-customer billing +
  failed-payment dunning; cancels and downgrades after `MAX_RETRIES`.
- **`Reseller/ResellerGatewayCharger`** — charges a reseller's customer using the
  reseller's OWN gateway keys (BYOK, never platform keys). No live credential →
  records in test mode. `ResellerGatewayDriver` interface with
  `Drivers/FakeResellerDriver` (test). Real drivers: `StripeResellerDriver`,
  `PaypalResellerDriver`, `TlyncResellerDriver`, plus (MoR run #418)
  `PaddleResellerDriver` and `LemonSqueezyResellerDriver` — live
  create-checkout + per-reseller HMAC webhook verification are wired (invalid
  signature 400, unconfigured 202, verified 200), but `charge()` is DEFERRED
  on all of them until live reseller billing is wired. `ChargeResult`
  carries `ok`/`testMode`/`deferred`/`reference`/`error`.
- **`ResellerMailService`** (P4/RP2) — resolves the mail identity so a reseller's
  customers never see the platform brand: reseller SMTP if verified, else platform
  transport with the reseller's from-name/reply-to.
- **`ResellerReportService`** (P6) — platform-side reseller reporting (estimated
  wholesale MRR, seats, active/suspended counts, realised revenue).

## Custom domains (`Domains/`)

- **`ResellerDomainService`** (P5/RP3) — custom-domain lifecycle: request →
  verify (real DNS CNAME/TXT check via `DomainVerifier`) → admin approve →
  activate + provision TLS via the configured driver.
- **`DomainProvisioner`** interface: **`ManualDomainProvisioner`** (the only
  driver wired — gives a CNAME target + instructions, marks `manual`).
  **`CloudflareDomainProvisioner`** is DEFERRED (needs the platform CF token +
  zone id); returns `provisioning`. Enable via
  `settings.reseller_domain_driver = 'cloudflare'`.
- **`DomainVerifier`** (#85, top-level) — passes when a CNAME targets the platform
  host or a TXT record contains the token; fail-closed on an unresolvable host.

## HungerStation delivery aggregator (`HungerStation/`)

Spec in `rev/hungerstation/`.

- **`HungerStationClientFactory`** — returns the fixture-backed
  **`FakeHungerStationClient`** (default and all tests) unless the driver is
  `real` AND the tenant has live BYOK credentials; a container binding overrides.
  **`HungerStationClient`** is the real OAuth2 client-credentials Partner API
  client (no live call in CI). `HungerStationClientInterface` is the contract.
- **`HungerStationOrderService`** (P2) — ingests an HS order webhook into a local
  `Order`, idempotent on `delivery_orders(provider, external_id)`; unmapped SKUs
  are logged and flagged, never dropped; fires the same `order.created` signal.
- **`HungerStationStatusMapper`** (P3) — maps local/KDS status to an HS status per
  picking mode (partner vs Pelican).
- **`HungerStationCatalogService`** (P4) — pushes local menu rows to the HS catalog
  (price/active/quantity), recording an `hs_catalog_jobs` row per push.

## Staff push (`StaffPush/`)

- **`StaffPushService`** — fans a new-order push to a restaurant's staff, scoped to
  that restaurant only; respects the `notify_new_order` pref and prunes dead
  endpoints. **`WebPushSender`** interface with **`NullWebPushSender`** as the
  default — live VAPID delivery (minishlink/web-push) is DEFERRED (needs VAPID keys
  + a device); the Null sender reports success so the fan-out/prefs/prune pipeline
  runs and tests.

## Backup and export (`Backup/`)

Self-service per-store backup/export v2 (2026-07).

- **`StoreBackupService`** — builds the export ZIP for a `StoreBackup` row,
  honouring the owner's choices (dataset selection, format, order date-window,
  single branch, optional AES-256 password). Dataset ALLOWLIST — never a
  blanket dump; settings are scrubbed against the authoritative gateway-vault
  secret list plus a name-pattern backstop; every CSV cell is neutralised
  against formula injection; PII datasets export only when explicitly selected.
  Streams one row at a time so memory is O(chunk); the ZIP lands on the private
  local disk and is served only through an ownership-checked controller.
- **`DatasetStreamWriter`** / **`MediaCollector`** — the streaming writer and
  media gatherer behind the service.
- **`StoreImportService`** — restore/import from an export archive.
- Runs on the `heavy` queue via `GenerateStoreBackupJob`; scheduling
  (`store_backup_schedules` + `backups:run-scheduled`) and retention
  (`backups:cleanup-store`) are cron-driven — see [queues](queues-jobs.md).

## Ownership transfer and platform catalog

- **`OwnershipTransferService`** (WI-G7) — moves a store between users, or a
  branch between stores, in one transaction with an audit row. Store transfer
  re-points `posts.user_id` plus user-keyed satellites (staff owner,
  self-service backups); store-scoped rows follow the Post for free. User-LEVEL
  rows (plan/subscription, wallet, API tokens, webhooks, transactions)
  deliberately do NOT move. Runs behind a two-party consent flow
  (`store_transfers`, `User\OwnershipTransferController`) or superadmin force;
  plan store-capacity is enforced at accept time.
- **`PlatformCatalogService`** (WI-G4) — superadmin-curated platform catalog of
  global menu categories (with sub-categories) and global extras
  (`global_menu_categories` / `global_menu_extras`, no `store_id`).
  Adopting COPIES the row into the store's own scoped `MenuCategory`/`MenuExtra`
  stamped with `source_global_id`, so a re-adopt updates the copy instead of
  duplicating — no cross-tenant row sharing. Distinct from the per-restaurant
  reusable library (`MenuExtra.is_global`), which stays restaurant-scoped.

## Developer docs and MCP (`DevDocs/`)

- **`DocsCorpus`** — loader for the developer docs corpus at `resources/docs/dev`.
  Markdown files chunked on `##` headings for search, cached per-file by
  `filemtime`. Paths are corpus-relative without extension (`themes/filters`,
  `learn-overview`) — the shape exposed to the public `/mcp/dev` MCP tools.

## Infrastructure and security utilities

- **`StorageService`** — abstraction over local disk and S3-compatible CDN; resolves
  BYOK storage credentials from the vault, resizes via Intervention, streams to S3
  or falls back to local disk. Use `StorageService::url()` for public URLs.
- **`SsrfGuard`** (GAP-4) — SSRF guard for owner-supplied outbound URLs (webhooks):
  a URL is safe only when https AND every resolved IP is public (blocks loopback,
  private, link-local, reserved, and `169.254.169.254`); fail-closed.
- **`WebhookService`** — fans a webhook event to every matching active endpoint,
  each as its own queued `DeliverWebhookJob` with independent retries so a dead
  endpoint never blocks the firing request.
- **`TwoFactorService`** (H8) — TOTP 2FA wrapping pragmarx/google2fa: secret
  generation, code verify (±1 window), recovery codes; secrets stored encrypted on
  the User.
- **`SitemapService`** — builds `sitemap.xml` from a known URL list (home + blog +
  active storefronts) instead of crawling, dropping the browsershot crawl chain.

## Catalog queries, wallets and pricing

- **`CatalogQueryService`** — the single gate for what the public Catalog MCP may
  expose. Filters stores by owner status, plan, and `catalog_visible` opt-out so
  no non-public store ever leaks. Key methods: `eligibleQuery()`,
  `isVisible(Post)`, `displayPrice(Menu)`.
- **`CustomerWalletService`** — customer store credit (spendable cash) on the
  unified ledger, kind `customer_store_credit`. Account is per (customer,
  restaurant). Key methods: `accountFor(Customer)`, `balance(Customer)`,
  `applyToOrder(Order, Customer, float)`. Entirely separate from loyalty points.
- **`LedgerService`** — the unified double-entry ledger engine. Every money
  movement is a `post()` of two or more legs whose signed amounts sum to zero.
  Balances are `SUM(entries.amount)`; `balance_after` is a non-authoritative
  snapshot. Concurrent debits are serialized via `lockForUpdate`. Key methods:
  `accountFor(ownerType, ownerId, kind, currency)`, `post(array $legs)`,
  `balance(LedgerAccount)`.
- **`WalletPromoService`** — redeems a standalone credit code (platform coupon
  with `applies_to = wallet_topup`) into a user's platform wallet. Idempotent per
  (code, user) via the wallet ref. Key method: `redeem(User, string $code)`.
- **`Orders/OrderPricing`** — the single shared implementation of coupon +
  store-credit application on an order. Every order surface (web storefront,
  agent, MCP, POS, staff) calls this so pricing rules are never duplicated.
  Server-authoritative: the caller passes intent (coupon_code, use_wallet); the
  discount and applied credit are computed here.
- **`ResellerEmailTemplateService`** — resolves subject + HTML body for a
  reseller customer email. When the reseller has an enabled override for the key,
  it wins; otherwise a built-in default is used. Placeholder whitelist only, no
  template engine in user content. Key methods: `resolve(Reseller, string $key,
  array $data)`. Keys: `customer_welcome`, `invoice_created`,
  `subscription_expiring`.

## Related pages

- [Data model](data-model.md) — the tables these services read and write.
