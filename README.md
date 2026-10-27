# juwri

juwri is an open-source commerce platform for restaurants, cafes, shops and service businesses:
QR and online menus, ordering, point of sale, kitchen screens, tables and reservations, delivery,
inventory, loyalty, bookings, reports and payments, in 11 languages including right-to-left Arabic and Urdu.
It is a Laravel 13 application (PHP 8.4) with a Livewire 4 admin and owner interface.

Made by JUWRI LTD (England and Wales, company number 14363043).

## Editions

| | Community Edition (this repository) | Enterprise |
|---|---|---|
| Licence | GNU AGPL-3.0-only plus the Extension Exception | juwri Enterprise Licence (separate package) |
| Run your own business, any number of stores and branches | Yes, free | Yes |
| Hotel, pharmacy, laundry, accounting and sales-channel modules | Yes | Yes |
| Platform features (multi-account sign-up, selling plans, reseller and white-label tools, SSO, audit log) | No | Yes |

The Community code never depends on the Enterprise package. A hosted service, juwri Cloud (juwri.com),
is run by JUWRI LTD; some services (AI credits, embedded payments, messaging bundles, partner connectors)
belong to Cloud and not to this repository.

## Licence summary

- Everything in this repository is AGPL-3.0-only (see `LICENSE`). If you change juwri and let other people use
  it over a network, you must offer them your changed source.
- Themes, plugins and integrations that use only the documented extension points may use any licence
  (`EXTENSION-EXCEPTION.md`).
- A commercial licence for the Community code is available if the AGPL does not suit you (sales@juwri.com).
- Third-party components keep their own licences: `THIRD-PARTY.md` and `THIRD-PARTY-NOTICES.md`.
- The juwri name and logo are not licensed: `TRADEMARKS.md`.
- More: `LICENSING.md` and `LICENSE-FAQ.md`.

## Install

See [INSTALL.md](INSTALL.md) for a fresh install from zero (requirements, `.env`, database, queue,
scheduler, mail, storage, first admin). Docker: `docker/README.md`. Shared hosting and cPanel VPS: `deploy/HOSTING.md`.

Quick start for development:

```bash
cd core
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

## Repository layout

- `core/` the Laravel application (modules in `core/app/Modules`, translations in `core/lang`)
- `assets/` front-end assets and the owner UI kit
- `desktop/`, `kiosk/`, `mobile/`, `driver-app/` companion clients
- `integrations/` Make, n8n and Zapier apps
- `storefront-starter/` a headless storefront example
- `docker/`, `deploy/` self-hosting files and runbooks
- `docs/` developer and operations documentation

## Contributing

Read `CONTRIBUTING.md` and `CODE_OF_CONDUCT.md`. Contributors sign the juwri CLA (`CLA-INDIVIDUAL.md`,
`CLA-CORPORATE.md`); you keep your copyright. Developer setup: `docs/development.md`.

## Security

Do not open public issues for vulnerabilities. Report them through GitHub private vulnerability reporting
(repository **Security** tab, **Report a vulnerability**); see `SECURITY.md`. There is no security email address.
