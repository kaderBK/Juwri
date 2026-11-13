---
title: "Menu Studio internals"
audience: internal
group: Internal
updated: 2026-07-08
---

# Menu Studio internals

Menu Studio is the public, no-signup surface where a visitor turns a photo or pasted text of a menu into either a hosted online-ordering menu or a print/PDF-styled design. It runs on guest sessions (cookie-scoped), a server-side credit ledger, and an auto-discovered HTML template engine.

Everything here lives under `core/app/Http/Controllers/Studio/`, `core/app/Services/Studio/`, `core/resources/views/studio/`, and `core/config/studio.php`. Routes are in `core/routes/web.php` (registered before the `{slug}` restaurant catch-all so `/studio` does not resolve as a restaurant).

## Sessions and the guest cookie

`StudioController::resolveSession()` is the entry point for nearly every action. It reads the `studio_guest` cookie, looks up a live `StudioSession` by `guest_token`, and mints a fresh one (`Str::random(48)`, `status=exploring`, `expires_at = now + 48h`) if none exists or the current one is expired. Every JSON response re-sets the cookie for 3 days.

`StudioSession` (`core/app/Models/StudioSession.php`):

- `guest_token` — the session credential. Whoever holds it owns the session; a `?resume=<guest_token>` query on the landing pages restores it. Never publish it.
- `storagePath($suffix)` — returns `studio/<guest_token>/<suffix>` on the `local` disk. All per-session artifacts (`draft.json`, `draft.prev.json`, `template.json`, `design.png`, `template-bg.png`, `template-export.pdf|png`) live under this prefix.
- `isExpired()`, `purgeFiles()` (deletes the whole `studio/<guest_token>` directory), `ensureShareToken()`.

Guest sessions and their files auto-purge. `php artisan studio:prune` (`PruneStudioSessions`, scheduled hourly in `Console\Kernel`) deletes guest sessions (`user_id` null) past `expires_at` and calls `purgeFiles()`; sessions migrated to an account are kept. On Google login the session migrates to the account: `publish()` sets `user_id` and `status=published`.

## share_token vs guest_token

This split is a deliberate security boundary. The `guest_token` is a bearer credential — it resolves and resumes a session. The `share_token` is a separate public identifier minted lazily by `ensureShareToken()` only when the user asks for a share link (`POST /studio/share-link`).

- `share()` (`GET /studio/s/{token}`) looks sessions up strictly by `share_token`, never `guest_token`. It returns a read-only, watermarked preview (no download, no edit).
- Referral codes are the same `share_token`. `applyReferral()` (`POST /studio/referral`) credits both referrer and referee; a session can redeem a referral only once (ledger `ref='referral'`), and the referrer earns per distinct referee (`ref='referral-<refereeId>'`).

Publishing the `guest_token` would let anyone hijack the session, so share/referral surfaces use the dedicated token exclusively.

## Credits ledger

`StudioCreditService` derives balance from the append-only `StudioCreditLedger` (`sum(delta)` for the session). There is no cached balance column.

- `credit($session, $amount, $reason, $ref)` — idempotent when `$ref` is supplied (a repeat with the same `ref` is a no-op). Used for top-ups, referrals, welcome credits, and refunds.
- `debit($session, $cost, $reason)` — inside a `DB::transaction` with `lockForUpdate()` on the session's ledger rows, so the client can never overspend concurrently. Returns false (no row) when the balance is short.
- `spend($session, $cost, $reason, $action)` — the paid-action choke point: debit first, run `$action`, and refund (`credit` with `reason='refund:<reason>'`) if it throws. Throws `InsufficientCreditsException` when the balance is too low.

Costs and bundles come from `config/studio.php`: `credit_cost.pdf_design` (default 5) is the single paid cost, reused for image design, template export, and template background. `bundles` are one-time top-up tiers (no subscription). `welcome_credits` defaults to 0 (off); if enabled it is granted once per session (`ref='welcome'`) — keep it small because clearing cookies mints a new session.

Top-ups run through Stripe Checkout: `checkout()` builds the line item server-side from `config('studio.bundles')` (never trusts the client price) and puts `guest_token`, `credits`, and `bundle` in the session metadata. Credits are granted only by `stripeWebhook()` after signature verification — not on the success redirect — and crediting is idempotent on `ref='stripe-<checkoutId>'`.

## Two output flows

`setOutput()` (`POST /studio/output`) records `output_type` as `online` or `pdf`.

- Online ordering: `upload()` extracts a draft via the shared `MenuGeneratorService` (`fromImage`/`fromText`/`mergeDrafts`, prompt-injection defended, metered), stores `draft.json`, and flags low-confidence rows (`flagDraft`: missing prices, price outliers). `publish()` (auth-gated — a guest is bounced to Google login) re-normalises the possibly client-edited draft, creates a `Post` plus `MenuCategory`/`Menu` rows in a transaction, and returns the public `publicView` URL.
- PDF-styled image: `StudioDesignService::generate()` builds a styling prompt from the draft plus a preset seed (`config('studio.presets')`) and aspect, runs `GeminiImageProvider` (Nano Banana) through the `spend()` guard, and caches `design.png`. A per-session cap (`max_designs_per_session`, default 20, counted from ledger `reason='pdf_design'` debits) bounds cost. `downloadDesign()` wraps the PNG into a one-page PDF via `StudioPdfService`.

The two destructive draft edits (`saveDraft`, `command`) snapshot `draft.prev.json` before overwriting so `rollbackDraft()` gives one level of undo; `fitTemplate` does not snapshot because its trims are non-destructive display overrides (see fit pass). `command` supports `price_adjust` (percentage) and `tag_dietary` (keyword heuristic).

## Template engine

The HTML/CSS template mode is the newer path (rev/studio-templates). Its guiding rule (spec §0): menu text stays data and is rendered by escaped Blade `{{ }}` — it is never baked into a generated image. Preview and export share the exact same rendered HTML.

`StudioTemplateService` auto-discovers packages from `config('studio.templates_path')` or `resource_path('views/studio/templates')`:

- Each package is a directory whose name matches `^[a-z0-9][a-z0-9-]*$` and contains `manifest.json` + `template.blade.php`. Optional `style.css` and `thumb.png`.
- The slug whitelist comes from discovery. `exists()`/`manifest()` gate every request slug against it before any path is built, so a request slug can never traverse the filesystem.
- Invalid packages are skipped with a logged warning, never fatal — one broken manifest does not kill the gallery.

`render($session, $slug, $options)` loads the session's `draft.json` (throws `RuntimeException` if none) and delegates to `renderWithDraft()`, which validates all user-tunable inputs:

- `aspect` must be one of the manifest's `aspects`, else the first is used.
- `colors` overrides apply only for keys the manifest defines and only when the value matches `^#[0-9a-fA-F]{3,8}$`.
- `slots` text is clamped to 200 chars; templates must emit it with `{{ }}`.
- P4 background art (`template-bg.png`) is inlined as a `data:` URI so pages stay standalone.

The rendered Blade is a full self-contained document (`<!DOCTYPE html>`, inline `<style>` from `style.css`, a `.sheet` element with an `aspect-*` class). The preview endpoint serves it under a strict CSP (`default-src 'none'; style-src 'unsafe-inline'; img-src data:`).

Shipped packages: `cafe-pastel`, `chalkboard`, `classic-bistro`, `dark-luxury`, `fine-dining-ornate`, `modern-minimal`, `street-food-bold`.

### Manifest format

`readManifest()` requires the keys `name`, `aspects`, `colors`, `density` (all non-empty). `aspects` is intersected with the studio-wide list (`config('studio.aspects')` = `a4`, `letter`, `square`, `story`); an empty result or non-array `colors` rejects the package. Example (`modern-minimal/manifest.json`):

```json
{
  "name": "Modern Minimal",
  "aspects": ["a4", "letter", "square", "story"],
  "rtl": true,
  "colors": { "accent": "#111111", "bg": "#ffffff", "text": "#1c1c1c" },
  "fonts": [ { "family": "sans-stack", "stack": "..." } ],
  "density": { "items_per_page": 24, "columns": 1, "desc_max_chars": 80 },
  "slots": ["store_name", "tagline", "footer_note"],
  "art_brief": "very subtle abstract geometric shapes ..."
}
```

`density.desc_max_chars` drives the fit pass; `slots` names the user-editable text fields; `art_brief` seeds the P4 background prompt; `fonts` is informational for the template CSS.

## Fit pass

`StudioFitService::fit()` (`POST /studio/template/fit`, free) makes one text-model call through the shared `AiService` provider chain to propose display trims for over-long descriptions, up to a few featured dishes, and an accent colour. Model output is untrusted: `sanitize()` validates every `catIdx.itemIdx` path against the draft, clamps trim text, caps counts, and checks the accent hex.

`applyTrims()` writes trims as `display_description` overrides and `featured` flags — the original `description` is never mutated, so the change is reversible. When the provider chain is down (or returns zero trims for over-long rows, which has been observed live), a deterministic `fallback()` clamps descriptions at a word boundary, so the button always does what it says. The response reports `source` as `ai` or `fallback`.

## Headless Chrome export

`StudioTemplateExportService` renders the exact preview HTML through headless Chrome (`HeadlessChrome`: node + puppeteer via `app/Modules/Content/Services/Studio/headless-render.mjs`; it replaced `spatie/browsershot` in J18), so what you previewed is what prints.

- `available()` — cheap static check: a configured `studio.export.chrome_path` file, or a resolvable `node_modules/puppeteer`. When unavailable, `exportTemplate()` returns HTTP 501 and the client falls back to `window.print()` on the preview.
- Print aspects (`a4`, `letter`) export as PDF (`toPdf`, zero margins); social aspects (`square`, `story`) export as a 2x element screenshot of `.sheet` (`toPng`).
- `HeadlessChrome` launches with `--no-sandbox`, prints backgrounds (dark templates like chalkboard need them) and has a 60s timeout; `chrome_path`/`node_path` from config override the binaries. A failed render throws, so the export refunds the credits.

Export is the paid template step (`spend()` with `credit_cost.pdf_design`; preview stays free). The result is cached under `template-export.<ext>`. The P4 background generator (`templateBackground`, `POST /studio/template/background`) is separately credit-gated and cached at `template-bg.png` (regenerating with `force=1` costs again).

## Thumbs command

`php artisan studio:template-thumbs [--only=<slug>]` (`StudioTemplateThumbs`) regenerates every package's `thumb.png` from a bundled sample menu. It calls `renderWithDraft()` (session-free) with fixed sample slots and screenshots `.sheet` at 794x1123 (A4 @96dpi) via `HeadlessChrome`. Run it after adding or restyling a package. Thumbnails are served by `templateThumb()` (`GET /studio/template/thumb/{slug}`) as a static repo asset — no session data, cacheable.

## Route reference

App shell and landings: `GET /studio` (`studio`), `/studio-v2`, `/studio-v1`, `/studio/app` (`studio.app.shell`, sent `Cache-Control: private, no-store` because it embeds the resume token).

Draft/flow: `POST /studio/output`, `/studio/upload`, `/studio/command`, `/studio/save-draft`, `/studio/rollback`, `/studio/publish` (auth), `/studio/generate-design`; `GET /studio/design/download`, `/studio/design/preview`.

Templates: `GET /studio/templates`, `/studio/template/preview`, `/studio/template/thumb/{slug}`; `POST /studio/template/options`, `/studio/template/export`, `/studio/template/fit`, `/studio/template/background`.

Commerce/share/privacy: `POST /studio/checkout`, `/studio/stripe-webhook`, `/studio/referral`, `/studio/share-link`, `/studio/forget`; `GET /studio/pricing`, `/studio/export`, `/studio/s/{token}` (`studio.share`).

Most write routes carry per-minute `throttle` middleware; `studio/template/export` and `.../background` use the `studio-export` named limiter (10/min/IP) and `.../fit` uses `studio-fit` (10/min/IP), both defined in `RouteServiceProvider`.

## Gotchas

- Studio routes must stay registered before the `{slug}` restaurant catch-all in `web.php`, and localized surfaces need the `app.locale` middleware.
- Credits are granted only by the Stripe webhook, never the success redirect; the checkout price is always chosen server-side from config.
- The template preview CSP allows only inline styles and `data:` images — a template that reaches for an external asset will silently fail in the iframe.
- Clearing cookies mints a brand-new session with a zero balance and no draft; there is no cross-device continuity for guests without login.
