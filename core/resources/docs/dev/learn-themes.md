---
title: "Building Liquid themes"
audience: developer
group: Dev
updated: 2026-09-19
---

# Building Liquid themes

Themes are Liquid (Shopify-compatible syntax, CLOSED whitelist) packages that
render a restaurant's public storefront. The engine only exposes what
`get_liquid_reference` returns — drops (objects), filters, and tags outside
that list fail validation and will not render.

## Package layout

```
theme.json                    # manifest (name, version, author)
templates/index.json          # OS2.0-style: ordered list of section instances
sections/*.liquid             # sections — each may end with {% schema %} JSON
snippets/*.liquid             # partials, included from sections
config/settings_schema.json   # theme-level settings
locales/en.json               # translations
assets/                       # css/js/images (served statically)
```

Caps enforced at upload: 10 MB zip total, 2 MB per file. The package validator
also rejects unknown top-level dirs, symlinks, and non-whitelisted file types.

## Workflow for an AI agent

1. `learn_platform` (this doc) → understand the surface.
2. `get_liquid_reference` → the exact drops/filters/tags you may use.
3. `get_section_schema` → the `{% schema %}` settings format.
4. Write sections/snippets.
5. `validate_theme` with your files → fix every reported error.
6. Deliver only when validation is clean.

## Sections and schema

A section is a `.liquid` file optionally ending with a `{% schema %} ... {% endschema %}`
JSON block declaring its settings, blocks, and presets. Settings values reach
the template through the `section.settings` drop. `templates/index.json` lists
which sections render on the storefront and in what order.

Merchant edits happen in the schema-driven customizer (v2): your schema is
normalized server-side, every setting has exactly one canonical key (aliases
fold on save), and values persist under the versionless `mp:<slug>` key so
they survive theme version updates. The customizer only ever accepts data
validated against your schema — never code.

## Rendering model

- Server-side render, resource-limited (render budget: time/memory caps).
- Two environments: `upload` (strict parse at validation time) and `live`
  (rendering with data). Both share the same closed whitelist.
- Commerce actions (cart, ordering) go through the `{% commerce %}` tag and
  the platform JS runtime — themes never call APIs directly.

## Make your theme vertical-aware (`vocab` + `store_type`)

The platform is multi-vertical: a store's `restaurant.store_type` is one of
`restaurant` (legacy stores read this), `retail`, `cosmetic`, `clothing`,
`carparts`, `laundry`, `pharmacy` or `hotel`. A theme hard-coding "Menu" /
"Dish" / "Add to order" reads wrong on a goods or services store. Two globals
exist so a theme can speak the right noun set without hand-rolling per-type
copy:

- `{{ restaurant.store_type }}` — the raw discriminator, for structural
  branching (swap a whole section, change a layout).
- `{{ vocab.* }}` — the SAME nouns, already resolved server-side and
  translated to the current locale. Prefer this for copy; it degrades safely
  (food wording) for unknown/legacy store types instead of ever rendering an
  empty label. Keys: `item`, `items`, `catalog`, `category`, `categories`,
  `create_item`, `add_to_cart`, `order`, `orders`, `board`, and `group`
  (`food` | `goods` | `services` | `stays` — a coarser bucket than
  `store_type`, meant for layout branches that don't need the exact type).
  Full property list: [Liquid drops → VocabDrop](themes/drops.md#vocabdrop).

```liquid
{% comment %} Copy: pull the noun instead of hard-coding "Menu" / "Dishes" {% endcomment %}
<h1>{{ vocab.catalog }}</h1>
<p>{{ vocab.items | size }} {{ vocab.items }}</p>
<button data-mb-add="{{ item.id }}">{{ vocab.add_to_cart }}</button>

{% comment %} Layout: branch structure on the coarse group, not every store_type {% endcomment %}
{% if vocab.group == 'stays' %}
  {% render 'availability-calendar' %}
{% else %}
  {% render 'catalog-grid' %}
{% endif %}
```

A first-party food theme (crave, starter) can ignore both — the food
defaults (`vocab.catalog` → "Menu", `vocab.item` → "Dish", …) are
byte-identical to today's hard-coded strings, so existing themes render
unchanged. Use `vocab` when you want one theme package to serve every
vertical set instead of shipping a food-only theme.

## Item reviews (`item.reviews`)

Every item in `menu.categories[].items` (and anywhere else an `ItemDrop`
appears) carries its own **approved** reviews, newest first:

```liquid
<p>{{ item.rating }} ★ ({{ item.rating_count }})</p>
{% for r in item.reviews %}
  <div class="review">
    <strong>{{ r.rating }} ★ {{ r.author }}</strong>
    {% if r.is_verified %}<span class="badge">{{ ___('Verified purchase') }}</span>{% endif %}
    <p>{{ r.body }}</p>
  </div>
{% endfor %}
```

`item.rating` / `item.rating_count` (pre-existing) are the item's aggregate
average and count — cheap to show without looping `item.reviews` at all.
Each entry in `item.reviews` is a [`ReviewsDrop`](themes/drops.md#reviewsdrop):
`id`, `author`, `rating`, `body`, `is_verified`, `created_at`. Only
`status: approved` reviews are ever reachable; pending/rejected reviews and
the reviewer's account (customer id, session hash, email, phone) are not
exposed anywhere in the Drop layer.

`r.author` and `r.body` are diner-submitted free text — unlike other Drop
strings (which are merchant-authored and expected to be piped through
`| escape` by the theme, per [quickstart](themes/quickstart.md)), these two are
**pre-escaped** server side as defense in depth. Don't also wrap them in
`| escape` — harmless, just redundant.

## Known limitations (no Drop yet)

Reservations and loyalty are real, shipped platform features — but both are
wired for the **Blade** themes in a way that doesn't hand a Liquid theme
anything to read without new plumbing, so neither has a Drop yet:

- **Reservations / table booking** — `Reservation`, `ReservationController`
  and a public write route already exist
  (`POST {slug}/reservation` → `ReservationController::publicBook`, gated on
  the store's `enable_reservations` option), but it's a classic HTML
  form-POST-then-redirect endpoint built for one Blade theme ("aurora"), not
  the JSON contract the `{% commerce %}` tag / runtime JS use for
  `submitOrder`/`applyCoupon`/`callTheWaiter`. There is also no *availability*
  read anywhere — `ReservationAvailabilityService::lockSlot` only runs
  inside the write, so there's no open-slots data to eager-load into a Drop
  even for display. And the render site doesn't yet expose whether a store
  even takes reservations: neither `OptionsDrop` nor `FlagsDrop` carries
  `enable_reservations` today. Needs, in order: (1) the option surfaced as a
  flag, (2) a `ReservationDrop` for read-only display (hours/party-size
  limits — real availability would need the slot-locking logic exposed as a
  read, which is more than a Drop), and (3) a commerce-runtime endpoint +
  JS binding (or a new tag) so a theme can actually submit a booking.
- **Loyalty / stored-value balances** — `CustomerDrop` exposes
  `store_credit` (the diner's own wallet, already loaded at render time),
  but the separate points/stamps loyalty system (`LoyaltyAccount`,
  `LoyaltyProgram`, `LoyaltyLedger`) is intentionally **not** in the
  server-rendered payload at all: `resources/views/store_templates/_partials/loyalty-redeem.blade.php`
  fetches a customer's live balance + tier + "ready to redeem" state from a
  separate `auth:customer` JSON endpoint (`publicView.loyalty.balance`) at
  checkout time, specifically so an anonymous storefront view never pays for
  a feature-entitlement + active-program + balance lookup it doesn't need.
  Pulling that into a `LoyaltyDrop` top-level global would mean running that
  same lookup unconditionally on every render (the new-query trap this pass
  is meant to avoid) or re-deriving its tier/ready-to-redeem logic a second
  time for the Drop layer — either way, more than a read-only expose. The
  existing balance endpoint is the reusable seam for a future pass (e.g.
  surfaced as a `commerce_config.endpoints` URL a theme's JS calls, the same
  way the Blade partial does), not a server-rendered Drop value today.

Don't fake these with static markup — there is real data and real endpoints
behind both, but wiring either into the sandboxed Liquid layer needs new
platform work (a flag, a Drop, and/or a runtime binding), not something a
theme package can bolt on by itself.

## Hard rules

- No raw PHP, no `include` of arbitrary paths, no external HTTP from Liquid.
- Do not invent filters from Shopify docs — check `get_liquid_reference`.
- Every user-visible string should come from `locales/*.json` when practical.
