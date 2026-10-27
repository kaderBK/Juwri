# html-mode builder runtime + merge tags

Two platform-owned capabilities that make sanitised html-mode builder pages
**interactive** and **dynamic** without ever letting a semi-trusted operator
author JavaScript. Both are gated by `App\Support\Html::cleanPage()` (operator
HTML keeps layout + inline CSS + scoped `<style>`, drops all `<script>`/`on*`).

Surfaces: CMS `/page/{slug}`, reseller public pages, biolink `/{slug}/bio`.
Loaded via `resources/views/builder/_runtime-assets.blade.php`.

---

## 1. Interaction hooks (`mb-builder.js` + `.css`)

The runtime scans `.mb-doc` for `data-mb-*` attributes and wires accessible,
`prefers-reduced-motion`-aware behaviour. Operators add these attributes to
ordinary elements via the editor's blocks (Interactive category) or the Settings panel (GrapesJS).

> **Triggers must NOT be `<button>`** — `cleanPage` strips form controls. Use a
> `<div>`/`<span>`/`<a>`; the runtime upgrades it to `role=button` + keyboard
> (Enter/Space/Arrow/Home/End). The carousel's own dot buttons are created by JS,
> so they are fine.

| Hook | Markup |
|------|--------|
| **Accordion** | `data-mb-accordion` (add `="single"` to auto-close others) on a container; each item `data-mb-accordion-item` (add `data-mb-open` to start open) with a `data-mb-accordion-trigger` and a `data-mb-accordion-panel`. |
| **Tabs** | `data-mb-tabs` container; `data-mb-tab` triggers + `data-mb-tabpanel` panels (matched by order, or by a shared id: `data-mb-tab="x"` / `data-mb-tabpanel="x"`). |
| **Carousel** | `data-mb-carousel` with a `data-mb-carousel-track` (scroll-snaps its children); optional `data-mb-carousel-prev` / `-next` / `-dots` (dots auto-populated); optional `data-mb-carousel-autoplay="5000"` (ms; pauses on hover/focus/hidden tab; off under reduced-motion). |
| **Scroll reveal** | `data-mb-reveal` (or `="up\|fade\|zoom\|left\|right"`), optional `data-mb-reveal-delay="120"`. Element is **visible without JS**; the effect arms only after the runtime runs. |
| **Count up** | `data-mb-count-up` on the number element; `data-mb-count-to="3300"` (else parsed from text), `data-mb-count-duration="1600"`. Re-formats with the page locale (Arabic-Indic digits supported). |
| **Word rotator** | `data-mb-rotate` container + `data-mb-rotate-item` children; `data-mb-rotate-interval="2600"`. Reduced-motion shows the first item only. |
| **Parallax** | `data-mb-parallax`, `data-mb-parallax-speed="0.2"`. Desktop + motion-ok only (skipped on touch and reduced-motion). |

Guarantees: idempotent (`data-mb-ready`), no operator JS, CSP-clean (external
`src`/`link` from `'self'`, script also nonce'd), content stays readable if JS
fails or the browser is old. `window.MBBuilder.init(root)` re-scans after dynamic
inserts (used by the editor preview).

---

## 2. Merge tags (`App\Services\Builder\MergeTags`)

Server-side, escaped data interpolation at render time (runs right after
`cleanPage`, matching the email builder order so the URL-attribute percent-encoded
form `%7B%7B…%7D%7D` resolves). No code execution.

Vocabulary (see `MergeTags::available()`): `{{store.name}}`, `{{store.tagline}}`,
`{{store.phone}}`, `{{store.email}}`, `{{store.address}}`, `{{store.url}}`,
`{{store.currency}}`, `{{page.title}}`, `{{year}}`, `{{date}}`.

Known-but-empty tokens resolve to blank; unknown tokens are left literal so a typo
is visible to the operator. Values are `htmlspecialchars`-escaped, so a token can
never inject markup.

Per-surface context builders: `MergeTags::forPage($page, $settings)`,
`forReseller($reseller, $page)`, `forPost($store)`.
