---
title: "Unified ledger, wallets, coupons and reconciliation"
audience: internal
group: Internal
updated: 2026-07-19
---

# Unified ledger, wallets, coupons and reconciliation

Every wallet in the platform — the merchant platform wallet, reseller wallets,
and customer store credit — is one account kind on a single double-entry ledger.
Every coupon across the three tiers (platform → owner/reseller, reseller →
merchant, owner → customer) is one row in the `coupons` table, scoped by
`issuer_scope` / `reseller_id` / `user_id` and by the surface it `applies_to`.

## The ledger

- **Accounts** (`ledger_accounts`): one row per `(owner_type, owner_id, kind,
  currency)`. Kinds: `platform` (a clearing account), `reseller`, `merchant`,
  `customer_store_credit`.
- **Entries** (`ledger_entries`): append-only postings. Every money movement is
  one `transaction_id` group of two or more legs whose signed `amount` values sum
  to zero. Entries are never edited or deleted; a correction is a reversal.
- A balance is always `SUM(amount)` over an account's entries; `balance_after` is
  a non-authoritative snapshot for display.
- The platform clearing account is expected to run negative — it is the
  counterparty to every credit into the other tiers.

The platform wallet still writes `wallet_transactions` as before and mirrors each
movement into the ledger, so existing balances and the admin wallet viewer are
unchanged.

Two service classes interact with the ledger tables:

- **`LedgerService`** (`core/app/Services/LedgerService.php`) — the low-level
  engine. `accountFor()` gets-or-creates accounts; `post()` writes a balanced
  multi-leg entry group; `balance()` returns the authoritative
  `SUM(entries.amount)`. Concurrent debits are serialized via `lockForUpdate`.
- **`CustomerWalletService`** (`core/app/Services/CustomerWalletService.php`) —
  customer store credit on the ledger (kind `customer_store_credit`). Wraps
  `LedgerService` for credit/debit/balance and handles the per-order
  `applyToOrder()` idempotently.

## Superadmin oversight

`GET /admin/coupons-wallets/overview` returns balances and account counts per
wallet kind and redemption stats (count, total used) per coupon issuer scope.
Per-reseller balances and admin grant/deduct live under the resellers admin
(`/admin/resellers/{id}/wallet`).

## Reconciliation

Run `php artisan ledger:reconcile` to verify integrity:

1. every `transaction_id` group sums to zero, and
2. each account's `SUM(amount)` equals its latest `balance_after` snapshot.

It is read-only — it reports drift and exits non-zero, and never rewrites the
append-only ledger. Exit code 0 means no drift. Run it as part of any
money-touching deploy check.

Because it is read-only, it also runs unattended: `Kernel::schedule()` invokes it
daily at 04:20 (`->withoutOverlapping()`), after the nightly money jobs.
