---
title: "Integrations and automation"
audience: reseller
group: Resellers
updated: 2026-08-27
---

# Integrations and automation

This guide covers the integration surfaces your customer restaurants can use —
delivery aggregators through Deliverect, no-code automation through Zapier, Make
and n8n, and raw scoped API tokens with signed webhooks — and how you gate and
support them as a reseller.

Everything here ships **dark by default**. Until the platform operator turns the
feature keys on, none of these surfaces appear in a tenant dashboard, so you can
plan your packaging before anything is customer-visible.

## The two feature keys

Two entitlement keys control the whole area:

- **`integrations`** — the Integrations hub in the owner dashboard (the landing
  page that lists Deliverect, Zapier, Make, n8n and Webhooks & API). Gate this to
  put the hub on a plan.
- **`aggregator_deliverect`** — the Deliverect panel specifically (location links,
  menu sync, PLU health, busy mode, snooze). A tenant can have the hub without
  Deliverect, so this is a separate key.

Both flow through the normal plan-entitlement path. When the operator marks them
sellable, they appear in the plan feature matrix exactly like every other key —
switch them on per plan and every tenant on that plan inherits them. There is no
separate reseller-only toggle: the plan matrix is the single place a feature is
attached to a plan, and your resold plans inherit whatever the operator maps.

## Deliverect (delivery aggregators)

Deliverect is middleware in front of many marketplaces — Talabat, Jahez,
HungerStation, Careem and more. A tenant links their juwri location as a POS in
the Deliverect console; orders then land in the normal order queue tagged with
their channel, and menu/stock/status flow back out. The tenant manages it from
**Integrations → Deliverect**: per-location link status, a manual "Sync now",
PLU health (menu items with no partner SKU), a busy-mode switch and the snoozed
(86'd) item list.

Live Deliverect traffic requires the operator's Deliverect partner credentials —
that is a platform-level acquirer relationship, not something a reseller sets up
per tenant. Until those credentials are in place the panel is inert (it records
intent but pushes nothing).

## Zapier, Make and n8n

These connect by minting a **pre-scoped API token** (webhooks-write plus
orders-read and menu-read) from the tenant's **API & Webhooks** page — one click
per tool. The tenant then pastes that token into the platform's connector.

**Stated limitation (V1):** the public Zapier, Make and n8n listings carry the
**platform brand**, not your reseller brand. Your tenants can use all three, but
a white-labelled listing under your own name is not available in this version.
Set that expectation with customers who ask for a branded connector.

## Webhooks & API

Every tenant can issue their own scoped tokens and subscribe HTTPS endpoints to
signed webhook events (orders, menu changes, the subscription lifecycle, and
more). Deliveries are logged, can be redelivered, and an endpoint that fails
repeatedly is auto-disabled with a visible reason so a dead URL is obvious.

## Supporting your tenants

- Feature off but on a sellable plan → the tenant sees the upsell, not a broken
  page. Off at the kill switch → the surfaces are hidden everywhere, including the
  nav.
- Point tenants at the in-product setup guides for each tool. Those guides carry
  the platform brand for the reasons above.
- The platform operator has a cross-tenant integrations monitor (webhook failure
  leaderboard, Deliverect locations, kill-switch state). If a tenant reports
  deliveries stopping, that console is where the operator confirms it.
