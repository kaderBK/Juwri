---
title: "Wallet transfer & Syrian gateways"
audience: owner
group: Users
updated: 2026-08-27
---

# Wallet transfer & Syrian gateways

This page covers three payment tenders built for Syrian stores: **Wallet
Transfer** — a manual Syriatel Cash / MTN Cash / Sham Cash tender you can turn on
today — and two online acquirers, **eCash** and **QRPay**, that a platform admin
configures once merchant credentials are in hand.

## Wallet Transfer — how it works

Wallet Transfer needs no third-party account. The diner pays you directly in
their wallet app and submits the transaction reference; you confirm that
reference against your own wallet SMS before the order is marked paid. Because
nothing is verified against a remote API, **the transaction reference is a claim,
not proof of payment** — always check it against the money that actually landed
in your wallet before you confirm.

## Turning it on

Open **Stores** in the sidebar, edit the restaurant, and go to the
**Payments** tab. Under **Wallet Transfer (Syria)**:

- Enable the method.
- Enter the wallet numbers you accept — any of **Syriatel Cash**, **MTN Cash**
  and **Sham Cash**. Only the numbers you fill in are shown to diners; leave a
  wallet blank to hide it.
- Write short **instructions** (for example, "Send the total to one of the
  numbers below, then paste your transfer reference"). This text appears on the
  payment page.

Wallet Transfer also obeys the store's online-payment switch: if **online
payments** are off for the restaurant, no online tender — Wallet Transfer
included — is offered at checkout.

## What the diner sees

At checkout the diner chooses to pay online and picks **Wallet Transfer
(Syria)**. The payment page shows only the wallet numbers you configured, the
exact amount due, the order number, and your instructions. After transferring in
their wallet app, the diner selects which wallet they sent from and enters the
**transaction ID** their app showed, then submits.

Submitting does **not** pay the order — it records the claim and sends you a
notification. The order-tracking page then shows a **"Payment awaiting
confirmation"** banner with the reference, so the diner knows you are verifying
it.

## Confirming a payment

Open the **Order Hub**. A wallet-transfer order that has a claim shows the wallet
used, the transaction reference, the amount, and when it was claimed, with
**Confirm** and **Reject** actions.

- **Confirm** — once you have checked the reference against your wallet SMS,
  confirm to mark the order paid. This runs the same settlement as any other
  paid order (receipt, loyalty, accounting), and confirming twice is safe — the
  second confirm does nothing.
- **Reject** — if the money never arrived, reject. The order stays unpaid and the
  diner's tracking banner returns to the awaiting state.

Only the store's own owner can confirm or reject its wallet-transfer claims.

## eCash and QRPay

**eCash** and **QRPay** are online payment acquirers for the Syrian market. They
are configured by a platform administrator on the admin **Payment Gateways**
screen (Merchant/Client credentials and a Sandbox/Live switch), not on the
restaurant Payments tab.

Until a provider's merchant onboarding is complete and its credentials are
saved, the gateway stays inactive: a diner who reaches it is sent back to choose
another method, and no money is moved. Once credentials are in place the gateway
behaves like any other redirect gateway — the diner is taken to the provider to
pay and returned to the order once payment is verified.

## Related pages

- [Checkout, tax and tipping settings](checkout-settings.md) — the Payments and
  Ordering tabs where per-restaurant gateways live.
- [Orders](orders.md) — the Order Hub where you confirm wallet-transfer claims.
