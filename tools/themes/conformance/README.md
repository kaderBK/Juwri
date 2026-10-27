# Theme conformance (P3-04, THEME-CONTRACT §11)

Source: copy of `~/Desktop/juwri-themes/tools/conformance/` (design repo commit `50c0670`, files dated 2026-10-02), plus
the inputs it reads. The kit original README is kept as `KIT-README.md` (rules table, grep gate allow-list syntax).

## What is here
| Path | What |
|---|---|
| `run_all.py schema_lint.py grep_gates.py totals_parity.py rtl/ lighthouse/size_budget.py tests/` | the kit gates, Python 3 stdlib: checks 1, 2, 3, 6-static, size budgets. `python3 run_all.py --self-test` proves each gate fires on `tests/bad` and stays quiet on `tests/good`. |
| `kit-snapshot/` | the inputs, laid out like the design repo root: `prototypes/` (the pages as designed), `kit/<theme>/{theme.json,css/}`, `kit/viewmodel/` (fixtures + schema), `tokens/`, `tools/{build_theme_css,check_derived}.py`, `tools/{a11y,keyboard}.mjs`, `tools/csp_server.py`. Regenerate from the design repo when the kit changes. |
| `browser-job.mjs`, `inflate.mjs` | Node job: axe, keyboard, CSP, Lighthouse budgets, RTL snapshot diff (below). |
| `modes.json`, `csp-policy.json` | report/enforce switch; the browser CSP. |
| `package.json` | devDependencies of the Node job only: `axe-core`, `lighthouse`, `pixelmatch`, `pngjs` (Chrome comes from the puppeteer in `core/package.json`). Not shipped, listed in the CI dependency audit. |
| `axe/ lighthouse/lighthouserc.json rtl/snapshot.mjs KIT-README.md` | kit originals (Playwright / LHCI based), kept for reference, not used. |

## Changes to the copied files (all marked `juwri` in the source)
- `schema_lint.py grep_gates.py totals_parity.py rtl/mirror_check.py lighthouse/size_budget.py`: `ROOT` is `$CONFORMANCE_ROOT` or `kit-snapshot/` (the kit assumed they sit two folders below the repo root); `schema_lint` reads its helpers from `ROOT/tools`.
- `run_all.py`: `--root DIR` and `--theme SLUG` (one theme of a tree: schema lint on `kit/<slug>`, the other gates on `kit/<slug>`, `prototypes/<slug>`, `prototypes/_kit`).
- `kit-snapshot/tools/a11y.mjs`: desktop width 1440 (was 1280), `A11Y_PORT` env.
- `kit-snapshot/tools/keyboard.mjs` `K_PORT` / `K_SRV` and `a11y.mjs` `A11Y_CSP_PORT` env (P3-15): `browser-job.mjs` gives every run its own random port block (`JOB_PORT_BASE` pins it). The fixed 8790 / 8791 / 9335 / 9340 ports collided with other jobs and e2e servers on a shared runner, and the keyboard pass then walked someone else's pages (1 tab stop per run, "page has no #main").
- `kit-snapshot/tools/keyboard.mjs` (P3-05, for pages that run real storefront JS): a dialog trigger must sit outside every dialog and `[inert]` and be on screen (the empty-cart bar of a live store is hidden); the Tab cycle is walked from the document start after the core JS has started (it moves focus into a dialog that opened with the page); a stop inside a `<nav>` in a side column reached on the same line is column order, not a backwards jump.

## Where things run
- **PHP suite** `core/tests/Modules/Storefront/ThemeConformanceTest.php` (+ `core/tests/Support/ThemeConformance.php`): self test; every prototype page is rendered through the Blade stub `core/tests/Fixtures/views/theme-conformance/page.blade.php` into a temp tree, then `run_all.py --root TREE --theme X` for each theme; a negative control (inline handler / style / script injected) must fail; the report/enforce switch; the browser CSP is no looser than `StorefrontPolicy`. Runs like any other test (`python3` required). When P3-02/P3-05 land, replace `ThemeConformance::render()` with the real theme render; nothing else changes.
- **Node job** (own slot, never beside a full suite): `cd tools/themes/conformance && npm ci && node browser-job.mjs [--quick] [--themes table,brew] [--steps axe,keyboard,csp,lighthouse,rtl]`. Needs `npm ci` in `core/` for puppeteer. `--root` points at a rendered tree (run the PHP test with `THEME_CONFORMANCE_OUT=/some/dir` to keep it), default is the snapshot. Report: `out/browser-job-report.json`. Unit test: `node --test inflate.test.mjs`.
- **CI**: `.github/workflows/ci.yml` audits this folder's npm dependencies; `nightly.yml` job `theme-conformance-browser` runs the Node job (report mode).

## Report vs enforce
`modes.json`: `kit_mode` (table, brew, aisle, basket, book) and `premium_mode` (carely and any other slug) are `report` or `enforce`.
Report: a failing gate is printed (PHP: stderr, Node: `REPORT` lines) and written to the report file, the test/job stays green.
Enforce: it fails the test (PHP) / exits 1 (Node). One-off override: `THEME_CONFORMANCE_KIT=enforce`, `THEME_CONFORMANCE_PREMIUM=enforce`.
Owner Q7: budgets block for the 5 kit themes, report-only for premium. **P3-05 flipped `kit_mode` to `enforce`**; premium stays `report`. A kit theme listed in `report_until_ported` (aisle, basket, book; `brew` came off the list in P3-07) has no Blade views yet, only the design prototypes, so it stays report-only until its own work item ports it and removes it from the list. The enforced browser job runs on a RENDERED tree (`--root`), not on the prototypes: the prototypes' own preview script blocks the main thread on the runner.

## Browser job details
- axe / keyboard: the kit scripts, per theme (en + ar, light + dark, 390 + 1440, 320 px reflow; keyboard order, focus visible/not obscured, no trap, dialogs).
- csp: every page of every theme, en + ar, light + dark, in Chrome under `csp-policy.json` (core storefront policy, no nonces, plus `style-src-attr 'none'`). Any `securitypolicyviolation` fails. This is P1-20's owed per-theme browser CSP check.
- lighthouse: mobile profile, simulated slow 4G + 4x CPU, home en + ar at 30 and 300 catalogue items (the server resizes the item list with `?items=N`, gzip on). Budgets (P3-05: re-measured on the runner with the `table` Blade theme, median of 3 runs, see the comment above `BUDGET` in `browser-job.mjs`): LCP 3.2 s, CLS 0.1, TBT 200 ms, script 90 KB, CSS 40 KB, fonts 4 files / 200 KB (the Arabic page carries IBM Plex Sans Arabic), 0 third party, accessibility score 0.95, unsized images, font-display, text compression. Lab numbers on a loaded laptop are noisy: use the runner for the reference run.
- rtl: every `ar` page, both widths and schemes, screenshotted from `--root` and from `--baseline-root` (the prototypes as designed) in the same Chrome and diffed with pixelmatch (limit 0.1 % of pixels; `modes.json` `rtl.<theme>` sets a per-theme `limit` and names `exempt` screens with the reason). No binary baselines are committed. For a theme ported to Blade the compared tree is the PARITY tree: `KitTree::writeParity` renders the Blade views from the kit's own view-model fixtures (no database), so the diff measures the port, not store data. `table`: limit 0.5 %; `track` (the prototype's decorative map) and `states` (the not-yet-product hosted-payment cards) are exempt; `brew`: limit 2.5 % (the item and phone pages are under 0.5 %; the desktop tracking page differs by about 2.1 % because the kit draws icons from its own set, not the prototype's hand-drawn glyphs, and the prototype shows a demo payment line and a brand glyph that no fixture carries); `states` exempt as for `table`. the cart and checkout are dialogs in the Blade theme and standalone pages in the prototypes, so the parity tree keeps the prototypes' pages for them (axe, keyboard and CSP cover the real dialogs).
