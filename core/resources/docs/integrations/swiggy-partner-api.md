---
title: "Swiggy integration — what you need, and how to connect"
audience: owner
group: Integrations
updated: 2026-07-22
---

# Swiggy integration — what you need, and how to connect

This page is for restaurant owners connecting a Swiggy outlet to their store.

## 1. Get partner access

Swiggy issues ordering-API credentials to **approved POS partners**, not to individual
restaurants, so the request goes through your Swiggy account manager. Ask for:

- **outlet ids** for each listed restaurant,
- a **client id and client secret** for the partner API,
- a **webhook secret** for verifying inbound orders.

Approval and certification take time. Many Indian restaurants reach Swiggy through a middleman
integration hub (UrbanPiper-class) instead — that works with this same connection screen, and we
can add a dedicated hub connector without changing anything on your side.

## 2. Connect the channel

In your dashboard: **Setup → Integrations → Swiggy**.

1. Paste the **outlet id** exactly as Swiggy shows it.
2. Paste the **webhook secret**, **client ID** and **client secret**. They are stored encrypted
   and never shown again — leaving a field blank later keeps the stored value.
3. Set your **preparation time**; it is sent with every order confirmation.
4. Leave **accept orders automatically** on unless your staff confirm each order by hand.

## 3. Give Swiggy the webhook URL

The page shows the URL and the signature header. Swiggy signs the raw body with HMAC-SHA256 and
sends it as `sha256=…`; anything unsigned or wrongly signed is rejected and creates nothing.

## 4. Publish your menu

Press **Push menu**. Categories, items, prices, veg marking, photos and addon groups are
published as one full replacement, and the result is recorded in the sync log.

**Prices published to Swiggy are your list prices.** Happy-hour and day-part schedules apply to
your own storefront and register only, because a price pushed into a marketplace catalogue stays
there until the next successful push.

## 5. Day-to-day

- Marking an item **sold out** anywhere takes it out of stock on Swiggy automatically;
  **Sync item availability** re-reconciles everything.
- **Close outlet on this channel** stops Swiggy sending orders without disconnecting.
- **Pause** stops us importing orders while keeping the connection.
- Kitchen status changes are pushed back to Swiggy as they happen.

## Troubleshooting

| Symptom | Cause |
|---|---|
| Swiggy reports 401 | The webhook secrets differ. Re-save on both sides. |
| Swiggy reports 404 | The outlet id does not match a connected outlet. |
| Swiggy reports 403 | Your plan no longer includes this channel, or an administrator switched it off. |
| Items appear in the order note rather than on the bill | Those items exist on Swiggy but not in your menu. Push the menu again. |
