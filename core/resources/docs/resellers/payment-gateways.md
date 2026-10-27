---
title: "Payment gateway setup"
audience: reseller
group: Resellers
updated: 2026-07-29
---

# Payment gateway setup

This guide covers the admin screens that enable and configure payment gateways:
the gateway list, per-gateway credentials, the webhook and IPN endpoints to register,
and how test and live modes are selected. It also explains the three separate layers
of keys the platform uses, so you charge the right money through the right account.

All admin routes below live under the admin panel prefix (`admin_url()`, configurable
per install) and carry the `admin.` route-name prefix. Access requires the `admin`
middleware.

## Where gateways live

Open Payment Gateways from the admin sidebar (credit-card icon), route
`admin.gateways.index`. The page lists every registered gateway with its logo, title,
and a status badge (Active or Disabled). Each row has an Edit action that opens a slide
panel (`admin.gateways.edit`).

The gateway catalogue is seeded into the `payment_gateways` table. Out of the box it
carries: PayPal, Stripe (titled "Credit & Debit Card"), Bank Deposit (Offline
Payment / `wire_transfer`), 2Checkout, Paystack, Payumoney, Paytm, CCAvenue, Mollie,
Iyzico, Midtrans, PayTabs, Telr, Razorpay, and Paddle. Later migrations add Tap,
Flutterwave, TDSP (T-Lync, Libya), Lemon Squeezy, and the Syria tenders — Wallet
Transfer (`wallet_transfer`), eCash (`ecash`) and QRPay (`qrpay`). Only Bank Deposit is
enabled on a fresh install; everything else starts disabled.

The three Syria tenders differ in where they collect money. **Wallet Transfer** is an
order-only, store-level manual tender (Syriatel/MTN/Sham Cash) with no platform keys —
see the owner guide, [Wallet transfer & Syrian gateways](../users/wallet-transfer.md).
**eCash** and **QRPay** are redirect acquirers wired for both order payments (BYOK, per
restaurant) and platform billing (platform keys, below).

## Enabling a gateway

In the edit slide panel:

- **Active** — a toggle (`payment_install`) that turns the gateway on or off in the
  checkout. This is the only required change to "enable" a gateway; credentials aside,
  the gateway will not appear to payers until Active is on.
- **Title** — the customer-facing name (`payment_title`), required, up to 100
  characters. This is what payers see at checkout, so "Credit & Debit Card" reads
  better than "Stripe".

Saving posts to `admin.gateways.update`. The controller validates title and active,
updates the gateway row, then persists that gateway's credential fields into the global
`platform_settings` table via `PlatformSettings::set()`. Those options are later read as
`config('settings.*')` at payment time.

## Platform keys versus bring-your-own-keys

Three independent key sets exist. Configuring one does not affect the others.

- **Platform keys** — the credentials you enter in the admin gateway editor above.
  They belong to the platform (or white-label) operator and collect money that owners
  pay you: subscription and plan billing, usage add-ons, and wallet top-ups. Read at
  runtime as `config('settings.stripe_secret_key')` and similar.
- **Restaurant keys (BYOK)** — each restaurant owner enters their own gateway keys in
  their restaurant settings (stored as `restaurant_stripe_secret_key`,
  `restaurant_paypal_api_client_id`, and so on, per `store_options`). These collect
  diner order payments and are paid straight to the restaurant. Order checkout always
  uses the restaurant's own keys and never falls back to platform keys, so a restaurant
  that has not entered keys cannot take online order payments even if the platform
  gateway is enabled.
- **Reseller keys (BYOK)** — a white-label reseller charging its own customers uses its
  own gateway credential (`Credential::forReseller('reseller_gateway', …)`), never
  platform keys. This path is currently test-mode only (see
  [Reseller checkout](#reseller-checkout-is-test-mode)).

## Configuring credentials

Each gateway renders its own credential form. Representative fields:

- **Stripe** — Payment Mode (One Time / Recurring / Both), Publishable Key, Secret Key,
  Webhook Secret. Keys come from `dashboard.stripe.com/apikeys`.
- **Mollie** — a single API Key (`dashboard.mollie.com`).
- **PayPal** — Sandbox Mode (Enable / Disable), Payment Mode, Client ID, Client Secret,
  APP ID, and a read-only webhook URL. Keys come from the PayPal developer applications
  page.
- **Razorpay** — API Key and Secret Key.
- **TDSP / T-Lync (Libya)** — Environment (Sandbox / Live), Store ID, API Token. See
  [Test versus live](#test-versus-live) for the environment note.
- **Paddle** — Sandbox Mode, API Key (a server-side Paddle Billing key), Webhook
  Secret. This is the modern **Paddle Billing** API — the old Paddle Classic
  vendor-id/public-key fields are retired; if you had Classic keys saved, re-create
  them as a Billing API key. See [Merchant-of-record gateways](#merchant-of-record-gateways-paddle-billing-and-lemon-squeezy).
- **Lemon Squeezy** — Test Mode, API Key, Store ID, a subscription Variant ID and a
  one-time Variant ID (create one subscription product and one single-payment product
  in your Lemon Squeezy store; the plan price overrides the variant price at
  checkout), and Webhook Secret.
- **Bank Deposit** (`wire_transfer`) — a single rich-text Bank Information field shown
  to the payer; no API keys. Useful as an always-available offline fallback.
- **eCash** — Merchant ID, Terminal ID and API Key; live only (no eCash sandbox host is
  known yet, so a sandbox mode counts as not-configured). With the API Key absent the gateway is treated as not-configured and settles nothing.
- **QRPay** — Client ID and Secret ID; live only (no QRPay sandbox host is known yet, so a
  sandbox mode counts as not-configured) and no refunds through the API. QRPay fetches a
  short-lived access token per request and never persists it.
- **Wallet Transfer** (`wallet_transfer`) — no admin credentials. It is an order-only
  manual tender configured per restaurant (wallet numbers + instructions on the
  Payments tab) and confirmed by the owner against their wallet SMS; it never appears
  in platform billing.

Regional gateways (Paystack, Payumoney, Paytm, CCAvenue, Iyzico, Midtrans, PayTabs,
Telr, 2Checkout) follow the same pattern: a sandbox toggle where the provider
has one, plus that provider's merchant/API keys. The exact field list per gateway is
defined in `PaymentGatewayController::update()`.

## Webhook and IPN endpoints to register

Two public callback routes accept provider notifications, both keyed by the gateway's
folder slug (`payment_folder`):

- Webhook — `POST /webhook/{gateway}` (route name `webhook`), for example
  `https://your-domain.com/webhook/stripe` or `/webhook/paypal`.
- IPN — `ANY /ipn/{gateway}` (route name `ipn`), used by gateways that post an
  instant payment notification instead of a signed webhook.

The Stripe and PayPal editors show the exact webhook URL (read-only) for you to copy
into the provider dashboard. Both callback paths are exempt from CSRF (matched by
`webhook/*` / `ipn/*` in `VerifyCsrfToken`), since the caller is the payment provider,
not a logged-in browser session.

Events to subscribe to in the provider dashboard:

- **Stripe** — `checkout.session.completed`, `invoice.paid`, `invoice.upcoming`. Paste
  the signing secret into the Stripe editor's Webhook Secret field so signatures verify.
- **PayPal** — `PAYMENT.SALE.COMPLETED`.
- **Paddle** — register `https://your-domain.com/webhook/paddle` as a notification
  destination and subscribe at least `transaction.completed`, `subscription.created`,
  and `subscription.canceled`; paste the destination's secret into the Paddle editor's
  Webhook Secret field.
- **Lemon Squeezy** — register `https://your-domain.com/webhook/lemon_squeezy` with a
  secret of your choice and the events `order_created`, `subscription_created`,
  `subscription_payment_success`, and `subscription_cancelled`; paste the same secret
  into the Lemon Squeezy editor.

## Merchant-of-record gateways (Paddle Billing and Lemon Squeezy)

Paddle and Lemon Squeezy are **merchant-of-record (MoR)** providers: they are the
seller on the payment, so they calculate, collect and remit sales tax/VAT themselves
and they issue the receipt. That gives them a few behaviors the other gateways do not
have:

- **Hosted checkout.** The payer is redirected to the provider's checkout page; the
  tax line appears there based on the payer's billing address. Plan activation,
  renewals and cancellations flow back through the webhook events above.
- **Receipts come from the gateway.** Transactions paid through an MoR gateway show
  "Receipt issued by \<gateway\>" instead of a locally generated PDF invoice.
- **Your admin Tax records do not apply** to MoR checkouts — the gateway computes and
  remits tax. Keep tax records for Stripe and the other gateways.
- **Platform billing only, by design.** MoR gateways are offered for plan
  subscriptions, add-ons, Studio purchases and reseller invoices. They never appear
  for restaurant order payments, wallet or gift-card credit, or marketplace
  purchases — physical goods and store credit are outside both providers' terms.

## Test versus live

How a gateway selects test versus live mode depends on the provider:

- **Stripe, Mollie, Razorpay** — no sandbox toggle. Test versus live is determined by
  the key you paste. Test keys (for example a Stripe `sk_test_…` secret) run in test
  mode; live keys take real payments. Swap the keys to switch.
- **Sandbox-toggle gateways** — PayPal (Sandbox Mode Enable/Disable), 2Checkout,
  Payumoney, Telr, Midtrans, Iyzico, Paddle (Sandbox Mode), and Lemon Squeezy (Test
  Mode) each expose a sandbox switch alongside their keys. For Lemon Squeezy, keep the
  store itself in Test mode while testing and use a test-mode API key.
- **TDSP / T-Lync** — an Environment selector: Sandbox routes to `uat-api.tlync.ly`,
  Live routes to `api.tlync.ly`. The tokens differ per environment, so re-enter the
  live token when you flip to Live.

The **Payment Mode** (One Time / Recurring / Both) on Stripe and PayPal is not a
test/live switch — it controls whether subscription billing charges once or sets up a
recurring subscription. Lifetime plans are always charged one-time regardless.

## Reseller checkout is test-mode

A reseller charging its own customers is meant to run through the reseller's own
gateway keys. Until a reseller wires live keys, `Reseller::gatewayConfigured()` is
false (driven by a `gateway_live` flag in the reseller's settings) and
`ResellerGatewayCharger` records a test-mode transaction rather than moving money.

Even with keys present, the live Stripe and PayPal reseller drivers are deferred: they
validate that the secrets exist and then return a deferred (test-mode) result — the
full charge, invoice, subscription-advance, and dunning flow runs end to end, but no
real card is charged. Wholesale invoice payment (`reseller.invoices.pay`) is likewise a
test-mode stub. See [Reseller overview](overview.md) for the billing model.

### Reseller BYOK for Paddle and Lemon Squeezy

The MoR gateways have real reseller BYOK drivers. A reseller connects its **own**
Paddle Billing or Lemon Squeezy account (the reseller is the seller of record for its
merchants) on the reseller dashboard's gateway-credentials page. Each reseller gets a
dedicated webhook URL to paste into its own provider dashboard, with its own secret:

- `https://your-domain.com/webhooks/reseller/<reseller-id>/paddle`
- `https://your-domain.com/webhooks/reseller/<reseller-id>/lemon_squeezy`

Hosted-checkout charging and webhook verification are live for these two drivers;
off-session recurring charging of a saved payment method remains deferred (same seam
as Stripe/PayPal). The set of gateways offered to resellers is admin-controlled via
`config('platform.reseller.gateways')` (defaults: stripe, paypal, tlync, paddle,
lemon_squeezy).

## Gaps and not-yet-available

- **Tap and Flutterwave** are seeded gateways with working payment processors, but the
  admin gateway editor has no credential form for them and
  `PaymentGatewayController::update()` has no field mapping for either, so their
  platform keys cannot be saved through the standard editor. They read
  `config('settings.tap_secret_key')` / `flutterwave_secret_key` at runtime, and both
  are fully configurable at the restaurant level (BYOK) for order payments.
- **eCash and QRPay** ship as redirect-driver skeletons built to each provider's
  documented API shape, but the live network calls are stubbed pending merchant
  onboarding — eCash needs its merchant application (a multi-week process) and QRPay is
  gated on licensing/settlement verification. With no credentials saved, both settle
  nothing and bounce the payer back to choose another method. Enable them only once
  onboarding completes and the credentials are entered. **Wallet Transfer** has no such
  dependency and works as soon as a store adds its wallet numbers.
- **Live reseller gateway charging** (Stripe and PayPal BYOK drivers) is deferred;
  reseller checkout and wholesale invoice payment run in test mode until the live SDK
  wiring ships.

## Related pages

- [Reseller and white-label overview](overview.md) — the reseller portal and its
  wholesale billing model.
- [Configuring plans and pricing](plans-config.md) — the plans that gateway payments
  bill against.
