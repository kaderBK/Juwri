---
title: "Theme validation"
audience: developer
group: Themes
updated: 2026-09-26
---

# Theme validation

Two layers guard the platform. Use the `validate_theme` MCP tool to run the
same checks locally before submitting.

## Package gates (hard security — zip level)

Checked before extraction, any failure rejects the whole package:

- Fixed layout: `theme.json` at root; `templates/index.json` (or
  `templates/index.liquid`) entrypoint; only known top-level dirs; depth ≤ 3.
- Caps: 10 MB total uncompressed, 2 MB per file, 1000 entries.
- Closed extension whitelist (`liquid/json/css/png/jpg/jpeg/webp/svg/woff2`);
  no `.php`, no `.js`.
- Traversal/symlink/zip-bomb hardening.
- Dangerous markup scan: `<script>` bodies, `on*=` handlers, `javascript:`
  URLs, `<iframe>` in `.liquid`; `<script>`/`<foreignObject>`/`on*=` in `.svg`.
- Manifest: valid JSON with resolver-safe `name`, `slug`, `version`.

## Liquid parse (upload environment)

Every `.liquid` file is parsed with the closed whitelist:

- Unknown filter → error naming the filter. Check `get_liquid_reference`.
- Unknown tag → error. Only `{% schema %}`, `{% commerce %}` and standard
  Liquid core tags exist.
- `{% schema %}` body must be a single valid JSON object (checked at parse
  time — you find out at upload, not at render).

## Lint report

The author-facing linter aggregates ALL findings per file with line numbers
instead of stopping at the first, so fix batches, not one error at a time.

## Feature-wiring warnings (advisory — never block)

The gates above prove a theme is safe and well-formed; they say nothing
about whether it actually works as a storefront. A theme can pass every
hard rule while rendering a static menu nobody can order from. These
heuristic checks run across the WHOLE theme (not one file — `{% commerce %}`
typically lives in the index template while the item loop it powers lives in
a section or snippet) and surface as `warnings`, never `errors`:

- `commerce-tag-missing` — order/cart markup is present (`flags.allow_order`,
  an add-to-cart or cart-mount control) but no `{% commerce %}` tag exists
  anywhere: cart/checkout will not work.
- `item-deep-link-missing` — item cards are rendered (a `{% for item in
  ...items %}` loop) but none carry `data-mb-open` — items can't be opened.
- `cart-add-missing` — item cards are rendered but no `data-mb-add` exists
  anywhere — nothing can be added to the cart.
- `price-missing-money` — a raw `{{ item.price }}` is rendered as visible
  text without the `| money` filter — currency symbol, position and RTL
  layout will be wrong. (Raw `{{ item.price }}` inside a `data-mb-*`
  attribute, e.g. `data-mb-price="{{ item.price }}"`, is fine — that's a
  machine-readable value for the runtime, not user-facing text.)

A theme that intentionally has no ordering (a read-only "browse the menu"
theme, `flags.allow_order` off and no cart UI at all) triggers none of
these — they only fire when the theme's own markup implies the feature
should work.

## Quality warnings (advisory — never block)

These also surface as `warnings` on your theme's own files:

- `rtl-physical-prop` — physical left/right CSS in a `.css` file or an
  inline `style=""` (`margin-left`, `padding-right`, `border-left`, `left:`,
  `right:`, `text-align: left|right`, `float: left|right`). Stores can be
  right-to-left (Arabic); use the logical equivalents: `margin-inline-start`,
  `padding-inline-end`, `border-inline-start`, `inset-inline-start`,
  `text-align: start|end`, `float: inline-start|inline-end`.
- `unescaped-setting-output` — `{{ section.settings.x }}`,
  `{{ block.settings.x }}` or `{{ settings.x }}` printed without an escaping
  or formatting filter (`escape`, `money`, `t`, `media_url`, `theme_asset`,
  `url_encode`, `json`, …). Merchant settings reach Liquid unescaped. Settings
  whose declared type cannot carry markup (colour, number, range, select,
  checkbox, color_token, font_pair, pickers, image_picker, and sanitized
  `richtext`) are not flagged.
- `img-missing-dimensions` — an `<img>` without both `width` and `height`
  attributes; the page shifts while images load (CLS). Set the intrinsic
  size — CSS can still resize the image.
- `section-css-unscoped` — a per-section stylesheet (`sections/<type>.css`)
  with a selector that does not start with `.mbs-<type>` (BEM elements and
  modifiers such as `.mbs-<type>__title` / `.mbs-<type>--wide` count as
  scoped; `.mbs-<type>-other` does not). For your own theme
  files this is a warning; for the platform section library it is an error
  (a section may only style itself).

## Quality gate (Lighthouse)

A submission that passes lint is queued for the performance/accessibility
gate against its marketplace preview (default direction and Arabic/RTL,
mobile and desktop). Below the bars it is auto-rejected with the scores in
`gate_report`. When the platform host cannot run Lighthouse (or the
preview cannot be reached) the gate is recorded as `skipped` with the reason — that never fails your submission;
a reviewer decides as usual.

## Typical mistakes

- Using a Shopify filter that is not whitelisted here (`image_url`,
  `money`, ...) — check the generated filter list, it is short on purpose.
- Inline `<script>` for interactivity — use `{% commerce %}` instead.
- Forgetting `default` on a setting and reading it as if set.
