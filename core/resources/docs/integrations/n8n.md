---
title: "n8n — self-hosted automation with our verified node"
audience: owner
group: Integrations
updated: 2026-08-27
---

# n8n — self-hosted automation with our verified node

n8n is fair-code, self-hostable automation. We publish a verified community node,
so you can trigger workflows on your orders and menu changes and call back into the
API — all from your own n8n instance.

## 1. Mint a connection token

1. Open **Integrations → Webhooks & API** (`/api`).
2. In **Connect an automation tool**, press **Connect** under n8n.
3. Confirm your password if asked, then copy the token — it is shown **once**.

The token is pre-scoped to read your orders and menu and to manage webhooks.

## 2. Install the node

1. In your n8n instance, open **Settings → Community Nodes**.
2. Install the published package for this platform.
3. Add the node's credential and paste the token from step 1.

## 3. Trigger on events

For real-time workflows, use the node's trigger (or a plain **Webhook** node):

1. Copy the webhook URL from your n8n workflow.
2. In **Webhook endpoints**, add that URL and pick the events to send.
3. Press **Test** to send a signed `ping`, then confirm it in **Recent
   deliveries** and in n8n.

## Scopes and safety

- The connection token carries only `webhooks:write`, `orders:read` and
  `menu:read`; revoke it any time from the token list.
- Verify the signature header on each delivery with the endpoint's signing secret.
- Deliveries are logged and a persistently failing endpoint is auto-disabled with
  a reason. Everything is scoped to your own stores and rate-limited.
