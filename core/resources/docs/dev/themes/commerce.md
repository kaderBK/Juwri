---
title: "Theme commerce runtime"
audience: developer
group: Themes
updated: 2026-09-19
---

# Theme commerce runtime

Marketplace (Liquid) themes never ship JavaScript and never call ordering
endpoints themselves — the package validator rejects `.js` files and
`<script>` bodies outright (see [structure](structure.md)). All cart,
checkout, order placement, coupons, customer accounts and waiter calls come
from the platform commerce runtime: a versioned, platform-owned bundle
(`public/assets/commerce-runtime/<version>/commerce.js`) that a theme
activates with a single tag and binds to declaratively with `data-mb-*`
attributes.

The bundle is a build/deploy artifact, not part of any theme package and not
in the tracked source tree. Its version comes from
`config('platform.commerce.runtime_version')` (`COMMERCE_RUNTIME_VERSION`,
default `v1`); bumping it ships a new bundle without touching a single theme.

## The {% commerce %} tag

Place `{% commerce %}` once in your layout (see [tags](tags.md)). At render
time it emits two CSP-nonced script tags: an inline `window.MB_COMMERCE`
config object and the versioned runtime bundle (deferred). The tag is
idempotent — repeat uses emit nothing, tracked by a render register — and
the config is built server-side (`Juwri\Modules\Storefront\Storefront\CommerceRuntime`) from
render registers a template cannot reach, so a theme can never forge the
CSRF token, endpoints or prices. The config JSON is escaped with
`JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` so DB item names and
descriptions cannot break out of the inline `<script>`.

The config carries everything the runtime needs: restaurant id and slug;
currency (sign, position, code, manual conversion rates); charges (tax and
tax label, delivery fee, minimum order); enabled order types (dine-in,
takeaway, delivery); feature flags (ordering, payment, coupons, tips,
scheduling, call-waiter, forced table, customer auth mode, logged-in
state); the CSRF token; all endpoint URLs; a full item map (prices,
variants, extras, option groups, gallery, sold-out state, rating); and
translated UI strings via the app `___()` translator. Related read-only data
is also exposed to Liquid via [drops](drops.md) (`flags`, `options`,
`customer`, `table`), so a theme can conditionally render its markup — for
example hide the cart button entirely when `flags.allow_order` is false.
The `vocab` global carries the store's vertical-aware nouns (`vocab.items`,
`vocab.add_to_cart`, …) so cart/checkout copy reads right on a goods or
services store instead of always saying "order" — see [Building Liquid
themes](../learn-themes.md#make-your-theme-vertical-aware-vocab--store_type).

The runtime URL is cache-busted by the bundle file's mtime, so a redeploy is
picked up immediately even though the URL is otherwise long-cached.

## Binding the cart with data attributes

The runtime binds by attribute; a theme opts in to exactly the surfaces it
wants. Core bindings (as used by the first-party `crave` and `starter`
Liquid themes):

```liquid
<button data-mb-add="{{ item.id }}">Add</button>   <!-- opens the option sheet -->
<span   data-mb-cart-count data-mb-hide-empty></span>
<span   data-mb-cart-total></span>
<button data-mb-open-cart>Cart</button>
<button data-mb-call-waiter>Call waiter</button>
<div    data-mb-item-sheet-mount></div>   <!-- runtime renders the option sheet here -->
<div    data-mb-cart-mount></div>         <!-- runtime renders cart + checkout here -->
```

The runtime renders the option sheet, the cart lines and the whole checkout
form (order-type segment, name/table/phone/address fields, coupon input,
tips, scheduling, totals, place-order button) into the mounts. The theme
skins them with CSS — the generated markup uses stable `mb-*` classes.

Further opt-in mounts: `data-mb-product-mount` (full product page instead of
the sheet), `data-mb-confirmed-mount` (in-app order-confirmed view),
`data-mb-account-mount` (customer account panel), `data-mb-favs-mount`
(saved items). Multi-view themes can declare a client-side router with
`data-mb-view="home|cart|product|confirmed|favs|profile"` sections plus
`data-mb-tabbar`/`data-mb-tab="..."` navigation controls (as crave does);
scroll-page themes that skip `data-mb-view` still work.

Catalog behaviour is also declarative: `data-mb-item`/`data-mb-name` on
cards enable `data-mb-search` and `data-mb-filter` (vegan, halal,
gluten-free, special, favourites), `data-mb-fav` toggles favourites,
`data-mb-sort` and `data-mb-layout-toggle` reorder and re-layout
server-rendered cards in place, `data-mb-set-lang` and `data-mb-currency`
switch language and display currency, and `data-mb-money="12.50"` marks
static prices for currency reformatting.

The cart persists in `localStorage` keyed by restaurant slug. A small escape
hatch is exposed as `window.MBCommerce` for debugging.

## Endpoints the runtime calls

All URLs arrive pre-built in `MB_COMMERCE.endpoints`; a theme never
constructs them. Route names from `routes/web.php`:

| Purpose | Method and path | Route name |
| --- | --- | --- |
| Place order | `POST /{store}/send-order` | `restaurant.submitOrder` |
| Apply coupon | `POST /{store}/coupon` | `restaurant.applyCoupon` |
| Call waiter | `POST /{store}/call-waiter` | `restaurant.callTheWaiter` |
| Order history lookup | `POST /{store}/order-lookup` | `restaurant.orderLookup` |
| Item view/add analytics | `POST /{store}/item-event` | `restaurant.recordItemEvent` |
| Login / register / logout | `POST /{store}/customer/...` | `customer.login`, `customer.register`, `customer.logout` |
| SMS OTP request / verify | `POST /{store}/customer/otp/...` | `customer.otp.request`, `customer.otp.verify` |
| Account, profile, addresses | `GET/POST /{store}/customer/...` | `customer.account`, `customer.profile.update`, `customer.addresses.store`, `customer.addresses.destroy` |
| Track order | `GET /order/track/{token}` | `restaurant.orderTrack` |

`submitOrder` uses the `restaurant-order` throttle, `callTheWaiter` uses
`restaurant-waiter`, and `applyCoupon`, `orderLookup` and `recordItemEvent`
share the `public-check` throttle. OTP request is 6/min and OTP verify is
10/min. Note `submitOrder` is keyed by restaurant id while `applyCoupon`,
`callTheWaiter` and `orderLookup` are keyed by slug. `recordItemEvent` is
present in the config but the v1 bundle does not call it — first-party Blade
themes use it directly.

## Order placement contract

The runtime POSTs to `restaurant.submitOrder` with the same field contract as
the first-party Blade themes. `StoreController@submitOrder` validates:

- `items` (required) — JSON array of `{id, quantity, variants, extras, mods}`.
  `variants` is a single variant id (or `0`); variant prices are absolute.
  `extras` is `[{id}]`; `mods` is `[{groupId, optIndex}]` for attached
  global option groups. Extras and mods are additive on top of the item or
  variant price. Items are re-fetched and re-priced server-side; unknown
  items, or items unavailable at the posted branch, reject the order.
- `name` (required), `ordering-type` (`on-table` | `takeaway` | `delivery`),
  `table` (required for `on-table`), `phone-number` (required unless
  `on-table`), `address` (required for `delivery`).
- `pay_via` (required) — the runtime always sends `pay_on_counter`. The
  controller also accepts `pay_online` (creates a transaction and returns the
  payment page URL in `message`), but online payment is not wired into runtime
  v1.
- Optional: `tip_amount`, `message` (notes), `scheduled_at`
  (`datetime-local`, only when scheduling is enabled), `coupon_code`,
  `branch_id` (forwarded from a `?branch_id=` query parameter; the server
  rejects orders for a branch that is closed or not accepting orders).

The JSON response is `{success, message, whatsapp_url, track_url}`. On
success the runtime clears the cart, opens `whatsapp_url` in a new tab when
WhatsApp ordering is configured, then shows the in-app confirmed view if the
theme mounted one (auto-forwarding to tracking) or redirects straight to
`track_url`. On failure `message` (HTML, validation errors joined with
`<br>`) is shown in the checkout form's error slot.

Client-side validation before the POST: an order type must be selected, the
subtotal must meet the configured minimum order, a name is required, and
when the restaurant's customer auth mode is `required` an unauthenticated
diner is blocked with a sign-in prompt.

## Live order tracking

Every order gets a random 32-character `track_token`; `track_url` resolves
to `GET /order/track/{token}` (`restaurant.orderTrack`, handled by
`Concerns\ManagesOrders@trackOrder`). As a normal request it renders a
tracking page; with `Accept: application/json` or as XHR the same URL returns
`{status, preparing_at, ready_at, completed_at, cancelled_at}` — the four
timestamps as ISO 8601 or `null`. The tracking page polls its own URL and
advances the status steps in place.

The tracking page is served by the platform, not the Liquid theme: the
server renders the restaurant's Blade template's `track` view when one
exists (`view()->exists('store_templates.<theme>.track')`), falling back to
the `aurora` track page otherwise. Marketplace theme packages have no track
template type, so diners on a marketplace theme always get the fallback
page. A theme-owned Liquid track template is not available in v1.

Guest order history uses the same tokens: `restaurant.orderLookup` takes an
`identifier` (phone or email) and returns recent orders each carrying its
`track_url`. The runtime's account panel wires this up for both guests and
logged-in diners, and auto-loads history for a logged-in customer using the
`customerPhone` from the config flags.

## Customer accounts

A `data-mb-account-mount` element gets the full auth surface: email/password
login, registration, SMS OTP login, logout, profile editing, saved delivery
addresses and order history — all rendered by the runtime against the
untouched `customer.*` controllers. Crave places the mount inside its
`data-mb-view="profile"` router tab. After a successful login, register or
logout the page reloads so
the server re-emits a fresh CSRF token and customer flags. Which tabs make
sense depends on the restaurant's customer auth mode (`guest`, optional, or
`required`), surfaced in `MB_COMMERCE.flags.authMode`.

## Related pages

- [Theme package structure](structure.md) — why themes cannot ship JS.
- [Custom Liquid tags](tags.md) — `{% commerce %}` reference.
- [Sections, snippets and templates](sections.md) — where the tag goes.
- [Liquid drops](drops.md) — read-only data for conditional markup.
- [Storefront API](../learn-storefront-api.md) — the separate REST API for
  external clients; themes do not use it.
