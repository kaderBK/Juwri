---
title: "Car parts: vehicle fitment and OEM search"
audience: owner
group: Users
updated: 2026-09-10
---

# Car parts: vehicle fitment and OEM search

A **Car parts** store (see [Choosing your store type](store-types.md)) uses
the same product catalog as [Retail products](retail-products.md) — brands,
price, weight, the size/colour variant grid — plus one more tool: telling
shoppers exactly which vehicles a part fits, and letting them search by the
part's OEM or cross-reference number.

## Managing fitments

Open **Fitments** from the Products page to see every part in your catalog
with its fitment count, then click **Manage fitments** on a part to open its
fitment manager. Three ways to add vehicles a part fits:

- **Add a fitment** — one row at a time: Year, Make, Model, and optional
  Submodel and Engine, plus free-text notes.
- **Bulk add by model-year range** — the same fields, but a "from year" and
  "to year" instead of one year, so a part that fits, say, a 2015–2018 model
  adds four rows in one submit. Years already covered by an existing row are
  skipped, so it's safe to run again.
- **Import CSV** — upload a flat spreadsheet for one part, with columns
  `year`, `make`, `model`, and optionally `submodel`, `engine`, `notes`.

Every fitment row you add shows up in the **Fitted vehicles** table, where
you can remove any of them individually.

This is a **flat, one-part-at-a-time** fitment sheet, not a full industry
(ACES/PIES) vehicle database import — for a large catalog you'll add
fitments part by part or via the CSV import per part.

## OEM and cross-reference numbers

On the same fitment manager page, the **OEM / cross-reference numbers** panel
lets you list a part's OEM numbers and any cross-reference numbers (one per
line, or comma-separated). These are shown on your storefront and are what
the storefront's OEM search box matches against.

## What shoppers see

On your **Shelf**-themed storefront (see
[Storefront themes and customizer](themes.md)), a car-parts store shows a
Year → Make → Model filter shoppers use to narrow the catalog down to parts
that fit their vehicle, plus a search box that matches OEM and
cross-reference numbers. Both only appear for a car-parts store — a retail or
clothing store never shows them.

## Related pages

- [Selling retail products](retail-products.md) — the shared product form,
  brands, and variant grid this store type also uses.
- [Choosing your store type](store-types.md) — the full list of store types.
- [Fulfillment methods](fulfillment-methods.md) — ship, pickup and in-store
  checkout for this store type.
