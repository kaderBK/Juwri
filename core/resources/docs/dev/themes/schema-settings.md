---
title: "Section schema & settings"
audience: developer
group: Themes
updated: 2026-09-26
---

# Section schema & settings

A section file may end with one `{% schema %} ... {% endschema %}` block whose
body is a single JSON object. The JSON is validated at upload (invalid JSON =
syntax error); the block renders nothing. Settings values reach the template
as `section.settings.<id>`.

## Schema shape

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

- `name` — label shown in the merchant customizer (falls back to section type).
- `settings` — list of Shopify-style defs: `id` (required), `type`, `label`,
  `default`, and `options` for selects.
- `blocks` — optional repeatable sub-elements; each block type has its own
  `settings` list with the same def shape and the same defaults rule.
- Defaults: the engine reads `id` + `default` only — a def without `default`
  contributes nothing until the merchant sets a value.

## Setting types

The merchant customizer maps types to UI controls and validates saved values.
Unknown types are treated as `text` — they do not error, but you get a plain
text input, so stick to this list:

## text

Default type. UI: text input. Validation: value must be a string.

## textarea

UI: multi-line text box. Validation: value must be a string of at most
5,000 characters. Output it with `| escape` like any text.

## color

UI: colour picker. Validation: string matching `#` + 3–8 hex digits
(`#fff`, `#3fd463`, `#3fd463ff`).

## checkbox / toggle

Both map to a toggle control. Validation: value must be boolean.

## select

UI: dropdown. Requires `"options": [...]` — plain strings or
`{ "value": "..." }` objects. Validation: saved value must be one of the
options (strict match).

## image

Treated as a string (an asset path / URL). UI: text input plus a
"choose from library" picker that lists the store's own media (served by the
ownership-scoped `media.list` endpoint). The media library owns and
ref-counts the file; the customizer only stores the path.

## range

UI: slider with the current value shown. Declare `min`, `max` and
optionally `step` and `unit` (display only, e.g. `"px"`). Validation: value
must be a number within `min`/`max`.

```json
{ "id": "columns", "type": "range", "label": "Columns", "min": 1, "max": 4, "step": 1, "default": 2 }
```

## number

UI: number input (honours `min`/`max`/`step`). Validation: value must be
a number within `min`/`max` when declared.

## image_picker

UI: thumbnail plus "choose from library" and "upload" buttons — both use
the store's own media library (the owner-authenticated media endpoints).
The value is the stored media **filename** (not a URL). Validation: a bare
filename (letters, digits, `.`, `_`, `-`; no path, no `..`), and on save it
must exist in that store's media library. `""` means no image. Render it
with the `media_url` filter:

```liquid
{% if section.settings.photo != '' %}
  <img src="{{ section.settings.photo | media_url }}" width="800" height="600" alt="">
{% endif %}
```

## font_pair

UI: dropdown of the platform's self-hosted, Arabic-capable font pairs.
Validation: value must be a font-pair id or the `none` sentinel ("theme
default").

## color_token

UI: dropdown of design-token names. The value is a **token name**, not a
hex: one of `primary`, `background`, `surface`, `text`, `accent`. Use it to
pick which store colour a section uses, e.g.
`style="--accent: var(--tc-{{ section.settings.tone }})"`.

## richtext

UI: small editor with bold, italic, underline and link. The saved value is
rebuilt from an allow-list (strong, em, u, s, a, span colour, mark, br) —
anything else is stripped, links must be http(s) or site-relative, at most
2,000 visible characters. Because only sanitized markup is stored, output it
without `| escape` (escaping would show the tags as text).

## url

UI: link input. Validation: `http://` / `https://` URLs or site-relative
paths starting with `/` only — `javascript:`, `data:`, protocol-relative
`//host` and values containing spaces, quotes or angle brackets are
rejected. `""` means no link. Output it with `| escape` inside `href`.

## category / item_list / branch

Pickers over the store's OWN data. UI: dropdown (`category`, `branch`) or a
searchable checklist (`item_list`). Values are row ids: `category` and
`branch` are one id, `item_list` is a list of up to 24 unique item ids (a
def's `max` may lower the cap). On save every id must belong to the store
being customized. Read the matching rows from the `menu` / `branches`
drops in your template by comparing ids.

## How the merchant customizer stores values (v2)

Everything the merchant edits is DATA validated server-side against your
schema — no code path exists from the customizer into a theme. Since the
customizer v2 rebuild, the rules are:

- Your theme's settings surface (theme-level `config/settings_schema.json`
  groups plus each section's `{% schema %}` settings, namespaced
  `sections.<sectionId>.<key>`) is normalized server-side into one v2 shape
  before the editor renders it, whatever shape it was authored in.
- **One canonical key per setting.** Legacy/alias ids listed in
  `config/theme-aliases.php` resolve to their canonical id at read time and
  are folded onto it on save — a setting can never exist under two keys.
- Values persist under the **versionless** `mp:<slug>` key, so merchant
  customizations survive a theme version update; publish a new version and
  saved values for unchanged setting ids carry over.
- The merchant side layers drafts + publish, version history with revert,
  JSON export/import, presets, and copy-between-stores (same theme family)
  on top of this data. All of these re-validate against your schema, so an
  imported or reverted value set can never contain anything your defs would
  reject.

First-party Blade themes additionally expose a design-token layer (CSS
custom properties `--tc-*` colour roles with derived tint/shade ramps,
`--tr-*` radius, `--ts-*` spacing, `--tf-*` font stacks, emitted
overrides-only with a warn-only WCAG contrast guard). Liquid marketplace
renders do not receive these vars — your values reach the template only
through `section.settings` and theme settings.

## Templates and section instances

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

- `sections.<id>.type` names the file `sections/<type>.liquid`.
- Instance `settings` override schema defaults; merchant customizer values
  override both. Merchants can also toggle whole sections off.
- `disabled: true` on an instance skips it. Section count is capped
  (engine constant `MAX_SECTIONS`) — exceeding it fails the render.

## Worked example (from the starter theme)

```liquid
<h1>{% if section.settings.heading != '' %}{{ section.settings.heading | escape }}{% else %}{{ restaurant.title | escape }}{% endif %}</h1>

{% schema %}
{
  "name": "Hero",
  "settings": [
    { "id": "heading", "type": "text", "label": "Custom heading (blank = restaurant name)", "default": "" },
    { "id": "accent", "type": "color", "label": "Accent colour", "default": "#3fd463" }
  ]
}
{% endschema %}
```
