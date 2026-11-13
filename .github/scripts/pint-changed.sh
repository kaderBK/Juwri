#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD

# T10-7 — Pint style gate scoped to a PR's CHANGED PHP files.
#
# The codebase is not fully pint-clean (migrations/app carry legacy style), so a
# whole-tree `pint --test` would red every PR. Instead this checks only the PHP
# files a PR actually adds/changes vs its base — genuinely additive: it never
# reds a PR that didn't touch PHP or whose touched PHP is clean, but it enforces
# the laravel preset on all NEW/changed code. Run from repo root.
set -euo pipefail

BASE_REF="${BASE_REF:-origin/main}"

# Core-relative paths of changed PHP files under core/ (A/C/M/R only).
# Locale dictionaries are generated legacy arrays and are validated by PHP
# syntax/parity tests; running Pint on them would rewrite tens of thousands of
# unrelated lines whenever a translation changes.
# Portable (no bash-4 mapfile): keep the list in a temp file.
CHANGED_FILE="$(mktemp)"
trap 'rm -f "$CHANGED_FILE"' EXIT
git diff --name-only --diff-filter=ACMR "${BASE_REF}...HEAD" -- 'core/**/*.php' \
  | sed '/^core\/lang\/[^/]*\/lang\.php$/d' \
  | sed 's|^core/||' > "$CHANGED_FILE"

if [ ! -s "$CHANGED_FILE" ]; then
  echo "pint: no changed PHP files under core/ — nothing to check."
  exit 0
fi

echo "pint: checking $(grep -c '' "$CHANGED_FILE") changed PHP file(s)…"
cd core
# shellcheck disable=SC2046
xargs vendor/bin/pint --test < "$CHANGED_FILE"
