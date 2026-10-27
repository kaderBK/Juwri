---
title: "Errors and rate limits"
audience: developer
group: Api
updated: 2026-08-06
---

# Errors and rate limits

How every API surface (`/api/v1/*`, the MCP endpoints, and the public
storefront POST routes) reports failures, and what limits apply before you
get a `429`.

## Always send an appropriate Accept header

Error rendering follows Laravel content negotiation. Framework-generated
errors (404, 419, 422, 429, 500) are returned as JSON only when the request
expects JSON — otherwise you get an HTML error page. Set
`Accept: application/json` on every API call so failures are machine
readable.

For MCP Streamable HTTP, prefer
`Accept: application/json, text/event-stream`; this deployment returns a JSON
response to each POST and does not expose an SSE GET stream.

## Error envelope

Every error on an `/api/v1/*` route uses one envelope:

```json
{ "message": "Invalid or revoked storefront token.", "code": "unauthenticated" }
```

`message` is a human-readable string, `code` is a machine string
(`unauthenticated`, `forbidden`, `not_found`, `conflict`, `unprocessable`,
`validation_failed`, `rate_limited`, `server_error`), and validation failures
add `errors` keyed by field. The legacy `{"error": "..."}` shape is gone, including
on `POST /api/v1/storefront/orders` (changed in API-4: same HTTP statuses, new body).
Parse `message` and `code` only.

All five MCP
endpoints (`/mcp`, `/mcp/dev`, `/mcp/storefront`, `/mcp/catalog`,
`/mcp/customer`) are JSON-RPC and return JSON-RPC error objects instead — see
[Connect your editor](../mcp/connect-editor.md).

## HTTP status codes

- `401` — no token, or the token is invalid, expired, or revoked. The exact
  string varies by surface: `"Unauthenticated."` (no token),
  `"Invalid or expired token."`, `"Invalid token."`,
  `"Missing storefront token."`, `"Invalid or revoked storefront token."`.
  Re-authenticate; do not retry the same token.
- `403` — the token is valid but lacks the required ability or scope
  (`"This token lacks the 'menu:write' ability."`,
  `"Token lacks required scope: agent"`), the owner's plan does not include
  the feature (`"Storefront API is not enabled for this plan."`), or a
  storefront write came from an origin not on the token's allowlist
  (`"Origin not allowed for this token."`), or an MCP request carries an
  unapproved browser `Origin`. Fix the token, plan, or exact origin allowlist;
  retrying unchanged will not help.
- `404` — resource not found within your tenant scope. Lookups are always
  scoped to the token's restaurant(s), so an id that exists for another
  tenant still 404s.
- `409` — conflict, e.g. registering a customer email that already exists
  for that restaurant.
- `419` — CSRF token mismatch on a state-changing request (see below).
- `422` — validation failed (see below).
- `429` — rate limited (see below).
- `5xx` — server fault. Safe to retry with backoff; for order placement,
  retry with the same `client_uuid` so the order cannot duplicate (see
  [Customer API — placing orders](customer-orders.md)).

## Validation errors (422)

Endpoints validate with standard Laravel rules, so a 422 carries a
top-level `message` and a per-field `errors` map, each field an array of
human-readable strings:

```json
{
  "message": "The items field is required.",
  "errors": {
    "items": ["The items field is required."],
    "type": ["The selected type is invalid."]
  }
}
```

Business-rule failures (unknown item id, closed restaurant, bad order type)
also surface as 422 on the ordering endpoints. Sensitive fields
(`password`, `password_confirmation`, `current_password`) are never echoed
back.

## The shared api limiter (60/min)

Every `/api/v1/*` route, the storefront MCP endpoint, and the legacy
`/api/user` and `/api/app/usage` routes run through the framework `api`
middleware group. That group applies one throttle:

```
RateLimiter::for('api', fn ($r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));
```

This limiter runs **before** the per-route token-auth middleware, so for an
unauthenticated-at-that-point bearer client it keys by **client IP**. It is
the binding constraint on those surfaces: a single IP cannot exceed **60
requests per minute** across all `/api/v1/*` routes combined, regardless of
the higher per-route numbers below.

The per-route throttles listed next are a **second, independent cap** — a
request must pass both. They only bind when they are lower than 60, or (for
the storefront limiter) when they key on something other than the IP.

## Per-route throttles

All windows are per minute. The "effective per-IP ceiling" for every row
except the storefront surfaces is 60/min from the shared api limiter.

| Surface | Per-route throttle | Keyed by |
| --- | --- | --- |
| Owner API `/api/v1/...` (`ApiToken` bearer) | 120 | API token (SHA-256 of the bearer/query token; client IP only when no token is sent) |
| Storefront API `/api/v1/storefront/*` | 120 (`api.storefront_rate`, default 120) | storefront token id |
| Storefront MCP `POST /mcp/storefront` | 120 (same `storefront` limiter) | storefront token id |
| Customer app `/api/v1/customer/*` | 120 | client IP |
| Staff app `/api/v1/staff/*` | 180 | client IP |
| Driver app `/api/v1/driver/*` | 120 | client IP |
| Driver OTP login `/api/v1/driver/login/request`, `/login/verify` | 12 | client IP |
| Public `/api/v1/track/{token}`, `/api/v1/menu/{storeId}` | 120 | client IP |
| Customer auth `/api/v1/stores/{id}/customer/register`, `/login` | 10 | client IP |
| Customer OTP `/customer/otp/request` | 6 | client IP |
| Customer OTP `/customer/otp/verify` | 10 | client IP |
| Staff auth `/api/v1/stores/{id}/staff/login`, `/staff/pin` | 10 | client IP |

The storefront limiter keys per **token**, not per IP: `StorefrontTokenAuth`
resolves and binds the token before the throttle runs, so each issued token
gets its own 120/min budget. Its effective ceiling is still capped by the
shared 60/min api limiter when traffic comes from a single IP, but spreads
across tokens rather than being shared.

## MCP endpoints

Every MCP endpoint except Storefront is outside the `api` group and carries a
single throttle:

- `POST /mcp` (owner, `ApiToken` auth, write ability gates mutating tools) —
  60/min, keyed by IP.
- `POST /mcp/dev` (public, read-only) — 60/min, keyed by IP.
- `POST /mcp/catalog` (public, read-only) — 60/min, keyed by IP.
- `POST /mcp/customer` (customer token auth) — 60/min, keyed by IP.
- `POST /mcp/storefront` — see the storefront rows above (api limiter plus
  the per-token storefront limiter).

All five default to protocol `2025-06-18` while retaining the two legacy
versions. Send the negotiated version in `MCP-Protocol-Version`. Accepted
notifications return HTTP 202 with no body. `GET` returns 405 because no SSE
transport is exposed.

When an `Origin` header is present it must match the request origin or an exact
comma-separated `MCP_ALLOWED_ORIGINS` entry; otherwise the server returns 403.
Non-browser clients normally omit `Origin`.

## Rate limit responses

Throttled routes attach `X-RateLimit-Limit` and `X-RateLimit-Remaining`
headers to every response. When you exceed a limit you get `429` with
`{ "message": "Too Many Attempts." }` and a `Retry-After` header (seconds).
Honour `Retry-After`, back off exponentially, and never tight-loop on 429.

## CSRF on state-changing requests (419)

The `/api/v1/*` routes and the MCP endpoints are registered inside the web
route file, but they are **CSRF-exempt**: `VerifyCsrfToken::$except` covers
`api/v1/*` along with explicit callback patterns (`ipn/*`, `webhook/*`,
`webhooks/reseller/*`, `studio/stripe-webhook`, `voice/*/incoming`), `mcp`, `mcp/dev`,
`mcp/storefront`, `mcp/catalog`, `mcp/customer`, and `csp-report`. A
`POST`/`PUT`/`PATCH`/`DELETE` to a token API therefore needs **no** CSRF
token — authentication is the bearer token (or `X-Storefront-Token` /
`X-API-Key`-style header) alone, and a server-to-server client with no
session works fine.

You can still see `419` from *non-API* form routes on the same domain (the
public menu-page POSTs, the dashboard, `/developers`), including authenticated
owner webhook-management writes under `/api/webhooks`. If a `/api/v1/*` call
ever returns `419`, the request almost certainly went to a different path than
you think.

## Public menu-page limiters

The public menu pages have their own named limiters, separate from the api
group (relevant if you drive them programmatically, e.g. in tests):

- Order submission (`restaurant.submitOrder`) — 10/min per restaurant + IP
- Call waiter — 6/min per restaurant + IP
- Coupon check, review, NPS, guest order lookup, item events — 30/min per IP
- Contact / feedback forms — 12/min per IP; newsletter — 8/min per email + IP
- Customer OTP on the menu page — request 6/min, verify 10/min
- Wallet intent — 30/min; waitlist join — 20/min; reservation and loyalty
  redeem — 10/min

## Platform auth limiters

Dashboard auth is keyed per email + IP, so an attacker hammering one account
does not lock out an office full of legitimate users behind one IP:

- Login and staff login and 2FA challenge — 6/min
- Signup — 3/min; signup availability check — 30/min
- Password reset email — 5/min; password reset — 5/min
- Email verification resend — not currently throttled (an `auth-verification`
  6/min limiter is defined but is not wired to the `email/resend` route)

## CAPTCHA on platform auth

When the platform admin enables reCAPTCHA (`settings.recaptcha_mode`), the
dashboard login, signup, and password reset forms additionally require a
valid `g-recaptcha-response` field; omitting it fails as a normal 422
validation error. This applies only to the web auth forms — no token-based
API surface ever requires a CAPTCHA.

## Related pages

- [API tokens and abilities](auth-tokens.md) — 401 vs 403 on owner tokens
- [Storefront API](storefront.md) — per-token limit and read-only scope
- [Customer API — placing orders](customer-orders.md) — idempotent retries
- [Webhooks](webhooks.md) — delivery retries in the other direction
