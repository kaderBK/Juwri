# POS-V2 — Staff Register

The staff register (`/pos`, `Staff\PosController` + `resources/views/staff/pos/*`)
was rebuilt to a "Restro-POS"-class point of sale. Blade + Alpine.js, no build step;
assets live in `assets/pos/*` and are cache-busted by `?v=filemtime`.

## Architecture

- **Shell** — `register.blade.php` is a thin layout that `@include`s partials
  (`partials/{rail,topbar,menu-grid,cart,customize-sheet,customer-panel,tables,
  orders-hub,settle,lock,display-bridge,shortcuts}.blade.php`).
- **JS** — `pos-core.js` holds the `posRegister()` Alpine factory + core state; each
  feature is a `window.PosModules.<name>` module (`pos-shortcuts`, `pos-customers`,
  `pos-tables`, `pos-hold`, `pos-payments`) composed over the core state, so tracks
  never edit one shared file. Blade-derived values (routes, ids, i18n) are injected
  via a `window.PosBoot` bootstrap.
- **Theme** — token-driven CSS (`pos-register.css`); light is the default, dark under
  `[data-pos-theme="dark"]`, applied before paint from `localStorage['pos-theme']`.
  Layout is fully logical (`inline-start/end`) so RTL mirrors for free.
- **Service worker** — precaches the shell + assets so the register survives a brief
  outage; order mutations queue in IndexedDB and replay on reconnect.

## Features

- **Menu** — category chips, photo-card grid, persistent topbar search
  (diacritics-insensitive, Latin + Arabic), barcode/PLU scanner wedge + manual PLU.
- **Cart** — line edit (tap a line to re-open the customize sheet), qty steppers,
  per-order Discount (amount/%) and Coupon (read-only `pos/coupon-check`), live
  Totals with a client tax preview mirroring `Order::computeTax`.
- **Choice-groups** — priced variant-option groups (required / min–max), snapshotted
  onto `order_items.options` and rendered on the receipt, kitchen ticket, KDS and
  customer display.
- **Customers** — search (`pos/customers`) with wallet + loyalty, attach chip, inline
  create (`POST pos/customers`), loyalty points + earn preview.
- **Tables** — Select-Table screen (`pos/tables`) with guest-count filter, legend and
  zone tiles; tap a vacant table to assign (dine-in `table_number`).
- **Orders hub** — On hold / Order history (`pos/orders/recent`) / Offline tabs; held
  detail (`pos/orders/{id}/detail`) with Resume + Print invoice; hold notes; offline
  queue rows with Retry / Discard.
- **Payments** — split settle sheet: cash keypad + presets + change/balance, tip
  (chips/custom), coupon, wallet redemption, and N-way split (`pos/orders/{id}/split`
  + `/split-pay`, reusing `SplitBillService`).

## Keyboard shortcuts

| Keys        | Action                      |
|-------------|-----------------------------|
| `/` · `F2`  | Focus the menu search       |
| `F9`        | Send to kitchen             |
| `F8`        | Cash (open settle)          |
| `F4`        | Hold                        |
| `Esc`       | Close the topmost surface / clear search |
| `?` · `F1`  | Toggle the shortcuts help   |
| scan        | A barcode burst adds its item by PLU |

## Notes / seams

- Coupons apply at order **create**, so the settle-sheet coupon works for the current
  cart but a held order cannot gain a coupon at settle (re-ring).
- In-hub "Settle" on a held order directs the cashier to resume then settle.
- Opening an existing (occupied-table) order back into the cart is not yet wired.
- The dashboard order-detail view does not yet show choice-group selections.
