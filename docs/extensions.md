# Extension points

This page lists the parts of juwri that themes, plugins and integrations may build on. Together
they are the "Extension Interface" named in `EXTENSION-EXCEPTION.md`. An extension that uses only
what is listed here may be distributed under any licence (see that file for the exact terms).
Anything not listed here is internal and may change without notice.

## Themes

A theme renders a store's public storefront. Third parties publish themes to the
marketplace as a zip package of Liquid templates. Built-in themes ship with the
platform.

Themes never ship JavaScript. All cart, checkout and ordering behaviour comes
from the platform's commerce runtime. There are two files called `theme.json`:
the package manifest that sits at a package root, and the design schema that a
built-in theme ships. Both are described below.

### Required files

A theme is a zip whose entries follow a fixed layout:

```
theme.json                    manifest at the archive root (required)
preview.png                   optional listing image at the archive root
templates/index.json          entrypoint: ordered section instances
templates/index.liquid        entrypoint: single-file form (one of the two is required)
sections/<type>.liquid        one file per section
snippets/<name>.liquid        partials, included with {% render %}
layout/                       optional layout wrappers
config/settings_schema.json   theme-level settings groups
locales/<lang>.json           translation strings
assets/                       css and images, served statically
```

The package gate runs before extraction and rejects the whole package on any
failure:

- `theme.json` must be at the archive root.
- There must be one entrypoint: `templates/index.json` or
  `templates/index.liquid`.
- Allowed top-level directories: `templates`, `sections`, `snippets`, `assets`,
  `config`, `locales`, `layout`.
- Allowed root files: `theme.json`, `preview.png`.
- Maximum path depth: 3.
- Allowed file types only: `liquid`, `json`, `css`, `png`, `jpg`, `jpeg`,
  `webp`, `svg`, `woff2`. No `.php` anywhere, and no `.js`.
- Caps: 1000 entries, 10 MB uncompressed total, 2 MB per file.
- Rejected markup: `<script>`, inline `on*=` handlers, `javascript:` URLs and
  `<iframe>` in `.liquid` files; `<script>`, `<foreignObject>` and `on*=` in
  `.svg` files.
- Rejected paths: absolute paths, drive letters, `..` traversal, symlinks and
  null bytes.

### Pages and slots

- A theme builds the home page from the section instances in
  `templates/index.json`, in the order given by its `order` array. Each
  instance's `type` names `sections/<type>.liquid`.
- A template may declare at most 25 sections; a section may declare at most 50
  blocks. Exceeding either fails validation and the render.
- Item detail, cart and checkout are rendered by the commerce runtime into
  mounts the theme provides. The platform serves the order-tracking page. A
  marketplace theme package has no track template.
- The runtime mounts (the slots a theme opts into):
  - `data-mb-item-sheet-mount` — the option sheet
  - `data-mb-cart-mount` — the cart and the checkout form
  - `data-mb-product-mount` — a full product page instead of the sheet
  - `data-mb-confirmed-mount` — the in-app order-confirmed view
  - `data-mb-account-mount` — the customer account panel
  - `data-mb-favs-mount` — saved items
- Behaviour is bound by attribute. Core bindings: `data-mb-add` (open the
  option sheet), `data-mb-open-cart`, `data-mb-cart-count`, `data-mb-cart-total`
  and `data-mb-call-waiter`. Catalog and multi-view bindings include
  `data-mb-view`, `data-mb-tabbar`, `data-mb-tab`, `data-mb-search`,
  `data-mb-filter`, `data-mb-sort`, `data-mb-layout-toggle`, `data-mb-fav`,
  `data-mb-set-lang`, `data-mb-currency` and `data-mb-money`.

### Required behaviour

- Place `{% commerce %}` once in the layout. It emits the inline runtime config
  and the versioned runtime bundle, and it is idempotent: repeat uses emit
  nothing. The config is built server-side from values a template cannot reach,
  so a theme cannot forge the CSRF token, endpoints or prices.
- Ship no JavaScript. A `.js` file or a `<script>` body is rejected.
  Interactivity comes only from the runtime.
- Use only whitelisted Liquid drops, filters and tags. An unknown filter or tag
  is a validation error. Filters include `t`, `money`, `money_code`,
  `media_url`, `theme_asset` and the standard Liquid core filters.
- A section may end with one `{% schema %}` block. Its body must be a single
  valid JSON object, checked at upload. The block renders nothing.
- Escape user-controlled values with `| escape`. Item review author and body
  are already escaped server-side.
- Use logical CSS properties (`margin-inline-start`, `inset-inline-end`,
  `text-align: start`) so right-to-left locales work.
- Money is always a server-formatted value. The theme prints it and never adds
  anything up.
- `slug` and `version` are stable and safe to use as filesystem path segments.
  Versions are immutable: ship a change as a new version.

### The manifest (`theme.json`)

The manifest is valid JSON at the package root. The package gate reads these
fields:

| Field | Type | Required | Allowed values |
|---|---|---|---|
| `name` | string | yes | any non-empty string |
| `slug` | string | yes | starts with a letter or digit, then letters, digits or dashes (`^[a-z0-9][a-z0-9\-]*$`, case-insensitive) |
| `version` | string (an integer is accepted and coerced to a string) | yes | starts with a letter or digit, then letters, digits, dots or dashes (`^[a-z0-9][a-z0-9.\-]*$`, case-insensitive) |
| `library` | string (an integer is accepted and coerced to a string) | no | a section-library version such as `1` or `1.2` (`^[0-9]+(\.[0-9]+)*$`) that the platform actually ships |
| `commerce_runtime` | string | no | `v1` or `v2` |

- A missing or invalid `name`, `slug` or `version` fails the gate.
- `library` pins the theme to a platform-owned section library. The theme's own
  sections, snippets and locales win on a name clash. An unknown or unshipped
  version fails the gate.
- `commerce_runtime` opts the theme into a runtime bundle. When it is absent or
  outside the allow-list the platform default (`v1`) is used.
- Real manifests also carry keys the package gate does not validate, such as
  `author`, `description`, `min_platform_version`, `source`, and, for
  library-based themes, `category`, `vertical`, `recipe` and `tokens`.

A real manifest, copied verbatim:

```json
{
    "name": "Studio Bistro",
    "slug": "studio-bistro",
    "version": "1",
    "author": "juwri",
    "description": "A warm, photo-led restaurant storefront built from the Theme Studio section library: cover hero, stories, dietary filters, sticky categories and a full photo menu, with reviews, hours and map. Rearrange every section in Theme Studio.",
    "min_platform_version": "2.0",
    "source": "liquid",
    "library": "1",
    "commerce_runtime": "v2",
    "category": "fine_dining",
    "vertical": "food",
    "recipe": "restaurant/classic",
    "tokens": {
        "colors.primary": "#b3401f",
        "colors.background": "#fbf7f2",
        "colors.surface": "#ffffff",
        "colors.text": "#231a15",
        "colors.accent": "#8a5a00",
        "layout.radius": "round",
        "layout.density": "cozy",
        "fonts.pair": "jakarta"
    }
}
```

### The settings schema

Merchant settings are declared in two places: `config/settings_schema.json`
holds theme-level groups, and a section's `{% schema %}` block holds that
section's settings and blocks. Both use the same setting def shape.

```json
{
  "name": "Hero",
  "settings": [
    { "id": "heading", "type": "text", "label": "Custom heading", "default": "" },
    { "id": "accent",  "type": "color", "label": "Accent colour", "default": "#3fd463" }
  ],
  "blocks": [
    { "type": "item", "settings": [ { "id": "title", "type": "text", "label": "Title", "default": "" } ] }
  ]
}
```

`name` is the label shown in the customizer (it falls back to the section
type). `settings` is a list of defs: `id` is required; `type` selects the
control; `options` is required for a select. `blocks` is optional. A def only
contributes a value when it declares a `default`, until the merchant sets one.

Setting types:

| Type | Control | Value rule |
|---|---|---|
| `text` | text input | a string |
| `textarea` | multi-line box | a string, at most 5,000 characters |
| `color` | colour picker | `#` plus 3 to 8 hex digits |
| `checkbox` / `toggle` | toggle | a boolean |
| `select` | dropdown | one of the declared `options` (strict) |
| `image` | text input plus library picker | a string path or URL |
| `range` | slider | a number within `min`/`max`; may declare `step` and `unit` |
| `number` | number input | a number within `min`/`max` when declared |
| `image_picker` | thumbnail plus library/upload | a bare media filename (letters, digits, `.`, `_`, `-`), no path |
| `font_pair` | dropdown of self-hosted pairs | a pair id or `none` |
| `color_token` | dropdown of token names | one of `primary`, `background`, `surface`, `text`, `accent` |
| `richtext` | small editor | sanitized markup from an allow-list; output without `| escape` |
| `url` | link input | `http(s)` or site-relative; no `javascript:`, `data:`, `//host` |
| `category` / `branch` | dropdown over the store's own data | one row id |
| `item_list` | searchable checklist | up to 24 unique item ids |

An unknown type does not error; it is treated as `text`.

Settings are normalised server-side into one shape before the editor renders
them. Each setting has exactly one canonical key and saved values persist under
a versionless key, so a theme update keeps merchant values for settings whose
ids did not change.

### Built-in themes: the design schema (`theme.json`)

Built-in themes ship a second `theme.json` that declares the theme's
customizable design. The platform's own customizer reads it and a schema linter
enforces the rules below. A marketplace package does not use this file.

Top-level keys. Only these are allowed; any other top-level key is dropped:

| Key | Type | Rule |
|---|---|---|
| `version` | number | must be `2` |
| `texts` | object | must declare `localized` and its value must be `true` |
| `presets` | array | 2 to 5 presets |
| `settings` | array | groups; see below |
| `sections` | array | page sections; see below |

Settings. A group is either a literal group (`group`, optional `icon`, and a
`fields` list) or an include: `{"include": "<name>"}` with optional `group`,
`icon` and a `fields` map of per-field overrides keyed by field id. If a group
is an include, its shared field definitions are pulled in from a shared
fragment. The known include names are `tokens`, `splash`, `search` and
`share`. An override may change a field's default and label but never its id. A
field hidden by `visible_if` is still validated on save.

A field definition may carry: `id` (required), `type`, `label`, `default`,
`options`, `option_labels`, `option_previews`, `option_sublabels`, `min`,
`max`, `step`, `help`, `placeholder`, `role`, `unit` and `visible_if`.

- `type` defaults to `text`. Selects must declare `options` and a `default`,
  and the default must be among the options. When `option_labels` is present,
  its keys must equal `options` exactly.
- Colour fields (`type: "color"`, and the canonical `colors.*` and
  `colors_dark.*` fields) must have `#rrggbb` defaults whenever a default is
  given.
- `visible_if` references other field ids; each condition must hold (AND), and
  a condition value may be a list (OR within it).

The linter requires a canonical field set: `colors.primary`,
`colors.background`, `colors.surface`, `colors.text`, `colors.accent`, the same
five under `colors_dark.*`, `layout.radius`, `layout.density`, `fonts.pair`,
`scheme.mode`, `layout.card`, `layout.image_ratio`, `layout.category_nav`,
`layout.header`, `layout.desktop_cart`, `menu.show_calories`,
`menu.show_allergens`, `menu.show_descriptions`, and at least one `texts.*`
field. The `menu.show_*` fields must be `type: "toggle"`. `fonts.pair` must
default to a real font pair, never `none`.

Canonical option sets. The `options` of a canonical select must include these,
and the default must be one of them:

| Field | Options |
|---|---|
| `layout.radius` | `none`, `soft`, `round`, `pill` |
| `layout.density` | `compact`, `cozy`, `comfortable` |
| `scheme.mode` | `light`, `dark`, `auto` |
| `layout.card` | `list`, `grid`, `compact` |
| `layout.image_ratio` | `1:1`, `4:3`, `16:9`, `4:5`, `3:4` |
| `layout.category_nav` | `chips`, `tabs` |
| `layout.header` | `cover`, `compact` |
| `layout.desktop_cart` | `panel`, `drawer` |

Sections. Each section declares `id`, `label`, `default`, `toggleable` and
`orderable`; the last three are booleans. The required sections are `header`,
`order_type`, `category_nav`, `catalog`, `info`, `footer` and `cart_bar`: each
must default to on and must not be toggleable. `search` is required for large
catalogues; when it defaults off it must be toggleable. The sections `header`,
`announcement`, `order_type`, `search`, `category_nav`, `catalog`, `cart_bar`
and `footer` are not reorderable. Other recognised section ids include
`announcement`, `hero`, `stories`, `offers`, `featured`, `combos`, `loyalty`,
`reviews`, `reservations`, `custom_blocks`, `reorder`, `free_delivery`,
`fitment`, `otc_notice`, `age_gate` and `licence`.

Presets. Each preset has `id`, `label`, an optional `thumb`, and a `values`
object mapping field ids to values. Every preset sets all ten role colours
(`colors.*` and `colors_dark.*`) to `#rrggbb` and sets `scheme.mode`. Every
value key must be a known field id, and select values must be valid options.
The colour contrast and the preset-to-token-file drift are also checked.

Because `texts.localized` is `true`, a `texts.*` value may be a per-locale
map; the server falls back from the exact locale to the store's default
language to the theme default.

A real built-in design schema, copied verbatim:

```json
{
  "version": 2,
  "texts": {
    "localized": true
  },
  "presets": [
    {
      "id": "classic",
      "label": "Classic",
      "thumb": "assets/store_templates/table/presets/classic.png",
      "values": {
        "colors.primary": "#B4331A",
        "colors.background": "#FAF6F0",
        "colors.surface": "#FFFFFF",
        "colors.text": "#1F1A17",
        "colors.accent": "#E8A33D",
        "colors_dark.primary": "#F07A52",
        "colors_dark.background": "#141210",
        "colors_dark.surface": "#1E1B18",
        "colors_dark.text": "#F6F1EA",
        "colors_dark.accent": "#F2B65A",
        "scheme.mode": "light"
      }
```

This is the start of a real shipping theme's file, copied verbatim (the preset
shown is the "Classic" preset of the `table` theme). The real file continues
with the preset's closing brace, more presets, then its settings groups and its
sections.

## View-model data per page

Every storefront page renders one view-model object. The object is JSON and follows the
machine-readable view-model contract, so a theme can rely on the keys and types below.

The same top-level object is sent on every page. A page adds a `page` object that names its kind
(`page.kind`) and carries that page's payload. A page with no `page` key is the home page.

Money, totals and taxes are computed on the server. Every amount is a Money object with
`"minor"`, `"currency"` and `"formatted"` keys; a theme prints `formatted`. A theme never adds up
lines, applies tax or formats money itself.

A key marked `*` is always present. Keys without `*` are optional; an absent optional key means the
theme renders nothing for it.

### Common keys (every page)

| Key | Type | Meaning |
|---|---|---|
| `store` * | object | Store identity, branding, status, hours and contact details. |
| `branch` * | object | Current branch and any branches the customer can switch to. |
| `context` * | object | Order type, table or room, kiosk, offline queue and refund switches. |
| `theme` * | object | Theme slug, preset, resolved settings, enabled sections and texts. |
| `catalog` * | object | Categories and the item map. |
| `features` * | object | Optional feature blocks; an absent key means that section is not rendered. |
| `customer` * | object or null | Signed-in customer, or null. |
| `endpoints` * | object | Named server URLs only; a theme never builds URLs. |
| `money` * | object | Currency facts (code, symbol, exponent, symbol position) and tax facts. |
| `i18n` * | object | UI strings keyed by name. |

### Home (`page` key absent)

The home page sends the common keys only.

| Key | Type | Meaning |
|---|---|---|
| `store` * | object | Store identity and status. `store.store_type` is the configured vertical (for example `services`, `grocery`). |
| `branch` * | object | Current branch (`branch.current`) and switchable branches. |
| `context` * | object | Order types, selected order type, table or room, schedule-ahead flag and refund switches. |
| `theme` * | object | Theme slug, preset, resolved settings and enabled sections. |
| `catalog` * | object | `catalog.categories` (each with `id`, `name`, `item_ids`, `count`) and `catalog.items` (a map of ItemCards). |
| `features` * | object | Announcement, stories, offers, featured items, loyalty, reviews, custom blocks and other optional blocks. |
| `customer` * | object or null | Customer name, loyalty points, wallet balances, recent items and wishlist. |
| `endpoints` * | object | Named server URLs. |
| `money` * | object | `money.currency` and `money.tax`. |
| `i18n` * | object | UI strings. |

Verbatim excerpt (from the `book` golden fixture — `core/tests/Fixtures/viewmodel/book.en.json`, a `services`-type store):

```json
"timezone": "Asia/Riyadh",
"vertical_set": "service",
"store_type": "services",
"status": {
    "open": true,
    "label": "Open · closes 9:00 PM",
    "next_change_at": "2026-10-04T21:00:00+03:00",
    "paused_reason": null
},
```

### Item (`page.kind` = `item`, payload `page.item`)

The item sheet. `page.item` holds the item, its choices and its server-formatted prices.

| Key | Type | Meaning |
|---|---|---|
| `id` * | string | Item id. |
| `name` * | string | Item name. |
| `brand` | string | Brand line. |
| `description` | string | Plain-text description. |
| `gallery` | array of Image | Item images. |
| `price` | Money | Item price. |
| `from_price` | Money | The item's "from" price, when it has one. |
| `compare_at` | Money | Struck-through was-price. |
| `saving_label` | string | Server-formatted saving text. |
| `base_label` | string | Server-formatted base line, for example `From 45 min · from SAR 120.00`. |
| `badges` | array of object | Badge objects (`kind`, `label`). |
| `option_groups` * | array of OptionGroup | Choices that change price or duration. |
| `quantity` | object | Default quantity (`min`, `max`, `step`, `value`). |
| `unit` | object | Unit and per-unit price information. |
| `stock_note` | string | Stock note. |
| `estimate_note` | string | Weighed items: estimate and cap text. |
| `nutrition` | array of object | Nutrition rows (`label`, `value`). |
| `details` | array of Disclosure | Extra disclosures (`title`, core-sanitised `html`). |
| `info_rows` | array of InfoRow | Info rows (delivery date, returns, pick up in store, policy). |
| `bnpl_label` | string | Buy-now-pay-later label. |
| `policy_note` | string | Policy note. |
| `deposit` | Money | Deposit amount. |
| `size_guide_url` | string | Size-guide URL. |
| `related` | array of string | Related item ids. |
| `add_label` * | string | Server-formatted add-button label for the default choice; the JS re-quotes on change. |
| `sku` | string | Stock-keeping unit. |
| `fits_vehicle` | boolean or null | Fitment: true or false; null means no fitments were entered; absent when no vehicle is chosen. |
| `gtin` | string | Global trade item number. |
| `category_url` | string | Category page URL. |
| `variants` | array of object | Per-variant sku, price, stock and image. |
| `medicine` | object | Medicine facts, all plain text. |
| `warnings` | array of string | Plain-text warning bullets (maximum 12, each up to 200 characters). |
| `leaflet` | object or null | Patient information leaflet; null means the theme shows the static "read the leaflet in the pack" text. |
| `use_by_label` | string or null | Deprecated; themes ignore it. |
| `medicine_text_lang` | string | Language code of the medicine text when it differs from the page locale; absent means the same language. |
| `age` | object or null | Age-restricted item; the confirmed age travels with the cart line. |
| `promo_allowed` | boolean | When false, the theme shows no promotional treatment for this item. |

Verbatim excerpt (from the `book` golden fixture — `core/tests/Fixtures/viewmodel/book.en.json`, a `services`-type store):

```json
"name": "Haircut & blow-dry",
"description": "A consultation, cut and finished blow-dry.",
"gallery": [
    {
        "src": "{app}/storage/menu/{file:1}",
        "alt": "Haircut & blow-dry"
    }
],
```
### Cart (`page.kind` = `cart`, payload `page.cart`)

The order quote shown on the cart. `page.cart` holds the lines, the money rows and the total.

| Key | Type | Meaning |
|---|---|---|
| `lines` * | array of QuoteLine | Order lines. |
| `rows` * | array of QuoteRow | Money rows: subtotal, discount, fee, delivery, tax, tip, deposit, due later, estimate cap and total. |
| `total` * | Money | Server-computed order total. |
| `total_label` | string | Label for the total. |
| `tax_note` | string | Tax note. |
| `notes` | array of string | Additional notes. |
| `coupon` | object | Applied coupon. |
| `free_delivery` | object | Free-delivery progress. |
| `substitution_preference` | string | Substitution preference for the order. |
| `cta_label` | string | Server-formatted call-to-action label. |

Verbatim excerpt (from the `book` golden fixture — `core/tests/Fixtures/viewmodel/book.en.json`, a `services`-type store):

```json
"rows": [
    {
        "key": "subtotal",
        "label": "Subtotal",
        "amount": {
            "minor": 52000,
            "currency": "SAR",
            "formatted": "⁨⃁⁩ 520.00"
        },
        "kind": "subtotal"
    },
```

### Checkout (`page.kind` = `checkout`, payload `page.checkout`)

The checkout step. `page.checkout` holds the choices, the fields and the quote. Booking flows reuse
the same kind for their time step and reschedule step.

| Key | Type | Meaning |
|---|---|---|
| `step_label` | string | Step label, for example `Step 2 of 3`. |
| `summary_rows` | array of InfoRow | Summary rows. |
| `address` | InfoRow | Delivery address row. |
| `staff` | array of Staff | Staff the customer can choose. |
| `selected_staff` | string | Chosen staff id. |
| `slots` | SlotPicker | Time-slot picker. |
| `delivery_methods` | array of Choice | Delivery choices. |
| `payment_methods` * | array of Choice | Payment choices. |
| `tip` | object | Tip options. |
| `substitution` | OptionGroup | The store's "if it is out of stock" choice for the whole order. |
| `fields` | array of object | Form fields. |
| `hold_note` | string | Hold note. |
| `policy_note` | string | Policy note. |
| `quote` * | Quote | The order quote. |
| `place_label` * | string | Server-formatted place-order label. |
| `messages` | array of object | What the last step answered (refused or done). |
| `form` | object | Where the step posts, where it refreshes and goes back, and the hidden values it carries. |

Verbatim excerpt (from the `book` golden fixture — `core/tests/Fixtures/viewmodel/book.en.json`, a `services`-type store):

```json
"slots": {
    "title": "October 2026",
    "days": [
        {
            "id": "2026-10-04",
            "label": "Sun",
            "sub": "4",
            "state": "selected"
        },
        {
            "id": "2026-10-05",
            "label": "Mon",
            "sub": "5",
            "state": "available"
        },
```

### Track (`page.kind` = `track`, payload `page.order`)

The order-tracking page. `page.order` holds the status, the steps and the quote.

| Key | Type | Meaning |
|---|---|---|
| `number` * | string | Order number. |
| `status` * | string | Status key. |
| `status_label` * | string | Server-formatted status label. |
| `eta_label` | string | ETA label. |
| `heading` | string | Page heading. |
| `ticket` | object | Ticket data. |
| `steps` * | array of object | Progress steps. |
| `carrier` | object | Carrier data. |
| `location` | InfoRow | Location row. |
| `review` | array of QuoteLine | Replacement lines to review. |
| `quote` * | Quote | Order quote. |
| `paid_label` | string | Server-formatted paid label. |
| `actions` | array of object | Available actions. |
| `messages` | array of object | What the last step answered (refused or done). |
| `policy_note` | string | Cancellation or change rule for this booking. |

Verbatim excerpt (from the `book` golden fixture — `core/tests/Fixtures/viewmodel/book.en.json`, a `services`-type store):

```json
"order": {
    "number": "{order_no}",
    "status": "new",
    "status_label": "Order placed",
    "heading": "Order no. {order_no}",
    "steps": [
        {
            "label": "Booked",
            "sub": "12:00 PM",
            "state": "current"
        },
        {
            "label": "Confirmed",
            "state": "todo"
        },
```

### State overlays (no `page` key)

Some pages override the shared keys instead of adding a `page` payload.

| Page | Keys | Meaning |
|---|---|---|
| Kiosk | `context`, `endpoints` | Carries `context.kiosk` (on, idle seconds, allowed order types, print settings) and the kiosk endpoints. |
| Closed | `store` | Carries `store.status` with `"open": false`, a paused label and a `paused_reason`. |

Verbatim excerpt (closed overlay, from the `book` golden fixture — `core/tests/Fixtures/viewmodel/book.en.json`, a `services`-type store):

```json
"status": {
    "open": false,
    "label": "Ordering is paused",
    "next_change_at": "2026-10-04T21:00:00+03:00",
    "paused_reason": "Ordering is paused"
},
```

### Shared blocks

These objects appear inside the pages above.

**Money**

| Key | Type | Meaning |
|---|---|---|
| `minor` * | integer | Amount in minor units. |
| `currency` * | string | Currency code. |
| `formatted` * | string | Server-formatted display string; the theme prints this. |

**Image**

| Key | Type | Meaning |
|---|---|---|
| `src` * | string | Image URL. |
| `srcset` | string | Responsive source set. |
| `w` | integer | Width. |
| `h` | integer | Height. |
| `alt` * | string | Alternative text. |

**Link**

| Key | Type | Meaning |
|---|---|---|
| `label` * | string | Link text. |
| `url` * | string | Link URL. |

**Staff**

| Key | Type | Meaning |
|---|---|---|
| `id` * | string | Staff id. |
| `name` * | string | Staff name. |
| `role` | string | Staff role. |
| `photo` | Image | Staff photo. |
| `service_ids` | array of string | Ids of the services this staff member provides. |

**Facet**

| Key | Type | Meaning |
|---|---|---|
| `id` * | string | Facet id. |
| `label` * | string | Facet label. |
| `kind` * | string | One of `checkbox`, `size`, `swatch`, `range`, `toggle`. |
| `options` * | array of object | Facet options. |

**ItemCard** (an entry in `catalog.items`)

| Key | Type | Meaning |
|---|---|---|
| `id` * | string | Item id; equals its key in `catalog.items`. |
| `name` * | string | Item name. |
| `short` | string | Short description. |
| `url` * | string | Item sheet or product page URL. |
| `image` | Image | Card image. |
| `price` | Money | Item price. |
| `from_price` | Money | "From" price. |
| `compare_at` | Money | Struck-through was-price. |
| `badges` * | array of object | Badge objects (`kind`, `label`). |
| `sold_out` * | boolean | Item is sold out. |
| `has_required_choices` * | boolean | Item has required choices. |
| `calories` | integer | Calories. |
| `allergens` | array of string | Allergens. |
| `brand` | string | Brand line. |
| `swatches` | array of object | Colour swatches (at most three shown, plus a count). |
| `unit` | object | Unit and per-unit price information. |
| `duration_min` | integer | Duration in minutes (bookings). |
| `meta_label` | string | Server-formatted duration and price line, for example `45 min · SAR 120.00`. |
| `cart_qty` | integer | In-cart quantity for the stepper. |
| `fits_vehicle` | boolean or null | Fitment; null means unknown. |
| `sku` | string | Stock-keeping unit. |
| `gtin` | string | Global trade item number. |
| `category_url` | string | Category URL. |
| `age_restricted` | boolean | Age-restricted item; the server decides, the theme never infers it. |
| `pack_label` | string | Server-formatted pack line. |
| `promo_allowed` | boolean | When false, the theme shows no promotional treatment for the item. |
| `expiring_soon` | boolean | Deprecated; themes ignore it. |
| `venue` | boolean | Can be done at the venue (bookings). |
| `at_home` | boolean | Can be done at the customer's address (bookings). |

**Option**

| Key | Type | Meaning |
|---|---|---|
| `id` * | string | Option id. |
| `label` * | string | Option label. |
| `sub_label` | string | Secondary label. |
| `price_delta` | Money | Price change. |
| `duration_delta_min` | integer | Duration change in minutes. |
| `swatch` | string | Swatch value. |
| `selected` | boolean | Option is selected. |
| `available` | boolean | Option is available. |
| `stock_label` | string | Stock label. |

**OptionGroup**

| Key | Type | Meaning |
|---|---|---|
| `id` * | string | Group id. |
| `label` * | string | Group label. |
| `kind` * | string | One of `radio`, `checkbox`, `segmented`, `chips`, `swatch`, `size`, `weight`, `quantity`. |
| `purpose` | string | `substitution` marks the store's "if it is out of stock" choice. |
| `required` * | boolean | A choice is required. |
| `min` | integer | Minimum number of choices. |
| `max` | integer | Maximum number of choices. |
| `rule_label` | string | Server-formatted rule line, for example `Optional · Choose up to 2`. |
| `selected_label` | string | Label of the current selection. |
| `help_url` | string | Help URL. |
| `options` * | array of Option | The choices. |

**Disclosure**

| Key | Type | Meaning |
|---|---|---|
| `title` * | string | Disclosure title. |
| `html` * | string | Core-sanitised HTML. |

**InfoRow**

| Key | Type | Meaning |
|---|---|---|
| `icon` | string | Icon name. |
| `label` * | string | Row label. |
| `sub` | string | Secondary text. |
| `url` | string | Row URL. |

**QuoteLine**

| Key | Type | Meaning |
|---|---|---|
| `id` * | string | Line id. |
| `item_id` | string | Item id. |
| `name` * | string | Item name. |
| `brand` | string | Brand line. |
| `thumb` | Image | Line thumbnail. |
| `choices_label` | string | Chosen options summary. |
| `qty` * | integer | Quantity. |
| `unit_label` | string | Unit label. |
| `line_total` * | Money | Server-computed line total. |
| `compare_at` | Money | Struck-through was-price. |
| `estimate` | boolean | Line total is an estimate. |
| `note` | string | Note. |
| `warning` | string | Warning. |
| `substitution` | string | Substitution applied to the line. |
| `age_confirmed` | boolean | Shows `18+ confirmed` on the line. |
| `limit_note` | string | Server correction text, for example `Limit 2 per order. We changed the quantity to 2`. |

**QuoteRow**

| Key | Type | Meaning |
|---|---|---|
| `key` * | string | Row key. |
| `label` * | string | Row label. |
| `amount` * | Money | Row amount; discounts are negative. |
| `kind` | string | One of `subtotal`, `discount`, `fee`, `delivery`, `tax`, `tip`, `deposit`, `due_later`, `estimate_cap`, `total`. |
| `muted` | boolean | Render the row muted. |
| `amount_label` | string | Display word instead of the amount, for example `Free` for a zero delivery row; the amount stays real Money. |
| `informational` | boolean | Shown but not summed into the total: savings, inclusive tax, estimate caps and deposit or due-later splits. |

**Slot**

| Key | Type | Meaning |
|---|---|---|
| `id` * | string | Slot id. |
| `label` * | string | Slot label. |
| `sub` | string | Secondary text. |
| `state` * | string | One of `available`, `selected`, `few`, `full`, `closed`. |

**SlotPicker**

| Key | Type | Meaning |
|---|---|---|
| `title` | string | Picker title. |
| `days` | array of Slot | Days to choose from. |
| `groups` * | array of object | Time groups. |
| `note` | string | Note. |
| `timezone_note` | string | Timezone note. |
| `selection_label` | string | Label for the current selection. |
| `express` | Choice | Express option. |

**Choice**

| Key | Type | Meaning |
|---|---|---|
| `id` * | string | Choice id. |
| `label` * | string | Choice label. |
| `sub` | string | Secondary text. |
| `amount` | Money | Amount. |
| `amount_label` | string | Amount label. |
| `selected` | boolean | Choice is selected. |
| `available` | boolean | Choice is available. |
| `icon` | string | Icon name. |
| `kind` | string | Choice kind. |


## Module hooks

A hook is a named place in juwri that a theme, module or add-on fills. The core fires the slot; every hook registered on it runs in registration order. With no hook registered the slot is empty, and the app behaves as if that add-on is not installed. A slot name is a plain string; the string you register on is the string the core fires.

`Juwri\Core\Support\Hooks` is the whole interface. A slot is best-effort by default: an exception thrown by a hook is swallowed and never reaches the caller, so a broken add-on cannot break the page or request that fired the slot. A few slots deliberately let exceptions through; the tables below say which.

### The API

| Method | What it does |
|---|---|
| `Hooks::on($slot, $name, $hook)` | Register `$hook` in `$slot` under `$name`. Registering the same name again replaces the earlier hook. Call it from a service provider. |
| `Hooks::has($slot)` | `true` when at least one hook is registered in the slot. Use it to skip work the slot would not change. |
| `Hooks::run($slot, ...$args)` | Run every hook in registration order. Return values are ignored. An exception in one hook is swallowed and the remaining hooks still run. |
| `Hooks::first($slot, ...$args)` | Run the hooks and return the first answer that is not `null`, or `null` when none answers. An exception in one hook is swallowed and the next is tried. |
| `Hooks::call($slot, ...$args)` | Run every hook in order. Nothing is caught: the first exception thrown reaches the caller. Use it for work that may fail its caller. |
| `Hooks::answer($slot, ...$args)` | Return the first hook's answer as-is, or `null` when the slot is empty. Nothing is caught. Use it when one add-on owns the slot and its answer must be returned unchanged. |

### Registering a hook

Register from a service provider. This is a real example, copied from the Verticals module provider:

```php
use Juwri\Core\Support\Hooks;

public function register(): void
{
    // The kiosk's idle return clears the goods cart of the device's session.
    Hooks::on('kiosk.session-reset', 'verticals', fn () => app(GoodsCartService::class)->forgetToken());
}
```

The name (`'verticals'`) identifies your hook inside its slot. Use a unique name; two hooks cannot share one name in the same slot.

### Published slots

A slot is published when the core fires it. Published slots are stable: the slot name and its arguments do not change without notice. The **Filled by** column names the module that answers the slot in the shipped edition; your own hook may answer the same slot alongside it. **Exceptions reach caller** says whether a thrown exception reaches the caller that fired the slot.

| Slot | Arguments (types) | When it runs | Return expected | Exceptions reach caller | Fired by | Filled by |
|---|---|---|---|---|---|---|
| `order.created` | `App\Models\Order $order` | In the order model's `created` event: right after any order row is created, on every creation path (storefront, POS, kiosk, API, aggregators). | None; the return is ignored. | No (best-effort) | Core | An Enterprise billing module |
| `order.status-changed.shopper` | `App\Models\Order $order` | In the order model's `updated` event, and only when `status` or `delivery_status` actually changed. | None; the return is ignored. | No (best-effort) | Core | An Enterprise marketplace module |
| `order.status-changed.delivery-whatsapp` | `App\Models\Order $order` | Same event and condition as `order.status-changed.shopper`. | None; the return is ignored. | No (best-effort) | Core | An Enterprise marketplace module |
| `order.status-changed.channels` | `App\Models\Order $order` | Same event and condition as `order.status-changed.shopper`. | None; the return is ignored. | No (best-effort) | Core | Channels module |
| `order.channel-label` | `string $source` (the order's stored source) | When `Order::channelLabel()` asks for the human name of the channel an order arrived through. | `?string` — the channel's display name, or `null` when there is none. | No (best-effort) | Core | Channels module |
| `store.public-listing` | `Illuminate\Database\Eloquent\Builder $query` | While a public store listing is built (`Store::scopeMkApprovedFor`), before the rows are returned. | None; the hook narrows the query in place. | Yes | Core | Pharmacy module |
| `menu.item-availability-changed` | `int $storeId`, `int $menuId`, `bool $available` | In the menu item model's `updated` event, and only when the sold-out flag actually changed (the 86 toggle). | None; the return is ignored. | No (best-effort) | Core | Channels module |
| `analytics.peer-benchmark.dashboard` | `string $cohort`, `string $currency` | While the owner dashboard's peer-benchmark card is rendered, only when the owner opted in and an AI assistant is available. | `?array` — a cohort row, or `null`. | No (best-effort) | Core | An Enterprise analytics module |
| `analytics.branch-comparison.dashboard` | `int $storeId`, `DateTimeInterface $start`, `DateTimeInterface $end` | While the owner dashboard is rendered, when a single store is active. | `?array` — `['view' => name, 'rows' => list]`, or `null`. | Yes | Core | An Enterprise analytics module |
| `platform.admin.merchant-revenue` | none | While the admin dashboard is rendered. | `?array` — the recurring-revenue tiles, or `null`. | Yes | Core | An Enterprise platform module |
| `platform.admin.analytics-teaser` | none | While the admin dashboard is rendered. | `?array` — the analytics teaser, or `null`. | Yes | Core | An Enterprise platform module |
| `platform.announcements.live-count` | none | While the admin dashboard's operations tiles are built. | A number — the live count, or `null` when there is no answer. | No (best-effort) | Core | An Enterprise platform module |
| `reseller.admin.active-count` | none | While the admin dashboard's operations tiles are built. | A number — the active count, or `null` when there is no answer. | No (best-effort) | Core | An Enterprise reseller module |
| `billing.admin.account-plan.update` | `Illuminate\Http\Request $request`, `App\Models\User $user` | On the admin action that changes an account's plan. | `Illuminate\Http\RedirectResponse` — anything else answers 404. | Yes | Core | An Enterprise billing module |
| `billing.admin.subscription.pause` | `App\Models\User $user` | On the admin action that pauses a subscription. | `Illuminate\Http\RedirectResponse` — anything else answers 404. | Yes | Core | An Enterprise billing module |
| `billing.admin.subscription.resume` | `App\Models\User $user` | On the admin action that resumes a subscription. | `Illuminate\Http\RedirectResponse` — anything else answers 404. | Yes | Core | An Enterprise billing module |
| `billing.admin.transaction.refund` | `Juwri\Modules\Payments\Models\Transaction $transaction` | On the admin refund action, after the admin is authorized. | `Illuminate\Http\RedirectResponse` — anything else answers 404. | Yes | Core | An Enterprise billing module |
| `billing.admin.transaction.refundable` | `Juwri\Modules\Payments\Models\Transaction $transaction` | When the admin transactions list decides whether to show the refund control. | `bool` — exactly `true` shows the control. | Yes | Core | An Enterprise billing module |
### Internal slots

These slots are fired by a bundled module, not by the core. They are the modules' own wiring and may change without notice. Do not write an extension against them.

| Slot | Arguments (types) | When it runs | Return expected | Exceptions reach caller | Fired by | Filled by |
|---|---|---|---|---|---|---|
| `order.paid.pos-push` | `App\Models\Order $order` | From the paid-order outbox handler, once per paid store order. | None; the return is ignored. | Yes | Ordering module | Channels module |
| `cron.hourly` | none | At the end of the `cron:hourly` console command. | None; the return is ignored. | Yes | Payments module | An Enterprise billing module |
| `payment.mor.refund` | `string $gateway`, `?string $paymentId` | When a merchant-of-record gateway confirms a refund (a gateway webhook or an in-app store refund). | None; the return is ignored. | Yes | Payments module | An Enterprise billing module |
| `payment.mor.cancel-at-period-end` | `string $gateway`, `?string $subscriptionId` | When a subscription is scheduled to cancel at the end of its period. | None; the return is ignored. | Yes | Payments module | An Enterprise billing module |
| `payment.mor.cancel` | `string $gateway`, `?string $subscriptionId` | When a subscription is expired or revoked immediately. | None; the return is ignored. | Yes | Payments module | An Enterprise billing module |
| `payment.mor.past-due` | `string $gateway`, `?string $subscriptionId` | When a renewal fails and the subscription moves to grace / past due. | None; the return is ignored. | Yes | Payments module | An Enterprise billing module |
| `kiosk.start-view` | `Juwri\Core\Models\Store $store`, `Illuminate\Http\Request $request` | When the kiosk start screen is requested. | `?Illuminate\Contracts\View\View` — the themed start screen, or `null` for the built-in screen. | No (best-effort) | Pos module | Storefront module |
| `kiosk.session-reset` | `int $storeId` | When the kiosk idle return runs, or when the staff exit PIN is accepted. | None; the return is ignored. | No (best-effort) | Pos module | Verticals module |
| `storefront.offline-queue` | `Juwri\Core\Models\Store $store` | When the storefront checks whether a store may queue an order while offline. | `?bool` — `false` switches the queue off; `null` or anything else leaves it on. | No (best-effort) | Storefront module | An Enterprise reseller module |
| `analytics.peer-benchmark.published` | `string $cohort`, `string $currency` | When the analytics insights page builds its benchmark card. | `?array` — `['median_aov' => float, 'cohort_n' => int]`, or `null`. | No (best-effort) | Analytics module | An Enterprise analytics module |

## Public HTTP APIs

juwri exposes three public HTTP surfaces for themes, extensions and integrations:

1. **REST API v1** at `/api/v1` — a token-authenticated merchant API over the owner's stores, menus, orders, customers and more.
2. **Outgoing webhooks** — HTTP POST callbacks juwri sends to a URL you register when events happen in a store.
3. **Headless storefront API** at `/api/v1/storefront` — a storefront-token API that serves a store's catalog, quotes, orders, slots and bookings to a theme or app.

A fourth, first-party set of app APIs (`/api/v1/app`, `/api/v1/customer`, `/api/v1/staff`, `/api/v1/driver`) is described at the end for completeness; those are used by juwri's own apps.

Every response is JSON. The API is served under the site root, so paths are absolute (`/api/v1/...`).

### REST API v1

#### Discovery and specification

Two meta endpoints describe the surface. Neither needs authentication:

| Method + path | Returns |
|---|---|
| `GET /api/v1` | A JSON index of every `api.v1.*` route, grouped by name segment |
| `GET /api/v1/openapi.json` | An OpenAPI 3.1 document generated from the live router |

The root response has this shape (fields copied from the route handler):

```json
{
  "name": "… API",
  "version": "v1",
  "base_url": "https://example.test/api/v1",
  "authentication": "Send your token as: Authorization: Bearer <token>",
  "docs": "https://example.test/developers",
  "openapi": "https://example.test/api/v1/openapi.json",
  "endpoints": { "restaurants": ["GET    /api/v1/stores"] }
}
```

`GET /api/v1/openapi.json` is built from the router, so a new `api.v1.*` route appears in it automatically. Its `servers` entry is the site root, its path keys are absolute (`/api/v1/...`), and it declares three security schemes:

| Scheme | Type | Sent as |
|---|---|---|
| `ApiToken` | `http` bearer | `Authorization: Bearer <token>` |
| `StorefrontToken` | API key in header | `X-Storefront-Token: <token>` |
| `Passport` | `oauth2` (authorization code) | OAuth 2.1; authorize at `/oauth/authorize`, token at `/oauth/token` |

The generated document carries each path and method with an `operationId`, a `summary`, `tags`, a generic `200`/`4XX` `responses` block, a `security` array and any path `parameters` — but it does not describe per-field request or response schemas. The detailed, field-level contract for the storefront surface is the hand-written `tools/themes/kit/api/openapi.json` (see the headless storefront section below).

#### Authentication

Send an owner API token as a bearer token:

```
Authorization: Bearer <token>
```

A token may also be passed as `?api_token=<token>` while the query fallback is enabled, but that path is deprecated: it logs a warning and answers with `Deprecation: true`, `Sunset: Thu, 31 Dec 2026 23:59:59 GMT` and `Link: <https://example.test/developers>; rel="deprecation"` (the owner API sends an absolute URL; the storefront deprecated aliases use a relative `</developers>`).

Tokens are hashed at rest and looked up by `sha256`. Failures return the standard error envelope:

| Status | `code` | When |
|---|---|---|
| 401 | `unauthenticated` | no token, or the token is invalid or expired |
| 403 | `forbidden` | the token lacks the required ability |
| 403 | `account_blocked` | the owner account is blocked (suspended or closed) |
| 423 | `http_error` | the account is on a temporary abuse hold and this is a write |

#### Abilities

A token's abilities are `resource:action`. The resources are `restaurants`, `menu`, `orders`, `customers`, `analytics`, `drivers` and `webhooks`. The selectable abilities are the coarse `read` and `write`, plus `{resource}:read` and `{resource}:write` for each resource.

Coverage rules:

- `*` or an exact match grants the ability.
- A `write` ability also grants the matching `read`.
- A `*` resource grants that action on any resource.
- A legacy `read` token still reads every resource, and `write` writes everything.

The identity endpoint `GET /api/v1/me` accepts any valid token regardless of ability.

#### Owner API resource groups

These routes are the merchant API. Each table column is the route's method and path, the ability it requires, and its route name. All are under the `/api/v1` prefix and the `owner-api` rate limit.

Identity and restaurants:

| Method + path | Ability | Route name |
|---|---|---|
| `GET /api/v1/me` | any valid token | `api.v1.me` |
| `GET /api/v1/stores` | `restaurants:read` | `api.v1.restaurants.index` |
| `GET /api/v1/stores/{id}` | `restaurants:read` | `api.v1.restaurants.show` |
| `GET /api/v1/stores/{id}/menu` | `menu:read` | `api.v1.restaurants.menu` |

Menu management:

| Method + path | Ability | Route name |
|---|---|---|
| `POST /api/v1/stores/{id}/menu/categories` | `menu:write` | `api.v1.menu.categories.store` |
| `PUT /api/v1/stores/{id}/menu/categories/{categoryId}` | `menu:write` | `api.v1.menu.categories.update` |
| `DELETE /api/v1/stores/{id}/menu/categories/{categoryId}` | `menu:write` | `api.v1.menu.categories.delete` |
| `POST /api/v1/stores/{id}/menu/items` | `menu:write` | `api.v1.menu.items.store` |
| `PUT /api/v1/stores/{id}/menu/items/{itemId}` | `menu:write` | `api.v1.menu.items.update` |
| `DELETE /api/v1/stores/{id}/menu/items/{itemId}` | `menu:write` | `api.v1.menu.items.delete` |
| `PATCH /api/v1/stores/{id}/menu/items/{itemId}/sold-out` | `menu:write` | `api.v1.menu.items.soldout` |

Orders and reservations:

| Method + path | Ability | Route name |
|---|---|---|
| `GET /api/v1/stores/{id}/orders` | `orders:read` | `api.v1.restaurants.orders` |
| `POST /api/v1/stores/{id}/orders` | `orders:write` | `api.v1.orders.store` |
| `GET /api/v1/stores/{id}/orders/{orderId}` | `orders:read` | `api.v1.orders.show` |
| `PUT /api/v1/stores/{id}/orders/{orderId}/status` | `orders:write` | `api.v1.restaurants.orders.status` |
| `GET /api/v1/stores/{id}/reservations` | `orders:read` + `feature:reservations` | `api.v1.restaurants.reservations` |

Brands, variants, shipments and fitments. Each also requires the `feature:multi_vertical` entitlement:

| Method + path | Ability | Route name |
|---|---|---|
| `GET /api/v1/stores/{id}/brands` | `menu:read` | `api.v1.brands.index` |
| `GET /api/v1/stores/{id}/brands/{brandId}` | `menu:read` | `api.v1.brands.show` |
| `GET /api/v1/stores/{id}/menu/items/{itemId}/variants` | `menu:read` | `api.v1.variants.index` |
| `GET /api/v1/stores/{id}/menu/items/{itemId}/variants/{variantId}` | `menu:read` | `api.v1.variants.show` |
| `POST /api/v1/stores/{id}/menu/items/{itemId}/variants` | `menu:write` | `api.v1.variants.store` |
| `PUT /api/v1/stores/{id}/menu/items/{itemId}/variants/{variantId}` | `menu:write` | `api.v1.variants.update` |
| `DELETE /api/v1/stores/{id}/menu/items/{itemId}/variants/{variantId}` | `menu:write` | `api.v1.variants.destroy` |
| `GET /api/v1/stores/{id}/shipments` | `orders:read` | `api.v1.shipments.index` |
| `GET /api/v1/stores/{id}/orders/{orderId}/shipment` | `orders:read` | `api.v1.shipments.show` |
| `GET /api/v1/stores/{id}/fitments` | `menu:read` | `api.v1.fitments.index` |
| `GET /api/v1/stores/{id}/fitments/{fitmentId}` | `menu:read` | `api.v1.fitments.show` |

Customers, guests and analytics. The customer and guest routes also require the `feature:crm_suite` entitlement:

| Method + path | Ability | Route name |
|---|---|---|
| `GET /api/v1/stores/{id}/customers` | `customers:read` + `feature:crm_suite` | `api.v1.customers.index` |
| `POST /api/v1/stores/{id}/customers` | `customers:write` + `feature:crm_suite` | `api.v1.customers.store` |
| `GET /api/v1/stores/{id}/guests` | `customers:read` + `feature:crm_suite` | `api.v1.guests.index` |
| `GET /api/v1/stores/{id}/guests/segments` | `customers:read` + `feature:crm_suite` | `api.v1.guests.segments` |
| `GET /api/v1/stores/{id}/analytics` | `analytics:read` | `api.v1.analytics.summary` |

Drivers and delivery. The driver routes also require the `feature:driver_app` entitlement:

| Method + path | Ability | Route name |
|---|---|---|
| `GET /api/v1/drivers` | `drivers:read` | `api.v1.drivers.index` |
| `POST /api/v1/drivers` | `drivers:write` | `api.v1.drivers.store` |
| `PUT /api/v1/drivers/{id}` | `drivers:write` | `api.v1.drivers.update` |
| `DELETE /api/v1/drivers/{id}` | `drivers:write` | `api.v1.drivers.destroy` |
| `POST /api/v1/drivers/{id}/rotate-token` | `drivers:write` | `api.v1.drivers.rotate` |
| `GET /api/v1/stores/{id}/deliveries` | `orders:read` | `api.v1.deliveries.index` |
| `POST /api/v1/stores/{id}/orders/{orderId}/assign` | `orders:write` | `api.v1.orders.assign` |
| `PUT /api/v1/stores/{id}/orders/{orderId}/delivery-status` | `orders:write` | `api.v1.orders.delivery` |

Webhook endpoint management:

| Method + path | Ability | Route name |
|---|---|---|
| `GET /api/v1/webhook-endpoints` | `webhooks:read` | `api.v1.webhooks.index` |
| `POST /api/v1/webhook-endpoints` | `webhooks:write` | `api.v1.webhooks.store` |
| `DELETE /api/v1/webhook-endpoints/{id}` | `webhooks:write` | `api.v1.webhooks.destroy` |

#### Error format

Every API error uses one envelope:

```json
{ "message": "human-readable text", "code": "machine_code", "errors": { "field": ["…"] }, "details": {} }
```

`message` and `code` are always present. `errors` is present for validation failures. `details` is an optional object carrying machine data for recovery (for example a fresh `quote` on `quote_changed`, the missing plan `feature` on a `*_api_not_enabled` refusal, or the `line_ids` a business rule refused).

A bare HTTP status maps to a `code` from this table:

| Status | `code` |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthenticated` |
| 403 | `forbidden` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 409 | `conflict` |
| 419 | `token_mismatch` |
| 422 | `unprocessable` |
| 429 | `rate_limited` |
| 500 | `server_error` |
| 503 | `service_unavailable` |

Framework exceptions on an `api/v1` route are mapped to the same envelope: a validation error becomes `422` with `code` `validation_failed` and per-field `errors`; an authorization error becomes `403`; a missing model becomes `404`; a rate limit becomes `429` with `code` `rate_limited` and the limiter's `Retry-After` and `Cache-Control: no-store` headers; any other HTTP exception keeps its status with its inferred `code`.

#### Pagination

Owner API list endpoints accept a `?per_page=` query parameter. The value is honoured up to a maximum of 100; an absent or invalid value falls back to the endpoint's default. The response body is a Laravel paginator JSON document, and the endpoint maps each row through its presenter into the paginator's `data`.

#### Rate limits

The owner API is keyed per token under the `owner-api` limiter. The storefront API uses the `storefront` (read) and `storefront-write` (write) limiters, keyed per token. The `api` middleware group applies the `api` limiter to every API route, keyed per signed-in user id or, for guests, per IP address.

### Outgoing webhooks

juwri sends HTTP `POST` callbacks to webhook endpoints you register. Endpoints are created, listed and deleted through the API (`/api/v1/webhook-endpoints`). A merchant subscribes a URL to a set of events, possibly the `*` wildcard for all events.

#### Events

Every event a merchant can subscribe to. A wildcard `*` subscribes to all of them.

| Event | Fired when |
|---|---|
| `order.created` | an order is created (storefront, API, POS or an inbound aggregator) |
| `order.paid` | an order is paid |
| `order.status_changed` | an order's status changes |
| `refund.completed` | a refund completes |
| `reservation.created` | a reservation is created |
| `reservation.cancelled` | a reservation is cancelled |
| `customer.created` | a customer is created |
| `shift.opened` | a cash shift opens |
| `shift.closed` | a cash shift closes |
| `menu.updated` | menu content changes |
| `entitlement.changed` | a plan feature entitlement changes |
| `subscription.paused` | a subscription is paused |
| `subscription.resumed` | a subscription resumes |
| `subscription.renewed` | a subscription renews |
| `subscription.expired` | a subscription expires |
| `subscription.plan_changed` | a subscription plan changes |
| `subscription.past_due` | a subscription payment becomes past due |
| `subscription.expiring` | a subscription is due to expire soon |
| `subscription.trial_ending` | a trial is about to end |
| `app.uninstalled` | a marketplace app is uninstalled |

#### Delivery envelope

Each delivery is one JSON document:

```json
{
  "id": "msg_h2K9xW3qLp8RtY5vN0aBcDe7",
  "event": "order.created",
  "created_at": "2026-07-08T14:03:11+00:00",
  "data": {
    "order_id": 4812,
    "store_id": 37,
    "type": "delivery",
    "status": "new",
    "total": 96.5,
    "currency": "USD",
    "track_token": "tk_9f3a2c7e",
    "source": "api",
    "restaurant_id": 37
  }
}
```

`id` is a unique message id per (endpoint, event) so retries can be deduplicated. `event` names the topic. `created_at` is ISO-8601 with a timezone offset. `data` is the event payload. As a legacy convenience, `store_id` is mirrored into `restaurant_id`.

#### Request headers

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `User-Agent` | `<site title>-Webhooks/1` |
| `webhook-id` | the message id |
| `webhook-timestamp` | the event's Unix timestamp |
| `webhook-signature` | `v1,<signature>` |
| `X-Webhook-Event` | the event name (legacy) |
| `X-Webhook-Signature` | `hash_hmac('sha256', body, secret)` (legacy) |

#### Signature verification

The signature is an HMAC-SHA256 over `{id}.{timestamp}.{body}`, base64-encoded, keyed with the endpoint's secret, and sent prefixed `v1,`:

```
signature = base64( HMAC-SHA256( key = endpoint secret, message = id + "." + timestamp + "." + body ) )
```

The `id` and `timestamp` are bound into the signed content so a captured request cannot be replayed against a different id or time. Receivers should reject requests whose `webhook-timestamp` is outside a small tolerance (about five minutes). Compare signatures in constant time.

#### Delivery behaviour

- Every endpoint subscribed to the event receives its own delivery attempt.
- Failed deliveries retry with exponential backoff plus jitter: roughly 1 minute, 5 minutes, 15 minutes and 1 hour after the first attempt (5 attempts in total).
- Every attempt is recorded on the endpoint (`webhook_deliveries`), with the attempt number and HTTP status.
- Deliveries only ever go to public HTTPS URLs; loopback, private, link-local and cloud-metadata addresses are refused (SSRF guard).
- Only 2xx responses count as success. A run of 15 consecutive failures auto-disables the endpoint.
- A disabled endpoint stops receiving events until re-enabled.
- With no queue worker running, a delivery is attempted once and never blocks the request that fired the event.

#### Sample payloads

Each event has a canonical sample in `core/resources/fixtures/webhook-samples/`. A test pins these fixtures to the set of subscribable events and verifies every `data` key they contain is one a live dispatcher sends. The `data` keys per event are (keys in parentheses are additions a live dispatcher may send on top of the sample):

| Event | `data` keys in the sample |
|---|---|
| `order.created` | `order_id`, `store_id`, `type`, `status`, `total`, `currency`, `track_token`, `source`, `restaurant_id` (plus `table_number`, `hs_order_id`) |
| `order.status_changed` | `order_id`, `new_status` (plus `by_staff`) |
| `order.paid` | `order_id`, `total`, `payment_gateway` (plus `via`) |
| `refund.completed` | `order_id`, `amount`, `method` |
| `reservation.created` | `reservation_id`, `status`, `source` |
| `reservation.cancelled` | `reservation_id`, `status` (plus `source`) |
| `customer.created` | `customer_id`, `name` |
| `shift.opened` | `shift_id`, `branch_id`, `staff_id` |
| `shift.closed` | `shift_id`, `over_short` |
| `menu.updated` | `store_id`, `change`, `entity`, `id`, `restaurant_id` |
| `entitlement.changed` | `action`, `feature_key`, `source_type`, `source_id`, `user_id`, `occurred_at` |
| `subscription.paused` | `subscription_id`, `plan_id` |
| `subscription.resumed` | `subscription_id`, `ends_at` |
| `subscription.renewed` | `subscription_id`, `plan_id`, `ends_at` |
| `subscription.expired` | `subscription_id`, `user_id`, `plan_id`, `status`, `ends_at` (plus `trial`) |
| `subscription.plan_changed` | `subscription_id`, `user_id`, `plan_id`, `status`, `ends_at`, `scheduled` |
| `subscription.past_due` | `subscription_id`, `user_id`, `plan_id`, `status`, `ends_at` |
| `subscription.expiring` | `subscription_id`, `user_id`, `plan_id`, `status`, `ends_at`, `days_left` |
| `subscription.trial_ending` | `subscription_id`, `user_id`, `plan_id`, `status`, `ends_at`, `days_left` |
| `app.uninstalled` | `app`, `user_id`, `uninstalled_at` (plus `reason`) |

### Headless storefront API

The storefront API is the surface themes and apps call to read a store and place orders. It is served under the `/api/v1/storefront` prefix.

The field-level contract is `tools/themes/kit/api/openapi.json` (an OpenAPI 3.1 document, currently version `1.7.0-draft`). It defines every request and response schema in detail, plus request/response samples under `tools/themes/kit/api/examples/`. Use it together with this page.

#### Authentication and scopes

Send the store's token in a header:

```
X-Storefront-Token: <token>
```

The store is resolved from the token only — never from the request body or URL. A token also carries a scope:

| Scope | Grants |
|---|---|
| `menu:read` | read the store, catalog, items, variants, orders (by track token) and booking listings |
| `order:write` | write shopper data: wishlist, stock alerts, returns, holds, bookings, orders, customer OTP |
| `kiosk` | the kiosk context endpoint |
| `agent` | the storefront agent (MCP) endpoint |

A request must also pass the owner's plan gate: every call needs the `storefront_api` feature; booking operations additionally need `booking_api`; a `kiosk` token also needs the `kiosk_api` and `kiosk` features and the store's kiosk switch.

For the write scopes, the token's origin allowlist is enforced. A `browser` token must send an allowed `Origin` and is rejected without one. A server token (the default) sends no `Origin` and is only checked when an allowlist is set.

A `CustomerToken` (an optional bearer token from the customer OTP endpoints, belonging to the same store) is required for bookings, cancel and reschedule when the store verifies guest phones.

#### Routes

Reads. All require the `menu:read` scope and the `storefront` rate limit unless noted.

| Method + path | Backed by |
|---|---|
| `GET /api/v1/storefront/store` | `StorefrontCatalogController@store` |
| `GET /api/v1/storefront/catalog` | `StorefrontCatalogController@catalog` |
| `GET /api/v1/storefront/items/{item_id}` | `StorefrontCatalogController@item` |
| `GET /api/v1/storefront/items/{item_id}/variants` | `StorefrontCatalogController@variants` |
| `GET /api/v1/storefront/orders/{track_token}` | `StorefrontCheckoutController@show` |
| `GET /api/v1/storefront/delivery/slots` | `StorefrontSlotsController@index` |
| `GET /api/v1/storefront/wishlist` | `StorefrontRetailController@wishlist` |
| `GET /api/v1/storefront/size-guides/{guide_id}` | `StorefrontRetailController@sizeGuide` |
| `GET /api/v1/storefront/orders/{track_token}/returns` | `StorefrontRetailController@returns` |
| `GET /api/v1/storefront/booking/staff` | `StorefrontBookingController@staff` |
| `GET /api/v1/storefront/booking/days` | `StorefrontBookingController@days` |
| `GET /api/v1/storefront/booking/slots` | `StorefrontBookingController@slots` |
| `GET /api/v1/storefront/bookings/{manage_token}` | `StorefrontBookingController@show` |

Writes. All require the `order:write` scope, the `storefront-write` rate limit and the same origin rule as above.

| Method + path | Backed by |
|---|---|
| `POST /api/v1/storefront/wishlist` | `StorefrontRetailController@wishlistAdd` |
| `POST /api/v1/storefront/wishlist/sync` | `StorefrontRetailController@wishlistSync` |
| `DELETE /api/v1/storefront/wishlist/{item_id}` | `StorefrontRetailController@wishlistRemove` |
| `POST /api/v1/storefront/stock-alerts` | `StorefrontRetailController@stockAlert` |
| `POST /api/v1/storefront/orders/{track_token}/returns` | `StorefrontRetailController@returnsStore` |
| `POST /api/v1/storefront/holds` | `StorefrontSlotsController@store` |
| `DELETE /api/v1/storefront/holds/{hold_id}` | `StorefrontSlotsController@destroy` |
| `POST /api/v1/storefront/bookings` | `StorefrontBookingController@store` |
| `POST /api/v1/storefront/bookings/{manage_token}/cancel` | `StorefrontBookingController@cancel` |
| `POST /api/v1/storefront/bookings/{manage_token}/reschedule` | `StorefrontBookingController@reschedule` |

Pricing and order submission. The quote is a read by scope (`menu:read`) that still spends the write budget.

| Method + path | Scope | Backed by |
|---|---|---|
| `POST /api/v1/storefront/quote` | `menu:read` (write budget) | `StorefrontCheckoutController@quote` |
| `POST /api/v1/storefront/shipping/quote` | `menu:read` (write budget) | `StorefrontCheckoutController@shippingQuote` |
| `POST /api/v1/storefront/orders` | `order:write` | `StorefrontCheckoutController@store` |

Kiosk and customer OTP:

| Method + path | Scope | Backed by |
|---|---|---|
| `GET /api/v1/storefront/kiosk` | `kiosk` | `StorefrontCatalogController@kiosk` |
| `POST /api/v1/storefront/customer/otp/request` | `order:write` | `StorefrontCustomerOtpController@request` |
| `POST /api/v1/storefront/customer/otp/verify` | `order:write` | `StorefrontCustomerOtpController@verify` |

Order, hold, booking and customer-OTP writes carry an `Idempotency-Key` header (and writes carry an `Origin`). Replaying the same key with the same body returns the original result; the retail wishlist, stock-alert and returns writes do not implement idempotency.

#### Pagination and caching

`GET /api/v1/storefront/catalog` accepts a `cursor` (opaque, from the previous response's `next_cursor`), a `per_page` (minimum 1, maximum 100, default 48) and search, filter and sort parameters. Large catalogs page with the cursor; a small menu may return every item in one page. `GET .../store` and `.../catalog` send an `ETag` and answer `304` when the client's `If-None-Match` matches.

#### Errors

The same envelope as the owner API. Storefront-specific `code` values include:

| HTTP | `code` | When |
|---|---|---|
| 401 | `unauthenticated` | token missing, invalid or revoked |
| 403 | `forbidden` | scope or origin not allowed |
| 403 | `storefront_api_not_enabled`, `booking_api_not_enabled`, `kiosk_api_not_enabled` | the owner's plan lacks that feature (`details.feature`) |
| 403 | `feature_disabled` | a platform kill switch is off |
| 403 | `kiosk_not_enabled` | kiosk token: the plan lacks `kiosk`, or the store's kiosk switch is off |
| 403 | `phone_verification_required` | guest phone verification is on and there is no verified customer token |
| 404 | `not_found` | unknown id, foreign branch, or the store is not servable |
| 409 | `quote_changed` | the submitted `quote_hash` does not match the current quote (`details.quote`) |
| 409 | `idempotency_conflict` | the same idempotency key with a different body |
| 409 | `slot_taken`, `slot_full`, `hold_expired` | slot and hold races (`details.next_free_slot`) |
| 409 | `hold_in_use`, `price_changed`, `past_deadline`, `booking_not_active` | hold, reschedule and booking state rules |
| 409 | `store_closed`, `bookings_not_open` | store state |
| 422 | `validation_failed` | field errors (`errors`) |
| 422 | `out_of_zone`, `below_minimum`, `out_of_stock`, `invalid_order_type`, `payment_method_unavailable` | business rules (`details.line_ids`) |
| 422 | `payment_mode_unavailable`, `return_url_not_allowed`, `otp_invalid` | payment mode, return URL and OTP rules |
| 429 | `rate_limited` | budget hit (`Retry-After` header) |

#### Deprecated aliases

Four endpoints stay for twelve months after launch and are then removed. Every answer — success or error — carries `Deprecation: true`, `Sunset: <HTTP-date>` and `Link: </developers>; rel="deprecation", <successor>; rel="successor-version"`.

| Deprecated | Successor |
|---|---|
| `GET /api/v1/storefront/menu` | `GET /api/v1/storefront/catalog` |
| `GET /api/v1/storefront/restaurant` | `GET /api/v1/storefront/store` |
| `GET /api/v1/menu/{storeId}` (public, no token) | `GET /api/v1/storefront/catalog` |
| `GET /api/v1/track/{token}` | `GET /api/v1/storefront/orders/{track_token}` |

### Owner app and other first-party app APIs

These routes serve juwri's own apps. They are listed because they share the `/api/v1` namespace and the same error envelope.

#### Owner app

Public routes for the owner mobile shell (Hotwire Native). The `handoff` route is a browser navigation inside the shell's WebView and deliberately carries no token middleware.

| Method + path | Notes |
|---|---|
| `GET /api/v1/app/config` | version gate |
| `GET /api/v1/app/path-configuration/{platform}.json` | `platform` is `ios` or `android` |
| `POST /api/v1/app/auth/login` | credential login |
| `GET /api/v1/app/handoff/{nonce}` | one-time session hand-off; `nonce` is 64 characters |

Token routes for the same shell. They authenticate any valid app token and then admit only `kind=app` tokens, so an integration token cannot be exchanged for a dashboard session.

| Method + path | Route name |
|---|---|
| `GET /api/v1/app/me` | `api.v1.app.me` |
| `POST /api/v1/app/session` | `api.v1.app.session` |
| `POST /api/v1/app/devices` | `api.v1.app.devices.register` |
| `POST /api/v1/app/devices/unregister` | `api.v1.app.devices.unregister` |
| `POST /api/v1/app/auth/logout` | `api.v1.app.logout` |

#### Customer app

Authenticated by a per-customer bearer token.

| Method + path | Route name |
|---|---|
| `GET /api/v1/customer/me` | `api.v1.customer.me` |
| `PUT /api/v1/customer/me` | `api.v1.customer.me.update` |
| `GET /api/v1/customer/orders` | `api.v1.customer.orders` |
| `POST /api/v1/customer/orders` | `api.v1.customer.orders.place` |
| `GET /api/v1/customer/store-credit` | `api.v1.customer.store-credit` |
| `GET /api/v1/customer/gift-cards` | `api.v1.customer.gift-cards` |
| `GET /api/v1/customer/addresses` | `api.v1.customer.addresses` |
| `POST /api/v1/customer/addresses` | `api.v1.customer.addresses.store` |
| `PUT /api/v1/customer/addresses/{id}` | `api.v1.customer.addresses.update` |
| `DELETE /api/v1/customer/addresses/{id}` | `api.v1.customer.addresses.destroy` |
| `POST /api/v1/customer/logout` | `api.v1.customer.logout` |

Customer and staff sign-in endpoints also live directly under `/api/v1`:

| Method + path | Route name |
|---|---|
| `POST /api/v1/stores/{id}/customer/register` | `api.v1.customer.register` |
| `POST /api/v1/stores/{id}/customer/login` | `api.v1.customer.login` |
| `POST /api/v1/stores/{id}/customer/otp/request` | `api.v1.customer.otp.request` |
| `POST /api/v1/stores/{id}/customer/otp/verify` | `api.v1.customer.otp.verify` |
| `POST /api/v1/stores/{id}/staff/login` | `api.v1.staff.login` |
| `POST /api/v1/stores/{id}/staff/pin` | `api.v1.staff.pin` |

#### Staff app

Authenticated by a per-staff bearer token.

| Method + path | Route name |
|---|---|
| `GET /api/v1/staff/me` | `api.v1.staff.me` |
| `GET /api/v1/staff/orders` | `api.v1.staff.orders` |
| `PUT /api/v1/staff/orders/{orderId}/status` | `api.v1.staff.orders.status` |
| `GET /api/v1/staff/kds` | `api.v1.staff.kds` |
| `PUT /api/v1/staff/kds/items/{itemId}/bump` | `api.v1.staff.kds.bump` |
| `PUT /api/v1/staff/kds/items/{itemId}/recall` | `api.v1.staff.kds.recall` |
| `POST /api/v1/staff/menu/items` | `api.v1.staff.menu.items.store` |
| `PUT /api/v1/staff/menu/items/{itemId}` | `api.v1.staff.menu.items.update` |
| `DELETE /api/v1/staff/menu/items/{itemId}` | `api.v1.staff.menu.items.delete` |
| `PATCH /api/v1/staff/menu/items/{itemId}/sold-out` | `api.v1.staff.menu.items.soldout` |
| `POST /api/v1/staff/menu/categories` | `api.v1.staff.menu.categories.store` |
| `GET /api/v1/staff/analytics` | `api.v1.staff.analytics` |
| `POST /api/v1/staff/push/register-device` | `api.v1.staff.push.register` |
| `POST /api/v1/staff/push/unregister-device` | `api.v1.staff.push.unregister` |
| `POST /api/v1/staff/2fa/setup` | `api.v1.staff.2fa.setup` |
| `POST /api/v1/staff/2fa/confirm` | `api.v1.staff.2fa.confirm` |
| `POST /api/v1/staff/logout` | `api.v1.staff.logout` |

#### Driver app

Authenticated by a per-driver bearer token. Also requires the `feature:driver_app` entitlement.

| Method + path | Route name |
|---|---|
| `GET /api/v1/driver/me` | `api.v1.driver.me` |
| `GET /api/v1/driver/deliveries` | `api.v1.driver.deliveries` |
| `GET /api/v1/driver/available` | `api.v1.driver.available` |
| `POST /api/v1/driver/deliveries/{orderId}/claim` | `api.v1.driver.deliveries.claim` |
| `PUT /api/v1/driver/deliveries/{orderId}/status` | `api.v1.driver.deliveries.status` |
| `POST /api/v1/driver/deliveries/{orderId}/pod-otp` | `api.v1.driver.deliveries.podOtp` |
| `POST /api/v1/driver/deliveries/{orderId}/pod-photo` | `api.v1.driver.deliveries.podPhoto` |
| `PUT /api/v1/driver/location` | `api.v1.driver.location` |
| `GET /api/v1/driver/offers` | `api.v1.driver.offers` |
| `POST /api/v1/driver/offers/{offerId}/accept` | `api.v1.driver.offers.accept` |
| `POST /api/v1/driver/offers/{offerId}/reject` | `api.v1.driver.offers.reject` |
| `POST /api/v1/driver/shift` | `api.v1.driver.shift` |
| `POST /api/v1/driver/push/register-device` | `api.v1.driver.push.register` |
| `POST /api/v1/driver/push/unregister-device` | `api.v1.driver.push.unregister` |
| `GET /api/v1/driver/earnings` | `api.v1.driver.earnings` |

Public driver OTP login (exchanges a phone and OTP for a rotated driver token):

| Method + path | Route name |
|---|---|
| `POST /api/v1/driver/login/request` | `api.v1.driver.login.request` |
| `POST /api/v1/driver/login/verify` | `api.v1.driver.login.verify` |

#### MCP servers

juwri also exposes Model Context Protocol servers as JSON-RPC over HTTP. These are not under `/api/v1`.

| Method + path | Auth | Route name |
|---|---|---|
| `POST /mcp` | owner API token (or OAuth with `mcp:use`) | `mcp` |
| `POST /mcp/catalog` | none (public, read-only) | `mcp.catalog` |
| `POST /mcp/customer` | customer token | `mcp.customer` |
| `POST /mcp/storefront` | agent-scoped storefront token | `mcp.storefront` |
| `POST /mcp/dev` | none (public, read-only) | `mcp.dev` |
