#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD
#
# P6-225 docker drill: prove the database advisory lock in juwri:migrate-gate actually prevents
# the race it was written to prevent. Usage:  bash docker/tests/migrate-drill.sh mysql|pgsql
#
# What it does:
#   * Builds the juwri/app image (target app) from the repository root.
#   * Brings up db + two web replicas (both JUWRI_MIGRATE=1, no published ports) + worker + scheduler.
#   * Asserts: exactly ONE web replica actually runs the migrations; the other waits under the lock.
#   * Asserts: worker and scheduler find the schema current (never against a half-migrated DB).
#   * Asserts: app1/app2 logs hold no SQLSTATE; worker/scheduler logs hold no SQLSTATE / "Base table or view not found".
#   * Then forces a version skew by inserting a fake row into `migrations`, restarts the worker, and
#     asserts it logs "version skew" (the entrypoint refuses to start).
#
# All secrets (APP_KEY, DB passwords) are generated locally, written into the temp dir, and torn down on exit.
set -euo pipefail

DRIVER="${1:-}"
case "$DRIVER" in
    mysql|pgsql) ;;
    *) echo "usage: bash $0 mysql|pgsql" >&2; exit 2 ;;
esac

# Resolve the repo root (this script lives in docker/tests/).
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

WORKDIR="$(mktemp -d -t p6225-migrate-drill.XXXXXXXXXX)"
# Project name: p6225-<driver>-<pid>. Unique per run so two drills on the same host do not collide.
PROJECT="p6225-${DRIVER}-$$"
DC() { docker compose -p "$PROJECT" -f "$WORKDIR/compose.yml" --env-file "$WORKDIR/.env" "$@"; }

# ---- Generate secrets (never printed) ----
APP_KEY="base64:$(openssl rand -base64 32)"
DB_PW="$(openssl rand -hex 12)"

# ---- Write .env for the app services ----
cat > "$WORKDIR/.env" <<ENV
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost
APP_KEY=${APP_KEY}
SERVER_NAME=:8080
CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=database
LOG_CHANNEL=stderr
LOG_LEVEL=info
TRUSTED_PROXIES=
DB_CONNECTION=${DRIVER}
DB_HOST=db
DB_PORT=$( [[ "$DRIVER" = pgsql ]] && echo 5432 || echo 3306 )
DB_DATABASE=juwri
DB_USERNAME=juwri
DB_PASSWORD=${DB_PW}
PASSWORD_POLICY_STRICT=true
SESSION_SECURE_COOKIE=false
ENV

# ---- Write the compose override ----
# context points at the repo root. The build target is `app`, the same image docker/compose.prod.yml uses.
DB_IMAGE="$( [[ "$DRIVER" = pgsql ]] && echo postgres:17 || echo mysql:8.4 )"

if [[ "$DRIVER" = pgsql ]]; then
    DB_ENV="POSTGRES_DB: juwri
      POSTGRES_USER: juwri
      POSTGRES_PASSWORD: ${DB_PW}"
    DB_DATA_DIR="/var/lib/postgresql/data"
    DB_HEALTH='test: ["CMD-SHELL", "pg_isready -U juwri"]
      interval: 10s
      timeout: 5s
      retries: 30'
else
    # MYSQL_ROOT_PASSWORD is intentionally left unset: the healthcheck uses mysqladmin ping -h 127.0.0.1
    # which cannot authenticate when root has a password (same as docker/compose.prod.yml). The juwri
    # user (DB user the app connects as) still has its own password, so this is not a credential-less DB.
    DB_ENV="MYSQL_DATABASE: juwri
      MYSQL_USER: juwri
      MYSQL_PASSWORD: ${DB_PW}
      MYSQL_ALLOW_EMPTY_PASSWORD: 'yes'"
    DB_DATA_DIR="/var/lib/mysql"
    DB_HEALTH='test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1"]
      interval: 10s
      timeout: 5s
      retries: 30'
fi
DB_SKEW_INSERT="INSERT INTO migrations (migration, batch) VALUES ('2099_01_01_000000_from_newer_release', 999);"

cat > "$WORKDIR/compose.yml" <<YAML
name: ${PROJECT}
x-app: &app
  build:
    context: ${REPO_ROOT}
    dockerfile: Dockerfile
    target: app
  image: juwri/app:p6225-test
  env_file: [.env]
  restart: "no"
  volumes:
    - framework:/app/core/storage

services:
  db:
    image: ${DB_IMAGE}
    restart: "no"
    environment:
      ${DB_ENV}
    volumes:
      - db-data:${DB_DATA_DIR}
    healthcheck:
      ${DB_HEALTH}

  # Two web replicas — both JUWRI_MIGRATE=1, no published ports. They start together (depends_on db only)
  # so they race for the advisory lock; exactly one should actually migrate.
  app1:
    <<: *app
    depends_on:
      db: {condition: service_healthy}
    environment:
      JUWRI_MIGRATE: "1"
  app2:
    <<: *app
    depends_on:
      db: {condition: service_healthy}
    environment:
      JUWRI_MIGRATE: "1"

  # Worker + scheduler wait for the schema to be current (the web replica that wins the lock runs it).
  # They depend on db only too — they can come up at the same time as the web replicas and either find
  # the schema already migrated or wait until it is.
  worker:
    <<: *app
    depends_on:
      db: {condition: service_healthy}
    command: ["queue"]
  scheduler:
    <<: *app
    depends_on:
      db: {condition: service_healthy}
    command: ["scheduler"]

volumes:
  framework:
  db-data:
YAML

# Always tear down the stack, even on failure.
trap 'RC=$?; docker compose -p "$PROJECT" -f "$WORKDIR/compose.yml" --env-file "$WORKDIR/.env" down -v >/dev/null 2>&1 || true; rm -rf "$WORKDIR"' EXIT

# ---- Build + up ----
echo "== build"
DC build >"$WORKDIR/build.log" 2>&1 || { tail -30 "$WORKDIR/build.log"; exit 1; }
echo "== up -d"
DC up -d >"$WORKDIR/up.log" 2>&1 || { tail -30 "$WORKDIR/up.log"; DC logs --tail 50; exit 1; }

# Track pass/fail counts. Print PASS/FAIL per assertion. Exit non-zero on any FAIL.
FAIL=0
pass() { echo "PASS  $*"; }
bad()  { echo "FAIL  $*"; FAIL=$((FAIL+1)); }

# Wait for db to be healthy first (10 min cap).
echo "== wait for db healthy"
DB_HEALTHY=0
for i in $(seq 1 120); do
    if DC ps --format '{{.Service}} {{.Health}}' | awk '$1=="db" && $2=="healthy"' | grep -q .; then
        DB_HEALTHY=1
        pass "db is healthy (within $((i*5))s)"
        break
    fi
    sleep 5
done
[ "$DB_HEALTHY" = 1 ] || { bad "db never became healthy"; DC logs --tail 30 db; exit 1; }

# Wait for app1 + app2 to be healthy (their healthcheck hits /up, so they need Caddy + Octane up + migrations done).
echo "== wait for app1 and app2 healthy (up to 600s)"
APP_HEALTHY=0
for i in $(seq 1 120); do
    a1=$(DC ps --format '{{.Service}} {{.Health}}' | awk '$1=="app1" && $2=="healthy"' | grep -c . || true)
    a2=$(DC ps --format '{{.Service}} {{.Health}}' | awk '$1=="app2" && $2=="healthy"' | grep -c . || true)
    if [ "${a1:-0}" = 1 ] && [ "${a2:-0}" = 1 ]; then
        APP_HEALTHY=1
        pass "app1 and app2 are both healthy (within $((i*5))s)"
        break
    fi
    sleep 5
done
[ "$APP_HEALTHY" = 1 ] || { bad "app1 or app2 never became healthy"; DC ps; DC logs --tail 50 app1 app2; exit 1; }

# Give the worker/scheduler another moment to settle — they exit immediately after the schema wait and
# the queue worker enters the long-lived php artisan queue:work loop, so they may take longer to log.
sleep 5

# ---- Assertions ----
echo "== assertions"
APP1_LOGS="$(DC logs --no-log-prefix app1 2>/dev/null || true)"
APP2_LOGS="$(DC logs --no-log-prefix app2 2>/dev/null || true)"
WORKER_LOGS="$(DC logs --no-log-prefix worker 2>/dev/null || true)"
SCHED_LOGS="$(DC logs --no-log-prefix scheduler 2>/dev/null || true)"

# 1. Exactly ONE of app1/app2 actually ran the migrations. Laravel prints "INFO  Running migrations."
#    and then "<name> ... DONE" per migration.
A1_MIGRATED=0; A2_MIGRATED=0
echo "$APP1_LOGS" | grep -qE 'Running migrations\.|DONE' && A1_MIGRATED=1 || A1_MIGRATED=0
echo "$APP2_LOGS" | grep -qE 'Running migrations\.|DONE' && A2_MIGRATED=1 || A2_MIGRATED=0
RAN=$((A1_MIGRATED + A2_MIGRATED))
if [ "$RAN" = 1 ]; then
    WINNER=$([ "$A1_MIGRATED" = 1 ] && echo app1 || echo app2)
    pass "exactly one web replica migrated (${WINNER}) — the other waited under the lock"
else
    bad "expected exactly 1 web replica to run migrations, saw $RAN (app1=$A1_MIGRATED app2=$A2_MIGRATED)"
    [ "$RAN" = 0 ] && bad "no web replica ran any migration — both hit the lock timeout or the schema check failed"
    [ "$RAN" = 2 ] && bad "BOTH web replicas ran migrations — the advisory lock did not serialize them"
fi

# 2. migrate:status on the winning web replica lists nothing Pending.
STATUS_OUT="$(DC exec -T app1 php artisan migrate:status 2>&1 || true)"
if echo "$STATUS_OUT" | grep -qE '^[[:space:]]*Pending[[:space:]]*$'; then
    bad "migrate:status still shows a Pending row after the drill started"
elif echo "$STATUS_OUT" | grep -qE 'No migrations found'; then
    bad "migrate:status sees no migrations at all (drill never created the migrations table?)"
elif echo "$STATUS_OUT" | grep -qE 'Ran'; then
    pass "migrate:status has no Pending rows"
else
    bad "migrate:status output unexpected: $(echo "$STATUS_OUT" | head -5 | tr '\n' '|')"
fi

# 3. worker and scheduler logs: "schema is current" appears, no SQLSTATE / "Base table or view not found".
echo "$WORKER_LOGS" | grep -q 'schema is current' && pass "worker logs 'schema is current'" \
    || bad "worker logs do not contain 'schema is current' (it ran against a half-migrated DB?)"
echo "$SCHED_LOGS" | grep -q 'schema is current' && pass "scheduler logs 'schema is current'" \
    || bad "scheduler logs do not contain 'schema is current'"

echo "$WORKER_LOGS" | grep -q 'SQLSTATE' && bad "worker logs contain SQLSTATE" || pass "worker logs have no SQLSTATE"
echo "$SCHED_LOGS"  | grep -q 'SQLSTATE' && bad "scheduler logs contain SQLSTATE" || pass "scheduler logs have no SQLSTATE"
echo "$WORKER_LOGS" | grep -q 'Base table or view not found' && bad "worker logs contain 'Base table or view not found'" || pass "worker logs have no 'Base table or view not found'"
echo "$SCHED_LOGS"  | grep -q 'Base table or view not found' && bad "scheduler logs contain 'Base table or view not found'" || pass "scheduler logs have no 'Base table or view not found'"

# 4. app1 / app2 logs contain no SQLSTATE (a race would surface here as a "table already exists" error).
echo "$APP1_LOGS" | grep -q 'SQLSTATE' && bad "app1 logs contain SQLSTATE" || pass "app1 logs have no SQLSTATE"
echo "$APP2_LOGS" | grep -q 'SQLSTATE' && bad "app2 logs contain SQLSTATE" || pass "app2 logs have no SQLSTATE"

# ---- Version skew drill ----
echo "== skew drill"
# Insert a fake newer-release migration row into the migrations table. The worker, on its next start, will
# see the row, find no matching migration file in the image, and exit 3 with "version skew".
if [[ "$DRIVER" = pgsql ]]; then
    DC exec -T db psql -U juwri -d juwri -c "${DB_SKEW_INSERT}" >/dev/null 2>&1 \
        || bad "could not insert skew row via psql"
else
    DC exec -T db mysql -ujuwri -p"${DB_PW}" juwri -e "${DB_SKEW_INSERT}" >/dev/null 2>&1 \
        || bad "could not insert skew row via mysql"
fi

DC restart worker >/dev/null 2>&1 || true
sleep 20
WORKER_LOGS_AFTER="$(DC logs --no-log-prefix worker 2>/dev/null || true)"
echo "$WORKER_LOGS_AFTER" | grep -q 'version skew' \
    && pass "worker logs 'version skew' after the fake newer-release row" \
    || bad "worker logs do NOT contain 'version skew' (entrypoint did not refuse to start)"

echo
echo "== failed assertions: $FAIL"
exit "$FAIL"