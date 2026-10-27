---
title: "Order status board"
audience: owner
group: Users
updated: 2026-08-06
---

# Order status board


The order status board is the "now serving" screen for a counter-service
dining room. Every order gets a daily number, and a TV or wall tablet shows two
columns — **Preparing** and **Ready** — so customers watch for their number
instead of crowding the counter.

The board shows **numbers only**. No name, no phone, no order id, no total ever
reaches the screen, because a screen hanging in a public room is the least
private surface you own.

This page also covers [curbside pickup](#curbside-pickup), the other way a
waiting customer is handled.

## Turning the board on

The settings live on the restaurant itself, not on a page of their own. Edit
the restaurant and open the **Order board** tab. The tab only appears when your
account is allowed to use the board (see [Availability](#availability)).

- **Show the order board** — Yes or No. Defaults to No. New orders start
  getting a daily number as soon as this is on. Nothing is backfilled: orders
  placed before you switched it on have no number.
- **Keep ready numbers on screen (minutes)** — 0 to 240, default 5. A ready
  number drops off the board after this many minutes. **0** keeps it up until
  the order leaves the ready state.
- **Play a chime when an order is ready** — Yes (default) or No. The board
  synthesises a short two-tone beep when a new number appears in Ready. A
  browser will not play sound until someone has touched the screen once — that
  is a browser rule, so the board never depends on the chime.
- **Which order types get a number** — one Yes/No per type: **Takeaway** (Yes
  by default), **On table** (Yes by default), **Delivery** (No by default) and
  **Room service** (No by default). A type set to No is never numbered and
  never appears on the board.

## The board link

The same tab has a **Board link** section with the address, a **Copy** button,
an **Open board** button, and a QR code. The address is built from your store
slug:

```
/board/{your-store-slug}
```

If the restaurant has [branches](branches.md), each branch gets its own row with
its own link and Copy/Open buttons:

```
/board/{your-store-slug}?branch={id}
```

A branch link only shows that branch's numbers. A branch id belonging to
another store is ignored rather than honoured.

Open the link once in the TV's browser and leave it. It is a standalone page:
no dashboard chrome, no login, no cookies. Sizing is relative to the screen, so
the same address reads correctly on a 55-inch TV across a room and on a
10-inch tablet on the counter.

## What the screen does

- The header shows your logo, restaurant name, the branch name on a branch
  board, and a clock.
- **Preparing** lists the numbers of orders that are new or preparing.
  **Ready** lists ready orders' numbers. Each column shows at most 40 numbers —
  more than a TV can display anyway.
- When both columns are empty the board switches to an idle card with your
  logo, your restaurant name and "No orders on the board right now".
- A **Reconnecting…** notice appears if the screen loses contact with the
  server.
- It re-polls every 5 seconds. Nothing needs refreshing by hand.

The page renders in the storefront's language and reading direction, so an
Arabic restaurant gets Arabic column headings and a right-to-left layout.

## About the numbers

- Numbering is one counter per store, per branch, per day.
- The day rolls over at the **branch's** local midnight, or the store's if the
  branch has none — not the server's. A restaurant's counter resets when its own
  day does.
- Yesterday's #7 can never sit next to today's #7: the board only ever reads
  today's numbers.
- The same number is printed on the kitchen ticket, so the [KDS](kds.md) and
  the screen the customer is watching always say the same thing.
- If an order reaches the kitchen without a number — for example it was placed
  just before you switched the board on — it is given one at that moment, so it
  is never an invisible ghost on the screen.

## Availability

The board serves only when both of these hold:

- the platform kill-switch for `order_board` is on (a superadmin setting; it
  ships **on**), and
- **Show the order board** is Yes for that restaurant.

If either fails, the board address returns "not found" — the same answer as an
unknown store, so nothing about your account is exposed to whoever holds the
link. The **Order board** settings tab is hidden when the feature is not
allowed for your store.

---

## Curbside pickup

Curbside is for the takeaway customer who waits in the car. They tick a box at
checkout, describe their vehicle, and then press **I'm here** on their order
tracking page when they arrive — which pushes a notification to your staff.

### Turning curbside on

Edit the restaurant, open the **Ordering** tab, and set **Curbside pickup** to
Yes. There is no other curbside setting.

### At checkout

On a storefront theme that supports it, a takeaway order shows a **Curbside
pickup — I'll wait in my car** checkbox. Ticking it reveals three optional
fields:

- **Car make/model**
- **Colour**
- **Plate**

They are combined into one short vehicle description stored on the order, in
the form `Colour Make (Plate)`. An order is only marked curbside when it is a
takeaway order, the box was ticked, curbside is switched on for the restaurant,
and your account is allowed to use it — so a customer is never told an order is
curbside when the arrival ping would later be refused.

Not every storefront theme renders the checkout checkbox; it is available on
the modern themes.

### The "I'm here" ping

The customer's order tracking page shows an **I'm here** button on a curbside
order. Pressing it:

- stamps the arrival time on the order (only the first press counts — pressing
  again changes nothing);
- pushes a notification to your staff titled "Customer arrived" with the order
  number, and the vehicle description (or the customer's name) as the body,
  opening the [staff app](staff-app.md);
- replaces the button with a "Staff notified" confirmation.

Pressing it on an order that is not curbside returns "This is not a curbside
order" and does nothing.

### On the kitchen ticket

Curbside orders carry their curbside flag, the vehicle description, and whether
the customer has arrived, so a [KDS](kds.md) ticket tells a runner which car to
walk out to and whether it is outside yet.

### Availability

Curbside runs only when the platform kill-switch for `curbside` is on (it ships
**on**) and the store owner's plan includes it — `curbside` is plan-gated.
A blocked account gets a clear message rather
than a silent failure. See [Plans and billing](plans-billing.md).

## Related pages

- [Digital signage screens](digital-signage.md) — the other kind of wall screen, including a pickup board that shows first names.
- [Managing orders](orders.md) — order statuses and the customer tracking page.
- [Kitchen display system (KDS)](kds.md) — where the number, the curbside flag and the vehicle appear for staff.
- [Branches](branches.md) — per-branch boards and per-branch daily counters.
- [Checkout, tax and tipping settings](checkout-settings.md) — the Ordering tab this page's toggles sit on.
