---
title: "Sections, snippets and templates"
audience: developer
group: Themes
updated: 2026-07-07
---

# Sections, snippets and templates

## Render model

`templates/index.json` lists section instances (`sections` map + `order`
array). Each instance's `type` names a file `sections/<type>.liquid`. The
engine renders sections in order; a broken section fails safe rather than
taking the page down. Section count is capped (`MAX_SECTIONS`).

Merchant-facing settings flow: schema `default` → instance `settings` in
index.json → merchant customizer values. Merchants can toggle whole sections
off; instances can also ship `"disabled": true`.

## Sections

One `.liquid` file per section under `sections/`. May end with a
`{% schema %}` block (see the schema-settings doc). Values are read via
`section.settings.<id>`; the section's id and type are on the `section` drop.

## Snippets

Partials under `snippets/`, included with the standard Liquid `{% render %}`
tag. Snippets receive only what you pass them — keep them pure.

## Layout

Optional wrappers under `layout/`. Put `{% commerce %}` once in the layout so
every page gets the commerce runtime (cart/checkout) without theme JS.

## Data access

All data comes from drops (see `get_liquid_reference` kind=drops):
`restaurant`, categories/items, `table`, `section`, and friends. Themes never
call HTTP APIs — the commerce runtime handles ordering.

## Environments

- `upload`: strict parse of every `.liquid` file at submission — whitelist
  violations and schema JSON errors fail here.
- `live`: rendering with real data under a resource budget (time/memory caps).
Both use the same closed whitelist; passing `upload` means it will parse in
`live`.
