#!/usr/bin/env bash
# P6-140: run the shared-host subset under typical cPanel/shared-hosting limits.
#   memory_limit (128M headroom, see SHARED_MEMORY_LIMIT), open_basedir = app dir + /tmp (+ PHP's own dirs), exec-family functions disabled.
# Each test FILE runs in its own PHP process: a web request is one short process, whereas one long phpunit process
# accumulates memory across tests and would fail for reasons no shared host ever sees.
# Usage (from core/):  bin/test-shared-host.sh [php-binary] [file-or-filter ...]
#   SHARED_MEMORY_LIMIT=240M  override (128M of request headroom + ~110 MB test-harness baseline)
# Example:  timeout 45m bash bin/test-shared-host.sh php8.4
# Exit code 0 = every file passed. Machine loops can call this script as-is.
set -uo pipefail
cd "$(dirname "$0")/.."
PHP="${1:-php}"; shift || true
APP="$(cd .. && pwd -P)"   # repo root: on a shared host the whole install (core/ + assets/ + storage/) sits in the account home
# A bare test process already holds ~110 MB (framework + in-memory schema), so 128M of request headroom = 240M here.
MEM="${SHARED_MEMORY_LIMIT:-240M}"
if [ "$#" -gt 0 ]; then FILES=("$@")
else mapfile -t FILES < <(sed -n 's#.*<file>\./\(.*\)</file>.*#\1#p' phpunit.shared.xml); fi
fail=0; failed=()
for f in "${FILES[@]}"; do
  if ! "$PHP" -d "memory_limit=$MEM" \
    -d "open_basedir=${APP}:/tmp:/var/tmp:/usr/share/php:/usr/share/zoneinfo:/dev/null:/dev/urandom:/etc/ssl" \
    -d "disable_functions=exec,shell_exec,proc_open,popen,passthru,system" \
    vendor/bin/phpunit -c phpunit.shared.xml --no-progress "$f" >"/tmp/shared-host.$$.log" 2>&1; then
    fail=1; failed+=("$f"); echo "FAIL $f"; tail -25 "/tmp/shared-host.$$.log" | sed 's/^/    /'
  else echo "ok   $f"; fi
done
rm -f "/tmp/shared-host.$$.log"
echo "SHARED-HOST: ${#FILES[@]} files, ${#failed[@]} failed (memory_limit=$MEM)"
exit $fail
