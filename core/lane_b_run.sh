# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD

for d in "$@"; do
  echo "### $d"
  timeout 45m php8.4 artisan test --parallel --processes=4 --compact $d 2>&1 | grep -aE 'Tests:|FAIL|Fatal|Failed asserting|^ +at ' | head -30
done
