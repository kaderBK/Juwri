---
title: "Payments internals"
audience: internal
group: Internal
updated: 2026-07-29
---

# Payments internals

How money moves through the platform: the transaction lifecycle, the two
choke points every gateway funnels through, the driver dispatch convention,
wallet top-up, and the webhook verification patterns each gateway uses. This is
an engineering reference; for the admin-facing gateway config see the reseller
docs, and for the customer flow see the user docs.

## The Transaction model

`App\Models\Transaction` (table `transaction`) is the single row that represents
one payment attempt. Key columns:

- `transaction_method` — the discriminator that decides what a confirmed payment
  does. Values seen in code: `order` (customer paying for a restaurant order),
  `membership` (restaurant owner buying a plan), `addon` (extra capacity),
  `wallet_topup` (platform wallet credit).
- `status` — `null` while pending, `Transaction::STATUS_SUCCESS` (`'success'`)
  once settled. `STATUS_PENDING` also exists. A `null` status is the idempotency
  guard: settlement code only touches rows where `status IS NULL`.
- `product_id` — plan id for membership, `Order` id for orders, `0` for
  wallet top-up.
- `payment_id` — the gateway reference; also used to dedupe recurring webhooks.
- `transaction_gatway` (note the spelling), `amount`, `base_amount`,
  `currency_code`, `frequency` (`MONTHLY` / `YEARLY` / `LIFETIME`).
- `details`, `coupon`, `billing` are cast to `object`.

`ensureInvoiceNumber()` atomically assigns a sequential `INV-<year>-<seq>`
number under a `lockForUpdate()` transaction.

## Two choke points

Every confirmed one-time payment is funneled through one of two static methods,
so gateway drivers never contain settlement logic of their own.

`CheckoutController::updateUserPlan(Transaction $transaction)` handles everything
that is not a restaurant order. It branches on `transaction_method`:

- `addon` → `activateAddon()` — creates a `UserAddon` keyed by
  `txn-<id>`, so a replayed webhook cannot double-grant.
- `wallet_topup` → `creditWalletTopup()` — calls `WalletService::credit()` with
  ref `topup-txn-<id>`, idempotent on the same key.
- otherwise (membership) — increments any coupon usage, computes the plan end
  date from `frequency`, deletes the old `Upgrade` row, creates a new one, sets
  `user.group_id`, and dual-writes into the new subscriptions system via
  `SubscriptionService::activate()`. The legacy `upgrades` + `group_id` path stays
  canonical until the plans-rebuild Retire phase (see
  [entitlements](entitlements.md)).

`App\Http\Controllers\User\PaymentController::paySuccess(Transaction $transaction)`
handles restaurant orders: marks the `Order` paid, credits the restaurant's
`wallet_amount` post-option, dispatches `SyncInvoiceJob`, fires the
`order.paid` webhook via `WebhookService`, redirects the customer to order
tracking, then deletes the transaction row.

Recurring subscription webhooks (Stripe invoices, etc.) instead go through
`CheckoutController::processWebhook()`, which creates the membership Transaction
server-side and dedupes on `payment_id` + `transaction_gatway`.

## Gateway driver dispatch

Drivers live in `core/app/Http/Controllers/User/PaymentMethods/`, one class per
gateway (~29 as of 2026-07: Stripe, Paypal, Paystack, Razorpay, Tap,
Flutterwave, Tdsp, Ccavenue, Iyzico, Midtrans, Mollie, Paddle, LemonSqueezy,
Paytabs, Paytm, Payumoney, Telr, TwoCheckout, WireTransfer, Fatora, Fawry,
Geidea, Gocardless, Noon, Paylink, Paymob, Tabby, Tamara — list the directory,
don't trust this sentence). Each exposes static `pay()`, and where relevant
`webhook()` / `ipn()`.

Dispatch is name-convention based. A gateway's `payment_folder` string (e.g.
`two_checkout`, `tdsp`) is converted to a class name by title-casing on
underscores and appending `Controller`. This happens in three places:

- `App\Http\Controllers\User\PaymentController::pay()` — resolves the driver from
  the submitted `payment_method` for order payments.
- `WalletController::topupPay()` — same resolution for top-ups.
- `App\Http\Controllers\User\PaymentMethods\PaymentController` (a distinct class
  from the order one) — the `ipn()` / `webhook()` dispatcher that inbound gateway
  callbacks hit. It looks the gateway up by `payment_folder` and forwards to the
  driver's static `ipn`/`webhook`.

Routes (`core/routes/web.php`):

- `Route::any('ipn/{gateway}', ...)->name('ipn')`
- `Route::post('webhook/{gateway}', ...)->name('webhook')` — registered after
  `webhook/hungerstation`, a single-segment route that would otherwise be
  shadowed by the `{gateway}` catch-all. The `webhook/sms/inbound`,
  `webhook/whatsapp/{provider}` and `webhook/delivery/{provider}` routes are
  multi-segment, so the single-segment `{gateway}` catch-all cannot shadow them
  regardless of order (they are in fact registered after it).

Both route patterns are CSRF-exempt through the explicit `ipn/*` and
`webhook/*` patterns in `app/Http/Middleware/VerifyCsrfToken.php`. Any inbound callback
URL must therefore contain the substring `ipn` or `webhook`, and because CSRF is
off the driver is solely responsible for authenticating the caller.

Gateways enabled for a restaurant are read from per-restaurant post-options
keyed `restaurant_<gateway>_install` / `_title`; the platform-level install flag
lives on the `payment_gateways` table (`payment_install`).

## Merchant-of-record gateways (Paddle Billing, Lemon Squeezy)

Two gateways are merchant-of-record: the provider is the legal seller,
calculates/collects/remits tax itself, and issues the receipt. The MoR set
lives ONLY in `config/mor-gateways.php` (`folders: paddle, lemon_squeezy`);
everything keys off `is_mor_gateway()` (Helper.php) and the
`PaymentGateway::withoutMor()` scope. Adding a MoR gateway = one config entry
plus a controller.

Hard rules (decision log in `rev/mor-gateways/`):

- **Platform billing only** — plans, add-ons, Studio, reseller invoices.
  Never restaurant orders, wallet top-up, gift cards, or marketplace
  purchases: those surfaces list gateways through `withoutMor()` (physical
  goods are prohibited by the providers' terms).
- **No local Tax records** on a MoR checkout (double-tax), and **no local
  invoice** for a MoR transaction — the row shows "Receipt issued by
  <gateway>" and the local invoice route 404s.
- Amount encodings: Paddle amounts are strings in zero-decimal-aware minor
  units (`tax_category: saas`); Lemon Squeezy `checkout_data.custom` values
  must be strings. Both webhooks verify HMAC over the RAW body only.

Flows: `PaddleController` (Billing API rewrite of the deprecated Classic
integration) POSTs `/transactions` with an inline non-catalog price and
redirects to hosted checkout; webhook verifies the `Paddle-Signature` ts:h1
HMAC with a ±300s tolerance and feeds events into `processWebhook()`.
`LemonSqueezyController` creates a JSON:API checkout (`custom_price` over a
pre-created variant, subscription vs one-time variant by `frequency`); webhook
verifies `X-Signature`. LS idempotency: `order_created` settles the money,
`subscription_created` only stamps `upgrades.unique_id`,
`subscription_payment_success` books renewals — no double-credit.

Reseller BYOK: `PaddleResellerDriver` and `LemonSqueezyResellerDriver` exist in
the reseller gateway registry (`app/Services/Reseller/Drivers/`) with
per-reseller HMAC verification (invalid signature 400, unconfigured 202,
verified 200 into the seam); `charge()` stays deferred like the Stripe/PayPal
drivers. Test kit: `MorWebhookFixtures` supplies signed webhook fixtures in
lieu of sandbox credentials.

## Wallet top-up flow

Self-service top-up lets a restaurant owner fund the platform wallet via any
enabled gateway. Routes (auth'd dashboard): `GET wallet/topup`
(`WalletController@topup`) and `POST wallet/topup`
(`WalletController@topupPay`, name `wallet.topup.pay`).

`topupPay()` validates `amount` against config bounds
(`wallet_topup_min`, default 5, floored at 0.5; `wallet_topup_max`, default
1000) and requires a `payment_method` whose `payment_install` is `'1'`. It
creates a Transaction with `transaction_method = 'wallet_topup'`,
`product_id = 0`, `frequency = 'LIFETIME'`, `status = null`, then hands off to the
resolved driver's `pay()`. When the gateway confirms, the driver calls
`updateUserPlan()`, which routes to `creditWalletTopup()` →
`WalletService::credit()`. Admin config lives under Settings → Wallet Top-up
(`wallet_topup_enabled`, min, max, presets). See
[wallet](../users/wallet.md) for the owner-facing side.

## Webhook verification patterns

There is no single verification helper; each driver authenticates callbacks in
the manner its provider supports.

- Stripe — `\Stripe\Webhook::constructEvent()` with the raw body, the
  `Stripe-Signature` header, and `settings.stripe_webhook_secret`. A
  `SignatureVerificationException` returns HTTP 400. Only `invoice.paid` events
  proceed, into `processWebhook()`.
- Paddle Billing — `Paddle-Signature` header (`ts:...;h1:...`): HMAC-SHA256
  over `<ts>:<raw body>` with the webhook secret, timestamp tolerance ±300s.
- Lemon Squeezy — `X-Signature` header: HMAC-SHA256 over the raw body with the
  signing secret, constant-time compare.
- Razorpay — recomputes `hash_hmac('sha256', order_id.'|'.payment_id, secret)`
  and compares to `razorpay_signature` before settling.
- Others (Paystack, Tap, Flutterwave, etc.) re-fetch or verify the transaction
  against the provider before calling `updateUserPlan()` — trust the driver, not
  the callback body.

The invariant across all drivers: never settle from the callback body alone.
Verify signature or re-query the provider, match the pending Transaction, and
only mutate rows with `status IS NULL`.

## TDSP / T-Lync gateway

Branded TDSP / pay.net.ly, actually T-Lync (`tlync.ly`) — a Libyan
redirect+webhook gateway (currency LYD). The memory brief lists this as
"not built", but the code is present and wired:
`TdspController.php`, the admin form
`resources/views/admin/paymentGateways/forms/tdsp.blade.php`, and migration
`2026_07_03_000000_register_tdsp_gateway.php` (inserts the `payment_gateways` row
with `payment_install = '0'`, i.e. disabled by default). It is also listed in the
per-restaurant gateway loop in `PaymentController::index()`. What remains open
per the spec is live UAT/prod credentials and confirming LYD currency support —
treat it as built but unproven end-to-end.

Flow: `pay()` POSTs `payment/initiate` (Bearer token) against
`uat-api.tlync.ly` or `api.tlync.ly` depending on sandbox mode, sends
`backend_url = route('webhook', 'tdsp')` and `frontend_url = route('ipn',
'tdsp')` with `custom_ref = transaction id`, then redirects the customer to the
returned `url`.

Credentials are context-dependent (`TdspController::creds()`): order payments
read per-restaurant post-options
(`restaurant_tdsp_store_id` / `_token` / `_sandbox_mode`); membership payments
read platform config (`settings.tdsp_store_id` / `tdsp_token` / `tdsp_sandbox`).

Security — the callback carries no HMAC or signature, so the callback body is
never trusted. `webhook()`:

1. loads the Transaction by `custom_ref` where `status IS NULL` and
   `payment_id IS NULL`; unknown/already-settled → HTTP 200 so the gateway stops
   retrying.
2. rejects if the callback `amount` does not match the pending Transaction
   (rounded to 2 dp).
3. re-verifies server-side via `verifyReceipt()` → `POST receipt/transaction`;
   an explicit `result: incomplete` fails, otherwise a `reference` /
   `payment_status` / success result confirms.
4. only then sets `status = STATUS_SUCCESS` and dispatches to `paySuccess()`
   (order) or `updateUserPlan()` (membership).

`ipn()` is the customer return (`frontend_url`) and is display-only — it shows a
success or "confirming" alert and redirects, but never settles. Settlement is
the webhook's job alone. Spec/contract detail lives in
`rev/payments/TDSP-TLYNC-INTEGRATION.md`.
