---
title: "Theme package structure"
audience: developer
group: Themes
updated: 2026-09-10
---

# Theme package structure

A theme ships as a zip with a fixed layout. The validator rejects anything
outside these rules before extraction — get them right first.

A theme package is the same regardless of what kind of store will use it,
but which stores are *offered* your theme depends on its theme set (food,
retail, or service) — see [Submission: which stores can pick your
theme](submission.md#which-stores-can-pick-your-theme).

## Layout

```
theme.json                    # manifest at root (required)
preview.png                   # optional root file
templates/index.json          # entrypoint (or templates/index.liquid)
sections/*.liquid
snippets/*.liquid
layout/                       # optional layout wrappers
config/settings_schema.json
locales/en.json
assets/                       # css/images/fonts
```

Allowed top-level directories: `templates`, `sections`, `snippets`, `assets`,
`config`, `locales`, `layout`. Allowed root files: `theme.json`, `preview.png`.
Maximum directory depth: 3.

## Manifest (theme.json)

Valid JSON with `name`, `slug`, `version`. Slug and version become filesystem
path segments — keep them resolver-safe (lowercase, digits, dashes, dots).

## Hard caps

- 10 MB uncompressed total, 2 MB per file, 1000 entries max.
- Allowed extensions ONLY: `liquid`, `json`, `css`, `png`, `jpg`, `jpeg`,
  `webp`, `svg`, `woff2`.
- **No `.php` anywhere. No `.js` in v1** — interactivity comes from the
  platform commerce runtime via the `{% commerce %}` tag.

## Dangerous markup (rejected)

`<script>` bodies, `on*=` handlers, `javascript:` URLs, `<iframe>` in
`.liquid` files; `<script>`, `<foreignObject>`, `on*=` in `.svg` files.
