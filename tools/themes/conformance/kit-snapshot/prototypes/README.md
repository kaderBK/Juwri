# HTML prototypes (replace the Pen designs)

Static HTML pages that render each theme's screens from a real view model (`kit/viewmodel/fixtures/<theme>.json`). They are the design reference and the starting markup for the Blade templates. They are not Juwri code: no PHP, no app JS, and no network calls.

## Layout of this folder
```
prototypes/
  _kit/kit.css            shared base: reset, layout primitives, components (uses --x-* only)
  _kit/proto.js           preview bar only: language, scheme, preset, viewport (not theme JS)
  <theme>/theme.css       the theme's look on top of kit.css (uses --x-* only)
  <theme>/{en,ar}/<screen>.html   generated pages
  index.html              gallery of every theme × screen
tools/build_prototypes.py generator: fixture JSON → static HTML
```
Screens per theme: `home`, `item`, `cart`, `checkout`, `track`, `states`, plus theme extras (`time` for book, `product` for aisle, `kiosk` for table and brew). One page serves phone and desktop through responsive CSS. Check it at 390 and 1440 wide.

## Rules (the same ones the real theme must follow)
- **Markup = future Blade.** `build_prototypes.py` has one Python function per section id (`header`, `order_type`, `catalog`, …), and each one becomes `sections/<id>.blade.php`. The functions read only the view model. Keep their HTML as plain as the Blade will be: semantic elements, `aria-*`, `data-*` hooks, BEM-ish classes `c-<component>__<part>--<modifier>`.
- **CSS:** load `kit/<theme>/css/tokens.css`, then `_kit/kit.css`, then `<theme>/theme.css`.
  - Use only `--x-*` tokens.
  - No hex outside tokens.css, no `style=""`.
  - Use logical properties only (`margin-inline-start`, `inset-inline-end`, `text-align: start`); no `left`/`right` except in an allow-listed comment.
- **RTL:** `ar` pages have `<html lang="ar" dir="rtl">`. Mirroring comes from logical CSS, with no separate RTL stylesheet. Directional icons flip with `[dir=rtl] .c-icon--dir { transform: scaleX(-1) }`. Codes such as order numbers and phone numbers go in `<bdi>`.
- **Money:** print `formatted` from the fixture. Never compute or format money in the page.
- **Schemes and presets:** `<html data-scheme="light|dark|auto">`. The preview bar swaps `kit/<theme>/css/preset-<id>.css` to show a preset as core would emit it.
- **Images:** the fixture's `image.src` points to a local placeholder file (`_kit/img/placeholder-<ratio>.svg`). Real photos come later and change no markup.
- **Accessibility:** WCAG 2.2 AA (contract §8). Primary targets are 44×44, chips 32 px, focus is visible, and states are text plus icon.
- **No JS behaviour.** States like an open sheet or a selected slot are separate generated pages or `?state=` variants, not scripts. The only JS is the preview bar.

## Run
```
python3 tools/build_theme_css.py && python3 tools/build_prototypes.py
python3 -m http.server -d . 8765     # then open http://localhost:8765/prototypes/
```

## Screenshots
```
node --experimental-websocket tools/shoot.mjs thumbs   # kit/<theme>/presets/*.png + cover.png (committed)
node --experimental-websocket tools/shoot.mjs pages    # out/shots/<theme>/<loc>-<page>-<device>-<scheme>.png (not committed)
```
Uses headless Google Chrome. Pages accept `?scheme=dark&preset=<id>` (one load only, not saved) and `?bar=0`.


## Keyboard and focus check
```
node --experimental-websocket tools/keyboard.mjs [filter…] [--quick]   # must print violations 0
```
Presses Tab and Shift+Tab (real key events in headless Chrome, pages served with the storefront CSP) through every page above plus `_customizer/` and the gallery, en and ar, at 390 and 1440, light and dark. It fails on: an element Tab never reaches, `tabindex` > 0, a missing or broken skip link, focus moving backwards against the reading order (mirrored for RTL), a focus indicator under 2 px or under 3:1 against what it covers, a focused element entirely hidden by a sticky/fixed bar, a keyboard trap, and dialogs that do not take and keep focus. Allowed exceptions are printed with the result and stored in `out/keyboard.json`. Always-open dialogs (item sheet, bag drawer, filters, carely gate) rely on `inert` on the page behind them; Esc and focus return need the core sheet-a11y script and are reported as pending. The aisle size guide is a no-JS `:target` dialog: the rest of the page is inert while it is open (CSS `interactivity: inert`) and its close links point back at the trigger.

## Owner-dashboard Customizer mockups (`_customizer/`)
Static mockups of the Customizer additions in `sections/customizer.md` (scheme mode cards, Light/Dark colour rows, preview toggles, split-thumbnail presets, contrast guard, layout card grids, sections). They show the intended owner-dashboard UI for the Juwri build session (P3-01); they are not theme pages and not Juwri code. Open `prototypes/_customizer/index.html`; each page exists in `en/` and `ar/` (RTL). Styling is `_customizer/customizer.css` with its own `--d-*` tokens (light and dark by `prefers-color-scheme`); the preview iframe loads `../../table/<loc>/home.html?scheme=…&bar=0`. Hand-written, not generated by `build_prototypes.py`.
