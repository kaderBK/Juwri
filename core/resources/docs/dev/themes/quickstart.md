---
title: "Theme development quickstart"
audience: developer
group: Themes
updated: 2026-09-19
---

# Theme development quickstart

Zero to a submittable theme. A theme is a zip of Liquid templates
(Shopify-compatible syntax, closed whitelist) that renders a restaurant's
public storefront server-side. No build step, no framework — a text editor
and a zip tool are enough. This page walks the whole loop: scaffold, write a
section, wire settings, validate, package, submit. Each step links to the
reference page with the full rules.

If you build with an AI coding tool, point it at the public dev MCP server
(`POST /mcp/dev`, no auth) — its `learn_platform`, `get_liquid_reference`,
`get_section_schema` and `validate_theme` tools serve the same facts as these
docs, generated from the engine itself.

## 1. Scaffold from the starter theme

Download the starter package from `/developers/themes/starter.zip` (linked on
the `/developers/themes` docs page). It is a minimal, validator-clean theme:

```
theme.json                    # manifest: name, slug, version
templates/index.json          # section instances + order
sections/hero.liquid          # heading + logo, with a {% schema %} block
sections/menu-grid.liquid     # categories/items loop + cart hooks
snippets/item-card.liquid     # partial rendered per item
config/settings_schema.json   # theme-level settings
locales/en.json               # translation strings for the t filter
assets/css/style.css
```

Rename `slug` in `theme.json` before you do anything else — the slug
identifies your theme forever and must be lowercase letters, digits and
dashes. `version` follows the same character rules; bump it for every
resubmission. The full layout contract (allowed directories, root files,
depth limit) is in [structure](structure.md).

## 2. Write a section

One `.liquid` file per section under `sections/`. Data comes from drops —
`restaurant`, `menu`, `flags`, `table`, `section`, `vocab` and friends;
themes never call HTTP APIs. `vocab` and `restaurant.store_type` let one
theme package speak the right nouns on any store vertical — see [Building
Liquid themes](../learn-themes.md#make-your-theme-vertical-aware-vocab--store_type).
The starter's hero shows the pattern:

```liquid
<link rel="stylesheet" href="{{ 'css/style.css' | theme_asset }}">

<header class="st-hero">
  {% if restaurant.logo %}
    <img class="st-logo" src="{{ restaurant.logo | media_url: 'logo' }}" alt="{{ restaurant.title | escape }}">
  {% endif %}
  <h1>{% if section.settings.heading != '' %}{{ section.settings.heading | escape }}{% else %}{{ restaurant.title | escape }}{% endif %}</h1>
</header>
```

Three rules save the most debugging time:

- Only whitelisted drops, filters and tags exist. The lists are short and
  generated from the engine: [drops](drops.md), [filters](filters.md)
  (`media_url`, `money`, `money_code`, `t`, `theme_asset` plus Liquid core),
  [tags](tags.md). A Shopify filter that is not listed fails validation.
- No `<script>`, no inline event handlers, no `.js` files. Interactivity
  comes from the platform commerce runtime: emit `{% commerce %}` once and
  use its mount points (the starter's menu grid shows `data-mb-open-cart`,
  `data-mb-cart-mount` and friends).
- Escape everything user-controlled with `| escape`, and pull user-visible
  strings from `locales/en.json` via the `t` filter.

Shared markup goes in `snippets/` and is included with the standard
`{% render 'item-card', item: item %}` tag — snippets see only what you pass
them. See [sections](sections.md) for the render model.

## 3. Wire the template

`templates/index.json` declares which sections render and in what order:

```json
{
  "sections": {
    "hero": { "type": "hero", "settings": { "heading": "" } },
    "menu": { "type": "menu-grid" }
  },
  "order": ["hero", "menu"]
}
```

Each instance's `type` names a file `sections/<type>.liquid`. Caps: 25
sections per template, 50 blocks per section — exceeding either fails lint.
Details in [schema-settings](schema-settings.md).

## 4. Add merchant settings

End a section file with one `{% schema %}` block; its body must be a single
valid JSON object (checked at upload). Values reach the template as
`section.settings.<id>`:

```liquid
{% schema %}
{
  "name": "Hero",
  "settings": [
    { "id": "heading", "type": "text", "label": "Custom heading", "default": "" },
    { "id": "accent", "type": "color", "label": "Accent colour", "default": "#3fd463" }
  ]
}
{% endschema %}
```

Every setting def needs `id` and `type`, and only defs with a `default`
contribute a value until the merchant sets one. Supported types (`text`,
`color`, `checkbox`/`toggle`, `select`, `image`) and their validation rules
are in [schema-settings](schema-settings.md).

## 5. Validate before you zip

Run the `validate_theme` MCP tool — it applies the same rules the
marketplace runs at submission and returns
`{passed, errors[], warnings[]}` with every finding at once:

```json
{
  "name": "validate_theme",
  "arguments": {
    "mode": "package",
    "files": {
      "theme.json": "...",
      "templates/index.json": "...",
      "sections/hero.liquid": "..."
    }
  }
}
```

Mode `files` lints fragments while you iterate; mode `package` additionally
requires `theme.json` and a `templates/` entrypoint. Input caps: 40 files,
200 KB per file, 1 MB total per call — validate large themes in batches with
mode `files`, then a final `package` pass. Common failures — unknown
filters, missing snippets, external URLs (bundle all assets), schema shape —
are catalogued in [validation](validation.md). Fix every error; warnings
(such as unused assigns, or a missing `{% commerce %}`/`data-mb-open`/
`data-mb-add`/`| money` on what looks like an ordering menu) do not block,
but read them — they usually mean the theme renders without actually being
orderable.

## 6. Package

Zip the theme with `theme.json` at the archive root (not inside a wrapper
folder). Hard gates checked before extraction: 10 MB uncompressed total,
2 MB per file, 1000 entries, depth of 3, closed extension whitelist
(`liquid/json/css/png/jpg/jpeg/webp/svg/woff2` — no `.php`, no `.js`), and a
dangerous-markup scan. Any failure rejects the whole package. Full list in
[structure](structure.md). An optional `preview.png` at the root becomes
your marketplace listing image.

## 7. Submit

Submission runs a fixed pipeline: package gates, then the lint report
(errors auto-reject with the report attached), then an automated
performance/accessibility gate over demo renders, then human review in the
platform review queue. Approved versions are published to the public
marketplace at `/marketplace`. Versions are immutable — ship fixes as a new
`version` with the same `slug`.

There is no self-service upload endpoint yet. The submission service and the
admin review queue exist server-side but are not wired to a public route, so
arrange submission with the platform team directly. Review flow and update
rules are in [submission](submission.md).

## Next steps

- [structure](structure.md) — the package contract in full
- [sections](sections.md) — render model, snippets, layout, environments
- [schema-settings](schema-settings.md) — setting types and template wiring
- [drops](drops.md), [filters](filters.md), [tags](tags.md) — the generated
  whitelist (also live via `get_liquid_reference`)
- [validation](validation.md) — every gate and lint rule
- [submission](submission.md) — marketplace review and versioning
- [Building Liquid themes](../learn-themes.md) — the one-page primer
