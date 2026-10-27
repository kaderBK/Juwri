---
title: "Reseller commission and wholesale pricing"
audience: reseller
group: Resellers
updated: 2026-10-05
---

# Reseller commission and wholesale pricing

Resellers earn no commission. Since the pricing rework of 5 October 2026 (Reseller Agreement §6.5: "We owe you no
commission or revenue share"), no share of a merchant's plan payment is credited to the reseller's wallet, on any
tier, whatever `commission_share` or `RESELLER_COMMISSION_SHARE` say. A reseller's margin is the wholesale discount
below. Partners who want a referral commission use the separate Affiliate Programme (Agreement §7.8).

Commission rows credited before the change stay in the ledger. If a payment that earned one is refunded, exactly the
credited amount is reversed (ref `rcommission-refund-txn-{transaction_id}:rsl`), once.

## Wholesale price per store

The platform invoices the reseller (`ResellerBillingService`, rules in `WholesalePricing`), in minor units of the
reseller's wholesale currency:

- **Base fee** for the period (monthly `base_fee`, or the yearly `annual_base_fee`), which includes `included_seats`
  stores.
- **Each further store**: the public price of the platform plan the store's owner is on, less the tier's
  `wholesale_discount_pct` (Starter 20%, Growth 30%, Agency 40%; a reseller's `terms_override.wholesale_discount_pct`
  wins), rounded half-up to the minor unit per store. A store whose plan has no public price in the wholesale currency
  (free or trial plan, private or switched-off plan, another currency) costs the per-store price `seat_price`.
- The included stores are the most expensive ones (ties: oldest first).
- Every non-sandbox store of the reseller's customers counts from creation until deletion, including stores of
  inactive customers. The reseller owner's own demo stores do not count.

## Billing cycle

- **Monthly** (default): invoiced in advance at the start of each month for the stores on that day.
- **Yearly prepaid** (`billing_cycle = yearly`, an Order option): the year is invoiced in advance for the stores on its
  first day. Once a month, stores added since are invoiced pro rata: price × days left in the term (from the store's
  creation day) ÷ days in the term, rounded half-up per store. A store is never invoiced twice in a term; a slot paid
  for a store that was deleted is reused by a new store free of charge. Removed stores earn no credit. On the term's
  last day a final true-up catches stores added in its last month.

## Limits

- `limits.max_stores` caps the stores (999 = no cap; Free 3, Starter 10, Growth 50, Agency 200). A store over the
  cap is refused on every creation path.
- `allowed_platform_plan_ids` lists the platform plans a retail plan may map to (empty = every live public plan).

## Existing resellers

Resellers that existed before the change keep the old pricing (base fee + extra stores × `seat_price`, stores of
active customers only) until the first billing period that starts at least 30 days after the notice e-mail
(`reseller:pricing-notice`, daily).

## Related pages

- [Wallet](wallet.md)
- [API](api.md)
