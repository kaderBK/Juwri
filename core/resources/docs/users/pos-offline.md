---
title: Selling when the internet is down (offline POS)
audience: owner
group: Taking orders
updated: 2026-10-12
---

# Selling when the internet is down (offline POS)

Your till keeps selling when the internet or the server is unreachable, for a limited time, and sends everything to your
dashboard when the connection comes back. This page explains what still works, what does not, and the limits.

## What works offline

- **Ringing up sales** from the menu your till last downloaded: items, options, variants, taxes, service charge and the
  discounts your staff are allowed to give.
- **Cash and manual payments** (for example a bank transfer you confirm yourself), with change worked out on the till.
- **Receipts** printed from the till, including the QR code where your country requires one.
- **Voiding** a sale made on this till, in this shift, before it has synced (with a manager PIN if your store asks for one).
- **Opening and closing the drawer**, pay-ins and pay-outs, and the shift summary of this till.
- **Card payments** stored on the till for later: only on a till with the card reader app, only within the card limits
  below, and only once your payment provider has confirmed this feature for your store. Until then offline card payments
  are switched off and the till asks for another way to pay.

When the connection returns the till sends every sale, payment and void to your dashboard in order, once each. A sale is
never created twice, even if the till restarts or the connection drops in the middle.

## Time limits

| Limit | Default | Maximum |
|---|---|---|
| How long a till may sell without contacting the server | 12 hours | 24 hours |
| How long a sale that cannot be sent is kept on the till | 7 days | 7 days |

- After the offline time is used up the till refuses **new** sales and shows "connect to continue". It still sends what it
  has and still reprints receipts. The hours count from the last time the till reached the server.
- If a sale is still waiting after 7 days, the till stops new sales and shows a manager alert. Nothing is deleted before it
  has been sent.
- Store owners can choose a shorter offline time in the POS settings; the platform maximum is 24 hours.

## Card limits

Offline card payments are held on the till by the card reader app until the provider can process them. The till checks
these limits **before** the card is read:

| Limit | Value |
|---|---|
| One card payment | up to SAR 250 |
| All card payments not yet sent, per till | up to SAR 2,500 |

You can set lower limits for your store, not higher. Over a limit the till says "needs a connection" and you take another
way to pay.

**The store carries the risk.** An offline card payment cannot be checked at the moment of sale. If the card is later
declined, the sale stays in your records and shows as **card declined after offline sale** on that order, on the shift
report and in the **Offline exceptions** list, with the amount, time, staff member and till. Juwri does not charge the
customer again for you. If you collect the money another way (cash, transfer), a manager marks it "collected another way",
which adds a correcting payment and never deletes the declined one. The till and Juwri never store card numbers.

## Browser tills are cash only

A till running in a web browser (not the card reader app) can sell offline with **cash and manual payments only**. It has no
card reader app, so offline card payments are not available there.

A browser can also clear its own stored data when the device is short of space or the browser is reset. To protect your
sales the till asks the browser to keep its data and shows a warning about this. If a browser till may be offline for long,
send its sales as soon as the connection is back and prefer the tablet app for busy outlets.

## What your staff will see

- A banner "Offline" with the number of sales waiting and how much offline time is left.
- Pricing, tax, rounding and receipts work the same as online. If a price was changed on the dashboard while the till was
  offline, the sale keeps the price the customer saw and paid.
- After syncing, a short **Offline exceptions** list for the shift shows anything a manager should look at.

## Things that need the connection (exceptions)

These do not work offline. The till says so when you try:

- Online card payments and payment links, wallet or stored-credit payments, gift cards (selling or redeeming), loyalty point
  redemption (points are still earned when the sale syncs), coupons that need the server (single-use or customer-specific
  codes).
- Refunds of sales not made on this till in this shift, any card refund, and voiding a card payment after it has been sent.
- Looking up customers (a name and phone typed on a sale is fine) and creating customer accounts.
- Incoming online and delivery-app orders, driver dispatch, the kitchen display on other devices, moving or merging a table
  with another till, reservations and the waitlist.
- Changing prices, items, taxes, staff or settings, opening a till that has never been connected, and signing in a staff
  member who was not on the last downloaded list.
- Reports beyond this till's shift summary (the end-of-day report is final only after the sales have synced).
- Tax invoices that need government clearance (for example business-to-business invoices in Saudi Arabia). Simplified
  customer receipts are signed on the till itself.
- Selling after the offline time or the 7-day limit has run out.

## When two things differ

- **Stock:** the till can sell an item below zero stock while offline. The sale is accepted when it syncs and the item goes
  on an **oversell** report for you. Items marked "never oversell" are blocked at zero on the till.
- **Same table on two tills:** both sales are kept as separate orders and flagged.
- **A shift closed from another device while this till was offline:** the late sales are added to the right shift and its
  report is re-issued with a "late sales" section.

## A till is lost or stolen

Ask whoever manages your Juwri account to **revoke** the till. The next time it reaches the internet it deletes everything
it holds, after sending any sales it can. If it never reaches the internet, it deletes its data by itself on its next start
once it has been revoked for 3 days. Everything stored on the till is encrypted and the key never leaves the device.

## Related pages

- [Point of sale (POS)](pos.md) — the register, payments and the drawer.
- [Staff accounts](staff.md) — roles, PINs and per-person permissions.
- [Managing orders](orders.md) — where synced offline sales appear.
