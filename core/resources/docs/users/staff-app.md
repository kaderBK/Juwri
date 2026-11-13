---
title: Staff mobile app (PWA)
audience: owner
group: Your team
updated: 2026-08-06
verified_against: e09d26f8
---

# Staff mobile app (PWA)

The staff app is a phone-friendly web app your team uses on the floor: live
orders with big tap targets, a table status view, a mobile kitchen screen,
daily checklists, and a team message thread. It installs on a phone home
screen like a normal app and can send push alerts when a new order arrives.
There is nothing to download from an app store — it runs at `/staff-app` on
your ordering domain.

## Before your team can use it

1. Create staff accounts. In the dashboard sidebar open **Staff** and add each
   employee with an email, password, and role (manager, cashier, kitchen, or
   waiter). You can also pin a staff member to a branch and, for kitchen
   staff, to a kitchen station.
2. Staff sign in at `/staff/login` on your domain with the email and password
   you set. This is the same staff login used by the [POS](pos.md).

Every screen in the app is scoped to your restaurant: a staff member only
ever sees your orders, your tables, and your team's messages. If they are
pinned to a branch, data defaults to that branch.

## Installing on a phone

1. Sign in at `/staff/login` in the phone's browser, then open `/staff-app`.
2. Add it to the home screen:
   - iPhone (Safari): tap the share icon, then **Add to Home Screen**.
   - Android (Chrome): tap the browser menu, then **Add to Home screen** or
     **Install app**.
3. The app appears as **Staff** on the home screen and opens full-screen in
   portrait, without browser bars.

The session persists like a normal website login. If it expires the app sends
the staff member back to `/staff/login`.

## The five views

A row of chips at the top switches between views:

- **Orders** — the live order feed (default).
- **Floor** — table status tiles.
- **Kitchen** — kitchen tickets on the phone.
- **Tasks** — opening/closing checklists.
- **Comms** — team message thread.

If your restaurant has more than one branch, a branch dropdown appears in the
header; switching it re-scopes the order feed and floor view to that branch. A
staff member cannot reach another restaurant's branches — a foreign branch is
rejected outright.

## Live orders

The Orders view refreshes every few seconds and shows every active order (new,
preparing, ready, or bill requested) as a card with the order number, a
running wait timer, where it is going (table number or order type), and the
item list. Cards are colour-coded by wait time: green under 5 minutes, amber
from 5 minutes, red from 15. Tap a card to expand details (type, table,
customer, total).

Filter chips narrow the feed: **All**, **New**, **Preparing**, **Ready**, and
**Tables** (dine-in only).

Actions on each card:

- New orders show **Accept** (moves to preparing) and **Reject** (cancels,
  with an optional reason kept in the activity log).
- After that, one big button advances the order one step: **Start preparing**
  → **Mark ready** → **Mark delivered**.
- Dine-in orders also get **Request bill** (flags the order as awaiting
  payment) and **Move** (transfer the order to another table).
- An order awaiting the bill shows **Mark paid**, which completes it.

Status changes made here update the customer's order tracker, and if you have
customer status SMS enabled, the customer is texted the new status. Only staff
whose account has the orders permission can change order status.

## Customer voice notes

If your store accepts voice notes at checkout (see
[Managing orders](orders.md)), an order that carries one shows a **🎤 Voice
note** button on its card, with the recording's length. Tap it to play the
customer's message right on the phone. When automatic transcription is on,
the note's text also appears under the card (marked 📝), so staff can read
the request without audio. Playback uses a short-lived signed link, so
recordings stay private to your team.

One more reason to move orders through their real statuses here: the times
between **Start preparing** and **Mark ready** are what feed the automatic
prep-time estimate customers see on their tracking page — see the ETA
section in [Managing orders](orders.md).

## Push notifications

Tap **Enable alerts** in the header and accept the browser's notification
prompt. From then on the phone gets a push notification — with vibration —
when a new order is placed for your restaurant, and when a manager sends a
broadcast (see team messages below). Tapping the notification opens the app.

Notes:

- Each staff member enables alerts on their own phone; a subscription belongs
  to that phone and that login.
- Alerts only fire for your own restaurant's orders and go to every phone that
  has enabled alerts.
- If tapping the button says "Push not supported", either the phone's browser
  does not support web push (on iPhone the app must be installed to the home
  screen first) or push has not been configured on the server. Push is a
  server-level setting (VAPID keys); on a managed platform ask your provider,
  on a self-hosted install see the admin configuration. Until it is
  configured, alerts are simply never sent — nothing else in the app breaks.

## Working offline

The app shell is cached on the phone, so it opens instantly and still loads
without a connection. Data behaves differently by type:

- The floor view, kitchen tickets, checklists, and the tableside menu fall
  back to the last copy the phone saw when the connection drops.
- The live order feed and team messages are never stored on the device (they
  contain customer names and internal chatter), so they show empty while
  offline and reload when the connection returns.
- Actions (advancing an order, ticking a task, sending a message) need a
  connection; if one fails you get a "Network error" toast and can retry. A
  status change that was retried after reconnecting is applied only once, so
  the customer is not texted twice.

A dot next to the "Orders" title shows the feed is live and turns grey when
the phone cannot reach the server.

## Tableside ordering

Waiters can take an order at the table without going to the till:

1. Tap **+ New order** in the header (or tap a free table in the Floor view,
   which pre-fills its table number).
2. Optionally type the table number.
3. Tap menu items to add them — tapping again increases the quantity, and
   sold-out items are greyed out.
4. Tap **Place order**.

Prices always come from your live menu on the server — the phone only sends
item ids and quantities — and the order is stamped with which staff member
created it. The order then appears in the normal feed and kitchen flow like
any other dine-in order. Requires the orders permission.

## Floor view

The **Floor** view shows a tile per table with its number, status (free,
seated, ordering, preparing, ready, bill requested), and section. Waiters can
tick **My tables** to see only tables assigned to them. Tapping a tile acts on
it: a free table starts a new tableside order for that table; an occupied
table offers to request the bill. Table layout and assignments are managed by
the owner on the dashboard [floor map](floor-map.md).

## Kitchen view

The **Kitchen** view shows the same open tickets as the kitchen display
system, grouped per order, filtered to the staff member's kitchen station if
they have one. A tally strip across the top shows total open quantities per
dish ("all-day" counts). Each line has a button to advance it one step and an
undo arrow to recall a bumped item. Requires the KDS permission.

## Tasks (checklists)

The **Tasks** view lists today's checklist — opening and closing duties for
the staff member's role (or items aimed at everyone). Tapping an item ticks it
with a timestamp; tapping again unticks it. Ticks reset each day, and each
tick records who did it. Managers can define checklist items, though there is
no screen for creating them in the app yet — they are added via the API.

## Team messages

The **Comms** view is a message thread shared by everyone on your
restaurant's staff (the most recent messages). Type in the box and tap
**Send**. Managers additionally see a **Broadcast** button: a broadcast is
highlighted in the thread and also sent as a push notification to every phone
with alerts enabled.

## Clock-in and tips

Shift clock-in/out (one open shift at a time, with optional GPS coordinates)
and a personal tips summary exist as server endpoints, but the app has no
buttons for them yet — so day-to-day they are not usable from the phone. The
tips summary also depends on orders being attributed to a serving staff
member, which nothing currently sets, so it would always read zero. Treat both
as planned rather than available.

## Security notes

- The screen locks automatically after 3 minutes of inactivity; tapping
  **Resume** unlocks it without signing in again. This is a shoulder-surfing
  guard for phones left on the counter, not a logout.
- Order actions, rejections (with reason), transfers, and tableside orders are
  all written to the activity log with the staff member attached.
- Roles gate what staff can do: order actions need the orders permission,
  kitchen actions need the KDS permission, and broadcast and checklist
  creation are manager-only.

## Related pages

- [POS](pos.md) — the full register for tills and counters.
- [Orders](orders.md) — the owner's order management view.
- [Floor map and tables](floor-map.md) — building the table layout.
- [Branches](branches.md) — multi-location setup.
- [Menu items](menu-items.md) — sold-out flags staff can flip.
