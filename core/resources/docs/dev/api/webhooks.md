---
title: "Webhooks"
audience: developer
group: Api
updated: 2026-08-06
---

# Webhooks

Real-time events (e.g. `order.paid`) delivered to your HTTPS endpoint from a
queued, retrying pipeline.

## Subscribing

Create endpoints in the dashboard under **API tokens & webhooks**. Subscribe
to specific events or `*` for all.

Endpoints can also be managed programmatically (REST Hooks — the pattern
Zapier/Make use): `GET`/`POST /api/v1/webhook-endpoints` and
`DELETE /api/v1/webhook-endpoints/{id}` with a `webhooks:read`/`webhooks:write`
token — see the [admin API](admin-api.md).

## Delivery format

Signed with the Standard Webhooks scheme. Headers:

```
webhook-id: <message id — stable across retries>
webhook-timestamp: <unix seconds>
webhook-signature: v1,<base64 hmac>
```

Body: JSON with `id` (the message id, matching the `webhook-id` header),
`event`, `created_at`, and `data` — the event payload. See the
[event catalog](webhooks-events.md) for the full envelope and every event's
`data` shape.

## Verifying (required)

Verify the HMAC with your endpoint secret before trusting the payload — any
Standard-Webhooks library works. Reject stale timestamps (replay window) and
unknown signatures.

## Retries & idempotency

Failed deliveries are retried with backoff and deterministic jitter.
`webhook-id` is stable across retries — deduplicate on it. Return 2xx fast;
do slow work async.
