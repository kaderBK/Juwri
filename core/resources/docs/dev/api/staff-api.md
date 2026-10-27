---
title: "Staff API reference"
audience: developer
group: Api
updated: 2026-07-09
---

# Staff API reference

The staff API lives under `/api/v1/staff` and is authenticated with a
per-device staff token. Every endpoint is scoped to the staff member's
restaurant; actions are gated by the role permissions assigned in the
dashboard.

Responses are JSON. The orders list uses standard Laravel pagination
(`data`, `current_page`, `next_page_url`, ...).

## Authentication

Staff tokens are issued via one of two login endpoints (no prior token
required). Once issued, send the token as a bearer header (preferred) or the
`staff_token` query parameter:

```bash
curl https://your-domain/api/v1/staff/me \
  -H "Authorization: Bearer STAFF_TOKEN"
```

Tokens are stored hashed (SHA-256) — the raw value is shown once at login and
cannot be recovered.

### Login (email + password)

```
POST /api/v1/stores/{id}/staff/login
```

| Field | Type | Required |
|-------|------|----------|
| `email` | string (email) | yes |
| `password` | string | yes |
| `device` | string (max 80) | no |

Rate-limited to 10 attempts per minute.

### Login (PIN)

```
POST /api/v1/stores/{id}/staff/pin
```

| Field | Type | Required |
|-------|------|----------|
| `pin` | digits (4–6) | yes |
| `device` | string (max 80) | no |

PINs are unique within a restaurant. Rate-limited to 10 attempts per minute.

Both login endpoints return:

```json
{
  "token": "raw-token-value",
  "staff": {
    "id": 42,
    "name": "Ahmad",
    "role": "cashier",
    "store_id": 7,
    "permissions": ["orders", "kds"]
  }
}
```

## Permissions

Each endpoint requires a specific permission. Permissions are set per-role in
the dashboard. The full set:

| Permission | Grants |
|------------|--------|
| `orders` | View and update orders |
| `kds` | Kitchen display tickets, bump, recall |
| `menu_edit` | Create, update, delete menu items and categories; toggle sold-out |
| `analytics` | View sales analytics |
| `coupons` | Manage coupons |
| `customers` | View customers |

A request without the required permission returns HTTP 403:
`{"error": "Your role lacks the 'kds' permission."}`.

## Endpoints

All paths are prefixed with `/api/v1/staff` and require the `StaffTokenAuth`
middleware (bearer token). Rate limit: 180 req/min per IP.

### Profile

| Method | Path | Permission |
|--------|------|------------|
| GET | `/api/v1/staff/me` | — |
| POST | `/api/v1/staff/logout` | — |

`GET /staff/me` returns the authenticated staff member's id, name, role,
store_id, branch_id, station_id, and the list of granted permissions.

`POST /staff/logout` revokes the current device's token.

### Orders (permission: `orders`)

| Method | Path |
|--------|------|
| GET | `/api/v1/staff/orders` |
| PUT | `/api/v1/staff/orders/{orderId}/status` |

`GET /staff/orders` lists orders for the staff member's restaurant, paginated
30 per page. Accepts an optional `status` query filter.

`PUT /staff/orders/{orderId}/status` updates an order's status. Body:
`{"status": "preparing"}`. Valid statuses: `new`, `preparing`, `ready`,
`delivered`, `completed`, `cancelled`. A successful update fires the
`order.status_changed` webhook with a `by_staff` field identifying the staff
member.

### KDS (permission: `kds`)

| Method | Path |
|--------|------|
| GET | `/api/v1/staff/kds` |
| PUT | `/api/v1/staff/kds/items/{itemId}/bump` |
| PUT | `/api/v1/staff/kds/items/{itemId}/recall` |

`GET /staff/kds` returns live kitchen tickets (order items with status
`queued`, `preparing`, or `ready`), grouped by order. Each ticket includes
order_id, table_number, type, placed_at, and items (id, name, quantity,
status, note). Accepts an optional `station_id` query parameter; defaults to
the staff member's assigned station.

`PUT /staff/kds/items/{itemId}/bump` advances a KDS item one step forward
(e.g. queued → preparing → ready).

`PUT /staff/kds/items/{itemId}/recall` steps a KDS item one status back.

### Menu editing (permission: `menu_edit`)

| Method | Path |
|--------|------|
| POST | `/api/v1/staff/menu/items` |
| PUT | `/api/v1/staff/menu/items/{itemId}` |
| DELETE | `/api/v1/staff/menu/items/{itemId}` |
| PATCH | `/api/v1/staff/menu/items/{itemId}/sold-out` |
| POST | `/api/v1/staff/menu/categories` |

`POST /staff/menu/items` creates a menu item. Returns 201.

`PUT /staff/menu/items/{itemId}` updates a menu item.

`DELETE /staff/menu/items/{itemId}` permanently deletes a menu item.

`PATCH /staff/menu/items/{itemId}/sold-out` toggles the sold-out flag. Pass
`{"is_sold_out": true}` to force a value, or omit to toggle. Quick "86" from
the floor.

`POST /staff/menu/categories` creates a menu category. Returns 201 with
`{id, name}`.

### Analytics (permission: `analytics`)

| Method | Path |
|--------|------|
| GET | `/api/v1/staff/analytics` |

Returns a sales summary for the staff member's restaurant. Accepts optional
`from` and `to` query parameters (dates).

## Errors

- **401 Unauthenticated** — no token, unknown token, or inactive staff member.
- **403 Missing permission** — the token is valid but the staff member's role
  lacks the required permission. Body:
  `{"error": "Your role lacks the 'kds' permission."}`.
- **404** — the resource does not exist within the staff member's restaurant.

## Related pages

- [API tokens and abilities](auth-tokens.md) — token types overview.
- [Admin API](admin-api.md) — owner-scoped API (superset of staff actions).
- [Driver API](driver-api.md) — delivery driver app endpoints.
- [Errors and rate limits](errors-rate-limits.md) — standard error envelope.
