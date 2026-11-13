---
title: "Submitting a theme to the marketplace"
audience: developer
group: Themes
updated: 2026-09-10
---

# Submitting a theme to the marketplace

## Flow

1. Build and validate locally (`validate_theme` MCP tool).
2. Submit the zip — the package validator runs the hard gates
   (see the validation doc); the parsed manifest creates a theme version.
3. Quality gate: an automated pass (performance/accessibility bar over demo
   renders) must clear before the version is publishable.
4. Versions are immutable; ship a fix as a new `version` in `theme.json`.
   A theme update replaces section code without wiping merchant settings —
   never rename setting `id`s casually.

## Rules of thumb

- Keep `slug` stable across versions; it identifies the theme.
- `preview.png` at root is your listing image.
- Test with real-looking data: long item names, RTL locales, missing images —
  the demo storefront exercises these.
- Locale strings: anything user-visible should come from `locales/*.json`
  via the `t` filter where practical.

## Which stores can pick your theme

The platform now hosts stores beyond restaurants (retail, cosmetics,
clothing, car parts, laundry/services, pharmacy — each with its own
`store_type`), and a store's theme picker only ever offers templates built
for its **theme set**: `food` (restaurants — 45+ first-party templates plus
every marketplace Liquid theme today), `retail` (goods stores; the
first-party **Shelf** grid theme), or `service` (laundry/services stores;
the first-party **Booking** theme). Every Liquid marketplace submission is
currently placed in the `food` set — there is no submission path yet for a
theme targeting the `retail`/`service` sets, so a submitted theme is only
ever offered to a restaurant store's picker, never a goods or services
store's.
