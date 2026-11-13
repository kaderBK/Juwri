---
title: "Checkout, tax and tipping settings"
audience: owner
group: Users
updated: 2026-07-25
---

# Checkout, tax and tipping settings

Everything a diner sees at checkout — which order types they can pick, whether
a tax line and tip prompt appear, and how they can pay — is controlled per
restaurant. This page walks through those settings and explains exactly how
each one changes the totals your customers are charged.

## Where these settings live

Open **Stores** in the sidebar, edit a restaurant, and use the tab bar at
the top of the page. Two tabs matter here:

- **Ordering** — order types, delivery charge, minimum order, tax, tips,
  coupons, scheduling, and the multi-currency display option.
- **Payments** — the per-restaurant payment gateways and their API keys.

Every setting on these tabs applies to that one restaurant only, so different
restaurants on your account can have different tax rates, tips, and gateways.

## Order types

Three toggles on the **Ordering** tab control which order types diners can
choose at checkout:

- **Table ordering** — the diner enters a table number. When they scan a
  table QR code the number is filled in for them.
- **Takeaway ordering** — the diner enters a phone number.
- **Delivery ordering** — the diner enters a phone number and address.

Only the enabled types appear as buttons in the checkout form. If all three
are off (or your plan does not include ordering), the menu becomes view-only.

Two related fields sit just below the toggles:

- **Delivery charge** — a flat amount added to the total of delivery orders
  only. Table and takeaway orders are never charged it.
- **Minimum order value** — checkout is blocked until the item subtotal
  reaches this amount. Set 0 to disable.

Also on this tab: **Store open** and **Prevent orders when closed** let you
stop new orders without unpublishing the menu, and if you use
[branches](branches.md), a closed branch rejects orders with its own closed
message regardless of these settings.

## Tax

Three fields on the **Ordering** tab control tax:

- **Tax rate** — the tax percentage. It is applied to the item subtotal
  (items, variants, and extras), rounded to two decimals, and added to the
  total. Set 0 for no tax line.
- **Tax label** — the name shown on the tax line in the cart, for example
  "VAT" or "GST". Defaults to "Tax". (The label is used by the newer menu
  templates; older ones show a generic "Tax" line.)
- **Tax type** — a Percentage/Fixed selector. Currently the order totals are
  always calculated as a percentage, so leave this set to **Percentage**; the
  Fixed option is saved but not yet applied to totals.

The tax percentage applies to the item subtotal only — the delivery charge
and tip are not taxed. The full total a diner is charged is:

    item subtotal + delivery charge + tax + tip − coupon discount

The server recalculates this total itself when the order is placed, so the
amount charged always matches the breakdown shown in the cart.

## Tipping

Turn on **Tips** on the **Ordering** tab to let diners add a gratuity at
checkout. When enabled, the checkout form shows preset buttons — **10%, 15%,
20%** of the item subtotal — plus a **Custom** option where the diner types
an exact amount. The presets are fixed and cannot currently be changed.

The tip is shown as its own line in the cart breakdown, included in the total,
and stored on the order — the [Orders page](orders.md) shows it next to the
order total. Tips are supported by the modern menu templates (Aurora, Pulse,
Vivid, Crave, Glow, Matcha); older templates ignore the setting.

## Online payment and gateways

The **Online payment** toggle on the **Ordering** tab adds a "Pay online"
choice at checkout (the platform administrator must also have online payments
enabled site-wide). Diners who pick it are redirected to a secure payment page
listing your enabled gateways; the order stays hidden from your Orders list
until it is paid.

Configure gateways on the **Payments** tab. Each gateway card has a status
(On/Off), a display title, its API key fields, and — where the provider
supports it — a sandbox mode for testing. Keys are stored per restaurant.
Available gateways: PayPal, Stripe, Razorpay, Mollie, Paddle, Paytm,
Paystack, PayUmoney, Iyzico, Midtrans, PayTabs, Telr, 2Checkout, CCAvenue,
Tap (MENA), Flutterwave (Africa), TDSP / T-Lync (Libya), and Wire Transfer.
Paddle redirects to a Paddle-hosted checkout page (sandbox mode supported).
Wire Transfer marks the transaction as pending and shows your bank details;
you confirm receipt manually.

Diners can always choose to pay at the counter or on delivery instead — online
payment is offered alongside, not instead of, cash.

## Gateway reference — what each one asks for

Every gateway card has the same first two fields — **status** (On/Off) and
**title** (the name diners see) — plus a **sandbox** switch where the provider
has a test mode. The list below is only the credential fields on top of those.
Enter them exactly as the provider's own dashboard labels them.

| Gateway | Credential fields | Sandbox |
|---|---|---|
| PayPal | Client ID, Secret, App ID | Yes |
| Stripe | Publishable key, Secret key | Uses test keys |
| Razorpay | API key, Secret key | Uses test keys |
| Mollie | API key | Uses test key |
| Paddle | API key (Paddle Billing) | Yes |
| Paytm | Merchant key, Merchant MID, Merchant website | Yes |
| Paystack | Secret key, Public key | Uses test keys |
| PayUmoney | Merchant POS ID, Signature key, OAuth client ID, OAuth client secret | Yes |
| Iyzico | API key, Secret key | Yes |
| Midtrans | Client key, Server key | Yes |
| PayTabs | Region, Profile ID, Secret key | Region-based |
| Telr | Store ID, Auth key | Yes |
| 2Checkout | Account number, Public key, Private key | Yes |
| CCAvenue | Merchant key, Access code, Working key | No |
| Tap (MENA) | Secret key, Publishable key | Yes |
| Flutterwave (Africa) | Secret key, Public key | Yes |
| TDSP / T-Lync (Libya) | Store ID, Token | Yes |
| Paylink / STC Pay (KSA) | API ID, Secret key | Yes |
| Paymob (KSA / Egypt) | Secret key, Public key, Integration ID, HMAC secret | Yes |
| Geidea (KSA) | Merchant public key, API password | Yes |
| Fawry (Egypt) | Merchant code, Security key | Yes |
| Fatora (Qatar) | API key | Yes |
| Noon Payments (KSA) | Business ID, Application ID, API key, Webhook secret | Yes |
| Tamara (BNPL) | API token, Notification token | Yes |
| Tabby (BNPL) | Secret key, Public key, Merchant code | Yes |
| Wire Transfer | Bank details text (no API keys) | — |

Notes on a few of them:

- **Wire Transfer** takes no keys at all. It marks the transaction pending and
  shows the bank details you type in; you confirm receipt yourself.
- **Noon** has a separate webhook secret because noon signs its callbacks; leave
  it blank and those callbacks cannot be verified.
- **Paymob** likewise needs its HMAC secret for callback verification.
- **CCAvenue** has no sandbox switch here — use CCAvenue's own test merchant
  account.
- **GoCardless** and **Lemon Squeezy** are configured by the platform
  administrator only, not per restaurant. GoCardless takes an access token and a
  webhook secret; Lemon Squeezy takes an API key, store ID, variant IDs and a
  webhook secret, with a test-mode switch.

Provider callbacks arrive on shared platform URLs — `/ipn/{gateway}` and
`/webhook/{gateway}` — so where a provider asks for a notification URL, use those
with the gateway's own name. You do not need to configure them per restaurant.

## Buy now, pay later (Tabby and Tamara)

Tabby and Tamara are instalment gateways and have an extra gate on top of the
usual On/Off, on the **Ordering** tab:

- **Enable BNPL** — a master switch. With it off, neither BNPL gateway is offered
  at checkout even if its card is On.
- **Minimum basket** — BNPL only appears once the order total reaches this
  amount. The default is **200** if you leave it blank. It is a plain number in
  your menu currency; the provider still enforces its own real currency floor on
  top.

So a BNPL option shows to a diner only when all three are true: the gateway is
installed for that restaurant, BNPL is enabled, and the total is at or above the
minimum. Subscription, wallet top-up and membership payments skip the basket
threshold entirely — there, the platform-level gateway switch is the only
condition.

## Apple Pay and Google Pay

Wallet payments ride on Stripe:

- **Via the Stripe payment page (works today).** Order payments through
  Stripe use Stripe's hosted checkout page, which offers Apple Pay and Google
  Pay automatically on supported devices once you enable wallets in your
  Stripe dashboard. For Apple Pay you must also complete Stripe's domain
  verification for the domain your menu is served from — the platform serves
  the required verification file once your host places it, so ask your
  platform administrator if verification fails.
- **In-page wallet button (planned, not yet available).** A one-tap
  Apple/Google Pay button that appears directly on the menu is built on the
  server side but is not yet shown on any menu template, so diners will not
  see it yet.

Both paths compute the charge amount on the server from the order, never from
the browser, so a tampered page cannot change what is charged.

## Multi-currency display

Under **Stores → Edit → Languages & currency** you can show approximate prices
in other currencies for tourists:

1. Set **Show prices in multiple currencies** to Yes.
2. Enter **Manual exchange rates**, one per line, in the form
   `CODE=rate` — for example `EUR=0.92` means 1 unit of your menu currency
   equals 0.92 EUR.

A small currency switcher then appears on the menu (supported by the Aurora,
Nebula, Pulse, Crave, Glow, and Matcha templates). This is display-only:
converted prices carry a disclaimer ("Prices shown in EUR (approximate).
Charged in base currency.") and the cart, checkout, and actual charge always
use your real menu currency. Rates do not update automatically — review them
now and then. There is no automatic exchange-rate feed; manual rates are the
only source.

## Cart persistence

Nothing to configure here — it is automatic. The diner's cart is saved on
their own device, separately per restaurant, so closing the browser or
switching apps does not lose their order. On the Aurora, Nebula, and Vivid
templates a returning diner sees a "resume your order" banner offering to
restore or clear the saved cart; on those templates saved carts expire after
24 hours, and items that have since been sold out or removed from the menu are
dropped automatically before the cart is restored. Other templates restore the
saved cart silently, without a banner.

## Related pages

- [Managing orders](orders.md) — where placed orders, tips, and paid badges show up.
- [Coupons](coupons.md) — discount codes applied in the same checkout.
- [Branches](branches.md) — per-branch menus and closing a branch to orders.
- [Themes](themes.md) — which menu template your restaurant uses.
