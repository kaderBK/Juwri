---
title: "Your own marketplace (reseller Fusion)"
audience: reseller
group: Resellers
updated: 2026-08-01
---

# Your own marketplace (reseller Fusion)

As a reseller you can run your **own** food marketplace — a "Fusion" storefront
that lists only the restaurants under your account, served at
`https://<your-domain>/fusion`. Your shoppers browse, search, and order from your
stores under your brand; they never see the platform marketplace or any other
reseller's stores.

This feature ships **dark**. Your platform operator turns it on per reseller
(the `own_fusion` entitlement). Until then the Marketplace section is hidden from
your portal.

## What you get

Once enabled, a **Marketplace** section appears in your reseller dashboard nav
with six pages (all under `/reseller/fusion`):

- **Overview** — store counts by status, orders today, and whether your
  marketplace is live.
- **Store approvals** — every store under your account with its marketplace
  lifecycle (pending / approved / suspended). Approve or suspend a store, and set
  its per-store marketplace fields (city, minimum order, delivery modes).
- **Home curation** — build the rows shoppers see on your marketplace home:
  featured, new, cuisine rows, banners, and hand-picked custom-store rows.
- **Branding** — your marketplace display name (English + Arabic) and primary
  colour, with a live preview. This is what shoppers see in the header and the
  installable app.
- **Settings** — a kill-switch to take your marketplace on/off, and the list of
  cities your stores are active in (read-only in v1).
- **Analytics** — orders, GMV, and your top stores, for your tenant only.

## What is isolated (and what is shared)

- **Stores** — your marketplace lists only stores whose owner belongs to your
  reseller. A store can also appear on the platform marketplace ("dual listing")
  unless your platform operator delists it there; you cannot control platform
  listing yourself.
- **Shoppers** — a shopper who signs in on your marketplace is a **separate
  account** from the same phone number on the platform or any other reseller.
  Their wallet, favorites, addresses, and order history are yours alone; nothing
  crosses between marketplaces.
- **Cities** — v1 shares the platform's global city/geo tree. You don't manage
  geography; your cities appear automatically as your stores set a marketplace
  city.
- **Payments** — unchanged. Orders are paid store-side through each store's own
  gateways; your marketplace takes no separate commission in v1.

## Turning it on

1. Ask your platform operator to enable **Own-marketplace** for your reseller
   (Admin → Resellers → your row → toggle Own-marketplace).
2. In your portal, open **Marketplace → Settings** and switch your marketplace
   **live**.
3. Set your marketplace name and colour in **Marketplace → Branding**.
4. Approve the stores you want to list in **Marketplace → Store approvals**, and
   arrange your home in **Marketplace → Home curation**.

Your marketplace is then live at `https://<your-domain>/fusion`, installable as a
PWA under your brand.

## Team access

The Marketplace pages are covered by your existing reseller team roles — a team
member who can manage your portal can manage your marketplace.
