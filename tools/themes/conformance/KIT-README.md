# Theme conformance checks (THEME-CONTRACT.md §11)

Portable scripts for the Juwri build session. Checks 1, 2, 3 and the static half of 6 are Python 3 stdlib only. Checks 4, 5 and the screenshot half of 6 are Node scripts and config that need npm packages (not installed in this design repo).

```
python3 tools/conformance/run_all.py              # 1, 2, 3, 6-static, size budgets; exit 1 on any failure
python3 tools/conformance/run_all.py --self-test  # every gate must fire on tests/bad and stay quiet on tests/good
python3 tools/conformance/run_all.py <theme_dir>… # point 1, 2, 6 and size budgets at other folders
```

| # | Script | Contract clause | What it enforces |
|---|---|---|---|
| 1 | `schema_lint.py [theme_dir\|theme.json …]` | §11.1, §2.1, §3, §8 | SchemaV2 shape (version 2, presets/settings/sections/texts, only those top-level keys, `texts.localized`). Canonical field ids present (`colors.*`, `colors_dark.*`, `scheme.mode`, `layout.*`, `fonts.pair`, `menu.show_*`, a `texts.*` field, fragments `tokens/splash/search/share`). Select defaults are in their options (and in the §3 option set for core-fragment fields). `fonts.pair` default is a real pair (D2). 2 to 5 presets; every preset value key is a field id with a valid option; all 10 role colours set. Required sections present, default on, not toggleable (`search` may be toggleable). Contrast for every preset × scheme with the same pairs and maths as `tools/check_derived.py` (the `CHECKS` list is read from that file, the maths is imported from `tools/build_theme_css.py`). Drift: preset roles equal `tokens/<theme>.json`. |
| 2 | `grep_gates.py [dir\|file …]` | §11.2, §3, §4, §6, §7 | See the rule table below. Default target: `prototypes/` and `kit/`. Output `file:line: rule: message`. |
| 3 | `totals_parity.py [fixture.json …]` | §11.3, §5.2, §6 | For every Quote: `sum(lines.line_total) == subtotal`; `total = subtotal + fee + delivery + tip + exclusive tax − discount`; inclusive tax rows, `estimate_cap` rows and muted discount rows ("You save") are informational; `deposit + due_later == total`; the `total` row equals `quote.total`. For every Money: `minor` equals the parsed `formatted`, decimals equal the currency exponent (SAR 2, KWD 3, JPY 0; fixture `money.currency.exponent` wins), locale pattern (en `SAR 1.00` / `−SAR 1.00`, ar `1.00 ر.س`), one symbol per currency. `--self-test` covers KWD (3 dp), a fixed-amount tax, inclusive tax, JPY and a deposit split. `--strict-zero` rejects words such as "Free" in a Money. Default: all `kit/viewmodel/fixtures/*.json` (Money is checked everywhere, Quote parity wherever a Quote exists). |
| 4 | `axe/run-axe.mjs` + `axe/urls.json` | §11.4, §8 | axe-core with tags `wcag2a, wcag2aa, wcag21a, wcag21aa, wcag22aa` on every page × light/dark × phone 390 / desktop 1440. Exit 1 on any violation (`--impact` raises the floor). Pages may list `states` (a click selector or `?state=` query) for the item sheet, errors and empty states. |
| 5 | `lighthouse/lighthouserc.json`, `lighthouse/size_budget.py` | §11.5, §9 | Lighthouse CI, mobile emulation, slow 4G and 4× CPU. Errors on LCP ≤ 2.5 s, CLS ≤ 0.1, TBT ≤ 200 ms (lab proxy for INP), script transfer ≤ 90 KB (92160 B), stylesheets ≤ 40 KB (25 theme + 15 core), fonts ≤ 4 files and ≤ 140 KB, 0 third-party requests, no unsized images, `font-display`, text compression, accessibility score ≥ 0.95. `size_budget.py` covers what LHCI cannot isolate: `js/theme.js` ≤ 20 KB gzip, theme CSS ≤ 25 KB gzip, core CSS ≤ 15 KB gzip, fonts. |
| 6 | `rtl/mirror_check.py`, `rtl/snapshot.mjs` | §11.6, §6 | Static: `<html lang="ar" dir="rtl">`, no `dir="ltr"` on html/body, directional icons (chevron, arrow, caret, back, next, prev) carry a `--dir` class (and non-directional ones such as play, check, clock do not), `[dir=rtl] .c-icon--dir` flip rule exists in `prototypes/_kit/kit.css`, `tel:` links and tel/OTP inputs are `dir="ltr"` or `<bdi>`, no `translateX`/`scaleX(-1)` in `<style>`. Visual: Playwright screenshot of every `ar` page (phone and desktop, light and dark) diffed against `rtl/baseline/` with pixelmatch (default limit 0.1 % of pixels). Create the baselines with `--update` after checking them against `designs/<theme>.pen`. |

## Grep gate rules (`grep_gates.py`)
| Rule id | Fires on | Contract |
|---|---|---|
| `raw-blade` | `{!!` | §7 |
| `inline-handler` | `on[a-z]+=` attribute inside a tag (and `onclick="…"`-style strings in JS) | §7 |
| `javascript-url` | `href/src/action="javascript:…"` | §7 |
| `inline-script` | `<script>` with neither `src` nor `type="application/json"` | §7 |
| `style-attr` | `style="…"` (also `setAttribute('style'…)` and `.cssText =` in JS) | §7, D5 |
| `hex-outside-tokens` | hex colour in CSS outside `:root` / `[data-scheme…]` blocks (including inside `@media`). `tokens.css` is exempt. `<style>` blocks in HTML are scanned too. | §3 |
| `physical-css` | `left`/`right` in a property name (`margin-left`, `padding-right`, `border-left-width`, `border-top-left-radius`, `left:`, `right:`), `text-align`/`float`/`clear: left\|right`, `background-position` / `object-position` / `transform-origin` with `left\|right` | §6 |
| `external-host` | `http(s)://` or `//` in `src`, `href`, `srcset`, `poster`, `action`, `url()`, `@import`; any `http(s)://` string in JS (the `www.w3.org` XML namespace is allowed) | §7 |
| `vm-bypass` | `post_options(`, `request(`, `\App\Models`, `DB::` in Blade (`.php`) outside `{{-- --}}` comments | §4 |
| `tc-var-outside-tokens` | `var(--tc-*)` in any CSS other than `tokens.css`; components use `--x-*` | §3 |
| `target-blank` | `target="_blank"` without `rel="noopener noreferrer"` | §7 |
| `js-eval` | `eval(`, `new Function`, `setTimeout('…')` | §7 |

### Allow-list syntax
- Raw Blade output, on the same line or the line before, with a reason (a marker without a reason still fails):
  `{{-- conformance:allow-raw core-sanitised owner HTML --}}`
- Physical CSS, on the same line or the line before:
  `background-position: right center; /* conformance:allow-physical */`
- There is no allow-list for hex colours, inline handlers, `style=""`, external hosts or `var(--tc-*)`. Fix the code.

## Wiring into Juwri CI
Python gates (no install):
```
python3 tools/conformance/run_all.py --self-test
python3 tools/conformance/run_all.py assets/store_templates/<slug> core/resources/views/store_templates/<slug>
python3 tools/conformance/totals_parity.py <rendered view-model fixtures from the quote endpoint tests>
```
Run `schema_lint.py` and `grep_gates.py` on both the `assets/store_templates/<slug>` and the `views/store_templates/<slug>` trees. `schema_lint.py` needs `tools/build_theme_css.py` and `tools/check_derived.py` next to it (it imports them) and reads `tokens/<theme>.json` for status colours and drift when that file exists.

Browser gates (Node 20+):
```
cd tools/conformance
npm install                       # playwright, @axe-core/playwright, pixelmatch, pngjs, @lhci/cli (see package.json)
npx playwright install --with-deps chromium
# serve the repo or the demo store, copy axe/urls.example.json to axe/urls.json and set "base"
node axe/run-axe.mjs --urls axe/urls.json
node rtl/snapshot.mjs --urls axe/urls.json            # --update to (re)create baselines
npx lhci autorun --config=lighthouse/lighthouserc.json
python3 lighthouse/size_budget.py <theme dirs>
```
- axe and the RTL snapshots read the same `urls.json` (pages × `en`/`ar`, light/dark through `data-scheme`, or `"scheme_mode": "query"` for `?scheme=`). For the Juwri demo store replace `pages[].path` with the real store URLs, and add `states` for the item sheet, empty cart, closed store, payment failed and offline.
- Lighthouse: `collect.url` lists the prototype home pages. In Juwri CI point it at the demo store with 30 and 300 items, `en` and `ar` (§9). `INP` has no lab value, so TBT ≤ 200 ms is the gate; the field INP budget needs RUM. "Requests before LCP ≤ 12" and image weights (hero ≤ 150 KB, card ≤ 30 KB) are not covered by LHCI assertions; check them with the media pipeline tests.
- Playwright needs `data-scheme="auto"` to follow `colorScheme`; the scripts set both.
- Without npm: `AXE=/path/to/axe.min.js node --experimental-websocket tools/a11y.mjs` runs the same axe tags over every prototype page (phone/desktop × light/dark) in local Chrome over CDP and writes `out/a11y.json`. Also checks reflow at 320 px (WCAG 1.4.10), and with `--csp` loads every page under the Juwri storefront CSP (`tools/csp_server.py`, plus `style-src-attr 'none'`). Last run 2026-10-02: 420 runs, 0 violations.

## Layout
```
tools/conformance/
  schema_lint.py  grep_gates.py  totals_parity.py  run_all.py
  axe/run-axe.mjs  axe/urls.example.json
  lighthouse/lighthouserc.json  lighthouse/size_budget.py
  rtl/mirror_check.py  rtl/snapshot.mjs
  tests/good/  tests/bad/  tests/bad_theme/  tests/rtl/     samples for --self-test
  package.json                                              npm deps for 4, 5, 6 (not installed here)
```
`tests/bad` and `tests/bad_theme` contain deliberate violations. Do not point the gates at `tools/conformance/tests` in CI.
