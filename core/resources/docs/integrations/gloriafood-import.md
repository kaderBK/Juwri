---
title: "GloriaFood migration importer"
audience: owner
group: Integrations
updated: 2026-07-25
---

# GloriaFood migration importer

A one-time migration path for restaurants moving off GloriaFood. Owner screen:
**Dashboard → Import → GloriaFood** (`/import/gloriafood`). The whole route group —
the page AND every POST — is behind `feature:gloriafood_import`.

There is also a public marketing landing page at `/switch-from-gloriafood`.

## Menu import (CSV, preview then commit)

1. `POST /import/gloriafood/preview` with `file` — a CSV (`csv`/`txt`, max 4 MB).
   Nothing is written; the parsed rows and errors are shown.
2. `POST /import/gloriafood` commits the previewed CSV (carried through the confirm
   step base64-encoded, so there is no re-upload) and reports items created,
   categories created, and skipped rows.

The commit is idempotent per file hash — re-uploading the same export never duplicates.

Header matching is flexible because GloriaFood column names vary by export:

| Field | Accepted headers |
|---|---|
| category | category, category name, group, section |
| name | item, name, item name, product, product name, dish |
| description | description, desc, details |
| price | price, amount, cost |

Name and price are mandatory — without both columns the file is rejected. Rows with an
empty name are skipped; a row with no category lands in "Menu". A UTF-8 BOM is stripped.
CSV is the supported format (dependency-free and CI-safe); xlsx is a library-gated
follow-up.

## Customer list import

`POST /import/gloriafood/customers` with `file` (same CSV limits). Imported customers
are **not** opted into marketing — the success message says so explicitly.

## Widget fallback (no CSV export)

`POST /import/gloriafood/widget` with `widget_url` — scrapes the public GloriaFood
ordering-widget page and pulls the embedded menu JSON out of the HTML.

The URL is checked by `SsrfGuard::isSafePublicUrl()` first; anything that is not a safe
public URL is refused. The fetch has a 10-second timeout, and a page with no readable
menu produces "Could not read a menu from that page" rather than a partial import.

## History

The screen lists the last 10 import batches for the restaurant, so an owner can see what
each run created.
