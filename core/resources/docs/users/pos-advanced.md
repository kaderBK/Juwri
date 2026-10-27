---
title: POS refunds, drawers and terminals
audience: owner
group: Taking orders
updated: 2026-08-20
---

# POS refunds, drawers and terminals

This guide covers the newer point-of-sale controls that build on the register,
shifts and Z-report basics in [Point of sale (POS)](pos.md): item-level
refunds, handing a cash drawer between staff, a printer fallback, registering
terminals, tax snapshots, rush mode, and per-branch order types.

## Item-level refunds

As well as refunding a whole order, staff can refund **individual line items** —
useful when one dish was wrong but the rest of the order was fine. The refund
records a reason and adjusts the order totals accordingly, so your reports and
the customer's receipt reflect exactly what was returned.

## Cash-drawer handoff

When one cashier hands the register to another mid-shift, a **drawer handoff**
closes out the outgoing cashier's accountability and opens the incoming
cashier's, without ending the business day. Each person is only answerable for
the cash that passed through the drawer while they held it, which keeps
end-of-day reconciliation clean across a shift change.

## Printer fallback

If a receipt or kitchen ticket cannot reach its assigned printer, the POS falls
back gracefully instead of silently dropping the ticket — so a printer that is
offline or out of paper does not lose an order. Check your
[printing setup](printing.md) for how tickets are routed.

## Terminals

You can register the individual **terminals** (registers/devices) in a branch.
Naming your terminals makes shift reports, drawer accountability and printer
routing clearer when more than one register is in use at the same location.

## Tax snapshot

Each order stores a **snapshot** of the tax rules that applied when it was
placed. If you later change your tax settings, historical orders keep the tax
they were actually charged, so past receipts and reports stay accurate and
auditable rather than being retroactively rewritten.

## Rush mode

During a peak, **rush mode** lets you temporarily adjust how the kitchen and
order flow behave to cope with the surge — for example widening quoted prep
times so customer expectations match reality when the kitchen is slammed. Turn
it off when the rush passes.

## Per-branch order types

Each branch can enable its own set of **order types** (takeaway, dine-in,
delivery and so on) independently. A branch that only does takeaway can turn off
dine-in without affecting your other locations, so each site offers exactly the
services it actually runs.

## Related pages

- [Point of sale (POS)](pos.md)
- [Printing](printing.md)
- [Kitchen display (KDS)](kds.md)
- [Suppliers, transfers and stock alerts](inventory-purchasing.md) — recipe auto-86
