---
title: "Split bill"
audience: owner
group: Users
updated: 2026-07-08
---

# Split bill

When a table wants to pay separately, you can split an order's bill into
equal shares and settle each share on its own. The order stays open until
every share is paid, so you always know exactly how much is still owed.
This page covers creating a split, marking shares as paid, and changing or
removing a split.

## What split bill does

A split turns one order total into a "check" with a set number of equal
shares. Each share tracks its own amount, an optional payer name, a payment
method, and a paid/pending status. The split page shows the order total, how
much has been paid so far, and how much is remaining, so you can settle a
table person by person without losing track.

Splitting does not charge anyone. It is a settlement tool for you and your
staff at the counter or table: you collect each person's payment (cash, card,
or an online payment they made) and mark the share as paid.

## Open the split page

The split page lives at a fixed address for each order:

```
https://your-domain.com/orders/ORDER-NUMBER/split
```

Replace `ORDER-NUMBER` with the number shown in the order list (see
[Managing orders](orders.md)). You must be logged in to the dashboard, and
the order must belong to one of your restaurants.

There is currently no button on the **Orders** page that links to the split
screen, so open the address directly or bookmark it while working a table.

## Split the bill evenly

1. Open the split page for the order.
2. In the **Split evenly** card, enter the **Number of people** — anywhere
   from 1 to 50 (the form suggests 2).
3. Press **Split bill**.

The order total is divided into equal shares. If the total does not divide
evenly to the cent, the last share absorbs the small rounding difference, so
the shares always add up to exactly the order total. For example, 100.00
split by 3 gives 33.33, 33.33, and 33.34.

Each share appears in the **Shares** table as "Person 1", "Person 2", and so
on, with its amount and a **Pending** badge.

## Settle each share

For each person who pays:

1. Find their row in the **Shares** table.
2. Optionally type their name in the **Name** field so the record shows who
   paid.
3. Choose the method — **Cash**, **Card**, or **Online**.
4. Press **Mark paid**.

The share flips to a **Paid** badge with the method and the time it was
settled. The summary at the top of the page updates the **Paid** and
**Remaining** amounts as you go.

## Partial and full payment

A freshly created split shows an **Open** badge. Once you mark the first
share paid while others are still pending, the check shows a **Partially
paid** badge. When the last share is marked paid:

- the check shows **Fully paid**, and
- the order itself is marked as paid, exactly as if it had been paid in one
  payment. If you use webhooks, an `order.paid` event is sent at this point
  (see the developer docs for webhook details).

## Change or remove a split

- **Re-split** — enter a different number of people and press **Split bill**
  again. This replaces the current shares with a fresh set, but only if no
  share has been paid yet; a split that already has payments is kept as it
  is.
- **Remove split** — the **Remove split** button deletes the split and its
  pending shares, returning the order to a normal unsplit bill. It is only
  available while no share has been paid; once any share is settled you will
  see "Cannot remove a split that already has payments."

## Splitting by items

Assigning specific items to specific people (pay-by-seat, where each person
pays only for what they ordered) is planned but not yet available. Today the
bill can only be split into equal shares. If a group wants uneven amounts,
you can approximate it by splitting into more shares and marking several
shares paid by the same person.

## Limits and notes

- A bill can be split into at most 50 shares.
- Only orders from your own restaurants can be split; the page refuses
  orders that belong to another account.
- Marking a share paid is a record for your books — the app does not send
  the customer a payment request or charge their card.
- Splitting works on any order type shown in your order list; it is most
  useful for on-table orders.

See also [Managing orders](orders.md) for the order list, statuses, and
receipts.
