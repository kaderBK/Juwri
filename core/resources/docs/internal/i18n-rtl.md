---
title: "Internationalisation and RTL"
audience: internal
group: Internal
updated: 2026-07-25
---

# Internationalisation and RTL

How the platform localises UI strings, chooses a request locale, and flips layouts for right-to-left languages. Audience: engineers touching `core/lang/`, the locale middleware, or any Blade layout.

## Shipped languages

Eleven locales ship as directories under `core/lang/`: `ar`, `de`, `en`, `es`, `fr`, `hi`, `pt`, `ru`, `tr`, `ur`, `zh`. Each is a folder of PHP array files, not JSON:

- `lang.php` — the application string catalogue (~8,300 keys as of 2026-07), keyed by a slug (see [The `___()` helper](#the-helper)). The dashboard translation backlog for the five non-Latin locales (`ar`, `ur`, `hi`, `ru`, `zh`) was closed in PRs #374–#380 — treat an English value in those files as a regression (usually a `___()` auto-registration from browsing), not a known gap.
- `auth.php`, `pagination.php`, `passwords.php`, `validation.php` — the stock Laravel framework strings.

The directory set is the source of truth for *what strings exist*. Which locales are *offered* at runtime is driven by the `languages` database table, not the folder list — the two can drift apart.

## The languages table

`App\Models\Language` (`core/app/Models/Language.php`) backs the `languages` table. Columns: `code`, `direction` (`ltr`/`rtl`), `name`, `active`, `position`. Migration `2024_06_15_122934_create_languages_table.php` seeds only `en` and `fr` as active; every other locale is added by an admin (or a later seeder). So a fresh install exposes two languages even though eleven string folders exist on disk.

Admins manage languages under the superadmin Languages page (`Admin\LanguageController`, routes `admin/languages` resource plus `languages/reorder`, `languages/translate/{code}`, `languages/{language}/translate`). The page copy is "Add a language, edit translations, set RTL direction." Key behaviours:

- **Create** (`store`) scaffolds a new `lang/{code}/` folder from disk.
- **Translate** (`translate` / `translateUpdate`) edits the per-file arrays and writes them back with `File::put` to `lang/{code}/{file}.php`. Translations are files on disk, not DB rows — they do not survive a container rebuild unless `lang/` is persisted.
- **Delete** removes the row and `File::deleteDirectory(lang/{code})`.
- Setting a language active/default calls `PlatformSettings::set('lang', $code)` — the default locale lives in the platform_settings table, not `.env`.

`get_active_languages()` (in `core/app/Http/Helpers/Helper.php`) returns `Language::where('active', 1)` keyed by code; `current_language()` returns the row for the current locale, and its `->direction` column drives the layout `dir` attribute.

## The `___()` helper

UI strings are looked up with `___('Some text')`, not `__()`. Defined in `Helper.php`:

1. Slugs the argument (`Str::slug($key, '_')`) and looks up `lang.<slug>`.
2. If missing, it **auto-appends** the key to `lang/*/lang.php` in every language folder, using the English source string as the placeholder value, and writes each file back.

Consequences: the first render of a new string mutates every `lang.php` on disk (a write on a read path — noisy in git, and it needs a writable `lang/`). Placeholders use Laravel's `:name` syntax and are passed through the `$replace` array.

## Locale selection: two modes

Locale resolution is governed by the `include_language_code` option (Settings → General → "Include Language Code in URL", seeded on). The main route group in `core/routes/web.php` branches on it:

**Mode A — prefix off (`include_language_code` false).** The group runs the `app.locale` middleware (`App\Http\Middleware\Locale`). It reads `session('locale')` and calls `App::setLocale()`. There is no URL prefix. A visitor switches language via `GET lang/{lang}` → `HomeController@localize`, which sets `Session::put('locale', $code)` and redirects back. This route only exists in Mode A.

**Mode B — prefix on (`include_language_code` true).** The group is prefixed with `LaravelLocalization::setLocale()` and runs mcamara's `localize`, `localizationRedirect`, and `localeSessionRedirect` middleware. URLs carry a `/{locale}/` segment. `AppServiceProvider` feeds the active languages into `laravellocalization.supportedLocales` so mcamara knows the set. Note `config/laravellocalization.php` hard-codes only `en`/`ar` as a test-time fallback — the real registry is injected at boot from the DB.

`lang_url($code)` picks the right link form per mode: `LaravelLocalization::getLocalizedURL()` in Mode B, `route('localize', $code)` in Mode A.

## Locale middleware overwrite trap

`App\Http\Middleware\Locale` (alias `app.locale`) is what applies the session locale in Mode A. Any route that lives **outside** the main localised group does not get it, so `App::getLocale()` falls back to the app default and the page renders in the wrong language regardless of the visitor's session choice.

This is why the Studio routes attach it explicitly. In `web.php`, every `studio*` route carries `->middleware('app.locale')` (e.g. `studio`, `studio-v2`, `studio-v1`, `studio/app`, and the `studio/template/*` endpoints). They are registered as standalone routes near the bottom of the file, not inside the localised group, so without the alias they would ignore the session locale. When adding a new top-level public route that must honour the visitor's language, add `app.locale` (Mode A) — or place it inside the localised group.

Note the middleware is a no-op when `include_language_code` is true; in Mode B mcamara handles the locale from the URL prefix.

## Session-locale SEO problem

In Mode A the locale is a session value with no URL prefix, so every language shares the **same** URL. A crawler sees one page per URL, only ever in the default locale; the other ten languages have no distinct, crawlable address. The `hreflang` alternates emitted by `resources/views/store_templates/layout.blade.php` point at `lang_url()`, which in Mode A resolves to `/lang/{code}` — a redirect that sets a session cookie and bounces back, not a canonical per-language page. Net effect: non-default languages are effectively invisible to search engines.

The fix is to run Mode B (`include_language_code` on), which gives each locale a real `/{locale}/` URL that mcamara can generate `hreflang` alternates for. A broader SEO remediation (prefix-mode flip, slug-collision handling for Arabic/Hindi/Russian, Arabic-title deploy gate) is specced but **not yet built** — treat this section as the current state, not the target.

## RTL rule: logical properties only

RTL is a first-class product constraint, not an afterthought — the primary owner persona is often Arabic or Urdu speaking. `PRODUCT.md` makes it a hard rule:

- "RTL-first — logical properties everywhere; every screen must flip cleanly to Arabic."
- "no physical margins (logical properties only — RTL)."

In practice: use `margin-inline`, `padding-inline`, `inset-inline`, `start`/`end` — never `margin-left`, `padding-right`, or physical `left`/`right` offsets. `PRODUCT.md` names IBM Plex Sans Arabic (self-hosted woff2, `font-display: swap`) as the intended RTL font. Customizer v2 shipped self-hosted, Arabic-faced font pairs for the storefront themes (`config/theme-fonts.php` + `App\Services\Theme\FontPairs`, emitted by `store_templates/_partials/theme-fonts.blade.php`) — the customizer-driven font path no longer touches the Google CDN. `store_templates/layout.blade.php` still carries legacy Google Fonts links (a `$menuFont` css2 link and Tajawal) for the non-token path, and some auth/landing layouts pull Google-hosted families; treat those as remaining cleanup, not the pattern to copy.

Direction is applied at the `<html>` element. The owner layouts (`resources/views/templates/old-theme/layouts/*.blade.php`) set `dir="{{ function_exists('current_language') && current_language() ? current_language()->direction : 'ltr' }}"` — i.e. the DB `direction` column drives the document direction. A few layouts (`auth`, `landing`) additionally compute an `$isRtl` flag from a hard-coded locale list (`ar`, `ur`, `fa`, `he`, `ps`, `sd`, `dv`) for conditional blocks. Because that list is duplicated across views, it can disagree with the `languages.direction` column; prefer the column, and keep any hard-coded list in sync if you must add one.

## Gotchas

- New string renders mutate `lang/*/lang.php` on disk — keep `lang/` writable, and expect the first pass over a new screen to produce a large diff.
- The active-language set comes from the DB, not the folder list; adding a `lang/xx/` folder does nothing until a `languages` row is created.
- Adding a public top-level route? It will ignore the session locale unless it carries `app.locale` (Mode A) or sits in the localised group.
- `config/laravellocalization.php` looks like it only supports `en`/`ar` — that is a test fallback; the live registry is set in `AppServiceProvider` from active languages.

## Tenant UI-copy overrides (HPMS E13)

There is deliberately **no separate per-store string-override system**. A merchant
who wants different wording for a button or label on their storefront changes it
through the **theme customizer labels** — the shipped, per-store mechanism. Platform
`lang/*/lang.php` files hold the global UI copy (edited by superadmins via
Admin → Languages, now with a *missing-strings* view and JSON pack export/import);
tenant-specific copy stays a customizer concern so a store's wording never forks the
platform catalogue. The admin importer writes lang files **directly** (never through
`___()`, which would auto-register English into every locale) and slugs incoming keys
to the `___()` convention so they collide-merge onto the existing row.

Dashboard locale: an authenticated dashboard user with no explicit session pick now
falls back to their saved `preferred_locale` (previously it drove mail only). The
storefront is untouched — it keeps its session/URL locale memory.

## Related

- [Architecture](architecture.md) — request lifecycle and the settings/options layer.
- [Theme system](theme-system.md) — how layouts and the active theme are resolved.
