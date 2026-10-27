---
title: "Reseller wallet and coupons"
audience: reseller
group: Resellers
updated: 2026-07-09
---

# Reseller wallet and coupons

Your reseller account has its own wallet — a credit balance you hold with the
platform — and its own coupon codes for the plans you sell to your merchants.
Both live under the reseller portal.

## The wallet

Open the **Wallet** tab in the portal to see your balance and a full ledger of
every movement. The balance is authoritative from that ledger, so it is always
the exact sum of its history.

### Top up

Add funds with the **Top up** action; you are taken to a payment gateway, and on
a confirmed payment the amount is credited to your wallet. A replayed or
duplicated payment notification never double-credits.

### Pay a platform invoice from the wallet

When the platform issues you a wholesale seat invoice, you can settle it straight
from your wallet — the balance is debited for the invoice total and the invoice
is marked paid. If your balance is short, the payment is declined and the invoice
stays open. The invoice remains the record of what was owed; the wallet records
the payment.

### Fund a merchant

You can move credit from your wallet into one of your merchants' spendable
wallets from their customer row. The amount leaves your balance and lands in the
merchant's wallet, ready for them to spend on add-ons or renewals. You can only
fund merchants you own.

### Commission

Resellers earn no commission (since 5 October 2026): your margin is the wholesale
discount off the public plan price. Commission credited before then stays in your
wallet history. See [commission and wholesale pricing](commission.md).

## Reseller coupons

Under the **Coupons** tab you create discount codes for the plans you sell to
your merchants. Each code is scoped to you — it only applies when a merchant that
belongs to you buys a plan, and never on a direct platform checkout or another
reseller's merchants. Set a code, a percentage or fixed discount, a usage limit,
and an expiry. Delete a code to retire it.

## Routes

Portal wallet routes (all gated by `ResellerOwnerMiddleware`):

- `reseller.wallet` (`GET /reseller/wallet`) — balance and ledger.
- `reseller.wallet.topup` (`POST /reseller/wallet/topup`) — initiate a top-up payment.
- `reseller.customers.fund` (`POST /reseller/customers/{id}/fund`) — transfer credit to a merchant's wallet.
- `reseller.invoices.payWallet` (`POST /reseller/invoices/{id}/pay-wallet`) — settle a wholesale invoice from wallet balance. If the balance is insufficient the payment is declined and the invoice stays open.

Admin wallet routes:

- `admin.resellers.wallet` (`GET /admin/resellers/{id}/wallet`) — view a reseller's balance and last 50 ledger entries.
- `admin.resellers.wallet.grant` (`POST /admin/resellers/{id}/wallet/grant`) — credit or debit the reseller's wallet with a reason. Amount range 0.01–999999. A debit is funds-gated (fails on insufficient balance). Both legs are recorded as double-entry (reseller leg plus platform clearing leg).

## Ledger model

Every wallet movement is a `LedgerEntry` row. Entries are append-only: corrections are new balancing entries, never updates or deletes. Key fields:

- `amount` (decimal, signed) and `balance_after` (running balance).
- `account_id` — belongs to a `LedgerAccount`.
- `type`, `reason`, `ref` — classify and deduplicate entries.
- `meta` (JSON) — optional context.
- `granted_by` — set on admin grants.

A unique constraint on `ref` prevents duplicate postings (used for commission idempotency among other things).

## Commission

Reseller commission is no longer paid (pricing rework, 5 October 2026; Reseller Agreement §6.5): no share of a merchant plan payment is credited to the reseller's wallet. Commission credited before the change stays in the ledger and is reversed exactly on a refund. See [commission and wholesale pricing](commission.md).

## Admin oversight

Superadmin oversight of every reseller wallet and coupon lives in the platform
admin — see the admin reconciliation notes.
