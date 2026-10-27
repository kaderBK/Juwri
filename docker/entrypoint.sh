#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD

# juwri container entrypoint. Creates nothing secret; refuses to start the web role without the keys it needs.
set -eu

cd /app/core

# Fail early and say why, instead of booting an app that answers 500 to everything.
if [ "${1:-}" = "frankenphp" ] || [ "${1:-}" = "queue" ] || [ "${1:-}" = "scheduler" ] || [ "${1:-}" = "reverb" ]; then
    if [ -z "${APP_KEY:-}" ]; then
        echo "juwri: APP_KEY is not set. Generate one with:  echo \"base64:\$(openssl rand -base64 32)\"  (see docker/README.md step 2)" >&2
        exit 64
    fi
fi

# P6-51: behind a TLS proxy every request reaches Caddy/PHP from the proxy's address, so without TRUSTED_PROXIES all
# visitors share one IP (one throttle bucket) and https is not detected. Default ONLY in proxy mode (SERVER_NAME is a bare
# ":port", the documented "own TLS proxy in front" setup) and ONLY to private/loopback ranges, i.e. a proxy on the same
# host, docker network or LAN. Never "*" (anyone could forge X-Forwarded-For). When the bundled Caddy terminates TLS itself
# (SERVER_NAME is a domain) nothing is trusted. An explicit TRUSTED_PROXIES (any value, "none" = trust nothing) wins.
# Cloudflare / ALB / a proxy on another public IP: set TRUSTED_PROXIES to its ranges (docker/README.md, "Behind a proxy").
if [ -z "${TRUSTED_PROXIES+x}" ] || [ -z "${TRUSTED_PROXIES}" ]; then
    case "${SERVER_NAME:-:80}" in
        :[0-9]*)
            export TRUSTED_PROXIES="127.0.0.1/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1/128,fc00::/7"
            [ "${1:-}" = "frankenphp" ] && echo "juwri: proxy mode (SERVER_NAME=${SERVER_NAME:-:80}); trusting X-Forwarded-* from private ranges only. Do not publish this port to the internet." >&2
            ;;
    esac
elif [ "${TRUSTED_PROXIES}" = "none" ]; then
    export TRUSTED_PROXIES=""
fi

# The framework keeps its caches / sessions / logs in core/storage (a volume): make sure the folders exist.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

# P6-225: several containers share one database. Exactly one migrates (JUWRI_MIGRATE=1 web containers take a database
# advisory lock, so two web replicas never race); every other role waits until no migration is pending, and every role
# refuses to start when the database is newer than this image (version skew, exit 3). JUWRI_MIGRATE_TIMEOUT bounds the
# wait (default 900 s); JUWRI_MIGRATE_WAIT=0 skips the wait (one-off `docker run ... php artisan ...` debugging only).
juwri_migrate_gate() {
    [ "${JUWRI_MIGRATE_WAIT:-1}" = "0" ] && [ "$1" = "--wait" ] && return 0
    php artisan juwri:migrate-gate "$1" --timeout="${JUWRI_MIGRATE_TIMEOUT:-900}"
}

case "${1:-}" in
    queue)
        shift
        juwri_migrate_gate --wait
        # No --stop-when-empty: this is the daemon the app's queue heartbeat looks for (deploy/supervisor/juwri-worker.conf).
        # P6-64b: default queue only (short jobs, retry_after 150s); long jobs run in `queue-heavy`.
        exec php artisan queue:work --queue=default --sleep=1 --tries=3 --max-time=3600 "$@"
        ;;
    queue-heavy)
        shift
        juwri_migrate_gate --wait
        # P6-64b: long jobs (backups, imports, AI, up to 1800s) on the `heavy` connection (retry_after 1860s).
        exec php artisan queue:work heavy --queue=heavy --sleep=1 --tries=3 --max-time=3600 "$@"
        ;;
    scheduler)
        shift
        juwri_migrate_gate --wait
        exec php artisan schedule:work "$@"
        ;;
    reverb)
        shift
        juwri_migrate_gate --wait
        # Live-updates WebSocket server (Laravel Reverb). Single process, no Redis/database. Start it only when
        # BROADCAST_CONNECTION=reverb and the REVERB_* keys are set; the app falls back to polling without it.
        exec php artisan reverb:start --host="${REVERB_SERVER_HOST:-0.0.0.0}" --port="${REVERB_SERVER_PORT:-8080}" "$@"
        ;;
    frankenphp)
        # Octane workers: unset = the number of CPU cores this container may use (the load test of 2026-10-07 on 16 vCPU:
        # 2 workers gave p50 2.6 s at 3 req/s, 8 workers p50 33 ms). A CPU limit (docker --cpus / compose cpus) is honoured.
        if [ -z "${FRANKENPHP_WORKERS:-}" ]; then
            w=$(nproc 2>/dev/null || echo 2)
            if [ -r /sys/fs/cgroup/cpu.max ]; then
                q=$(cut -d' ' -f1 /sys/fs/cgroup/cpu.max); p=$(cut -d' ' -f2 /sys/fs/cgroup/cpu.max)
                if [ "$q" != "max" ] && [ "${p:-0}" -gt 0 ] 2>/dev/null; then c=$(( (q + p - 1) / p )); [ "$c" -lt "$w" ] && w=$c; fi
            fi
            [ "$w" -lt 2 ] && w=2
            [ "$w" -gt 32 ] && w=32
            export FRANKENPHP_WORKERS="$w"
        fi
        echo "juwri: ${FRANKENPHP_WORKERS} Octane workers" >&2
        if [ "${JUWRI_MIGRATE:-0}" = "1" ]; then
            juwri_migrate_gate --run
        else
            juwri_migrate_gate --wait
        fi
        exec "$@"
        ;;
    *)
        exec "$@"
        ;;
esac
