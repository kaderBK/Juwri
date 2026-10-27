---
title: "Reseller API"
audience: reseller
group: Resellers
updated: 2026-07-10
---

# Reseller API

The reseller API provides read-only programmatic access to a reseller's stats,
customers, and invoices. It is consumed via `rsk_`-prefixed bearer tokens and
enforced by dedicated middleware, separate from the portal session.

## Authentication

The `ResellerApiAuth` middleware gates every `/api/v1/reseller` route:

1. Extracts the bearer token from the `Authorization: Bearer <token>` header.
2. Computes `hash('sha256', $raw)` and looks up `resellers.api_token` by that
   hash.
3. **Accept-and-upgrade:** if the hash lookup fails, tries matching the raw token
   directly (legacy plaintext `rsk_` tokens stored before hashing was added). On
   match, transparently overwrites `api_token` with the hash so at-rest storage
   converges to hashed values.
4. Returns `401` if no reseller is found.
5. Returns `403` if the reseller is not `active` or has `billing_status`
   `suspended`.
6. Binds the resolved reseller into the container as `app('apiReseller')`.

Tokens are generated from the portal Settings tab (`reseller.apiToken`).

## Endpoints

All routes use the prefix `api/v1/reseller`, middleware `api` +
`ResellerApiAuth` + `throttle:60,1`, and route-name prefix `api.v1.reseller.`.

| Method | Path | Route name | Description |
|--------|------|------------|-------------|
| GET | `/api/v1/reseller/stats` | `api.v1.reseller.stats` | Customers count, seats, included seats, billing status, next-invoice estimate. |
| GET | `/api/v1/reseller/customers` | `api.v1.reseller.customers` | Paginated customer list (max 100/page). Fields: id, name, email, status, created_at. |
| GET | `/api/v1/reseller/invoices` | `api.v1.reseller.invoices` | Wholesale invoices for this reseller. |
| GET | `/api/v1/reseller/tier` | `api.v1.reseller.tier` | Current tier (slug, name, prices, seats, wholesale discount, store cap, allowed platform plans; `commission_share` is always 0) + resolved limits/features + billing status. |
| POST | `/api/v1/reseller/plans` | `api.v1.reseller.plans.store` | **Agency only** — create a retail plan. Requires the `api_write` feature; still bound by the tier's `reseller_plans` limit. Body: `platform_plan_id`, `name`, `monthly_price?`, `annual_price?`. |

Reads are available to any tier with the `api` feature (Growth+). The only WRITE
endpoint — `POST /plans` — is gated to the Agency tier's `api_write` feature
(reseller v2 P6); a non-Agency token gets `403`.

### `GET /tier` response

```json
{
  "tier": { "slug": "growth", "name": "Growth", "monthly_price": 149,
            "included_seats": 20, "seat_price": 7.99, "commission_share": 0, "wholesale_discount_pct": 30 },
  "limits":   { "customers": 50, "reseller_plans": 10 },
  "features": { "custom_domain": true, "byok_gateway": true,
                "api_write": false, "referrals": false },
  "billing_status": "active"
}
```
A `999` limit is returned as `null` (unlimited).

## Rate limiting

All endpoints share a `throttle:60,1` limit — 60 requests per minute per token.

## Related pages

- [Overview — API token section](overview.md#reseller-api-token) — how the token
  is issued from the portal.
