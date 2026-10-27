#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD

# T10-6 — PHPStan baseline ratchet.
#
# The reviewed phpstan-baseline.neon captures accepted legacy level-6 findings.
# The blocking full scan stops NEW unsuppressed errors; this companion ratchet
# stops new SUPPRESSIONS by failing when the baseline grows versus the PR's base
# branch. A PR that SHRINKS the baseline passes (and is encouraged).
#
# Metric: the sum of the `count:` integers in the baseline == the number of
# suppressed findings (more meaningful than raw line count, and stable against
# reformatting). Usage: run from repo root. BASE_REF defaults to origin/main.
set -euo pipefail

BASE_REF="${BASE_REF:-origin/main}"
# Every baseline under core/ (the application's, plus an edition package's own phpstan-baseline.neon when the
# repository carries one), summed: moving entries between them is neutral, adding one anywhere fails.
BASELINE_GLOB='core/*phpstan-baseline.neon'

count_suppressed() {
  # Sum every "count: N" in the baseline. Missing file → 0 (e.g. base had none).
  grep -oE 'count:[[:space:]]*[0-9]+' 2>/dev/null | grep -oE '[0-9]+' | awk '{s+=$1} END {print s+0}'
}

baselines_at() {   # baselines_at <tree-ish>|"" (work tree): print the baseline files' contents, concatenated
  if [ -z "$1" ]; then
    git ls-files -- ":(glob)$BASELINE_GLOB" ":(glob)core/**/phpstan-baseline.neon" | sort -u | while IFS= read -r f; do cat "$f"; done
  else
    git ls-tree -r --name-only "$1" -- core | grep -E '(^|/)phpstan-baseline\.neon$' | grep -v '/vendor/' | while IFS= read -r f; do git show "$1:$f"; done
  fi
}

head_count="$(baselines_at "" | count_suppressed)"

if git rev-parse --verify --quiet "$BASE_REF" >/dev/null; then
  base_count="$(baselines_at "$BASE_REF" | count_suppressed)"
else
  base_count=0
fi

echo "phpstan baseline suppressed findings: base($BASE_REF)=$base_count  head=$head_count"

if [ "$head_count" -gt "$base_count" ]; then
  echo "::error::PHPStan baseline GREW by $((head_count - base_count)) suppressed finding(s)."
  echo "Fix the new level-6 finding instead of baselining it (or, if truly unavoidable,"
  echo "an owner may regenerate the baseline deliberately)."
  exit 1
fi

echo "OK: baseline did not grow ($base_count -> $head_count)."
