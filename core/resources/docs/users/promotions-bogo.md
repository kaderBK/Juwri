---
title: Buy-one-get-one (BOGO) offers
audience: owner
group: Customers and marketing
updated: 2026-08-20
---

# Buy-one-get-one (BOGO) offers

A **BOGO** ("buy X, get Y") offer rewards customers for buying more — buy two
coffees and get the third free, buy one pizza and get a side at half price, and
so on. It is a coupon type alongside the percentage and fixed-amount coupons in
[Coupons](coupons.md).

## How it works

When you create a BOGO coupon you set:

- **Buy quantity** — how many qualifying items the customer must buy.
- **Get quantity** — how many items they then get discounted or free.
- The **discount** applied to the "get" items (a percentage, up to and
  including 100% for a free item).

At checkout the discount is calculated **server-side** from the cart. The offer
repeats as the cart grows — buy-2-get-1 gives one free item for every three
eligible units — and the number of discounted units is capped by both the
offer's *get quantity* per cycle and what is actually in the cart, so a
customer can never be discounted for more items than they bought. Prices are
never taken from the client, so the maths cannot be tampered with.

## Tips

- Point the offer at a category or item to run "buy a main, get a drink" style
  deals.
- Combine BOGO with the usual coupon controls — usage limits, expiry, and who
  can redeem it — from the same Coupons screen.
- The discount shows on the order and on any receipt, so staff and customers
  both see why the total changed.

## Related pages

- [Coupons](coupons.md)
- [Loyalty expiry and referrals](loyalty-referrals.md)
- [Marketing automations](marketing-automation.md)
