# Storefront View Model (schema)

`viewmodel.schema.json` is the machine-readable form of THEME-CONTRACT.md §4, plus the fields the five section specs added. Each addition names its spec in its `description`.

- **Build session:** the controller that builds the view model should output JSON that passes this schema. Add a test that runs `python3 tools/validate_viewmodel.py` (or a PHP port of it) on a rendered view model per theme.
- **Prototypes:** `fixtures/<theme>.json` are realistic view models built from `kit/demo/`. The HTML prototypes render from them, so a prototype never invents data the server will not send.
- **Rules:** absent optional key = section not rendered. Money is always `{minor, currency, formatted}`, and themes print `formatted`. URLs come only from `endpoints` and from item `url`s.

Additions beyond §4 (all optional):
- `context.free_delivery_threshold`
- `prep_time_min`
- `next_free_slot`
- `staff`
- `vehicle`
- `kiosk`
- `offline_queue`
- `refunds`
- `customer.recent_items`, `customer.wishlist`
- `features.bnpl`, `wishlist`, `barcode_scan`, `fitment`, `announcement`
- `catalog.total`, `next_page_url`, `facets`
- ItemCard: `compare_at`, `brand`, `swatches`, `unit`, `duration_min`, `meta_label`, `cart_qty`, `fits_vehicle`, `url`
- `store.store_type`, `tagline`, `status.label`, `status.accepting_bookings`

## Page payloads (`page`)
Home needs only the top-level keys. Other screens add `page.kind` plus one payload: `item` (ItemDetail: option groups that change price and duration, disclosures, info rows, estimate and deposit notes), `cart` (Quote: lines, rows such as subtotal, discount, fee, tax, deposit and due later, free-delivery progress), `checkout` (slots, delivery and payment choices, fields, hold or policy notes, quote) and `track` (Order: steps, ticket, carrier, review lines, actions). Every amount is a server-formatted Money, so the theme never adds anything up.
