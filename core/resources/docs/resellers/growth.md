---
title: "Growth fast-follows (P7)"
audience: reseller
group: Resellers
updated: 2026-07-10
---

# Growth fast-follows (P7)

Reseller v2 Phase 7 adds four growth levers plus an optional payment rail. Every
figure and route below is drawn from the source.

## Sale links (7.1)

Each retail plan can mint a **sale link** — a public, zero-touch acquisition URL.

- Generate/rotate from the panel Plans page (`POST /reseller/plans/{id}/sale-link`),
  which sets `reseller_plans.sale_token` (24 chars) and returns `/p/{token}`.
- A prospect opens `/p/{token}` (`reseller.sale`), enters name/email/password, and
  pays. The merchant is auto-provisioned under the reseller on the linked plan and
  greeted with the reseller-branded `customer_welcome`.
- The platform plan + subscription are granted **only on a live-verified charge**
  (hotfix 1.1). With no live gateway keys the charge is test-mode and the merchant
  lands on FREE with the checkout recorded.

## Usage rebilling (7.2)

`ResellerUsageRebillingService::meter($reseller, $plan, $unit, $qty, $ref)` meters a
metered unit (`whatsapp_msg` / `ai_credit` / `sms`) at the tier's
`wholesale_usage` rate and debits the reseller wallet. The per-plan policy
(`reseller_plans.rebilling.policy`) decides the merchant charge:

| Policy | Merchant charge | Tier gate |
|--------|-----------------|-----------|
| `absorb` (default) | 0 | all tiers |
| `at_cost` | wholesale cost | Growth+ (`rebill_at_cost`) |
| `markup` | cost × multiplier (default 1.05, min 1.0) | **Agency** (`rebill_markup`) |

A policy above the tier's entitlement is silently clamped to `absorb`.

## Wallet auto-recharge (7.3)

`resellers.wallet_autorecharge` = `{enabled, threshold, amount}`. The daily
`reseller:auto-recharge` sweep charges the reseller's card on file (their BYOK
gateway) for `amount` when the wallet falls to/below `threshold`, and credits the
wallet **only on a real (non-test, non-deferred) charge** — balance is never
fabricated from a non-charge.

## Team & Roles (7.4)

`reseller_team_members` (`role` ∈ owner|admin|support, unique per reseller+user).
The panel Team page adds `admin`/`support` members (owner is implicit and never
removable), capped by the tier's `team_members` limit (FREE 1 · Starter 1 ·
Growth 3 · Agency 10; `999` = unlimited).

## Stripe Connect — Rail B (7.5)

`ResellerConnectService`, gated behind `platform.reseller.connect_enabled`
(**default OFF** — owner decision #3, the designated cut). When enabled, a reseller
on `billing_mode='connect'` runs direct-charge subscriptions with a per-tier
`application_fee_pct` (3/2/1/0 by tier). Live OAuth + direct-charge wiring is
deferred until the flag is switched on with live keys; everything is inert while
the flag is off.
