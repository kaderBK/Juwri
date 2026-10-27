---
title: "Google Business ordering link"
audience: owner
group: Integrations
updated: 2026-07-25
---

# Google Business ordering link

Puts an "Order online" link on the restaurant's Google Search / Maps profile pointing at
its menu. Owner screen: **Dashboard → Google ordering** (`/google-ordering`), behind
`feature:gbp_link`.

Order-with-Google in-checkout is dead. The **preferred FOOD_ORDERING place action link**
IS the integration — that is all this feature sets.

## Connect a location (BYOK)

`POST /google-ordering/connect`:

| Field | Required | Notes |
|---|---|---|
| `location_id` | yes | the Google Business Profile location id |
| `access_token` | yes | the merchant's own OAuth access token |
| `refresh_token` | no | stored when supplied |

All three are stored encrypted in the Credential vault under `feature=gbp`. The screen
shows "connected" once a `location_id` credential exists, along with the ordering URL we
would publish (the restaurant's public menu URL) and the link currently live on Google.

## Set the link

`POST /google-ordering/set-link` publishes the restaurant's public menu URL as the
preferred FOOD_ORDERING link:

- Existing **MERCHANT** FOOD_ORDERING links are patched (`uri`, `isPreferred: true`),
  never duplicated — the operation is idempotent.
- If none exists, one is created with `placeActionType: FOOD_ORDERING`,
  `isPreferred: true`.
- Without a connected location the call fails with "Connect your Google Business
  location first."

Entitlement is enforced **inside the service**, not only on the route: the link is what
actually publishes on Google, and a job, API call or importer reaching the service
directly would otherwise have published a paid link for an unentitled store. The kill
switch always vetoes; the plan entitlement applies once `gbp_link` is plan-gated.

## Manual fallback

The screen carries manual guidance for merchants who cannot or will not connect a token:
the same link can be set by hand from the Google Business Profile UI, using the ordering
URL shown on the page.
