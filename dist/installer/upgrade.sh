#!/usr/bin/env bash
# LPanel upgrade: install this bundle over the running panel, and put the old
# one back if that fails.
#
# Run as root from an unpacked bundle:  ./upgrade.sh
# (The Admin panel's Updates page runs the same script, from the release the
# agent downloaded and checked, in a systemd unit of its own: lpanel-upgrade.)
#
#  1. Backs up what install.sh replaces: the panel's code, the agent and its
#     helpers, LPanel's systemd units, and the panel's database (a dump).
#  2. Runs install.sh, which keeps every setting and secret and migrates the
#     database (see install.sh).
#  3. If install.sh fails, puts the backup back (code, binaries, units, the
#     database as it was) and restarts the panel, so the server runs the
#     release it ran before. Configuration of other services that install.sh
#     rewrote is left as the new release wrote it: install.sh writes those
#     files whole, and the old release's installer writes them back when run.
#
# Progress goes to /var/lib/lpa/updates/upgrade.log, and how it went to
# /var/lib/lpa/updates/status.json, which the panel reads through the agent.
set -uo pipefail

BUNDLE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
PANEL=/usr/local/lpanel
STATE=/var/lib/lpa/updates
BACKUP=$STATE/rollback
LOG=$STATE/upgrade.log
UNITS="lpa.service lpanel-authd.service lpanel-authd.socket lpanel-php-fpm.service lpanel-nginx.service lpanel-scheduler.service lpanel-scheduler.timer"

[ "$(id -u)" -eq 0 ] || { echo "upgrade.sh: run it as root" >&2; exit 1; }
install -d -m 0700 "$STATE"
exec 9>"$STATE/upgrade.lock"
flock -n 9 || { echo "upgrade.sh: an upgrade is running already" >&2; exit 1; }

TARGET=$(tr -d '[:space:]' < "$BUNDLE/VERSION" 2>/dev/null)
FROM=$(tr -d '[:space:]' < "$PANEL/VERSION" 2>/dev/null || true)
version_rule='^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,6}(-[0-9A-Za-z.]{1,40})?$'
[[ "$TARGET" =~ $version_rule ]] || { echo "upgrade.sh: this bundle has no release number (VERSION)" >&2; exit 1; }
[[ "$FROM" =~ $version_rule ]] || FROM=""
STARTED=$(date -u +%Y-%m-%dT%H:%M:%SZ)

# status <state> [error]: what the panel sees (the values are release numbers
# and fixed words, so plain printf makes valid JSON).
status() {
    local err=${2:-} finished=""
    case "$1" in done|failed|rolled_back) finished=$(date -u +%Y-%m-%dT%H:%M:%SZ) ;; esac
    err=${err//\\/ }; err=${err//\"/\'}
    printf '{"state":"%s","target":"%s","from":"%s","started":"%s","finished":"%s","error":"%s"}\n' \
        "$1" "$TARGET" "$FROM" "$STARTED" "$finished" "$err" > "$STATE/.status.json.tmp"
    mv -f "$STATE/.status.json.tmp" "$STATE/status.json"
}

: > "$LOG"
chmod 0600 "$LOG"
exec >>"$LOG" 2>&1
echo "==> LPanel upgrade from ${FROM:-an unknown release} to $TARGET ($STARTED)"
status running

# ---------------------------------------------------------------------------
echo "==> Backing up the panel, the agent and the database"
rm -rf "$BACKUP"
install -d -m 0700 "$BACKUP"
backup() {
    tar -C "$PANEL" --exclude=./system --exclude=./.env -cpf "$BACKUP/panel.tar" . &&
    tar -C /usr/local/lpa -cpf "$BACKUP/lpa.tar" bin &&
    { [ ! -d /usr/libexec/lpanel ] || tar -C /usr/libexec -cpf "$BACKUP/libexec.tar" lpanel; } &&
    (cd /etc/systemd/system && tar -cpf "$BACKUP/units.tar" $(for u in $UNITS; do [ -f "$u" ] && echo "$u"; done)) &&
    mariadb-dump --single-transaction --routines --triggers --events lpanel > "$BACKUP/lpanel.sql" &&
    echo "${FROM}" > "$BACKUP/VERSION"
}
if ! backup; then
    echo "==> The backup failed; nothing was changed"
    status failed "the backup before the upgrade failed; nothing was changed"
    exit 1
fi
echo "    $(du -sh "$BACKUP" | cut -f1) in $BACKUP"

# ---------------------------------------------------------------------------
echo "==> Installing $TARGET"
"$BUNDLE/install.sh"
rc=$?
if [ "$rc" -eq 0 ]; then
    echo "==> $TARGET is installed"
    status done
    exit 0
fi

# ---------------------------------------------------------------------------
echo "==> install.sh failed ($rc); putting ${FROM:-the old release} back"
rollback() {
    find "$PANEL" -mindepth 1 -maxdepth 1 ! -name system ! -name .env -exec rm -rf {} + &&
    tar -C "$PANEL" -xpf "$BACKUP/panel.tar" &&
    tar -C /usr/local/lpa -xpf "$BACKUP/lpa.tar" &&
    { [ ! -f "$BACKUP/libexec.tar" ] || tar -C /usr/libexec -xpf "$BACKUP/libexec.tar"; } &&
    tar -C /etc/systemd/system -xpf "$BACKUP/units.tar" &&
    systemctl daemon-reload &&
    mariadb -e "DROP DATABASE IF EXISTS lpanel; CREATE DATABASE lpanel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" &&
    mariadb lpanel < "$BACKUP/lpanel.sql" &&
    { ! { command -v selinuxenabled >/dev/null 2>&1 && selinuxenabled; } || restorecon -R "$PANEL" /usr/local/lpa /usr/libexec/lpanel; } &&
    rm -rf "$PANEL/system/Cache/"* &&
    systemctl restart lpa.service lpanel-php-fpm.service lpanel-nginx.service
}
if rollback; then
    for _ in $(seq 50); do [ -S /run/lpa/lpa.sock ] && break; sleep 0.2; done
    echo "==> ${FROM:-The old release} is back"
    status rolled_back "install.sh of $TARGET exited with $rc; ${FROM:-the old release} was put back"
    exit 1
fi
echo "==> Putting the old release back failed too: restore from $BACKUP by hand"
status failed "install.sh of $TARGET exited with $rc, and putting the old release back failed; see $LOG"
exit 1
