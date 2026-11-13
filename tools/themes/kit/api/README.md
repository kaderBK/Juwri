# Headless storefront API for every store type (D14, P3-06b)

Owner decision D14 (2026-10-02): the headless storefront API covers food, grocery, retail and services in v1. This folder is the design that Phase 3 work item **P3-06b** implements.

| File | What |
|---|---|
| `openapi.json` | OpenAPI 3.1 for the new and changed endpoints. Display shapes `$ref` the View Model (`../viewmodel/viewmodel.schema.json`). JSON, not YAML, so it checks with the stdlib. |
| `check_openapi.py` | `python3 kit/api/check_openapi.py`: JSON parses, 3.1 basics, unique operationIds, path params declared, every `$ref` resolves (including inside the View Model). It also checks the owner decisions: every operation lists `x-plan-features` (API-5) and kiosk operations list both `kiosk_api` and `kiosk` (API-13), legacy aliases are `deprecated` with `Deprecation`/`Sunset` headers (API-4), and no text still says "question for owner". Item 40 adds: group ids are `OptionGroupId` (API-20), `received_late` exists (API-21), the substitution fields are option ids (API-22), and the "Release holds during payment" setting and `releaseHold` 200/409 are documented (API-24a). Item 41 adds: the `PickerAction` enum on every substitution option, store and item (API-25), the release setting naming booking-deposit holds (API-26), `pending_approval` for an accepted late payment without a free slot on orders and bookings (API-27), late-payment refunds outside D7 (API-28), the customer-release message documented on `HoldReleased` (API-29). Item 42 adds: the no-answer `fallback` on `call_customer` options (API-30), the refund of a line removed after capture outside D7 (API-31), `authorisation_expires_at` and the automatic cancel of a lapsed late card hold (API-32) and `info.version` 1.7.0-draft. |

Status: design only. Nothing here is built. All owner questions are answered (§8, API-1…34, 2026-10-02 and 2026-10-03) and applied to §1–7, `openapi.json` (spec 1.7.0-draft) and the examples (PREBUILD-PLAN items 32, 37, 40, 41 and 42).

---

## 1. What Juwri has today (read-only survey, `~/Desktop/juw/core`, 2026-10-02)

| Area | Finding | Path |
|---|---|---|
| Route file | All REST v1 routes live in `routes/apiv1.php`, `require`d from `routes/web.php` after the storefront `{slug}` catch-all. Module `Routes/` folders are empty. | `routes/apiv1.php` |
| Storefront API | `GET /api/v1/storefront/menu`, `GET /api/v1/storefront/restaurant` (scope `menu:read`), `POST /api/v1/storefront/orders` (scope `order:write`). | `routes/apiv1.php:439-455` |
| Controller | `StorefrontController`: `menu` delegates to `PublicController::menu`; `restaurant` returns id/title/slug/… plus `verticalSummary()`; `createOrder` → `AgentToolsService::createOrder` → `OrderApiService::create`, `source=storefront_api`, **UNPAID only**, server-priced. | `app/Http/Controllers/Api/V1/StorefrontController.php` |
| Menu shape | `PublicController::menu`: categories → items with **float `price`**, integer ids, `variants`, `variant_groups` (option_prices), `extras`, goods-only `brand`/`fitments`/expiry/age gate by vertical caps. ETag = `StorefrontMenuCache::signature(store, null, locale)`, `Cache-Control: public, max-age=120`, 304 support. Servability gate (`StorefrontAvailability::servable`) runs before the ETag check. | `app/Http/Controllers/Api/V1/PublicController.php:24-183` |
| Track | `GET /api/v1/track/{token}` (public, `throttle:120,1`): status, timestamps, shipment, driver/courier location, `total` (float). | `PublicController::track` |
| Auth | `StorefrontTokenAuth`: `X-Storefront-Token` (sha256-hashed in `storefront_tokens`), store bound in the container (`api.storefront_post`) and **never taken from the client**. Plan gate `plan_feature('storefront_api')`. Write and `agent` scopes enforce an Origin allowlist; `browser` tokens fail closed. Query-string token is deprecated (Sunset 2026-12-31). Scopes: `menu:read`, `order:write`, `agent` (`*`; a `:write` grants its `:read`). | `app/Http/Middleware/StorefrontTokenAuth.php`, `app/Models/StorefrontToken.php` |
| Customer auth | `/api/v1/restaurants/{id}/customer/{register,login,otp/request,otp/verify}` issue a customer token; `/api/v1/customer/*` uses `CustomerTokenAuth`. OTP: `throttle:6,1` on request and `throttle:10,1` on verify; `SmsOtpService` allows 3 codes per phone per 10 minutes, a code lives 10 minutes, 5 wrong attempts void it, and it is single use; verify does `Customer::firstOrCreate` by store + phone and sets `phone_verified_at`. Errors are hand-made (`{success, message}` 429, `{error}` 401). | `app/Modules/Storefront/Routes/apiv1-1.php`, `app/Modules/Customers/Http/Controllers/Api/V1/CustomerAuthController.php`, `app/Modules/Messaging/Services/SmsOtpService.php` |
| Owner API | Bearer `ApiTokenAuth:<ability>`, `restaurants/{id}/…`; read-only variants/brands/shipments/fitments behind `feature:multi_vertical`. | `routes/apiv1.php:555-615` |
| Rate limits | `storefront`: 120/min **per token** (`config('api.storefront_rate')`). `owner-api`: per token + 600/min per account (`TenantRateLimits`). Reads and writes share one storefront bucket. | `app/Providers/RouteServiceProvider.php:72-100` |
| Versioning | Only `v1`. `GET /api/v1` discovery and `GET /api/v1/openapi.json` are generated from the router (`OpenApiGenerator`, `OpenApiParityTest`) with paths only, no field schemas. | `routes/apiv1.php:27-62` |
| Errors | `ApiError` envelope `{message, code, errors?}` for `api/v1/*`. **But `StorefrontController::createOrder` hand-returns `{error}`** (422/403), outside the envelope. | `app/Support/ApiError.php` |
| Idempotency | `client_uuid` from the body or `Idempotency-Key`; replay returns the first order; unique index `orders_rid_cuuid_uniq` catches races. No request-hash check (a different body with the same key silently replays). LEDGER: "idempotent client UUIDv7 orders → separate WI". | `app/Services/OrderApiService.php:485-645` |
| Public ids | `Store`, `Order`, `Menu`, `Branch` have UUIDv7 `public_id` (`HasUuids`, `SchemaMacros::publicId`). The API still exposes integer ids. | `app/Core/Database/SchemaMacros.php`, `app/Modules/Menu/Models/Menu.php:686` |
| Fulfilment | `config/verticals.php` `fulfillment` catalog: `on-table`, `takeaway`, `delivery`, `room_service`, `ship`, `pickup_slot`, `appointment`, `in_store` (`needs_address`, `needs_slot`). Verticals: restaurant, retail, cosmetic, clothing, carparts, laundry, pharmacy, hotel. **No `grocery` or `services` vertical yet.** | `config/verticals.php:119-260`, `app/Services/Verticals/FulfillmentRegistry.php` |
| Slots | Food pickup scheduling: `SchedulingService` (lead, interval, capacity, `slotIsFull`), web-only `GET {restaurant}/schedule-slots`. Table reservations: `ReservationAvailabilityService` (covers per slot, `lockSlot` with `Cache::lock`, store timezone). **No staff × day booking engine, no delivery-slot capacity per zone, no shipping rates or dates.** Delivery module has `Shipment` and `CourierDelivery` only. | `app/Modules/Ordering/Services/Orders/SchedulingService.php`, `app/Modules/Reservations/Services/Rwg/ReservationAvailabilityService.php` |
| Quote | No storefront quote endpoint over the API. Web checkout uses `OrderApiService::previewOrder` (from `PostController` ~line 3378); agents use the MCP `validate_cart` tool. | `app/Services/OrderApiService.php:228`, `app/Services/Agents/AgentToolsService.php:614-636` |
| Weighed / pack | No `pack_size`, `pack_unit` or weighed flag on `Menu` yet (P1-06 / P3-09). | — |
| Kiosk | `source=kiosk` only with `kiosk_mode` + feature `kiosk` + store `kiosk_enabled` (web form). No API path. See `sections/kiosk.md`. | — |

Mismatch to fix in P3-06b: the View Model `context.order_types[].key` enum (`dine_in, takeaway, delivery, pickup, pickup_slot, appointment, at_home, ship, room_service, walk_in`) is not the DB `orders.type` set (`on-table`, `in_store`, …). The API speaks View Model keys; the server maps them (`dine_in`↔`on-table`, the rest 1:1). `in_store` has no View Model key yet; `at_home`, `walk_in` and `pickup` have no DB literal yet. The build session settles the map with P3-09/P3-10 when the `grocery` and `services` verticals land.

## 2. Design principles
1. **One contract.** Display data uses the Storefront View Model defs (`Money`, `ItemCard`, `ItemDetail`, `Quote`, `QuoteRow`, `Slot`, `SlotPicker`, `Choice`, `Staff`, `Order`, and the `store`/`context`/`catalog`/`money` blocks) by `$ref`. Machine fields that clients need to act (ISO times, capacity, stock state, ids, hashes) sit **next to** the display object (`display`, `view`, `item` + `sell`), never inside it, because the View Model defs are `additionalProperties: false`. A theme and a headless client therefore read the same labels and the same money.
2. **Money is never a float.** Every amount is `Money {minor, currency, formatted}`. Clients send amounts only where the customer chooses one (custom tip `minor`, price filter `price_min`/`price_max`), always as integer minor units. Clients never send prices; the server prices every line.
3. **The server is the only source of totals** (contract §5.2). `POST /quote` returns the View Model `Quote` with `informational` rows. Submit echoes `quote_hash`; a changed total returns 409 `quote_changed` with the new quote (contract §5.3).
4. **Additive versioning.** New resources go under the existing `/api/v1/storefront/` prefix with new paths. The old paths keep their exact legacy shape (§5). The router-generated `openapi.json` and `OpenApiParityTest` pick them up; this file adds the field schemas the generator lacks. (Owner decided 2026-10-02, API-1: keep the v1 prefix.)
5. **Tenancy from the token only.** The store is resolved by `StorefrontTokenAuth`, never by path or body. `branch` is an optional query param holding a branch `public_id` that must belong to that store (404 otherwise). Every endpoint runs the `StorefrontAvailability::servable` gate. Ids in and out are `public_id` strings (UUIDv7); integer ids stay only in the legacy aliases.
6. **Auth.**
   - Public reads (store, catalog, item, variants, slots, shipping quote, cart quote, status): storefront token with `menu:read`. Read tokens stay embeddable.
   - Writes (holds, orders, bookings, cancel, reschedule): `order:write`. Origin allowlist as today (mandatory for `browser` tokens).
   - Customer: optional `Authorization: Bearer <customer token>`. It must belong to the same store; it unlocks saved addresses, wallet and loyalty. Guest checkout stays possible where the store allows it.
   - Guest bookings (API-7): a new per-store setting **"Verify guest phone"**, off by default. Clients read it as `booking.verify_guest_phone` in `GET /store`.
     - Off: a guest books with name + phone, and cancels or reschedules with the `manage_token`.
     - On: booking, cancel and reschedule also need a customer token of the same store with a verified phone. A headless client gets one from the storefront-token OTP endpoints (API-14, below) or from customer login. Without it the call answers 403 `phone_verification_required`. The token's phone wins over the body.
     - The quote lists `verified_phone` in `requires[]` when the rule applies.
     - Kiosk walk-in bookings (made with a `kiosk` token) are exempt (API-15): no customer token, never `phone_verification_required`, and the quote never lists `verified_phone`.
   - Customer OTP for headless clients (API-14): `POST /api/v1/storefront/customer/otp/request` `{phone}` and `POST /api/v1/storefront/customer/otp/verify` `{phone, code, device?}`.
     - Storefront token with the `order:write` scope and an `Idempotency-Key` (API-24c), Origin allowlist; the store comes from the token, never from a path id. Plan feature `storefront_api` only.
     - Rate limits follow Juwri's customer OTP today (§1): request 6/min per IP and 3 codes per phone per 10 minutes, verify 10/min per IP and 5 wrong attempts per code, plus `storefront-write`. Over a limit: 429 `rate_limited` with `Retry-After` (in the `ApiError` envelope, not today's `{success, message}`).
     - No account enumeration: request answers 202 with the same body for every well-formed phone (`OtpRequestAccepted` is closed: `message`, `expires_in_seconds = 600`); verify finds or creates the customer by phone, so a new and an existing phone answer the same way; a wrong, used or expired code is 422 `otp_invalid` for every phone.
     - Verify returns a customer token of that store (`Authorization: Bearer`) with `phone_verified_at` set. The old `/api/v1/restaurants/{id}/customer/otp/*` routes stay for the customer apps.
   - Kiosk: new token scope `kiosk`, minted per device by the owner. It makes the `menu:read` and `order:write` calls of its own store (API-24d). Orders and bookings through it are tagged `source=kiosk` server-side; the request body cannot set the source. PINs are never in a response.
   - Booking manage: signed `manage_token` (book §7), same idea as `track_token`. Reschedule calls send it as the `X-Manage-Token` header, never in the URL query (API-24b).
   - Plan features (API-5) are named like the existing `storefront_api` and `agent_api` keys:
     - Every token call needs **`storefront_api`** (today's gate).
     - The booking operations also need **`booking_api`**.
     - Every call made with a `kiosk` token needs **both `kiosk_api` and the existing `kiosk` feature** (API-13; `kiosk_api` does not imply `kiosk`), plus the store's `kiosk_enabled` toggle. The `StorefrontToken` scheme records this as `x-kiosk-plan-features`; `GET /kiosk` lists both in `x-plan-features`.
     - Each operation lists its features in `x-plan-features`. A missing feature answers 403 `storefront_api_not_enabled`, `booking_api_not_enabled` or `kiosk_api_not_enabled`, with the key in `details.feature` (`EnsureFeatureEnabled` already returns `feature` to API callers). A missing `kiosk` feature answers 403 `kiosk_not_enabled` with `details.feature = kiosk`; the same code without `details.feature` means the store's `kiosk_enabled` is off. Today's plan gate answers the generic `forbidden`; P3-06b switches it to the specific code.
7. **Idempotency (J27-A2).** Every write takes `Idempotency-Key: <UUIDv7>` (required on new endpoints, including the OTP calls, and with the new `POST /orders` body). The deprecated legacy order body keeps today's rule until the sunset (API-17): the key is optional and may come as body `client_uuid` (which wins over the header when both are sent). OpenAPI cannot make a header depend on the body, so `POST /orders` declares the header `required: false` (`IdempotencyKeyOrder`) and `check_examples.py` enforces the rule per body type. The key is stored as `client_uuid`, unique per store. Same key + same body → the first response with `Idempotent-Replayed: true`. Same key + a different body → 409 `idempotency_conflict` (new: store a request hash next to `client_uuid`). The server checks that the key is version 7. The server-side `public_id` stays separate from the client key.
8. **Holds.** A hold is a row with `expires_at`. It is taken under the same `Cache::lock` pattern as `ReservationAvailabilityService::lockSlot` and re-checked at submit (the double-booking guard).
   - Every slot type is held for 10 minutes (API-11): booking slots (book §5), grocery delivery slots (API-3), food schedule-ahead pickup and delivery slots, and laundry pickup + delivery slots. Same table, lock and re-check.
   - `POST /holds` takes `kind = booking_slot` (from `GET /booking/slots`) or `delivery_slot` (any slot from `GET /delivery/slots`, whatever its `order_type`). Laundry holds its pickup (`slot_id`) and delivery-back slot (`return_slot_id`) in one hold with one token (API-23).
   - `POST /bookings`, `POST /bookings/{token}/reschedule` and `POST /orders` (`hold_id`) re-check the hold and answer 409 `hold_expired`, `slot_taken` (booking) or `slot_full` (delivery) with `details.next_free_slot`.
   - The hold `token` is returned once and sent back as `X-Hold-Token`.
   - A `pay_later` order or booking consumes the hold. A `hosted` one binds it (API-10): the hold's `expires_at` moves to the payment window end (`payment.next_action.expires_at`, created + 15 minutes, always later than the remaining hold time), it cannot be used by another submit, and it ends with the order: kept as the order's slot on payment, freed when the window ends unpaid.
   - Releasing a bound hold (API-24a) follows the per-store setting **"Release holds during payment"**, off by default, exposed as `holds.release_during_payment` in `GET /store`. Off: `DELETE /holds/{id}` answers 409 `hold_in_use`. On: the release cancels the pending order or booking (`payment.status = failed`, `next_action` null, as at the window end), frees the slot and answers 200 `HoldReleased {hold_id, released, cancelled {kind, public_id, number, status: cancelled, track_token | manage_token}, payment}`. A hold with no pending payment is released with 204.
   - One setting for every bound hold (API-26): delivery and pickup slots of hosted orders (grocery, food schedule-ahead, laundry) and booking slots waiting for a hosted deposit follow the same rule (off: 409 `hold_in_use`; on: 200 and the pending booking is cancelled).
   - Message after the customer's own release (API-29): a separate per-store notification setting, "Message when a customer cancels a pending payment", off by default. On: core sends the flow's existing payment-not-completed message (`food.payment_failed`, `grocery.payment_failed`, `book.deposit_not_paid`) after a release cancels the pending order or booking; off: nothing is sent. Only core acts on it, so it is not in `GET /store` (the store payload carries only settings a client needs before checkout, like `holds.release_during_payment` and `booking.verify_guest_phone`).
   - Reschedule holds (API-8) are made with `X-Manage-Token`; the hold then carries `booking_id`. A hold that overlaps the booking's own current slot is refused unless it is tied to that booking.
   - Limits: one live hold per idempotency key; at most 3 live holds per token per IP, so nobody can block a calendar. Expired holds free capacity lazily on the next read plus a sweep job.
9. **Rate limits.** Keep `storefront` (120/min per token) for reads. Add `storefront-write` (proposed 30/min per token **and** per IP for `browser` tokens) for quote, holds, orders and bookings, plus a per-account ceiling via `TenantRateLimits`, as `owner-api` already has. 429 always carries `Retry-After`.
10. **Caching.**
    - `store`, `catalog`, `items/{id}`: ETag = menu-cache signature + locale + branch, `public, max-age=120`, 304 on `If-None-Match` (today's pattern). The signature must also bump when an item flips `sold_out` (P3-06b check).
    - `variants`: `max-age=30`.
    - Slots, quotes, holds, orders, bookings: `no-store`.
    - The service worker's menu snapshot (contract §5.5) uses the same `catalog` response.
11. **Locale.** `Accept-Language` picks labels and `formatted`. It is part of the ETag. RTL info comes from `store.dir`.
12. **Payment (API-2).** `payment.mode` is `pay_later` or `hosted`; the quote's `payment_modes[]` says which ones the cart allows.
    - `hosted` returns `payment.next_action {type: redirect, url, expires_at}`: a **core-hosted payment page** on the store's host. Gateway scripts, card fields and wallets never leave core (contract §5.4).
    - Payment window (API-10): 15 minutes. `next_action.expires_at` = order or booking creation + 15 minutes. An order or booking still unpaid then is cancelled (`status = cancelled`, `payment.status = failed`, `next_action` null) and its slot, hold and reserved stock are released (sweep job, like expired holds).
    - The client passes `return_url`. Its origin must be in the token's allowed origins (else 422 `return_url_not_allowed`).
    - The quote request may carry `payment_mode` (the mode picked so far). It is not priced and not in `quote_hash`; it only adds `return_url` to `requires[]` when it is `hosted`, and a `payment_mode_unavailable` warning when it is not in `payment_modes[]`. On `POST /orders`, `payment.mode` is authoritative.
    - The order starts as `pending_payment` (DB `unpaid`) with `payment.status = pending`. The gateway webhook to core moves it to `paid` (or `authorised` for a weighed-item card hold, `paid` once captured) or `failed`.
    - After payment, `payment.status` can become `partially_refunded` or `refunded` (with `refunded_amount`; only when the store has refunds on for that flow, D7, except a late-payment refund, which is always allowed, API-28, and a grocery line removed after the card was captured, which is always refunded, API-31), `voided` (an authorised hold released without capture) or `kept` (a booking deposit the store keeps after the deadline or with deposit refunds off, D8; Juwri `deposit_status = kept`). All of these are public payment states (API-19).
    - Query parameters on the return are hints only. The client polls `GET /orders/{track_token}` (or `GET /bookings/{manage_token}`), which carries `payment`.
    - Hosted covers normal online payment, weighed-item card holds (authorise the cap now, capture after weighing; basket §9, D7) and booking deposits (D8: booking `pending_payment` → `confirmed`).
    - Hosted orders never enter the offline queue (contract §5.5).
    - Late payment (API-21): a payment the gateway confirms after the window has ended does not revive the order or booking and is not refunded automatically. The order or booking stays `cancelled` (slot, hold and stock stay released), `payment.status` becomes `received_late` with `amount_now` = the amount received and `next_action` null. It shows in the store dashboard and the store owner decides per case: refund it (`refunded` with `refunded_amount`, or `voided` for an authorised card hold; the order stays `cancelled`) or accept it.
      - Accepting (API-27) re-reserves the slot and stock automatically when they are still free: `paid` or `authorised`, the order moves to `placed`, a booking to `confirmed`. When they are no longer free, the money is accepted (`paid` or `authorised`) and the order or booking waits as `pending_approval` (an existing order status; new on `BookingRef`) while the dashboard asks the owner to pick another slot or adjust the order (then `placed` / `confirmed`) or to refund. The record, its `public_id`, number and tracking link (`track_token` / `manage_token`) stay the same in every outcome. A `pending_approval` booking has no `reschedule_deadline_at` and answers 409 `booking_not_active` to cancel and reschedule until it is `confirmed`.
      - Refunding a late payment (API-28) is always allowed: the per-flow refund switches (D7) do not apply, because the order or booking never went ahead (from `received_late` or from `pending_approval`; `refunded`, or `voided` for an authorised hold). Customer messages: `food.refund_issued`, `grocery.late_payment_refunded` (`grocery.late_hold_released` for a voided hold), `retail.late_payment_refunded`, `book.late_deposit_refunded`.
      - Late card hold expiring (API-32): an `authorised` card hold carries `payment.authorisation_expires_at`, when the gateway lets the authorisation lapse (gateway-set, stored by core). While an accepted late hold waits in `pending_approval`, the store dashboard warns the owner before that time (a dashboard item, not a customer notification). If it lapses before the owner acts, the order is cancelled automatically (`pending_approval` → `cancelled`), the hold is voided (`payment.status = voided`, nothing charged, `next_action` null), and both are told that nothing was charged: the customer by `grocery.late_hold_expired`, the owner in the dashboard. Same record, number and tracking link.
      - A polling client shows "Payment received after the order expired; the store will contact you" and offers no retry for that order. The customer message is `<flow>.payment_received_late` / `book.deposit_received_late` in `kit/notifications/`; while it is `pending_approval` the client also shows that the store will contact the shopper.

## 3. Endpoints (prefix `/api/v1/storefront`)

| # | Method + path | Scope | Plan features | food | grocery | retail | services | Backed by |
|---|---|---|---|---|---|---|---|---|
| 1 | `GET /store` | menu:read | storefront_api | ✓ | ✓ | ✓ | ✓ | View Model builder (P3-05) |
| 2 | `GET /catalog` (`q`, `category`, `filter[...]`, `in_stock`, price range, `vehicle`, `sort`, `cursor`) | menu:read | storefront_api | ✓ (one page) | ✓ | ✓ | ✓ (services as items) | catalog + Scout (J27-B) |
| 3 | `GET /items/{item_id}`: ItemDetail + `sell` (pack size, unit price, weighed steps + cap, duration) + variants | menu:read | storefront_api | ✓ | ✓ | ✓ | ✓ | P1-06 options, P3-09 pack/weighed |
| 4 | `GET /items/{item_id}/variants`: matrix + stock state per variant | menu:read | storefront_api | – | ✓ | ✓ | – | `VariantApiController` data (P3-08) |
| 5 | `POST /shipping/quote`: methods, fee, earliest/latest date, cut-off, carrier, pickup branch | menu:read | storefront_api | – | – | ✓ | – | **new** (P3-08) |
| 6 | `GET /delivery/slots`: days × slots, `capacity_remaining`, `few`/`full`, express | menu:read | storefront_api | ✓ schedule-ahead | ✓ | – | ✓ laundry | `SchedulingService` + **new** zone capacity (P3-09) |
| 7 | `GET /booking/staff?service_ids=` | menu:read | storefront_api + booking_api | – | – | – | ✓ | **new** (P3-10) |
| 8 | `GET /booking/days?service_ids&option_ids`: day strip | menu:read | storefront_api + booking_api | – | – | – | ✓ | P3-10 |
| 9 | `GET /booking/slots?service_ids&option_ids&date&staff_id=any` | menu:read | storefront_api + booking_api | – | – | – | ✓ | P3-10 |
| 10 | `POST /holds`: 10-min hold for every slot type (booking, delivery, pickup, laundry; API-3, API-11) | order:write | storefront_api (+ booking_api for booking holds) | ✓ schedule-ahead | ✓ | – | ✓ (+ laundry) | P3-10 + P3-09 |
| 11 | `DELETE /holds/{hold_id}` (bound to a pending hosted order: 409, or cancels it when "Release holds during payment" is on; API-24a) | order:write | storefront_api (+ booking_api for booking holds) | ✓ | ✓ | – | ✓ | same |
| 12 | `POST /quote`: View Model Quote + `quote_hash`, weighed estimate/cap, filtered payment methods, warnings, `requires[]` | menu:read | storefront_api (+ booking_api with a booking hold) | ✓ | ✓ | ✓ | ✓ | `OrderApiService::previewOrder` widened |
| 13 | `POST /orders` (existing path, widened; `pay_later` or `hosted`) | order:write (+customer) | storefront_api | ✓ | ✓ | ✓ | ✓ laundry | `OrderApiService::create` |
| 14 | `GET /orders/{track_token}`: OrderRef + View Model `Order` | menu:read | storefront_api | ✓ | ✓ | ✓ | ✓ | track service |
| 15 | `POST /bookings`: from a hold | order:write (+customer) | storefront_api + booking_api | – | – | – | ✓ | P3-10 |
| 16 | `GET /bookings/{manage_token}` | menu:read | storefront_api + booking_api | – | – | – | ✓ | P3-10 |
| 17 | `POST /bookings/{manage_token}/cancel` | order:write (+customer when "Verify guest phone" is on) | storefront_api + booking_api | – | – | – | ✓ | P3-10 + D7/D8 |
| 18 | `POST /bookings/{manage_token}/reschedule`: to a new held slot at the same price, deposit carried over, no money moves (API-7, API-8) | order:write (+customer when "Verify guest phone" is on) | storefront_api + booking_api | – | – | – | ✓ | P3-10 + D8 |
| 19 | `GET /kiosk`: `context.kiosk` + context | kiosk | storefront_api + kiosk_api + kiosk (API-13) | ✓ | ✓ | ✓ | ✓ | P3-06 |
| 20 | `POST /customer/otp/request`: text a code (API-14) | order:write | storefront_api | ✓ | ✓ | ✓ | ✓ | `SmsOtpService` |
| 21 | `POST /customer/otp/verify`: customer token with a verified phone (API-14) | order:write | storefront_api | ✓ | ✓ | ✓ | ✓ | `SmsOtpService` + `CustomerToken` |

21 new or changed operations, plus 4 deprecated legacy aliases documented in `openapi.json` (`GET /menu`, `GET /restaurant`, the public `GET /api/v1/menu/{restaurantId}` (API-12) and `GET /api/v1/track/{token}`): 25 operations in total. The legacy order body on `POST /orders` is a deprecated `LegacyOrderRequest` schema inside the same operation. Every call with a `kiosk` token also needs `kiosk_api` + `kiosk` (API-13).

Store-type notes:
- **food**: catalog in one page. Dine-in sends the signed QR `table` token. Schedule-ahead uses `/delivery/slots?order_type=pickup|delivery`, and the chosen slot is held for 10 minutes (`POST /holds`, `kind = delivery_slot`, API-11); quote and `POST /orders` send `slot_id` + `hold_id` (+ `X-Hold-Token`). The offline queue replays `POST /orders` with the same key after a re-quote (contract §5.5).
- **grocery** (`basket`):
  - Weighed lines pick a weight step through `option_ids`, and `qty` stays an integer.
  - The quote returns `weighed {estimate, cap, cap_percent}` and a `hold_amount` in the payment state. `estimate` = the weighed lines' (`estimate: true`) line totals, already inside `quote.total`; `cap` = `estimate` × (1 + `cap_percent`/100), rounded to the minor unit; `hold_amount` = `quote.total` + (`cap` − `estimate`) (basket §9).
  - Payment methods are filtered by the D7 weighed rule. Card holds go through `payment.mode = hosted` (authorise the cap, capture after weighing; API-2).
  - The chosen delivery slot is held for 10 minutes (`POST /holds`, `kind = delivery_slot`; API-3). `POST /orders` sends `hold_id` + `X-Hold-Token`.
  - Substitution is set per line and per order (API-22). The "If it's out of stock" choice is a store-defined option group: `GET /store` returns it as `substitution {group, default_option_id}`, and an item that takes a per-line choice names its group in `substitution_group_id`. `CartLine.substitution` and `CartRequest.substitution_preference` are option `public_id`s of those groups (a line without one follows the order preference, an order without one the store default). Picker actions (API-25): every option of the group maps to exactly one fixed `PickerAction`, listed next to the group as `substitution.picker_actions[] {option_id, action}` in `GET /store` and as `substitution_picker_actions[]` on the item (the View Model `Option` stays unchanged). The store names the options freely; the action drives the picker app:
    - `offer_replacement`: the picker proposes a replacement; the shopper sees it on the tracking page as "Changes to review" (basket §10: ordered vs replacement, Keep replacement / Refund instead, new estimated total; `grocery.replacement_needed`). Without an answer before dispatch, the order-level preference applies.
    - `call_customer`: the picker phones the shopper (the order's customer phone) before changing the line. Each `call_customer` option also carries a store-set `fallback` for a call that goes unanswered (API-30): `offer_replacement` or `remove_item`, default `remove_item`. It is in the same `picker_actions[]` entry (`{option_id, action: call_customer, fallback}`) and absent on the other actions.
    - `remove_item`: the picker removes the line (an authorised card hold captures less, pay later collects less). A line removed after the card was captured is always refunded (`partially_refunded` with `refunded_amount`), whatever the D7 switches say, because the shopper never got it (API-31). The demo option label ("Remove it", "You get a refund for it") stays.
    - The basket's order-level row (basket §8) and the per-line choice are the same options; the line's option (else the order preference, else `default_option_id`) picks the action for that line. Clients may use the action to describe an option (e.g. "we'll call you") but never price it; they always send the option id.
  - Kiosk: no slots (collect now).
- **retail** (`aisle`):
  - A variant is chosen by `variant_id` or by its option ids.
  - Call `/shipping/quote` before `/quote`, and pass the chosen `shipping_method_id`.
  - Fitment is a `vehicle` filter: `year|make|model` (optionally `|submodel|engine`) or `vin:<VIN>`; it keeps only products with a confirmed fitment and sets `fits_vehicle` on every card (also on `GET /items/{id}` and `context.vehicle` of the store response). Owners load fitments with the store CSV import (P3-08b).
  - The kiosk offers ship-to-home or collect in store.
- **services** (`book`):
  - Flow: services (catalog items with `sell.duration_min`) → staff → days → slots → hold → quote (the deposit shows as `deposit`/`due_later` rows, `informational` per §5.2) → `POST /bookings` (deposit via `payment.mode = hosted`; API-2).
  - Options and add-ons change duration and price (book §5), so `GET /booking/days`, `GET /booking/slots` and the `booking_slot` hold take the chosen `option_ids` next to `service_ids` (the same ids as `CartLine.option_ids`); `total_duration_min` is services + options.
  - Manage (API-7): `GET /bookings/{manage_token}`, cancel (D7/D8 deposit rules) and reschedule (new 10-minute hold, deposit carried over, until `reschedule_deadline_at`).
  - Reschedule at the same price only (API-8): the client lists staff, days and slots and takes the hold with `X-Manage-Token`, so only staff and slots at the booking's price come back; a held slot whose price differs answers 409 `price_changed`. No money moves on reschedule. A change of price (e.g. another staff level) is cancel + a new booking.
  - Reschedule deadline (API-9): the cancellation deadline (D8, default 24 h, one store setting); `reschedule_deadline_at` always equals `cancel_deadline_at`.
  - Guest phone check follows the store's "Verify guest phone" setting (§2.6, API-7).
  - Laundry uses `/delivery/slots` (pickup, then delivery with `after_slot`), holds both in one 10-minute hold (`slot_id` + `return_slot_id`, API-11, API-23) and `POST /orders` (`slot_id`, `return_slot_id`, `hold_id`).
  - Until the store sets its hours (D13), the booking endpoints answer 409 `bookings_not_open`.
  - At the kiosk: today only, no deposit step, and no guest phone check (API-15).

## 4. Request and response shapes (summary; full in `openapi.json`)
- `CartLine {line_id?, item_id, variant_id?, option_ids[], qty, note?, substitution? (option id of the item's substitution group, API-22)}`
- `CartRequest {order_type, lines[], coupon?, tip?, address?, shipping_method_id?, slot_id?, return_slot_id? (laundry), express?, hold_id? (slot hold, API-3, API-11), substitution_preference? (option id of the store's substitution group, API-22), table?, scheduled_at?, payment_method_id?, payment_mode? (pay_later|hosted; drives `requires: return_url`)}`
- `QuoteResponse {quote: Quote, quote_hash, expires_at, weighed? {estimate (weighed lines only), cap, cap_percent}, payment_methods: Choice[], payment_modes[] (pay_later|hosted), warnings[] (adds payment_mode_unavailable), requires[] (adds verified_phone, return_url when payment_mode = hosted)}`
- `OrderSubmitRequest = CartRequest + {quote_hash, customer?, payment {mode: pay_later|hosted, method_id?, return_url (required for hosted)}}`. The deprecated `LegacyOrderRequest {items[], type, client_uuid, …}` is the other `oneOf` branch.
- `OrderCreated {order {public_id, number, status, track_token, track_url}, quote, payment {mode, status, next_action? {type: redirect, url, expires_at (created + 15 min, API-10)}, amount_now?, hold_amount?, refunded_amount?, authorisation_expires_at? (card holds, API-32)}, replayed}`. `OrderStatusResponse` and `BookingResponse` (also from cancel) carry `payment`. The legacy body gets the frozen `LegacyOrderCreated {ok, order_id, total, …, track_token, continue_url, track_url, eta_range}` instead; the two 201 shapes are disjoint.
- `OrderRef.status` (public enum): `pending_payment` (DB `unpaid`), `pending_approval`, `placed` (`new`), `accepted`, `preparing`, `ready` (also `call_bill`, API-16), `shipped`, `on_the_way` (`on_delivery`, driver or courier en route), `delivered`, `completed`, `cancelled`, `refunded`; any other or unknown legacy status reads as `placed` (API-16). `pending_approval` also covers an accepted late payment waiting for a new slot (API-27); an accepted card hold that lapses there moves to `cancelled` with `payment.status = voided` (API-32). The View Model `Order.status` is the theme's step key and may be finer (grocery `picking`).
- `BookingRef.status`: `confirmed`, `pending_payment`, `pending_approval` (new in 1.6.0: accepted late deposit, slot no longer free, waiting for the owner; API-27), `cancelled`, `completed`, `no_show`.
- `PaymentState.status`: `not_required`, `pay_later`, `pending`, `authorised`, `paid`, `failed`, `partially_refunded`, `refunded`, `voided` (also a late card hold that lapsed in `pending_approval`, API-32), `kept` (§2.12; all public, API-19), `received_late` (a payment after the window on a `cancelled` order or booking, waiting for the store owner, API-21; the owner's refund is outside D7, API-28).
- `DeliverySlot` and `BookingSlot` carry `id`, `starts_at`/`ends_at` (ISO with offset, store timezone), `state`, `capacity_remaining` (delivery), `staff_id` (booking), and `display: Slot`. `staff_id` is required on a `BookingSlot` and never present on a `DeliverySlot`, so `details.next_free_slot` is unambiguous.
- `HoldRequest {kind: booking_slot|delivery_slot, slot_id, return_slot_id? (laundry), service_ids? + option_ids? (booking)}` (+ `X-Manage-Token` for a reschedule) → `Hold {id, token, kind, slot_id, return_slot_id?, booking_id? (reschedule), expires_at, ttl_seconds (600), hold_note}`
- `RescheduleRequest {hold_id, notes?}` (+ `X-Hold-Token`, `Idempotency-Key`) → `BookingResponse`. `BookingRef` gains `reschedule_deadline_at` (= `cancel_deadline_at`, API-9). `GET /booking/staff|days|slots` take an optional `X-Manage-Token` (same-price listing, API-8); `BookingSlotsResponse.reschedule_of` echoes the booking.
- `OtpRequest {phone}` → 202 `OtpRequestAccepted {message, expires_in_seconds}`; `OtpVerifyRequest {phone, code, device?}` → `OtpVerified {token, token_type: Bearer, expires_at, customer {public_id, name, phone, phone_verified_at}}` (API-14)
- `StoreResponse.booking {verify_guest_phone: boolean}` (API-7, default false); `StoreResponse.holds {release_during_payment: boolean}` (API-24a, default false; covers booking-deposit holds, API-26); `StoreResponse.substitution {group: OptionGroup, default_option_id, picker_actions: [{option_id, action: offer_replacement|call_customer|remove_item, fallback? (offer_replacement|remove_item, only with call_customer, default remove_item, API-30)}]} | null` (API-22, API-25: exactly one action per option)
- `ItemResponse {item: ItemDetail, sell {…, weight_option_group: OptionGroupId}, substitution_group_id?: OptionGroupId, substitution_picker_actions? (with `substitution_group_id`, one per option of that group, API-25), variants?, stock?}`
- `DELETE /holds/{hold_id}` → 204, or 200 `HoldReleased {hold_id, released: true, cancelled {kind: order|booking, public_id, number, status: cancelled, track_token?, manage_token?}, payment}` when "Release holds during payment" is on and a pending hosted order or booking was bound to the hold, or 409 `hold_in_use` when it is off (API-24a)
- `StockState {state: in_stock|low|out|untracked, qty? (only when low, i.e. at or below the store's low-stock threshold; API-6), label}`
- **Ids:** the API always uses `public_id` strings (UUIDv7) for stores, branches, items, variants, options and modifiers (API-18: `CartLine.option_ids`, weight steps, booking `option_ids`, `ItemDetail` option ids, `Variant.options` values; schema `OptionId`), option groups (API-20: `ItemDetail.option_groups[].id`, `Variant.options` keys, `sell.weight_option_group`, `substitution_group_id`, the store's substitution group; schema `OptionGroupId`), orders, bookings, holds and staff. The substitution options are normal option `public_id`s too (API-22); they go in `CartLine.substitution` / `substitution_preference`, never in `option_ids`. The View Model fixtures (`kit/viewmodel/fixtures/*`) use slug ids such as `hummus` only as readable display placeholders; they are not API ids, and a theme must never parse or build an id. The example pack (`examples/`) uses UUIDv7-shaped ids for the same objects.

## 5. Legacy endpoints: deprecated aliases (API-4)
From launch, every alias response carries `Deprecation: true`, `Sunset: <launch + 12 months, HTTP-date>`, `Link: </developers>; rel="deprecation"` and a `rel="successor-version"` link. This is the same header pattern as today's deprecated query-string token. The aliases are removed 12 months after launch. In `openapi.json` they are `deprecated: true` (`legacyMenu`, `legacyRestaurant`, `legacyPublicMenu`, `legacyTrack`, and the `LegacyOrderRequest` body schema).

| Old | New equivalent | Rule |
|---|---|---|
| `GET /api/v1/storefront/menu` | `GET /catalog` | Deprecated. Shape frozen as `LegacyMenuResponse` (`restaurant`, `categories[].items[]` with float `price`, integer ids, `variants`, `variant_groups`, `extras`, per-vertical attributes). Served by the same catalog service through a legacy projection. Same ETag. |
| `GET /api/v1/storefront/restaurant` | `GET /store` | Deprecated. Shape frozen as `LegacyRestaurantResponse` (`id, title, slug, description, phone, address, store_type` + `vertical`). |
| `GET /api/v1/menu/{restaurantId}` (public) | `GET /catalog` | Deprecated with the others (API-12): same headers, removed 12 months after launch. Stays public (no token, `throttle:120,1`) until then. Shape frozen as `LegacyMenuResponse` (today's `PublicController::menu`, the same body as `GET /menu`), same ETag and 304. |
| `GET /api/v1/track/{token}` | `GET /orders/{track_token}` | Deprecated. Shape frozen as `LegacyTrackResponse` (DB `status`/`type` literals, float `total`, `timeline`, `driver`, `courier`, `shipment`) until removal. |
| `POST /api/v1/storefront/orders` legacy body (`items`, `type` DB literal, `client_uuid`) | Same path, new body | Detected by the presence of `items` without `lines`. Success is the frozen `LegacyOrderCreated`; the idempotency key stays optional until the sunset (body `client_uuid` or header; API-17). Legacy `type` literals are accepted on this body only. Deprecated. Errors move to the `ApiError` envelope now (today `{error}`; API-4). The owner accepted this small break for anyone parsing `error`. |
| MCP `storefront` tools (`validate_cart`, `create_order`, …) | `/quote`, `/orders` | The tools call the same services, so agents and REST stay in parity (P1-16 shared registry). |

## 6. Error model
The existing `ApiError` envelope is extended with an optional `details` (additive): `{message, code, errors?, details?}`.

| HTTP | `code` | When | `details` |
|---|---|---|---|
| 401 | `unauthenticated` | token missing/invalid | – |
| 403 | `forbidden` | scope, origin | – |
| 403 | `storefront_api_not_enabled`, `booking_api_not_enabled`, `kiosk_api_not_enabled` (API-5) | the owner's plan lacks that feature | `feature` |
| 403 | `feature_disabled` | platform kill switch | – |
| 403 | `kiosk_not_enabled` (API-13) | kiosk token: the plan lacks the `kiosk` feature, or the store's `kiosk_enabled` is off | `feature` = `kiosk` for the plan case, absent for the toggle |
| 403 | `phone_verification_required` (API-7) | "Verify guest phone" is on and there is no verified customer token (never for a kiosk walk-in, API-15) | – |
| 404 | `not_found` | unknown id, foreign branch, store not servable | – |
| 409 | `quote_changed` | submit hash ≠ current quote | `quote` (new) |
| 409 | `idempotency_conflict` | same key, different body | – |
| 409 | `slot_taken`, `slot_full`, `hold_expired` | hold, submit and reschedule races (booking and delivery holds) | `next_free_slot` (BookingSlot or DeliverySlot) |
| 409 | `hold_in_use` (API-10, API-24a) | `DELETE /holds/{id}` on a hold bound to a pending hosted order or booking while the store's "Release holds during payment" is off (on: the release cancels the order or booking instead) | – |
| 409 | `price_changed` (API-8) | reschedule to a held slot whose price differs from the booking's (cancel and rebook instead) | – |
| 409 | `past_deadline`, `booking_not_active` (API-7) | reschedule after `reschedule_deadline_at` (= `cancel_deadline_at`, API-9), or booking not `confirmed` | – |
| 409 | `store_closed`, `bookings_not_open` (D13) | store state | – |
| 422 | `validation_failed` | field errors | `errors` |
| 422 | `out_of_zone`, `below_minimum`, `out_of_stock`, `invalid_order_type`, `payment_method_unavailable` | business rules | `line_ids` |
| 422 | `payment_mode_unavailable`, `return_url_not_allowed` (API-2) | mode not in the quote's `payment_modes`; `return_url` origin not in the token's allowlist | – |
| 422 | `otp_invalid` (API-14) | wrong, used or expired OTP code (same answer for every phone) | – |
| 429 | `rate_limited` | budget hit | `Retry-After` header |

A quote never fails for a sellable-but-changed cart. It returns 200 with `warnings[]` so the client can show "Price changed" and "Out of stock" states (basket, aisle and book state frames).

## 7. Phased rollout (P3-06b)
| Phase | Ships | Depends on |
|---|---|---|
| A: foundation | `GET /store`, `GET /catalog` (Money, public ids, ETag), `GET /items/{id}` (food + plain goods), `POST /quote`, widened `POST /orders` (`pay_later`, and `hosted` with the `return_url` check; API-2), `GET /orders/{token}` with `payment`, 15-minute hosted payment window with the auto-cancel sweep (API-10), `GET /kiosk` + `kiosk` scope + `kiosk_api` and `kiosk` features (API-13), the `*_api_not_enabled` codes, `storefront-write` limiter, idempotency request hash, `details` in the error envelope, legacy aliases (including the public `GET /api/v1/menu/{restaurantId}`, API-12) on the shared service with `Deprecation`/`Sunset` and `ApiError` legacy errors (API-4), storefront OTP endpoints (API-14), option and option group `public_id`s (API-18, API-20), late payments left `cancelled` + `received_late` for the store owner, with a dashboard entry (API-21): accept re-reserves the slot and stock when free, else `pending_approval` until the owner picks a slot or refunds (API-27); late-payment refunds bypass the D7 switches (API-28); a late card hold that lapses in `pending_approval` cancels the order and voids the hold automatically, with a dashboard warning before the expiry (API-32); per-store "Message when a customer cancels a pending payment" (off) in the notification settings (API-29) | P3-05 (View Model builder), P3-06 (kiosk) |
| B: retail | `variants` + stock state, `filter[...]`/`sort`/`cursor` on Scout, `vehicle`, `POST /shipping/quote`, `shipping_method_id` in quote/order | **P3-08** (+ P1-06, P1-17, P3-08b for fitment) |
| C: grocery | `sell` pack/unit price, weighed lines + `weighed` in quote, D7 payment filtering, weighed card holds via `hosted` (authorise/capture), substitutions as a store-defined option group (API-22) with one picker action per option (API-25) and a no-answer fallback on `call_customer` options (API-30), refund of lines removed after capture outside D7 (API-31), `GET /delivery/slots` with zone capacity + express, 10-minute holds for delivery, food schedule-ahead and laundry slots (API-3, API-11, API-23), the "Release holds during payment" store setting (API-24a) | **P3-09** (+ Delivery, Payments modules, P3-14) |
| D: services | `booking/staff`, `booking/days`, `booking/slots`, `POST/DELETE /holds`, `POST /bookings`, `GET /bookings/{token}`, cancel, same-price reschedule with `X-Manage-Token` and the single deadline (API-7, API-8, API-9), kiosk walk-in exemption (API-15), D13 gate, deposits via `hosted` (D8, API-2) under the same "Release holds during payment" rule (API-26), `pending_approval` bookings (API-27), `booking_api` feature (API-5), "Verify guest phone" setting (API-7) | **P3-10** |
| Exit | Contract tests: each `kit/viewmodel/fixtures/*` page validates against the referenced defs; the quote total equals the `Order` total (contract §5.2 acceptance) through the API too; `OpenApiParityTest` covers the new routes; per-store-type smoke (food, grocery, retail, services) | P3-15 |

Split by risk (PHASE3 notes): idempotency, holds, payments, weighed capture and tenancy checks go to Opus slices; read endpoints, projections and tests go to Sonnet.

## 8. Owner decisions (all answered 2026-10-02)
The spec text above was written before these answers; where it still says "question for owner", these decisions win. Item 32 in `PREBUILD-PLAN.md` brings `openapi.json` and §1–7 in line.
- **API-1 Version path:** keep `/api/v1/storefront/`. New resources are additive there; no v2.
- **API-2 Online payment:** allow `payment.mode = hosted`. The API returns a core-hosted payment page URL, so gateway scripts never leave core (contract §5.4). This covers weighed-item card holds (basket §9) and booking deposits (D8). Pay-later stays available.
- **API-3 Delivery-slot holds (grocery):** yes, hold the slot at checkout for 10 minutes, the same as booking holds.
- **API-4 Legacy aliases:** sunset after 12 months. Add `Deprecation` and `Sunset` headers from launch, and remove the aliases 12 months later. Legacy order errors move to the standard `ApiError` envelope now.
- **API-5 Plan gating:** separate plan features: a base `storefront_api`, plus separate features for the booking API and the kiosk API, so they can sit in higher plans.
- **API-6 Stock exposure:** stock states only (`in_stock`, `low`, `out`, `untracked`), as `openapi.json` already models; an exact quantity appears only at or below the store's low-stock threshold.
- **API-7 Guest bookings:** the store owner chooses. A per-store setting "Verify guest phone" (OTP) is **off by default**; with it off a guest books with name + phone, with it on an OTP or customer token is required. The same rule applies to cancel and reschedule over the API.
- **API-7 Cancel and reschedule:** both are in v1. Reschedule re-holds the new slot (10 minutes) and carries the deposit over; cancel follows the D7/D8 deposit rules.

### Follow-up decisions (owner, 2026-10-02)
Item 37 in `PREBUILD-PLAN.md` applies these to `openapi.json`, §1–7 and the examples.
- **API-8 Reschedule price:** same price only. Reschedule offers only slots at the same price as the booking; a change of price (e.g. another staff level) means cancel and rebook. No money moves on reschedule.
- **API-9 Reschedule deadline:** the same as the cancellation deadline (D8, default 24 h). One store setting; `reschedule_deadline_at` always equals `cancel_deadline_at`.
- **API-10 Hosted payment window:** 15 minutes. An unpaid hosted order or booking stays `pending_payment` for 15 minutes, then is cancelled and its slot, hold or stock is released.
- **API-11 Slot holds everywhere:** the 10-minute hold applies to every slot type: booking, grocery delivery, food schedule-ahead and laundry pickup/delivery.
- **API-12 Public menu:** `GET /api/v1/menu/{restaurantId}` is deprecated with the other aliases (same headers, removed 12 months after launch).
- **API-13 Kiosk plan features:** both required. A kiosk API call needs `kiosk_api` and the existing `kiosk` feature (plus `kiosk_enabled`); `kiosk_api` does not imply `kiosk`.
- **API-14 OTP for headless clients:** add storefront-token OTP endpoints (`POST /api/v1/storefront/customer/otp/request` and `…/verify`); the store comes from the token; rate limited.
- **API-15 Kiosk walk-in bookings:** exempt from "Verify guest phone".
- **API-16 Status mapping:** confirmed: `call_bill` → `ready`, unknown legacy statuses → `placed`.
- **API-17 Legacy idempotency:** stays optional on the legacy body until the sunset; the new body requires the key.
- **API-18 Option ids:** option and modifier ids become UUIDv7 `public_id`s like every other id.
- **API-19 `voided` and `kept`:** public payment states.

### Follow-up decisions from item 37 (owner, 2026-10-03)
Item 40 in `PREBUILD-PLAN.md` applies these.
- **API-20 Option group ids:** option group ids (`weight`, `colour`, `size` …) become UUIDv7 `public_id`s too.
- **API-21 Late payment:** a payment that arrives after the 15-minute window does **not** revive the order and is **not** refunded automatically. It shows in the store dashboard, and the store owner decides per case: refund it, or accept it and place the order. Notifications must not promise an automatic refund.
- **API-22 Substitution choice:** store-defined. The "If it's out of stock" choice is a normal option group the store sets up (option `public_id`s), not fixed values. `CartLine.substitution` / `substitution_preference` fixed enums go away or become the option ids (item 40 decides the shape).
- **API-23 Laundry hold:** confirmed: one hold covers both the pickup slot and the return slot (`return_slot_id`).
- **API-24a Releasing a hold during payment:** per-store setting **"Release holds during payment"**, **off by default**. Off: `DELETE /holds/{id}` on a hold tied to a pending hosted order answers 409 `hold_in_use`. On: releasing it cancels the pending order and frees the slot.
- **API-24b:** confirmed: the reschedule manage token goes in the `X-Manage-Token` header, never the URL.
- **API-24c:** confirmed: OTP endpoints need the `order:write` scope and an `Idempotency-Key`.
- **API-24d:** confirmed: a `kiosk` token can make its own store's `menu:read` and `order:write` calls (orders tagged `source=kiosk`).

### Follow-up decisions from item 40 (owner, 2026-10-03)
Item 41 in `PREBUILD-PLAN.md` applies these.
- **API-25 Picker actions:** required. Each store-defined out-of-stock option must map to exactly one fixed picker action: `offer_replacement`, `call_customer` or `remove_item`. The store names the option freely; the action drives the picker flow (basket §8, §10).
- **API-26 Release setting for bookings:** yes. "Release holds during payment" applies to booking-deposit holds too (one setting for all holds).
- **API-27 Accepting a late payment:** accepting re-reserves the slot and stock automatically if they are still free. If not, the dashboard asks the owner to pick another slot (or adjust the order) or to refund. The order keeps the same record and tracking link.
- **API-28 Refunding a late payment:** always allowed. The per-flow refund switches (D7) do not apply, because the order never went ahead. Add customer refund messages for late-payment refunds in grocery, retail and booking deposits (food has `food.refund_issued`).
- **API-29 Message after a customer's own release:** per-store setting "Message when a customer cancels a pending payment", **off by default**. When on, the "payment not completed" message is sent.

### Follow-up decisions from item 41 (owner, 2026-10-03)
Item 42 in `PREBUILD-PLAN.md` applies these.
- **API-30 `call_customer` with no answer:** each `call_customer` option also names a store-set fallback, `offer_replacement` or `remove_item`; default `remove_item`.
- **API-31 `remove_item` after capture:** the removed line is always refunded, whatever the D7 switches say (the customer never got it). The demo label stays.
- **API-32 Late card hold expiring:** if the authorisation expires while the order waits in `pending_approval`, the order is cancelled automatically, the hold is voided, and both customer and owner are told nothing was charged. The dashboard warns before the expiry.
- **API-33 Booking `pending_approval`:** keep it.
- **API-34 `grocery.late_hold_released`:** keep it.

Note for the build session (item 37): Juwri's current `SmsOtpService` keys codes and limits by phone only, not by store. The storefront OTP endpoints must scope them per store (tenancy).

Notes for the build session: holding a new slot that overlaps the booking's own current slot is refused unless the hold is tied to the booking being rescheduled; §1 cites `routes/apiv1.php` line numbers, which now live in module route files (e.g. `app/Modules/Storefront/Routes/apiv1-1.php`).
