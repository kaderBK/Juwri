---
title: "Foodics integration verification checklist"
audience: owner
group: Integrations
updated: 2026-07-12
---

# Foodics integration verification checklist

Foodics VERIFIES an integration before any live restaurant can connect it. This maps
their online-ordering test cases to how our Foodics driver satisfies them, for the BD
verification pass (BD-ACTIONS #2).

## Auth & tokens
- OAuth2 authorization-code flow (console.foodics.com/authorize → api.foodics.com/oauth/token).
  Sandbox uses console-sandbox / api-sandbox. `state` is generated per connect and verified on
  callback (CSRF).
- Access tokens do not expire; stored encrypted in the Credential vault; disconnect revokes.

## Menu import (two-way)
- Pull `products?include=category,modifiers,branches`, categories, and modifier groups.
- Dry-run preview shows new / updated / categories-created before commit.
- Commit writes our Menu/MenuCategory and a PosItemMap (our item ↔ Foodics product id).
- Re-import updates mapped items only and NEVER deletes owner-created items.
- Arabic `name_localized` is carried into our translations.

## Order push (pricing accuracy)
- Two-step: `POST /orders_calculator` first (Foodics is authoritative for tax/rounding),
  then `POST /orders` with a client-generated UUID.
- Foodics's recomputed totals are stored on the push record (provider_meta) for
  reconciliation; our own order totals are never overwritten.
- Order type maps dine-in→1 / takeaway(pickup)→2 / delivery→3.
- Modifiers map through the imported modifier ids.

## Idempotency & stock
- The UUID is derived deterministically from our order id, so a retry reuses it and a
  duplicate (Foodics 422) is treated as success — never a double order.
- Unmapped items fail the push SOFT with an actionable message; no partial order is sent.

## Webhooks
- `application.order.updated` updates the push record's status (1 Pending / 2 Active /
  3 Declined / 4 Closed / 7 Void).
- `menu.updated` flags "menu drift — re-import suggested" in the UI (no auto-import).
- The tenant is resolved from a per-connection webhook token in the URL, never the payload;
  an unknown token returns 404.

## Still required for live (owner)
- Sandbox developer account from support@foodics.com (BD-ACTIONS #1).
- Submit for verification + marketplace listing after the sandbox pass (BD-ACTIONS #2).
- Delivery charges must pre-exist in the merchant's Foodics dashboard.
