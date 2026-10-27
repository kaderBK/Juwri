---
title: "Reseller API"
audience: developer
group: Api
updated: 2026-08-06
---

# Reseller API

The Reseller API gives white-label resellers access to their portfolio stats,
customer list, and invoices. All endpoints live under `/api/v1/reseller/` and
require reseller authentication.

## Authentication

Reseller API requests are authenticated via the `ResellerApiAuth` middleware.
Send credentials as `Authorization: Bearer <token>`.

## Endpoints

Rate limit: 60 req/min, keyed per API token (SHA-256 of the bearer/query
token; client IP only when no token is sent). The shared api limiter (60/min
per IP) applies on top, so 60/min is the effective ceiling.

- `GET /api/v1/reseller/stats` — portfolio-level statistics (restaurant
  count, revenue, plan breakdown).
- `GET /api/v1/reseller/customers` — paginated list of the reseller's
  customers (restaurants).
- `GET /api/v1/reseller/invoices` — the reseller's invoices.
- `GET /api/v1/reseller/tier` — the reseller's tier (slug, name,
  `monthly_price`, `included_seats`, `seat_price`, `commission_share` — always 0 since the pricing rework —,
  `wholesale_discount_pct`, `billing_cycle`), the
  `limits` map (`customers`, `reseller_plans`, `max_stores` — null = no cap), the `features` map
  (`custom_domain`, `byok_gateway`, `api_write`, `referrals`), and
  `billing_status`. Read this before attempting any write.
- `POST /api/v1/reseller/plans` — create a reseller plan. **Agency tier
  only**: requires the `api_write` feature. Body:

  ```json
  {
    "platform_plan_id": 3,
    "name": "Starter",
    "monthly_price": 29.0,
    "annual_price": 290.0
  }
  ```

  `platform_plan_id` and `name` (max 80 chars) are required; the two prices
  are optional. Returns `201 {"id": 17}`.

  Two distinct `403`s: `"api_write requires the Agency tier."` when the tier
  lacks the feature, and `"Plan limit reached for your tier."` when the
  reseller is already at its `reseller_plans` limit.

Everything except `POST /plans` is read-only.

## Error handling

Standard error envelope — see [Errors and rate limits](errors-rate-limits.md).
