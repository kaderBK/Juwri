---
title: "CONVENTIONS — Non-negotiable repo rules (violating these ships prod bugs)"
audience: internal
group: Internal
updated: 2026-08-06
---

# CONVENTIONS — Non-negotiable repo rules (violating these ships prod bugs)

Distilled from hard-won session memory. Read once, obey always. Each has burned this
codebase before.

> **How to cite this file.** Code and tests reference sections by their `§` anchor, e.g.
> `CONVENTIONS §theme-totals` at `core/app/Models/Order.php:250`. Every anchor below is checked by
> `core/tests/Feature/ConventionsDocTest.php`, so a citation can never dangle and an anchor can never
> be renamed silently.
>
> `§sqlite-vs-MySQL` · `§limit-sentinel` · `§feature-gate` · `§theme-totals` · `§shared-media-delete` ·
> `§view-settings-collision` · `§blade-compile-pairing` · `§linen-click-quote` · `§storefront-locale` ·
> `§ci-not-merge-blocking` · `§no-global-setting` · `§browser-verify` · `§theme-usage-skew`

## Database / schema

- **sqlite tests, MySQL prod, `qrtest_` table prefix.** A change can be green on sqlite CI
  and 500 in prod. For any migration, `DB::raw`, raw SQL, or JSON-column work: also run it
  on the MySQL lane before merge. §sqlite-vs-MySQL
- **Never hardcode table names in `DB::raw`/`selectRaw`.** Prod tables are `qrtest_*`. Use
  the model, or `->getTable()` / the configured prefix. Hardcoded names 500 in prod only.
- **MySQL index names cap at 64 chars.** Auto-generated names on long
  table+column combos silently exceed it — sqlite won't complain, MySQL migrate will fail.
  Name long indexes explicitly and short.
- **Discard `core/lang/` dirt before any `git checkout`/branch.** `___()` auto-registration
  re-writes lang files on every storefront visit; `git checkout HEAD -- core/lang/` first or
  the checkout blocks.

## Static memos

- **A static keyed by a database id must be stamped with the app instance.** Hold
  `private static ?int $memoApp = null` and, at the top of every memo READER,
  `$id = spl_object_id(app()); if (self::$memoApp !== $id) { self::$memoApp = $id; /* clear THIS
  class's memos */ }`. An id says nothing about which database it came from; under the test runner
  `RefreshDatabase` reuses low ids and the memo answers for an unrelated store in the next class
  (POS totals halved 24 -> 12, money rendered 3 decimals). Copy
  `App\Services\Tables\TableMergeService::scopeMemo()`. §static-memo
- **One stamp per CLASS, and it clears only that class's memos.** A guard that calls another class's
  `flush()` wipes a memo that class legitimately filled — see the comment at
  `MenuScheduleService.php:88-89`.
- **A new memo in an already-guarded class must be added to the guard too** — especially in
  `FeatureSpotlight`/`FeatureUpsell`, whose guards delegate to `flush()`/`forget()`, so the reset
  lives in a second place.
- **Do not rebind driver registries, disk/config data or `Fake*Client` test doubles.** A registry
  filled by a service provider at boot would be discarded by the first post-boot read. The full list
  lives in `Tests\Support\StaticMemoGuardLint::ALLOWLIST`.
- `core/tests/Feature/StaticMemoGuardLintTest.php` fails CI on a new unstamped memo;
  `core/tests/Feature/StaticMemoAppScopeTest.php` pins the behaviour of the existing ones.

## Limits / entitlements

- **`999` = unlimited sentinel** for most limits, **but `0` = unlimited** for
  `whatsapp_messages_per_month` (`ZERO_UNLIMITED_LIMITS` in `Admin/FeatureMatrixController`).
  Watch add-on collisions and `effective_limit(...) ?? 999` leakage. §limit-sentinel
- **Feature-gate subject rule:** never put `feature:` / `entitled:` middleware on **anonymous**
  routes (storefront, webhooks) — there's no user to resolve the subject. Use
  `FeatureAccess::storeAllows($store, 'key')` inside the action instead. §feature-gate

## Money / orders

- **Theme totals contract:** any total you render must match `Order::getTotalAttribute()`.
  Tax is computed on the **pre-discount** subtotal. Divergent per-theme math is the exact bug
  class behind past totals incidents. §theme-totals
- **Currency decimals vary:** BHD/JOD/KWD/OMR/LYD/TND are **3-decimal**. Route money through
  `price_symbol_format()` / `currency_decimals()`, never a hardcoded `number_format(…, 2)` or `$`.

## Media

- **Ref-count before unlink.** Filenames are shared across rows; deleting a row must check
  `MediaReferences` before removing the file, or you 404 another store's image.
  §shared-media-delete

## Blade / views

- **Never name a controller view variable `settings`** — it collides with the global
  `$settings` composer and silently overrides it. §view-settings-collision
- **Blade component tags pair at compile time.** A mismatched `<x-…>` / `</x-…>` yields a
  blank page, not an error. §blade-compile-pairing
- **Linen/Alpine:** never single-quote an `@click` that contains `@js(...)` — it breaks Alpine
  parsing silently. Use double quotes. §linen-click-quote

## i18n

- **`___()` auto-registers English into ALL locales.** After browsing the storefront, lang
  files dirty with English defaults. Don't commit that residue; verify real translations via
  the bulk-translate pipeline. Keys are slugged (`Str::slug` drops apostrophes/`@`), and
  Title/lowercase collide — the helper is case-insensitive by design; don't "fix" it.
- **Storefront locale in dev is session mode:** switch with `/lang/{code}`; `/fr/slug` 404s
  in dev. §storefront-locale

## Documentation

- **Docs gate: any change that adds or renames an operator-visible surface** — a
  dashboard tile, switch, form field, page, console, or API endpoint — **must touch
  the matching docs page** in the same PR (`core/resources/docs/users/**` for owners,
  `shoppers/**`, or `dev/api/**` for the API). Reseller operator guides ship with
  the edition package, not in this tree. Docs frozen while
  features ship is exactly how the 2026-07 pages drifted three weeks behind the code.
- **Every doc page carries front-matter** (`title`, `audience`, `group`, `updated`);
  set `updated` to the date you verified the content against code, not merely edited it.
  `php artisan docs:staleness` reports pages by that date; `DocsPlatformDriftTest`
  enforces front-matter, `/api/v1` doc↔route parity, webhook-event parity, the
  integrations registry, setup-guide wiring, and that relative doc links resolve.
- **AI-facing docs:** every Help Center page is also raw markdown at `{slug}.md` /
  `Accept: text/markdown`; `/llms.txt` and `/.well-known/mcp.json` advertise the
  surfaces. Keep them crawlable — never gate public docs behind auth or bot-blocking.

## CI / merge

- **Verify CI green before every merge.** `gh pr checks <pr>` must be **green** — "skipped" ≠ passing.
  Branch protection was **verified unavailable on this repo's plan** on 2026-08-05 (the protection and
  ruleset APIs both return 403 "Upgrade to GitHub Pro"), so a direct push to `main` is *not* blocked by
  the server. That makes the local gate the only gate: run it in full, every time.
  §ci-not-merge-blocking
- CI runs sqlite + a non-blocking PHPStan; it does NOT catch MySQL-only or UI regressions.
  That's what the MySQL lane and browser verification are for.

## Behavior / ops

- **No global setting changes as workarounds.** Don't toggle an app-wide setting to make one
  thing pass; fix the specific cause or leave it and log an `OWNER-DECISION`. §no-global-setting
- **Verify in real Chrome (claude-in-chrome), not the Claude Browser pane.** §browser-verify
- **Theme usage skew:** ~3,391 stores on legacy templates vs a handful on modern. Check real
  usage before theme work; verify storefront changes on a legacy theme too. §theme-usage-skew
- **Dark-ship convention:** risky/behavior-changing features land behind a flag or env
  default-off with an owner runbook line — matches monetization, directory, feature-platform
  runs. The owner flips in prod.

## Worktree tooling (see WORKTREES.md for the full recipe)

- `php83` = `~/Library/Application Support/Herd/bin/php83`. `mysql`/`mysqldump` =
  your MySQL client's bin directory. composer = Herd's shim.
- **Never `composer update --ignore-platform-reqs`** — it bypasses the `php ^8.2` platform
  pin and locks 8.4-only packages that CI (8.2) then rejects. The pin IS the guard.
- `herd isolate 8.3 --site=<linkname>` **silently no-ops if link name ≠ dir name.** Pass
  `--site` explicitly.
- `.env`, `vendor/`, `storage/` media are gitignored → absent in a fresh worktree. Copy
  `.env`, `composer install`, `storage:link`, and APFS-clone `storage/*` media.
- Local-only red: EC-keygen tests (ZATCA/ETA) need
  `export OPENSSL_CONF=/usr/local/etc/openssl@3/openssl.cnf`. CI passes them.
