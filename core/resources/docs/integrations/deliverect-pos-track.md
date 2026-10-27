---
title: "Deliverect POS-track certification checklist"
audience: owner
group: Integrations
updated: 2026-07-12
---

# Deliverect POS-track certification checklist

Maps our Deliverect driver to the documented POS-integration track (their "Dummy POS"
test flow) for the certification pass (BD-ACTIONS #8).

## Auth
- OAuth2 M2M: `POST /oauth/token` {client_id, client_secret, audience,
  grant_type=client_credentials}. Token is CACHED until expiry (real client, deferred).

## Webhook security
- HMAC-SHA256 with the pre-shared secret; header `X-Deliverect-Signature`. Bad signature
  → 401, no side effects. Tenant is resolved from the stored connection by `channelLinkId`,
  never trusted from the payload.

## POS-track surfaces (we serve)
- `pos_register` — persists the channelLinkId on the connection.
- `pos_syncproducts` — Deliverect pulls the menu; we respond from CatalogExport
  (products/categories/modifierGroups, prices in cents, PLU = our menu id).
- Order Notification webhook — inbound order via the shared AggregatorIngestor (idempotent,
  one order-creation path); unmapped PLUs surfaced on the order note.
- `pos_ordercancel` — cancels the local order.
- Order status update — mapped via the status matrix: accepted 10 / preparing 20 / ready 30 /
  in-delivery 40 / finalized 50 / cancelled 90 (+ prep-time), pushed by the outbound client
  when staging creds land.
- Store API — busy mode + product snooze by PLU (auto-snooze on is_sold_out) + opening hours
  (seam persisted on the connection; outbound call deferred).

## Still required for live (owner)
- "Become a partner" → staging keys (BD-ACTIONS #8), then run their certification.
- Note: Deliverect's channel catalog includes Jahez — an indirect route to Jahez while the
  direct Jahez portal access (BD-ACTIONS #7) is pending.
