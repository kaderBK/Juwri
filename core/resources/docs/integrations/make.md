---
title: "Make — visual automation scenarios for your restaurant"
audience: owner
group: Integrations
updated: 2026-08-27
---

# Make — visual automation scenarios for your restaurant

Make (formerly Integromat) builds visual "scenarios" that react to your orders and
reservations in real time. You connect it with a scoped API token and webhook
events from your dashboard.

## 1. Mint a connection token

1. Open **Integrations → Webhooks & API** (`/api`).
2. In **Connect an automation tool**, press **Connect** under Make.
3. Confirm your password if asked, then copy the token — it is shown **once**.

The token is pre-scoped to read your orders and menu and to manage webhooks, and
nothing else.

## 2. Build a scenario in Make

1. In Make, create a scenario and add a **Custom webhook** module as the trigger.
2. Copy the webhook URL Make generates.
3. Back in **Webhook endpoints**, add that URL and pick the events to send
   (Orders, Reservations & customers, Menu, and more are grouped in the picker).
4. Press **Test** to send a signed `ping`, then confirm it arrived in **Recent
   deliveries** and in Make.

## 3. Act on the data

Add further Make modules to route the order or reservation wherever you need it —
a Google Sheet, an email, a CRM. To send data back (for example, mark an order
prepared), add an HTTP module and call the API with the token from step 1.

## Scopes and safety

- The connection token carries only `webhooks:write`, `orders:read` and
  `menu:read`; revoke it any time from the token list.
- Deliveries are signed and logged; an endpoint that keeps failing is
  auto-disabled with a reason so a dead URL never retries forever.
- Everything is scoped to your own stores and rate-limited.
