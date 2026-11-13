#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) JUWRI LTD
# P6-167: install the juwri systemd units. Run as root on the server.
#   bash install.sh ACCOUNT [--core /home/ACCOUNT/juwri/core] [--php /opt/cpanel/ea-php84/root/usr/bin/php] [--dest /etc/systemd/system] [--no-scheduler] [--no-enable]
# Copies the units with ACCOUNT, the core directory and the PHP binary filled in, runs daemon-reload, and (unless
# --no-enable) enables and starts juwri-queue@default, juwri-queue@heavy and juwri-scheduler.timer.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ACCOUNT="${1:-}"; [ -n "$ACCOUNT" ] && [ "${ACCOUNT#-}" = "$ACCOUNT" ] || { echo "usage: install.sh ACCOUNT [--core DIR] [--php BIN] [--dest DIR] [--no-scheduler] [--no-enable]" >&2; exit 2; }
shift
CORE="/home/$ACCOUNT/juwri/core"; PHP_BIN="/opt/cpanel/ea-php84/root/usr/bin/php"; DEST="/etc/systemd/system"; SCHED=1; ENABLE=1
while [ $# -gt 0 ]; do case "$1" in
  --core) CORE="$2"; shift 2;; --php) PHP_BIN="$2"; shift 2;; --dest) DEST="$2"; shift 2;;
  --no-scheduler) SCHED=0; shift;; --no-enable) ENABLE=0; shift;; *) echo "unknown option $1" >&2; exit 2;; esac; done
[[ "$ACCOUNT$CORE$PHP_BIN" =~ ^[A-Za-z0-9_./-]+$ ]] || { echo "ACCOUNT, --core and --php may only contain letters, digits and _ . / -" >&2; exit 2; }
[ -f "$CORE/artisan" ] || { echo "no artisan in $CORE" >&2; exit 1; }
[ -x "$PHP_BIN" ] || { echo "PHP binary $PHP_BIN is not executable" >&2; exit 1; }
id "$ACCOUNT" >/dev/null 2>&1 || { echo "no such user $ACCOUNT" >&2; exit 1; }
# Supervisor and systemd workers must not both run: refuse when supervisor already has juwri workers.
if command -v supervisorctl >/dev/null 2>&1 && supervisorctl status 2>/dev/null | grep -Eq '^juwri-worker'; then
  echo "supervisor already runs juwri workers: stop them first (supervisorctl stop juwri-worker:* juwri-worker-heavy:*)" >&2; exit 1; fi
fill() { sed -e "s|^User=ACCOUNT|User=$ACCOUNT|; s|^Group=ACCOUNT|Group=$ACCOUNT|; s|JUWRI_CORE|$CORE|g; s|PHP_BIN|$PHP_BIN|g" "$1"; }
mkdir -p "$DEST/juwri-queue@default.service.d" "$DEST/juwri-queue@heavy.service.d"
fill "$HERE/juwri-queue@.service" > "$DEST/juwri-queue@.service"
cp "$HERE/juwri-queue@default.service.d/default.conf" "$DEST/juwri-queue@default.service.d/"
cp "$HERE/juwri-queue@heavy.service.d/heavy.conf" "$DEST/juwri-queue@heavy.service.d/"
if [ "$SCHED" = 1 ]; then
  fill "$HERE/juwri-scheduler.service" > "$DEST/juwri-scheduler.service"; cp "$HERE/juwri-scheduler.timer" "$DEST/"
fi
echo "units written to $DEST"
[ "$DEST" = "/etc/systemd/system" ] || { echo "custom --dest: not running systemctl"; exit 0; }
systemctl daemon-reload
if [ "$ENABLE" = 1 ]; then
  systemctl enable --now juwri-queue@default juwri-queue@heavy
  [ "$SCHED" = 1 ] && systemctl enable --now juwri-scheduler.timer
  systemctl --no-pager --lines=0 status 'juwri-queue@*' || true
fi
