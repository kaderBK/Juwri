---
title: Hotel booking engine (PMS)
audience: owner
group: Operations
updated: 2026-08-20
---

# Hotel booking engine (PMS)

If you run a hotel, guesthouse or resort, the hotel module now includes a
lightweight property-management system (PMS): room types, rate plans, a
date-range availability calendar, reservations with a full check-in to
check-out lifecycle, housekeeping tasks, banquet events, and a public
online-booking page your guests can use themselves. It sits alongside the
in-room ordering and charge-to-room folios covered in
[Hotel stays and charge-to-room folios](hotel-folios.md).

The module is available on plans that include it. Turn it on with the
**Hotel mode** master toggle in your Hotel settings; if your plan does not
include hotel, the settings screen shows how to upgrade.

## Room types

A **room type** is a category of room you sell (for example *Standard Double*,
*Deluxe Suite*). Each type has a name, a base nightly rate, a maximum occupancy
and a sort order. Create your room types first — everything else (rates,
availability, bookings) is organised around them. Individual physical rooms are
your existing tables/rooms, so a room type maps to the set of rooms of that
kind.

## Rate plans

A **rate plan** is a set of pricing and cancellation rules applied on top of a
room type's base rate. Every hotel starts with one auto-created **Standard**
flexible plan, so a single-rate property never has to think about the concept.
When you want to sell the same room at different terms, add more plans:

- **Flexible** — fully refundable up to a cutoff you set.
- **Non-refundable** — a lower price in exchange for no refund.
- **Advance purchase** — a discounted rate for booking early.

A plan can derive from another with a percent or fixed modifier (for example
"10% off the Standard rate"), so you only maintain one base price. Each plan
records whether it is refundable and how many hours before arrival a guest may
cancel.

## Availability calendar

The availability calendar shows, for any date range, how many rooms of each
type are free to sell. Availability is computed from your rooms minus the
nights already held by bookings — a reservation blocks a room for each night
from check-in up to (but not including) check-out. Use the calendar to check a
date range before quoting a guest, and to spot the nights that are filling up.

## Reservations

A **reservation** (booking) moves through a Mews-style lifecycle:

1. **Pending** — created but not yet confirmed (for example a request awaiting
   your approval).
2. **Confirmed** — a room is held for the stay.
3. **Checked in** — the guest has arrived; the booking links to a live stay and
   its folio takes over the money (see [Hotel folios](hotel-folios.md)).
4. **Checked out** — the stay is closed and settled.

Two terminal branches exist: **cancelled** (from any pre-check-in state) and
**no-show** (a confirmed booking whose guest never arrived, past the arrival
grace). Pending, confirmed and checked-in bookings all hold a room and block
availability; cancelled, no-show and checked-out bookings release it.

Every booking has a short public reference **code** (for example `A1B2C3D4`)
and a private guest manage-link. From the desk you can create a booking,
assign a specific room, confirm, modify dates, extend a stay, cancel, or mark a
no-show.

## Housekeeping

Housekeeping tasks track the work of turning rooms over — cleaning, inspection
and maintenance — so front desk and housekeeping stay in sync. Create tasks
against a room, assign them, and mark them done as rooms are serviced and
released back to sellable availability.

## Banquet and venue events

The events tool books banquets, meetings and private functions. An event can
be billed two ways:

- **Linked** to a guest's stay, so the charges post to that stay's folio.
- **Standalone**, with its own running account you settle separately.

Events move through *enquiry → confirmed → completed*, with *cancelled* as the
terminal branch, and can carry the food and extra-service items the function
includes.

## Public online booking

You can let guests book a room themselves from a public page. The **online
booking mode** (in Hotel settings) has three settings:

- **Off** — the public booking page is disabled (the default).
- **Request** — the guest submits a booking request; it arrives as a *pending*
  reservation for you to confirm. No room is held until you approve, so there
  is no risk of a race.
- **Instant** — the guest books a room immediately. Availability is re-checked
  inside the booking transaction and the room-night is claimed atomically, so
  two guests can never grab the last room at the same time; the loser sees a
  friendly "just taken" message.

No deposit or online payment is taken at booking time in this release. After
booking, the guest gets a manage link to view or cancel (subject to the rate
plan's cancellation policy).

## Related pages

- [Hotel stays and charge-to-room folios](hotel-folios.md)
- [Reservations](reservations.md) — restaurant table reservations
- [Floor map](floor-map.md) — rooms and tables
