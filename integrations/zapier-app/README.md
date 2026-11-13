# juwri — Zapier integration

A [Zapier Platform CLI](https://docs.zapier.com/platform/build-cli/overview) app that
connects juwri to 7,000+ Zapier apps. Triggers are **REST Hooks** (instant) with a
polling fallback; actions and searches drive the juwri owner API (`/api/v1`).

Nothing here is wired into the Laravel build — this is a standalone Node package. The
verification bar is `npm test` (green, offline, zero-dependency).

## What it does

**Triggers** (REST Hook + polling fallback, except where noted)

| Trigger | Event | Fallback source |
|---------|-------|-----------------|
| New Order | `order.created` | `GET /stores/{id}/orders` |
| Order Status Changed | `order.status_changed` | `GET /stores/{id}/orders` |
| Order Paid | `order.paid` | `GET /stores/{id}/orders` (paid rows) |
| New Customer | `customer.created` | `GET /stores/{id}/customers?sort=created` |
| New Reservation | `reservation.created` | `GET /stores/{id}/reservations` |
| Refund Completed | `refund.completed` | **instant-only** (no refunds list endpoint) |

**Actions**: Update Order Status · Create Menu Item · Update Menu Item · Set Item
Availability · Create Customer.

**Searches**: Find Order (by number) · Find Customer (by phone/email). Both return
`[]` on no match — pair them with a create for "find or create".

## Connection

API-key auth. In your juwri dashboard go to **Developers → API tokens** and mint a
token. `webhooks:write` is enough for triggers; add `orders:write`, `menu:write` and
`customers:read` for the actions and searches. The connection asks for:

- **API base URL** — the "Base URL" from **Developers → API** (ends in `/api/v1`).
- **API token** — the token you minted.

The connection is tested and labelled from `GET /api/v1/me`.

## Develop & test

```bash
cd integrations/zapier-app
npm test                 # node --test — the authoritative suite (offline, zero-dep)
npm run build:samples    # re-copy samples/ from core/resources/fixtures/webhook-samples
```

The specs mock HTTP with a fake `z`/`bundle` and assert the real contract:
subscribe/unsubscribe request bodies, `perform` array shape, and that each
`performList` transforms a list row into **exactly** the canonical webhook envelope
(the F0-5 fixture). See `REVIEW-CHECKLIST.md` for the Zapier integration-checks map.

`zapier test` (the CLI, which runs the operations through `zapier-platform-core`) runs
the same operations; install core first (`npm install`) — it is a `dependency` here.

## Publish (OWNER)

These need a Zapier account and assets and are tracked as OWNER steps in
`rev/integrations-pack/LEDGER-B.md` (B10):

```bash
npm install -g zapier-platform-cli
zapier login                     # account under the COMPANY domain (check M005)
npm install                      # installs zapier-platform-core
zapier register "juwri"        # first time only; sets app title/description/category
npm test                         # must be green
zapier push                      # upload this version
```

Then, in the Zapier developer dashboard:

1. Add a **256px+ square RGBA PNG** logo (check M004), a category, and a homepage URL.
2. App description: 40–140 chars, starts `juwri is a…`, must not contain the word
   "Zapier".
3. Invite **3+ pilot stores**; build **one live Zap per visible operation**
   (S001/S002/T001).
4. In review notes, declare **Refund Completed** as instant-only (no polling sample —
   check D006).
5. Submit for review (≈1 week), run the 90-day beta, then go public (public apps are
   auto-exposed to Zapier's MCP).

## Frozen contract

Output keys are the platform's canonical F0-10 payloads and **must never be renamed or
removed** after publish (Zapier checks C002–C004). `samples/*.json` are byte-identical
copies of the platform fixtures; `npm test` fails if they drift.
