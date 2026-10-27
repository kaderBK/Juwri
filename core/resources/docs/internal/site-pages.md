---
title: Site Pages — block-composed home & marketing pages
audience: internal
group: Platform
updated: 2026-08-06
verified_against: 4edfbce9
---

# Site Pages (home-page management)

Block-composed platform marketing pages (home + `/page/{slug}`) managed from
**Admin → Site Pages** (`/admin/site-pages`). Generalizes the reseller pages
engine for the platform's own sites; the reseller system is untouched and its
leaf services (`LocalizedContent`, `TranslateService`, `SafeUrl`, `Html::clean`)
are shared.

## How rendering works

- `HomeController@index` seam: a **published** Site Pages home renders instead
  of the hardcoded old-theme Blade home; when none is published, `/` falls through
  to the legacy view unchanged. **Unpublish = instant per-site kill switch.**
  Seam order (pinned by `SitePageSeamTest`): reseller host > 
  `disable_landing_page` > auth redirect > site page > legacy Blade.
- `/page/{slug}`: published Site Page wins over the legacy `Page` CMS row.
- Blocks are stored as JSON (`site_pages.blocks` draft,
  `published_blocks` snapshot — the renderer reads ONLY the snapshot).
  Vocabulary = `SiteBlockRegistry` (23 types wrapping the untouched legacy
  landing partials, `resources/views/site/blocks/*`).
- Rendered blocks HTML is fragment-cached 300s under
  `sitepage:html:{id}:{locale}:{updated_at}` — keys self-version, publishing
  starts a fresh key automatically.
- Copy overrides are optional per-locale maps; **empty fields fall back to the
  partial's `___()` defaults**, so translations keep flowing through the live
  lang pipeline.
- SEO: the page's `seo` JSON feeds the landing layout head; the default locale
  serves its stored strings **verbatim** (rev/seo R9 deploy gate).
- JSON-LD: SoftwareApplication emits when a hero block is enabled, FAQPage when
  a faq block is enabled and rows exist (`site/page.blade.php`).
- Per-site theming: `sites.theme` JSON (`primary`/`accent`/`surface`/`radius`)
  → validated CSS vars after the `theme_color` remap; reseller hosts keep the
  reseller `primary_color` win. `<html data-site="{key}">` scopes overrides.

## Operating it

- `php artisan site:seed-home` — creates the install's `Site` row and composes
  a **draft** home reproducing today's hardcoded page 1:1 (never publishes;
  refuses to overwrite hand-edited drafts without `--force`).
- Composer: drag to reorder, toggle to hide, per-locale copy tabs (★ = default
  locale, • = has content), 2s autosave, iframe preview of the DRAFT
  (noindex), AI translate (provider chain, 30/h per admin).
- Publish writes a revision (last 20 kept) — **History → Restore to draft**
  rolls back into the draft; publish again to make it live.
- Rollout runbook for the 4 production installs: `rev/home-pages/HANDOFF.md`.

## Fences to respect

- Never edit owner partials for a block-specific need — extend via props with
  output-preserving defaults (`rev/home-pages/KICKOFF.md` rule 3).
- `SiteBlockRegistry` and the reseller `BlockRegistry` stay separate; block
  `settings` never carry colors (tokens own color).
- Schema changes bump the block def `version` and need a fixture in
  `SiteBlockFixtureRenderTest` — the renderer skips stored versions newer than
  the def so old published JSON never 500s.
