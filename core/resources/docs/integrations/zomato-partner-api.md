---
title: "Zomato integration — what you need, and how to connect"
audience: owner
group: Integrations
updated: 2026-07-22
---

# Zomato integration — what you need, and how to connect

This page is for restaurant owners connecting a Zomato outlet to their store.

## 1. Get partner access

Zomato grants ordering-API access through its POS-integration programme. Apply from the Zomato
developer / partner-integration page and ask for:

- **outlet (res_id) access** for each listed restaurant you want connected,
- an **API key and secret** for the ordering API,
- a **webhook secret**, so we can verify that an incoming order really came from Zomato.

Expect this to take a few weeks and to involve a certification pass. If your outlets are already
managed through a middleman integration hub (UrbanPiper-class), tell us — the same connection
screen works with a hub in front of Zomato.

## 2. Connect the channel

In your dashboard: **Setup → Integrations → Zomato**.

1. Paste the **outlet id** (`res_id`) exactly as it appears in your Zomato partner dashboard.
2. Paste the **webhook secret**, **API key** and **API secret**. They are stored encrypted and
   are never shown again — leaving a field blank later keeps the value already stored.
3. Set your **preparation time**; it is sent with every order acknowledgement.
4. Decide whether orders are **accepted automatically**. Automatic is recommended: Zomato
   expects an acknowledgement inside a short window.

## 3. Give Zomato the webhook URL

The page shows the exact URL and the signature header to configure. Every request is verified
with HMAC-SHA256 over the raw body using your webhook secret. **An unsigned or wrongly signed
request is rejected and creates nothing** — this is what stops anyone else pushing fake orders
into your kitchen.

## 4. Publish your menu

Press **Push menu**. Your categories, items, prices, veg/non-veg marking, photos and modifier
groups are published as one full replacement, and the result is recorded in the sync log.

**Prices published to Zomato are your list prices.** Happy-hour and day-part schedules apply to
your own storefront and register only. This is deliberate: a marketplace catalogue is moderated
and slow to update, so a discounted price pushed at 17:00 would stay live long after the happy
hour ended.

## 5. Day-to-day

- Marking an item **sold out** anywhere — dashboard, POS, inventory — takes it out of stock on
  Zomato automatically. **Sync item availability** re-reconciles everything if the two ever drift.
- **Close outlet on this channel** stops Zomato sending you orders without disconnecting.
- **Pause** stops us importing orders while leaving the connection and secrets in place.
- Kitchen status changes (preparing, ready, out for delivery) are pushed back to Zomato as they
  happen — no separate tablet workflow.

## Troubleshooting

| Symptom | Cause |
|---|---|
| Zomato reports 401 on the webhook | The webhook secret here and the one at Zomato differ. Re-save it on both sides. |
| Zomato reports 404 | The outlet id does not match any connected outlet. Check for a stray space. |
| Zomato reports 403 | Your plan no longer includes this channel, or an administrator switched it off. |
| Orders arrive with items listed in the note instead of on the bill | Those items exist on Zomato but not in your menu. Push the menu again, then re-check the item codes. |
