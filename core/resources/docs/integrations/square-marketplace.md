---
title: "Square App Marketplace requirements checklist"
audience: owner
group: Integrations
updated: 2026-07-12
---

# Square App Marketplace requirements checklist

Maps Square's listing requirements to our implementation (BD-ACTIONS #9).

## Auth (hard requirement)
- **OAuth only** — personal access tokens disqualify a listing. Our connect path is the
  authorization-code flow (`SquareOAuthController`): authorize → callback (state-checked) →
  token exchange, with refresh + revoke on disconnect. Raw-token connections still work for
  BC but are flagged "legacy — reconnect via OAuth".
- Requested scopes are least-privilege (MERCHANT_PROFILE_READ, ORDERS_READ/WRITE, ITEMS_READ,
  PAYMENTS_WRITE). Tokens stored encrypted; never logged.

## Webhooks
- Endpoint verifies the app signature: `base64(HMAC-SHA256(signatureKey, notificationUrl + body))`
  vs `x-square-hmacsha256-signature`; bad signature → 401.
- Handlers: `order.updated` / `order.fulfillment.updated` → push record + fulfillment state;
  `catalog.version.updated` → flags "catalog drift" so the mapping is re-pulled (replaces
  scheduled sync). Tenant resolved from merchant_id against the stored connection.

## Orders + catalog (kept rich for AI ordering)
- Orders API + Catalog remains the sanctioned approach (no deprecation). Push creates a PICKUP
  fulfillment with a stable idempotency key; catalog list powers item mapping. Keep images,
  descriptions, and modifiers rich — Square's ChatGPT/Claude ordering reads seller catalog.

## Fulfillment lifecycle
- PROPOSED → RESERVED → PREPARED → COMPLETED arrive via order.fulfillment.updated and are
  reflected into the push log.

## Listing gate (owner)
- ≥ 5 active live sellers using the app before applying (BD-ACTIONS #9).
- Per-API requirement checklists (general / OAuth / Orders); ~14-business-day review.

## Optional
- Terminal API is fully sandbox-simulable via special device_id values — ledgered (relates to
  the live card-terminal hardware deferral); build only if prioritized.
