#!/usr/bin/env bash
# LPanel installer for AlmaLinux / Rocky Linux 9.
#
# Run as root from an unpacked bundle (dist/installer/bundle.sh makes one):
#     ./install.sh [--hostname panel.example.com] [--allow-no-quota]
#
# It is safe to run again: secrets (database password, APP_KEY, the agent's
# signing key, the TLS certificate) are made once and kept; code, units and
# configuration LPanel owns are refreshed; migrations run.
#
# What ends up where is in design/ARCHITECTURE.md ("Layout on an AlmaLinux 9
# server"). Every step says what it does; nothing is hidden behind a pipe.
set -euo pipefail

BUNDLE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
PANEL=/usr/local/lpanel
HOMES=/home          # every account's home is made here (lpa.json "homes_base")
PHP=/usr/bin/php83
HOSTNAME_ARG=""
ALLOW_NO_QUOTA=0

usage() { sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; }
step() { printf '\n==> %s\n' "$*"; }
note() { printf '    %s\n' "$*"; }
die() { printf '\nlpanel install: %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --hostname) HOSTNAME_ARG="${2:-}"; shift 2 ;;
        --allow-no-quota) ALLOW_NO_QUOTA=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "unknown option $1 (see --help)" ;;
    esac
done

# ---------------------------------------------------------------------------
step "Checking this server"

[ "$(id -u)" -eq 0 ] || die "run the installer as root."
[ -d "$BUNDLE/panel" ] && [ -x "$BUNDLE/bin/lpa" ] && [ -x "$BUNDLE/bin/lpanel-authd" ] \
    || die "this is not an LPanel bundle (missing panel/ or bin/). Build one with dist/installer/bundle.sh."

. /etc/os-release
case "${ID:-}" in almalinux|rocky) ;; *) die "LPanel installs on AlmaLinux or Rocky Linux 9 for now; this is ${PRETTY_NAME:-unknown}." ;; esac
[ "${VERSION_ID%%.*}" = "9" ] || die "LPanel installs on version 9 for now; this is ${PRETTY_NAME}."
[ "$(uname -m)" = "x86_64" ] || die "LPanel installs on x86_64 for now; this is $(uname -m)."
[ "$(ps -p 1 -o comm=)" = "systemd" ] || die "systemd must be running as PID 1."
note "${PRETTY_NAME}, $(uname -m), systemd"

HOST="${HOSTNAME_ARG:-$(hostname -f 2>/dev/null || hostname)}"
[[ "$HOST" =~ ^[A-Za-z0-9.-]{1,253}$ ]] || die "the hostname \"$HOST\" is not a host name; pass --hostname."
note "hostname: $HOST"

for port in 7443 7444; do
    if command -v ss >/dev/null 2>&1 && ss -Hltn "sport = :$port" | grep -q . && ! systemctl is-active --quiet lpanel-nginx; then
        die "port $port is in use by something else."
    fi
done

# ADR-0008: disk limits are kernel quotas. Without them accounts cannot be sold.
home_opts=$(findmnt -no OPTIONS --target /home 2>/dev/null || true)
QUOTA_ON=0
if [[ ",$home_opts," =~ ,(usrquota|uquota|usrjquota=[^,]*|quota), ]]; then
    QUOTA_ON=1
    note "user quotas are on for /home"
elif [ "$ALLOW_NO_QUOTA" -eq 1 ]; then
    note "WARNING: user quotas are off for /home (allowed by --allow-no-quota; accounts will have no disk limits)"
else
    die "user quotas are off for the filesystem holding /home.
    XFS: add 'uquota' to its mount options in /etc/fstab (for / that also means
         rootflags=uquota on the kernel command line), then reboot.
    ext4: add 'usrquota', remount, and run quotacheck -cum /home && quotaon /home.
    To install anyway, for testing only: --allow-no-quota"
fi

# ---------------------------------------------------------------------------
step "Installing packages (EPEL, Remi's PHP, MariaDB, nginx for the panel, Apache for sites)"

rpm -q epel-release >/dev/null 2>&1 || dnf -y -q install epel-release
rpm -q remi-release >/dev/null 2>&1 || dnf -y -q install https://rpms.remirepo.net/enterprise/remi-release-9.rpm
dnf -y -q install \
    php83-php-fpm php83-php-cli php83-php-sodium php83-php-intl php83-php-mbstring \
    php83-php-pdo php83-php-mysqlnd php83-php-process php83-php-opcache php83-php-xml \
    mariadb-server nginx httpd openssl policycoreutils-python-utils procps-ng iproute
note "$($PHP -r 'echo "PHP ", PHP_VERSION;'), $(mariadb --version | awk '{print $1, $5}' | tr -d ,), $(nginx -v 2>&1), $(httpd -v | head -1)"

# PHP for customers' sites: each version side by side, one PHP-FPM pool per
# account in each (written by the agent). More versions come from the panel
# later (M2b).
CUSTOMER_PHP="82 83"
pkgs=()
for v in $CUSTOMER_PHP; do
    for ext in fpm cli common mysqlnd gd intl mbstring opcache xml zip bcmath process; do
        pkgs+=("php$v-php-$ext")
    done
done
dnf -y -q install "${pkgs[@]}"
for v in $CUSTOMER_PHP; do
    # Remi's sample pool runs as apache; LPanel's pools run as each account.
    # Emptied rather than removed, so a package update keeps it empty.
    www="/etc/opt/remi/php$v/php-fpm.d/www.conf"
    if [ -f "$www" ] && grep -q '^\[www\]' "$www"; then
        printf '; Emptied by LPanel: every pool here is an account'"'"'s, written by the agent.\n' > "$www"
    fi
done
note "PHP for sites: $(for v in $CUSTOMER_PHP; do printf '%s.%s ' "${v:0:1}" "${v:1}"; done)"

# The stock nginx stays off: the panel runs its own (lpanel-nginx). Each PHP
# version's FPM service is started by the agent once an account uses it, so
# it is left alone here: stopping it would take customers' sites down.
systemctl disable --now nginx >/dev/null 2>&1 || true
# Apache serves customers' sites; the agent writes its sites and reloads it.
systemctl enable -q --now httpd

# ---------------------------------------------------------------------------
step "Users and directories"

getent group lpanel >/dev/null || groupadd --system lpanel
getent passwd lpanel >/dev/null || useradd --system --gid lpanel --home-dir "$PANEL" --no-create-home --shell /sbin/nologin lpanel
install -d -m 0755 -o root -g root "$PANEL" /usr/local/lpa /usr/local/lpa/bin /etc/lpa
install -d -m 0750 -o root -g lpanel /etc/lpanel
install -d -m 0750 -o lpanel -g lpanel /var/log/lpanel /var/lib/lpanel
install -d -m 0700 -o nginx -g nginx /var/lib/lpanel/nginx
install -d -m 0700 -o root -g root /etc/lpanel/tls
note "lpanel user: uid $(id -u lpanel)"

# ---------------------------------------------------------------------------
step "Copying the panel to $PANEL"

# Code is root's and read-only to the panel; only system/ (cache, logs,
# sessions, runtime) is the panel's to write. .env and system/ survive upgrades.
tar -C "$BUNDLE/panel" --exclude=./.env --exclude=./system -cf - . | tar -C "$PANEL" --no-same-owner -xf -
chown -R root:lpanel "$PANEL"
find "$PANEL" -path "$PANEL/system" -prune -o -type d -exec chmod 0750 {} + -o -type f -exec chmod 0640 {} +
chmod 0750 "$PANEL/laika"
for d in Cache Logs Runtime Sessions Security Queue Schedule Storage Commands; do
    install -d -m 0750 -o lpanel -g lpanel "$PANEL/system/$d"
done
chown lpanel:lpanel "$PANEL/system"
chmod 0750 "$PANEL/system"
note "$(find "$PANEL" -type f | wc -l) files"

install -m 0755 "$BUNDLE/bin/lpa" /usr/local/lpa/bin/lpa
install -m 0755 "$BUNDLE/bin/lpanel-authd" /usr/local/lpa/bin/lpanel-authd
note "lpa $(/usr/local/lpa/bin/lpa version), lpanel-authd $(/usr/local/lpa/bin/lpanel-authd -version)"

# ---------------------------------------------------------------------------
step "Database (MariaDB)"

systemctl enable --now mariadb >/dev/null
if [ ! -f "$PANEL/.env" ]; then
    DB_PASSWORD=$(openssl rand -hex 24)
    mariadb -uroot <<SQL
CREATE DATABASE IF NOT EXISTS lpanel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'lpanel'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER 'lpanel'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON lpanel.* TO 'lpanel'@'localhost';
FLUSH PRIVILEGES;
SQL
    note "database lpanel and user lpanel created"

    step "Panel settings ($PANEL/.env)"
    APP_KEY=$(cd "$PANEL" && runuser -u lpanel -- $PHP laika security:key --bare)
    umask 027
    cat > "$PANEL/.env" <<ENV
# Written by the LPanel installer. Secrets: readable by root and the panel only.
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=UTC
APP_URL=https://${HOST}:7443
APP_KEY=${APP_KEY}
DB_DSN=mysql:unix_socket=/var/lib/mysql/mysql.sock;dbname=lpanel;charset=utf8mb4
DB_USERNAME=lpanel
DB_PASSWORD=${DB_PASSWORD}
SESSION_STORE=database
SESSION_COOKIE_SECURE=true
MAIL_TRANSPORT=log
LPANEL_ROOT_VERIFIER=authd
LPANEL_AGENT_KEY_FILE=/etc/lpanel/agent.key
ENV
    umask 022
    note "new APP_KEY and database password written"
else
    note "existing $PANEL/.env kept"
fi
chown root:lpanel "$PANEL/.env"
chmod 0640 "$PANEL/.env"

# ---------------------------------------------------------------------------
step "Agent and root sign-in"

if [ ! -f /etc/lpanel/agent.key ]; then
    (cd "$PANEL" && $PHP laika agent:keygen --secret=/etc/lpanel/agent.key --public=/etc/lpa/panel.pub)
else
    note "existing agent signing key kept"
fi
chown lpanel:lpanel /etc/lpanel/agent.key
chmod 0600 /etc/lpanel/agent.key
chmod 0644 /etc/lpa/panel.pub
# agent:keygen booted the panel as root; whatever it wrote under system/ is the panel's.
chown -R lpanel:lpanel "$PANEL/system"

# `lpanel`: the panel's command line, for root. It runs as the panel's user,
# so it touches the system only through the agent, like the web pages do.
cat > /usr/local/sbin/lpanel <<WRAPPER
#!/bin/sh
# LPanel's command line (installed by install.sh). Try: lpanel help
[ "\$(id -u)" -eq 0 ] || { echo "lpanel: run it as root" >&2; exit 1; }
cd $PANEL && exec runuser -u lpanel -- $PHP $PANEL/laika "\$@"
WRAPPER
chmod 0750 /usr/local/sbin/lpanel

[ -f /etc/lpa/lpa.json ] || install -m 0644 "$BUNDLE/packaging/lpa.json" /etc/lpa/lpa.json
# Quotas as found above, on every run: an older lpa.json lacks the key, and a
# server that gained quotas since the last run should start using them.
LPA_QUOTA=on
[ "$QUOTA_ON" -eq 1 ] || LPA_QUOTA=off
$PHP -r '$f = "/etc/lpa/lpa.json"; $c = json_decode(file_get_contents($f), true) ?: [];
    $c["homes_base"] ??= "/home"; $c["quota"] = $argv[1]; $c["web"] ??= "apache";
    file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' "$LPA_QUOTA"
note "disk quotas for accounts: $LPA_QUOTA"
[ -f /etc/lpanel/authd.json ] || install -m 0644 "$BUNDLE/packaging/authd.json" /etc/lpanel/authd.json
install -m 0644 "$BUNDLE/packaging/pam.d-lpanel.el" /etc/pam.d/lpanel
note "root signs in with the server's own root password, checked by PAM (/etc/pam.d/lpanel)"

# ---------------------------------------------------------------------------
step "The panel's PHP-FPM and nginx"

install -m 0644 "$BUNDLE/packaging/lpanel-php-fpm.conf" /etc/lpanel/php-fpm.conf
install -d -m 0755 /etc/lpanel/nginx
install -m 0644 "$BUNDLE/packaging/lpanel-nginx.conf" /etc/lpanel/nginx/nginx.conf
install -m 0644 "$BUNDLE/packaging/php.conf" /etc/lpanel/nginx/php.conf
touch /var/log/lpanel/php-fpm.log /var/log/lpanel/php-error.log
chown lpanel:lpanel /var/log/lpanel/php-error.log

if [ ! -f /etc/lpanel/tls/panel.crt ]; then
    # Self-signed until the panel can issue its own certificate (M3).
    openssl req -x509 -newkey rsa:3072 -nodes -days 825 -subj "/CN=${HOST}" \
        -addext "subjectAltName=DNS:${HOST}" \
        -keyout /etc/lpanel/tls/panel.key -out /etc/lpanel/tls/panel.crt 2>/dev/null
    chmod 0600 /etc/lpanel/tls/panel.key
    note "self-signed certificate for ${HOST} (replace it once DNS points here)"
else
    note "existing certificate kept"
fi

# ---------------------------------------------------------------------------
step "Services"

for unit in lpa.service lpanel-authd.service lpanel-authd.socket lpanel-php-fpm.service \
            lpanel-nginx.service lpanel-scheduler.service lpanel-scheduler.timer; do
    install -m 0644 "$BUNDLE/packaging/$unit" "/etc/systemd/system/$unit"
done
systemctl daemon-reload

if command -v selinuxenabled >/dev/null 2>&1 && selinuxenabled; then
    # LPanel's SELinux settings (dist/selinux/setup.sh): the policy module,
    # the panel's ports, the labels of accounts' web content and the
    # booleans customers' sites need. Installed in permissive mode too, so
    # switching to enforcing later needs nothing more.
    bash "$BUNDLE/packaging/selinux-setup.sh" "$BUNDLE/packaging/lpanel.pp" "$PANEL" "$HOMES"
    restorecon -R "$PANEL" /etc/lpanel /var/log/lpanel /var/lib/lpanel /usr/local/lpa
    mkdir -p /etc/httpd/lpanel && restorecon -R /etc/httpd/lpanel
    for d in "$HOMES"/*/public_html "$HOMES"/*/tmp "$HOMES"/*/logs; do
        if [ -d "$d" ]; then restorecon -R "$d"; fi
    done
    for d in /run/lpa /run/lpanel /run/lpanel-nginx /run/lpanel-authd; do
        if [ -e "$d" ]; then restorecon -R "$d"; fi
    done
    note "SELinux: lpanel policy module and labels ($(getenforce))"
fi

if systemctl is-active --quiet firewalld; then
    firewall-cmd -q --permanent --add-port=7443/tcp --add-port=7444/tcp --add-service=http --add-service=https
    firewall-cmd -q --reload
    note "firewalld: 7443 and 7444 (the panel), 80 and 443 (sites) open"
fi

step "Database tables"
runuser -u lpanel -- $PHP "$PANEL/laika" migrate

systemctl enable -q --now lpa.service lpanel-authd.socket lpanel-php-fpm.service lpanel-nginx.service lpanel-scheduler.timer
# Restarted even when already running, so a rerun brings new binaries, units
# and SELinux domains into effect (authd's socket is made again with its label).
systemctl restart lpa.service lpanel-authd.socket lpanel-php-fpm.service lpanel-nginx.service
for _ in $(seq 50); do [ -S /run/lpa/lpa.sock ] && [ -S /run/lpanel/php-fpm.sock ] && break; sleep 0.2; done

# ---------------------------------------------------------------------------
step "Checking the installation"

ok=1
curl -ksf -o /dev/null https://127.0.0.1:7443/health && note "Admin panel answers on 7443" || { note "FAILED: nothing answers on 7443"; ok=0; }
curl -ksf -o /dev/null https://127.0.0.1:7444/health && note "User panel answers on 7444" || { note "FAILED: nothing answers on 7444"; ok=0; }
if runuser -u lpanel -- $PHP "$PANEL/laika" agent:ping >/dev/null 2>&1; then note "the panel reaches the agent"; else note "FAILED: the panel cannot reach the agent"; ok=0; fi
[ "$ok" -eq 1 ] || die "the installation finished but does not work yet; see journalctl -u lpa -u lpanel-php-fpm -u lpanel-nginx."

cat <<DONE

LPanel is installed.

    Admin panel:  https://${HOST}:7443   sign in as root with this server's root password
    User panel:   https://${HOST}:7444

The certificate is self-signed for now, so the browser will warn once.
DONE
