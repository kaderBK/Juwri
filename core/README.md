# juwri core

juwri is a multi-tenant, multi-vertical commerce platform — restaurants, retail, services, and more, selected per store via `store_type` — for QR/catalog menus, ordering, staff operations, analytics, payments, and merchant administration. This directory contains the Laravel application. Native and desktop clients ship with Enterprise.

## Requirements

- PHP 8.4 or newer
- Composer 2
- MySQL 8 for production-parity migrations
- Node.js 20 for browser journeys

Use `nvm use` from this directory to select the version pinned in `.nvmrc`.

## Local setup

```bash
composer install
cp .env.example .env
chmod 600 .env
php artisan key:generate
php artisan migrate
npm ci
```

Configure the database and application URL in `.env` before running migrations. Never commit `.env`, backups of `.env`, credentials, or generated application keys.

Start the Laravel development server:

```bash
php artisan serve
```

The owner UI (the `<x-ui.*>` kit in `assets/owner-ui/`) is plain CSS and JavaScript served directly; it does not require a frontend build. `THEME_NAME=juwri`. Node is used by the Puppeteer E2E journeys.

## Validation

Run the same core checks used by CI:

```bash
composer validate --strict --no-check-publish
php vendor/bin/phpunit
php vendor/bin/phpstan analyse app --level=3 --memory-limit=2G --no-progress
```

Run the whole test suite the OOM-safe way with `composer test` — an alias for
`php artisan test --parallel` with Composer's process timeout disabled so the
~8-minute parallel run is not cut off at 300s. A bare `php artisan test` runs
single-process and can fatally exhaust memory on the large localization
fixtures before any test executes, so always use `composer test` / `--parallel`
for a full run. For a focused run use `composer test:filter <Filter>` (forwards
to `php artisan test --filter`).

Additional CI lanes cover prefixed SQLite tests, MySQL 8 migrations, Blade caching, changed-file Pint checks, coverage floors, and nightly browser journeys. Workflow definitions live in `.github/workflows/`.

## Repository guidance

- `app/` — application code and services
- `routes/` — web, API, marketplace, and integration routes
- `resources/views/` — Blade views and themes
- `lang/` — supported locale dictionaries
- `database/migrations/` — schema history
- `tests/Feature/` and `tests/Unit/` — PHPUnit suites
- `resources/docs/` — product and operational documentation
- `resources/docs/internal/conventions.md` — non-negotiable repo rules; source comments cite it by
  `§` anchor. Read it before your first change.
- repository-level `rev/` — implementation plans, ledgers, and handoff artifacts

The product and Linen design rules are documented in the repository-level `PRODUCT.md`.
