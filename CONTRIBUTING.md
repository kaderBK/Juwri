# Contributing to juwri

Thank you for helping. juwri is built in the open by JUWRI LTD and its community.

## Before you start
- **CLA:** the CLA bot asks you to accept the juwri Individual CLA on your first pull request
  (companies: the Corporate CLA). We can only merge pull requests once it is accepted. You keep
  your copyright; the CLA lets JUWRI LTD offer juwri under both the AGPL and commercial licences.
  The bot is configured in .github/cla/ and is switched on by the maintainers when the repository goes public.
- For anything bigger than a small fix, open an issue first so we can agree on the approach.
- Security problems: never open a public issue. See `SECURITY.md`.

## Licences and file headers
- Everything in this repository: AGPL-3.0-only.
  New PHP/JS/CSS files start with: `SPDX-License-Identifier: AGPL-3.0-only`
- The Enterprise features are developed by JUWRI LTD and distributed separately; they are not in
  this repository. Community code must never depend on them (the boundary test fails if it does).
  A feature that is in Community stays in Community.
- Don't copy code from other projects unless its licence is compatible with AGPL-3.0, and list it
  in `THIRD-PARTY.md` in the same pull request. Never paste code you don't have the right to share.

## Development
- PHP 8.4, MySQL 8.4 or PostgreSQL 18 (SQLite for quick local tests), Node for assets. Setup: `docs/development.md`.
- Tests: `cd core && php artisan test --parallel`. The suite must pass on MySQL and PostgreSQL.
- Static analysis: `vendor/bin/phpstan analyse --memory-limit=1G`; format changed files with Pint.

## Pull requests
- One change per pull request, with tests. Describe what changed and why; link the issue.
- UI changes: screenshots in light and dark mode, and checked right-to-left (Arabic).
- New user-facing text goes through the translation files; no hard-coded strings.

## Code of conduct
Be respectful. Harassment or abuse leads to removal from the project. Full rules:
CODE_OF_CONDUCT.md (Contributor Covenant 2.1).
