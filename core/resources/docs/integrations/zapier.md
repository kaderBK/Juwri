---
title: "Zapier — connect your restaurant to 6,000+ apps"
audience: owner
group: Integrations
updated: 2026-08-27
---

# Zapier — connect your restaurant to 6,000+ apps

Zapier lets you push your orders, customers and menu changes into thousands of
other apps — spreadsheets, email, CRMs, accounting — with no code. You connect it
with a scoped API token and (optionally) webhook events from your dashboard.

## 1. Mint a connection token

1. Open **Integrations → Webhooks & API** (`/api`).
2. In **Connect an automation tool**, press **Connect** under Zapier.
3. Confirm your password if asked. A token appears **once** — copy it now; it is
   never shown again.

The token is pre-scoped to exactly what Zapier needs: read your orders and menu,
and manage webhooks. It cannot touch settings, billing or other stores.

## 2. Add it in Zapier

1. In Zapier, create a Zap and search for the platform connector.
2. When asked to authenticate, paste the token from step 1.
3. Pick a trigger (for example, **New order**) or an action (for example,
   **Update order status**).

## 3. (Optional) Push events instead of polling

For real-time triggers, add a webhook endpoint under the same page:

1. In **Webhook endpoints**, add the HTTPS URL Zapier gives you for a "Catch Hook".
2. Choose the events to send (Orders, Menu, and more are grouped in the picker).
3. Use **Test** to send a signed `ping`, then check **Recent deliveries**.

Every delivery is signed and logged. If an endpoint fails repeatedly it is
auto-disabled with a visible reason, so a dead URL never retries forever — fix the
URL and re-enable it from the same page.

## Scopes and safety

- The connection token carries only `webhooks:write`, `orders:read` and
  `menu:read`. Revoke it any time from the token list.
- Webhook payloads are signed; verify the signature header with the endpoint's
  signing secret (click to reveal it in the endpoints table).
- Everything is scoped to your own stores and rate-limited.
