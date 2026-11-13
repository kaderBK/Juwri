#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD

# Runs the Pest browser tests and ALWAYS stops the Playwright server afterwards.
#   bash tests/Browser/run.sh [paths/args]          (from core/; default: tests/Browser, e.g. tests/Browser/SignUpTest.php)
# On a CI or remote machine: bash tests/Browser/run.sh
# Why: pest-plugin-browser starts `playwright run-server` as a child that outlives a failed or killed run, keeps the
# ssh session open and holds the slot lock. Only Playwright servers whose cwd is this core/ are stopped.
cd "$(dirname "$0")/../.." || exit 2
CORE="$(pwd -P)"
timeout 40m ${PHP:-php} -d max_execution_time=0 -d memory_limit=2G artisan test --compact "${@:-tests/Browser}"
rc=$?
for pid in $(pgrep -f "playwright run-server" 2>/dev/null); do
  [ "$(readlink "/proc/$pid/cwd" 2>/dev/null)" = "$CORE" ] && kill -9 "$pid" 2>/dev/null
done
exit $rc
