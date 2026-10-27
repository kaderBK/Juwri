# Owner dashboard UI kit — adoption contract (P1-6)

One page. The rules every owner-dashboard blade must follow so the surface reads as one
system and survives dark mode / RTL. "Adopt" = use the component/token, don't hand-roll.

## Tokens (never hardcode)
- **Colour**: only Bootstrap CSS variables / semantic classes. NO hex literals in blades or inline
  styles for badges, text, backgrounds, borders. Chart JS may fall back to a hex *after* reading a
  `--bs-*` variable (see analytics chart `g('--bs-primary', …)`). Everything else → `var(--bs-*)`
  or a `text-bg-*` / `bg-*-subtle` class.
- **Numbers**: numeric table cells and stat values use `text-end` + `tabular-nums` (add
  `font-variant-numeric: tabular-nums` via the `tabular-nums` utility) so digits align.
- **Spacing**: card bodies `p-3`/`p-2`; vertical rhythm `mb-3` between blocks; `gap-*` for flex.

## Components (use these, not bespoke markup)
| Need | Use | Not |
|---|---|---|
| Button | `x-ui.button` (variant: primary/secondary/danger/light) | raw `<button class="btn …">` with ad-hoc classes |
| Badge / status pill | `x-ui.pill` or `text-bg-{variant}` / `bg-{variant}-subtle text-{variant}` | hardcoded hex pill (`style="background:#…"`) |
| Empty state | `x-ui.empty` with `:cta`/`:cta-href` | bespoke "No data" divs |
| Store/branch selector | `partials/context-store-select` (P1-3 write-through) | page-local `?restaurant_id` `<select>` |
| Period selector | `partials/periodbar` (P1-4 one model) | page-local `?range=`/`?period=` controls |
| Feature sub-views | `partials/feature-tabs` via `juwri_feature_tabs()` (P1-2) | duplicate nav rows |
| Table | `table-responsive` > `table table-sm align-middle mb-0`; adopt `x-ui.data-table` where slots fit | raw unstyled `<table>` |
| Confirm / toast | `window.dashConfirm()` / `window.dashToast()` (P1-5) | `window.confirm` / `window.alert` |

## Badge variant map (semantic → variant)
- success / positive / "Star" / paid / active → `success`
- warning / caution / "Plowhorse" / pending → `warning`
- danger / error / "Dog" / failed / open dispute → `danger`
- info / neutral-highlight / "Puzzle" / "Popular" → `info` / `primary`
- muted / "Unpopular" / disabled → `secondary`

## Dark mode & RTL
- Verify every badge/label against `prefers-color-scheme: dark` — no hex means it inherits.
- No `left`/`right` hardcoding; use `start`/`end` (`ms-*`/`me-*`, `text-end`, `inset-inline-*`).

## Sweep order (page groups, ownership zones — parallel-safe)
orders · stations · foodics/marn · analytics · inventory · coupons/gift-cards · staff/rota · books
cluster. Each: swap badges→variants, numeric cells→`text-end tabular-nums`, buttons→`x-ui.button`,
empties→`x-ui.empty`, selectors→shared partials. Spot-check with a hex-in-blade grep per page.

## Conformance check
`grep -rnE 'style="[^"]*#[0-9a-fA-F]{6}' L:/user` should return only chart-config fallbacks, never
badge/pill fills. The menu-engineering quadrant pills were the known dark-mode offender (fixed P1-6).
