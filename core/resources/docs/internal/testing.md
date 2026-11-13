---
title: "Testing guide"
audience: internal
group: Internal
updated: 2026-08-06
---

# Testing guide

How the automated test suite is laid out, how to run it, and the environment-specific traps that waste the most time. The suite is the safety net for the money and critical paths (ordering, payment webhooks, plan-limit enforcement, feature gating) plus most feature areas built since — roughly 1,820 test methods and growing with every feature branch.

Grounding files: `core/phpunit.xml`, `core/tests/TestCase.php`, `core/tests/CreatesApplication.php`, `core/tests/Feature/`, `core/tests/Unit/`, `rev/features/091-automated-tests.md`, `rev/studio-templates/BUILD-LOG.md`.

## Suite layout

Two PHPUnit test suites, defined in `core/phpunit.xml`:

- `Unit` — `core/tests/Unit`. Currently just `ExampleTest.php`; the project is almost entirely feature-tested.
- `Feature` — `core/tests/Feature`. 350 test files, one per feature area (e.g. `CriticalPathsTest`, `AdminOrdersTest`, `StudioTemplateEndpointsTest`, `AgentChatTest`, `TwoFactorTest`, `KdsStationsCourseTest`, `CspTest`). This is where nearly all coverage lives.

Both test-file directories are matched by the `*Test.php` suffix. Base class is `Tests\TestCase` (extends the Laravel `TestCase`), which pulls in `CreatesApplication`. Almost every feature test uses the `RefreshDatabase` trait (327 of 350 files) so each test runs against a fresh migrated schema.

There is only one factory, `database/factories/UserFactory.php`. Most tests build models directly with `Model::create([...])` in `setUp()` rather than through factories — follow the pattern of the neighbouring test in the area you are working on rather than reaching for a factory that does not exist.

## Test environment

`phpunit.xml` pins the whole environment so tests never touch a real service:

- `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` — in-memory SQLite, never the dev DB. `DB_FOREIGN_KEYS=true`.
- `APP_INSTALLED=false` — the app boots as if not yet installed (relevant to the `Installed` middleware, see traps).
  It also makes `ViewServiceProvider` skip all eight of its `view()->composer()` registrations, so no
  view receives `$settings`/`$activeTheme`/`$languages`. Do **not** flip it to `true`: composer output
  wins over `view()->share()` (`View::gatherData()` is `array_merge(shared, data)` and a composer writes
  into `data`), so flipping it would replace every test's seeded fixture with a `(object) []` built from
  an empty options table. Instead call `TestCase::shareLayoutGlobals(array $options = [], bool $admin = false)`
  for a **production-shaped** `stdClass` fixture (via `array_to_object()`, exactly what the composer hands
  a view), or `TestCase::bootRealViewComposers(?string $path = null)` to render against the real composers.
- `THEME_NAME=juwri` — also force-set in `TestCase::setUp()` via `putenv`/`$_ENV`/`$_SERVER` so theme resolution is deterministic regardless of the shell environment.
- `CACHE_DRIVER=array`, `SESSION_DRIVER=array`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync` — everything in-process. Queued jobs run synchronously; mail is captured, not sent.
- `BCRYPT_ROUNDS=4` — fast hashing for tests.
- `TELESCOPE_ENABLED=false`, `LOG_CHANNEL=null`.

Because `QUEUE_CONNECTION=sync`, a job that implements `ShouldQueue` still executes inline — but you must assert against it correctly. `Mail`/`Queue` fakes short-circuit dispatch, so a mailable declared `ShouldQueue` (e.g. `CampaignMail`) is caught by `Mail::assertQueued`, not `Mail::assertSent`. See [queues and jobs](queues-jobs.md) for how the sync path differs from a real worker.

## Running the suite

From the `core` directory:

```
php artisan test
```

Run a single file or filter while iterating:

```
php artisan test --filter=CriticalPathsTest
php artisan test tests/Feature/StudioTemplateEndpointsTest.php
```

Nothing external is required — no MySQL, no Redis, no Chrome. The suite is self-contained on the in-memory SQLite DB. CI (rec #97) runs the same `php artisan test`.

## Running with Pest (P1-18)

Pest 4 is the runner (dev dependency, `core/tests/Pest.php`); the existing tests stay class-based PHPUnit and are not rewritten. `php artisan test` and `vendor/bin/pest` run the same tests with the same counts, and both accept `--parallel` (the runner scripts keep using `artisan test --parallel`).

```bash
cd core
vendor/bin/pest tests/Modules/Fiscal            # one folder
vendor/bin/pest tests/Architecture              # arch rules (~4 min, memory heavy, see note)
php artisan test --parallel --processes=4       # as before
```

Architecture rules are Pest `arch()` tests in `core/tests/Architecture/`: `ArchPresetsTest.php` (php, security and laravel presets; where a preset hits existing code the class is scoped out with `->ignoring()`, the banned call named in a comment next to the class) and `ModuleBoundariesArchTest.php` (modules use only the modules `app/Modules/dependencies.php` allows, and reach other modules only through Contracts / Data / Events / Models; today's exceptions are the shrink-only `module-boundary-baseline.php`). Fix an ignore by removing the offending code, then delete its line. Closure-style Pest tests under `tests/Feature` and `tests/Modules` boot `Tests\TestCase` automatically. Note: arch() rules parse the whole app (about 2 GB resident per file); `tests/Pest.php` raises the memory limit for `tests/Architecture` and drops Pest's cached object repository after each arch file so serial and parallel runs both fit. Mutation testing (Core money, Payments, Fiscal only) is run on demand, never in the suite or the slices: `composer mutate:money|mutate:payments|mutate:fiscal` (needs pcov/xdebug; the covered classes are the testsuites in `phpunit.mutation.xml`; the mutants the runs found are pinned in `MoneyMutationKillersTest` and `FiscalMutationKillersTest`). Pest browser tests are not set up yet.

## Static analysis

PHPStan runs at level 6 against `core/app` and is a blocking CI gate:

```bash
cd core
composer phpstan
```

The reviewed baseline records 5,696 legacy findings as of issue #608. It is a
debt ledger, not permission to suppress new code:

- the full `Static Analysis` job fails on every new unsuppressed finding;
- `reportUnmatchedIgnoredErrors` fails when a fixed finding is left stale in
  the baseline;
- `.github/scripts/phpstan-baseline-ratchet.sh` fails a pull request if the
  total number of baseline suppressions grows relative to its base branch.

Issue #608 began with 124 unsuppressed level-6 findings in the local
machine-readable scan: 56 missing return types, 24 missing iterable value
types, 16 missing parameter types, 9 argument type mismatches, 4 redundant
null-coalescing checks, 2 unmatched ignore counts, and 13 other findings.
Production-facing controller, MCP protocol, storefront, checkout, subscription,
and abuse-guard paths were corrected first. Regenerating the reviewed baseline
after those fixes reduced accepted suppressions from 6,176 to 5,696 (480 fewer)
while the full analysis returned zero unsuppressed errors.

When touching baselined code, fix the finding and remove its matching baseline
entry in the same pull request. Do not add inline `@phpstan-ignore` comments or
regenerate the baseline merely to make CI green. A deliberate full rebaseline
requires a reviewed no-baseline scan, a lower suppression total, and the
before/after count recorded in the pull request.

## Trap: never disable middleware wholesale

Do not call `$this->withoutMiddleware()` with no arguments. It disables `SubstituteBindings`, so a route-model-bound argument like `Post $store` injects a blank, unsaved model and the request 403s (or behaves as if the record does not exist) for reasons that have nothing to do with the code under test.

Disable middleware by explicit class only. The two that commonly need turning off in feature tests, because `APP_INSTALLED=false` and the staff-permission gate otherwise redirect or 403:

```php
$this->withoutMiddleware([
    \App\Http\Middleware\Installed::class,                    // else 302 -> /install
    \App\Http\Middleware\EnsureStaffDashboardPermission::class,
]);
```

Many tests also drop `\App\Http\Middleware\VerifyCsrfToken::class` for POST routes. Grep existing tests in the same area (`AddonTest`, `ActivityLogTest`, `AdminDashboardTest` all show the pattern) and disable the same set — never more.

## Trap: Plan settings must be an array

When constructing a `Plan` in a test, pass `settings` as a PHP **array**, not a JSON-encoded string. The model casts the column to `object`; handing it a pre-encoded string double-encodes it and the gating logic reads back garbage. See [entitlements](entitlements.md) for how plan settings drive feature gates.

## Trap: CA bundle on the local CLI

This dev machine's PHP curl points at a missing CA bundle (`/usr/local/etc/openssl@3/cert.pem`). This does **not** affect `php artisan test` (tests never hit the network) and does **not** affect Herd/web requests. It only bites CLI smoke tests — `php artisan tinker`, `php -r`, ad-hoc scripts — that make a real outbound HTTPS call (AI providers, SMS, payment gateways). Run those with an explicit CA file:

```
php -d curl.cainfo=/etc/ssl/cert.pem -d openssl.cafile=/etc/ssl/cert.pem artisan some:command
```

## Trap: headless Chrome / FPM for Studio exports

Studio template export (PDF/PNG) shells out to headless Chrome via `HeadlessChrome` (node + puppeteer; J18 replaced `spatie/browsershot`). In the test suite this service is swapped for a mock (and `HeadlessChromeTest` drives the process contract with a fake `node`) — CI has no Chrome, so export feature tests assert the rendered HTML reaches the exporter and check the 501/402/409 guard paths rather than producing a real file. `StudioTemplateExportService::available()` is a cheap static check (chrome path is a file, or `node_modules/puppeteer` exists); when the toolchain is missing the endpoint returns 501 and the client falls back to `window.print()`.

For manual/e2e export runs, two environment knobs (`config/studio.php`, `export` block) exist because of real bugs found during the build:

- `STUDIO_CHROME_PATH` — path to system Chrome. Leave null to let puppeteer resolve its own browser.
- `STUDIO_NODE_PATH` — the render dies under Herd FPM because nvm's node is not on FPM's PATH; this names the node binary so exports work under FPM. Set it in local `.env`.

When touching that code: keep `printBackground: true` in `headless-render.mjs` (dark templates lose their background otherwise) and `--no-sandbox` (needed in most local/CI environments).

## Trap: shared throttle bucket in tests and e2e

Laravel's inline `throttle:N,1` keys guests by `domain|ip` only. Several Studio routes originally shared one bucket, so ordinary preview traffic starved the export route's budget into instant 429s. The fix was named rate limiters (`studio-export`, `studio-fit`) with their own keys. If a test or e2e script hammers one guest route and sees unexpected 429s, check whether the route is on a shared inline throttle rather than a named limiter.

## End-to-end browser checks

Feature tests cover the server contract; UI behaviour (Alpine re-renders, iframe previews, live editing) is verified with a headless-Chrome script driven by `puppeteer-core` against a real Herd HTTPS site. The reference script is `rev/studio-templates/e2e/browser-check.mjs`. The pattern:

- Launch system Chrome (`puppeteer-core`, `--ignore-certificate-errors` for the self-signed `.test` cert), point at the Herd site (e.g. `https://landing.test/studio/app`).
- Server cookies are encrypted, so authenticate through a supported entry point rather than forging a cookie — the Studio script uses a `?resume=<token>` link; the dashboard login submits via `form.submit()` because ref-clicks are flaky under Herd.
- Log a step marker per stage (`STEP resume`, `GALLERY-OK`, `LIVE-RERENDER-OK ms=...`) and print `DONE` at the end. Treat `DONE` as the success marker — `browser.close()` sometimes hangs after completion, so the exit code is unreliable; log to a file and read it back.
- Assert timing where it matters (live re-render should be under ~2s; a degraded live-AI chain can take ~100s, so do not set e2e waits below ~90s on AI-backed endpoints).

The scratchpad is wiped between chat sessions, so a browser-check script written there must be recreated each time. A committed reference copy lives under `rev/studio-templates/e2e/`.

Do not mark a UI feature done on "it renders" — run the end-to-end path and observe the behaviour (a re-render firing, an export returning `200 application/pdf`, both EN and AR locales) before calling it complete.

The maintained cross-platform smoke runner is `core/tests/e2e/run.js`. Its
default journeys cover storefront checkout, owner login, staff-PWA boot/login,
and seeded owner/customer page matrices. Dedicated staff, reseller, and admin
credentials enable their true role matrices; an absent credential is reported
as not configured and must never be recorded as a pass. See
`core/tests/e2e/README.md` for environment variables.

Each role runs in a separate browser context so owner, staff, reseller, admin,
and customer cookies or service workers cannot contaminate one another. The
customer journey performs a real password login, probes the authenticated
session, requires the exact account URL, and asserts the active account/logout
DOM. A successful login JSON response followed by a storefront redirect is a
failure, not customer coverage.

Release-candidate testing must also include account lifecycles, not only the
role page matrix: owner signup and first-store onboarding, owner-created staff
plus an operational mutation, partner signup and customer provisioning, and
customer registration through tracked order placement. The retained local
evidence, disposable fixture IDs, mutations, and resolved reseller lifecycle are
recorded in `production-test-scenarios.md`.

For storefront-template browser coverage, the supported release scope is:
Crave, Matcha, Glow, Aurora, Vivid, Feast, Velvet, Dough, Plate, Lime, Picnic,
Orbit, Velora, Crumb, Soiree, Scoop, and Ember. Other bundled templates are
scheduled for removal and are outside the release-candidate template matrix.

The opt-in `retained-template-csp` journey derives a real product query from
each fixture, exercises that theme's inline/page/overlay search and requires a
visible result, opens a product, satisfies known required modifier controls,
adds to cart, satisfies configured minimum-order rules, and audits checkout on
all 17 retained themes. Checkout validation requires a visible enabled final
action, a rendered total, fulfillment controls, contact inputs or an
authentication gate, and the available payment controls. A capture-phase
submit guard prevents the journey from placing an order:

```bash
E2E_RUN_RETAINED_CSP=1 E2E_BASE_URL=https://candidate.example \
  npm run test:e2e -- retained-template-csp
```

Add `E2E_RETAINED_MOBILE=1` to run the same real interaction flow at 390×844.
Mobile mode also fails when the checkout document has horizontal overflow or
the final action extends beyond the viewport.

For the release enforcement gate, run the same journey against an environment
started with `CSP_ENFORCE=true` and add `E2E_REQUIRE_CSP=1`. This strict mode
requires the enforcing header, `script-src-attr 'none'`, zero inline event
handlers, and zero browser CSP refusals. The runner clears service workers,
CacheStorage, local storage, and session storage between theme loads. Enforcing
CSP also disables anonymous storefront HTML ETag reuse because a 304 response
with a fresh nonce cannot safely reuse a cached body carrying the old nonce.

Use the [production release test scenarios](production-test-scenarios.md) for
the complete release-candidate role matrix, API/MCP/security/deploy cases,
evidence fields, safe parallel local/agent lanes, current automation/manual
boundaries, and final sign-off. Parallel workers must use isolated databases,
actors, tenants, caches, queues, and artifact directories; migrations, seeders,
cache builds, payment/POS mutations, and recovery drills remain serial unless
that isolation is proven.

Use the [full role and feature test plan](full-role-feature-test-plan.md) when
the goal is behavioral coverage of the entire platform rather than a
production smoke. It separates what has actually been executed from the full
public/customer/owner/staff/reseller/admin scenario backlog.

## Related

- [Production readiness handbook](production-readiness-handbook.html) —
  visual implementation, usage, evidence, and remaining-work guide.
- [Production release test scenarios](production-test-scenarios.md) — release
  gate and sign-off matrix.
- [Queues and jobs](queues-jobs.md) — sync-driver behaviour, the no-worker fallback, scheduled ticks.
- [Entitlements](entitlements.md) — plan settings and feature gates that the gating tests exercise.
- [Architecture](architecture.md) — where controllers, services, and middleware sit.
