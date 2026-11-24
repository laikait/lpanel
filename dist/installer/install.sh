#!/usr/bin/env bash
# LPanel installer for AlmaLinux / Rocky Linux 9.
#
# Run as root from an unpacked bundle (dist/installer/bundle.sh makes one):
#     ./install.sh [--hostname panel.example.com] [--allow-no-quota]
#                  [--web apache|nginx|hybrid]
#
# --web chooses what serves customers' sites: Apache (the default), nginx,
# or nginx in front of Apache (hybrid). Run it again with another --web to
# switch; every site is then written for the new stack.
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
WEB_ARG=""

usage() { sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//'; }
step() { printf '\n==> %s\n' "$*"; }
note() { printf '    %s\n' "$*"; }
die() { printf '\nlpanel install: %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --hostname) HOSTNAME_ARG="${2:-}"; shift 2 ;;
        --allow-no-quota) ALLOW_NO_QUOTA=1; shift ;;
        --web) WEB_ARG="${2:-}"; shift 2 ;;
        --web=*) WEB_ARG="${1#--web=}"; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "unknown option $1 (see --help)" ;;
    esac
done

# ---------------------------------------------------------------------------
step "Checking this server"

[ "$(id -u)" -eq 0 ] || die "run the installer as root."
# -f, not -x: a bundle unpacked from a copy may have lost its execute bits;
# each binary is installed with its mode set (install -m 0755).
[ -d "$BUNDLE/panel" ] && [ -f "$BUNDLE/bin/lpa" ] && [ -f "$BUNDLE/bin/lpanel-authd" ] && [ -f "$BUNDLE/bin/lpanel-jail" ] \
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
step "Installing packages (EPEL, Remi's PHP, MariaDB, nginx for the panel, Apache for sites, PowerDNS, Exim, Dovecot, Rspamd, ClamAV, Pure-FTPd, bubblewrap and restic)"

rpm -q epel-release >/dev/null 2>&1 || dnf -y -q install epel-release
rpm -q remi-release >/dev/null 2>&1 || dnf -y -q install https://rpms.remirepo.net/enterprise/remi-release-9.rpm
# Rspamd is not in EL or EPEL; its own repository is signed with its key.
if [ ! -f /etc/yum.repos.d/rspamd.repo ]; then
    rpm --import https://rspamd.com/rpm/rspamd.asc
    cat > /etc/yum.repos.d/rspamd.repo <<'REPO'
[rspamd]
name=Rspamd stable repository
baseurl=https://rspamd.com/rpm-stable/centos-9/$basearch
enabled=1
gpgcheck=1
repo_gpgcheck=1
gpgkey=https://rspamd.com/rpm/rspamd.asc
REPO
fi
dnf -y -q install \
    php83-php-fpm php83-php-cli php83-php-sodium php83-php-intl php83-php-mbstring \
    php83-php-pdo php83-php-mysqlnd php83-php-process php83-php-opcache php83-php-xml \
    mariadb-server nginx httpd mod_ssl mod_security mod_security_crs openssl policycoreutils-python-utils procps-ng iproute logrotate \
    pdns pdns-backend-mysql bind-utils \
    exim dovecot dovecot-pigeonhole \
    rspamd clamd clamav-update \
    pure-ftpd \
    openssh-server openssh-clients bubblewrap rsync git-core \
    restic cronie dnf-plugins-core
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

# mod_ssl's own file listens on 443 with a default HTTPS site of its own.
# LPanel's include (lpanel.conf, written by the agent) does both when Apache
# serves HTTPS itself; behind nginx, nginx does. Emptied rather than removed,
# so a package update keeps it empty.
ssl_conf=/etc/httpd/conf.d/ssl.conf
if [ -f "$ssl_conf" ] && grep -q '^Listen' "$ssl_conf"; then
    printf '# Emptied by LPanel: Listen 443 and the default HTTPS site are in lpanel.conf.\n' > "$ssl_conf"
fi

# What serves customers' sites: --web, else what this server runs already.
WEB_BEFORE=$($PHP -r '$c = json_decode((string) @file_get_contents("/etc/lpa/lpa.json"), true); echo is_array($c) ? ($c["web"] ?? "") : "";' 2>/dev/null || true)
WEB="${WEB_ARG:-${WEB_BEFORE:-apache}}"
case "$WEB" in apache|nginx|hybrid) ;; *) die "--web must be apache, nginx or hybrid, not '$WEB'." ;; esac

# Each PHP version's FPM service is started by the agent once an account uses
# it, so it is left alone here: stopping it would take customers' sites down.
# The panel runs its own nginx (lpanel-nginx) whatever the stack.
httpd_listen() { sed -i -E "s/^Listen .*/Listen $1/" /etc/httpd/conf/httpd.conf; }
# Apache stops listening on 443 before nginx takes it: the agent rewrites
# Apache's include for the new stack only when the sites move (web:sync).
apache_off_443() { if [ -f /etc/httpd/conf.d/lpanel.conf ]; then sed -i '/^Listen 443/d' /etc/httpd/conf.d/lpanel.conf; fi; }
sites_nginx() {
    apache_off_443
    [ -f /etc/nginx/nginx.conf.lpanel-orig ] || cp -p /etc/nginx/nginx.conf /etc/nginx/nginx.conf.lpanel-orig
    install -m 0644 "$BUNDLE/packaging/nginx-sites.conf" /etc/nginx/nginx.conf
    install -d -m 0755 /etc/nginx/lpanel /etc/nginx/lpanel/sites /var/log/nginx/lpanel
}
case "$WEB" in
    apache)
        # Apache on :80 alone.
        systemctl disable --now nginx >/dev/null 2>&1 || true
        httpd_listen 80
        systemctl enable -q httpd && systemctl restart httpd
        ;;
    nginx)
        # nginx on :80 alone; Apache stays installed but off.
        systemctl disable --now httpd >/dev/null 2>&1 || true
        sites_nginx
        systemctl enable -q nginx && systemctl restart nginx
        ;;
    hybrid)
        # nginx on :80 in front, Apache behind it on 127.0.0.1:8080. nginx
        # serves static files itself, so it joins Apache's group, which may
        # read public_html.
        usermod -a -G apache nginx
        httpd_listen 127.0.0.1:8080
        sites_nginx
        systemctl enable -q httpd nginx
        systemctl stop nginx >/dev/null 2>&1 || true
        systemctl restart httpd
        systemctl start nginx
        ;;
esac
[ "$WEB" = hybrid ] || gpasswd -d nginx apache >/dev/null 2>&1 || true
note "sites are served by: $WEB${WEB_BEFORE:+ (was $WEB_BEFORE)}"

# ---------------------------------------------------------------------------
step "Users and directories"

getent group lpanel >/dev/null || groupadd --system lpanel
getent passwd lpanel >/dev/null || useradd --system --gid lpanel --home-dir "$PANEL" --no-create-home --shell /sbin/nologin lpanel
install -d -m 0755 -o root -g root "$PANEL" /usr/local/lpa /usr/local/lpa/bin /etc/lpa
install -d -m 0750 -o root -g lpanel /etc/lpanel
install -d -m 0750 -o lpanel -g lpanel /var/log/lpanel /var/lib/lpanel
install -d -m 0700 -o nginx -g nginx /var/lib/lpanel/nginx
# The file manager's uploads, saved files and downloads on their way to and
# from the agent, which opens them by name only (lpa.json's files_staging).
install -d -m 0700 -o lpanel -g lpanel /var/lib/lpanel/files
install -d -m 0700 -o root -g root /etc/lpanel/tls
# Sites' certificates and keys (the agent's store), and the folder every site
# answers certificate checks from (HTTP-01).
install -d -m 0700 -o root -g root /etc/pki/lpanel /etc/pki/lpanel/sites
install -d -m 0755 -o root -g root /var/www/lpanel-acme
# phpMyAdmin runs in a PHP-FPM pool of its own, as its own user: a flaw in
# it reaches neither the panel's code nor its secrets. Tickets (sign-on from
# the User panel) are written by the panel and read by phpMyAdmin only.
getent group lpanel-pma >/dev/null || groupadd --system lpanel-pma
getent passwd lpanel-pma >/dev/null || useradd --system --gid lpanel-pma --home-dir /var/lib/lpanel-pma --no-create-home --shell /sbin/nologin lpanel-pma
install -d -m 0755 -o root -g root /usr/local/lpanel-pma /var/lib/lpanel-pma
install -d -m 0700 -o lpanel-pma -g lpanel-pma /var/lib/lpanel-pma/sessions /var/lib/lpanel-pma/tmp /var/lib/lpanel-pma/log
install -d -m 2770 -o lpanel -g lpanel-pma /var/lib/lpanel-pma/tickets
# Roundcube the same way, as lpanel-webmail; the panel writes its sign-on
# tickets.
getent group lpanel-webmail >/dev/null || groupadd --system lpanel-webmail
getent passwd lpanel-webmail >/dev/null || useradd --system --gid lpanel-webmail --home-dir /var/lib/lpanel-webmail --no-create-home --shell /sbin/nologin lpanel-webmail
install -d -m 0755 -o root -g root /usr/local/lpanel-webmail /var/lib/lpanel-webmail
install -d -m 0700 -o lpanel-webmail -g lpanel-webmail /var/lib/lpanel-webmail/sessions /var/lib/lpanel-webmail/temp /var/lib/lpanel-webmail/logs
install -d -m 2770 -o lpanel -g lpanel-webmail /var/lib/lpanel-webmail/tickets
# Accounts' Apache logs daily, the panel's own weekly (see the file).
install -m 0644 "$BUNDLE/packaging/logrotate-lpanel" /etc/logrotate.d/lpanel
note "lpanel user: uid $(id -u lpanel)"

# ---------------------------------------------------------------------------
step "Copying the panel to $PANEL"

# Code is root's and read-only to the panel; only system/ (cache, logs,
# sessions, runtime) is the panel's to write. .env and system/ survive upgrades.
# The old code goes first, so a file a release no longer has (a module, a
# migration) is not left behind; compiled templates go with it.
find "$PANEL" -mindepth 1 -maxdepth 1 ! -name system ! -name .env -exec rm -rf {} +
rm -rf "$PANEL/system/Cache/"*
tar -C "$BUNDLE/panel" --exclude=./.env --exclude=./system -cf - . | tar -C "$PANEL" --no-same-owner -xf -
# Which release this is: the Admin panel's Updates page and the agent read it.
install -m 0644 "$BUNDLE/VERSION" "$PANEL/VERSION"
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
    $c["homes_base"] ??= "/home"; $c["quota"] = $argv[1]; $c["web"] = $argv[3]; $c["panel_php"] = $argv[2];
    file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' "$LPA_QUOTA" "${PHP##*php}" "$WEB"
note "disk quotas for accounts: $LPA_QUOTA"
# LPanel's own updates (Admin panel, Updates): the channel a release build
# names (UPDATE_URL in the bundle) and the keys its releases are signed with.
# A channel set by hand in lpa.json is kept.
if [ -f "$BUNDLE/packaging/release.pub" ]; then
    install -m 0644 "$BUNDLE/packaging/release.pub" /etc/lpa/release.pub
fi
UPDATE_URL=$(tr -d '[:space:]' < "$BUNDLE/UPDATE_URL" 2>/dev/null || true)
$PHP -r '$f = "/etc/lpa/lpa.json"; $c = json_decode(file_get_contents($f), true) ?: [];
    $u = is_array($c["updates"] ?? null) ? $c["updates"] : [];
    if (($u["url"] ?? "") === "" && $argv[1] !== "") { $u["url"] = $argv[1]; }
    $u["url"] ??= ""; $u["key_file"] ??= "/etc/lpa/release.pub"; $c["updates"] = $u;
    file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' "$UPDATE_URL"
install -d -m 0700 -o root -g root /var/lib/lpa/updates
if [ -n "$($PHP -r '$c = json_decode(file_get_contents("/etc/lpa/lpa.json"), true); echo $c["updates"]["url"] ?? "";')" ]; then
    note "LPanel's updates come from $($PHP -r '$c = json_decode(file_get_contents("/etc/lpa/lpa.json"), true); echo $c["updates"]["url"];')"
else
    note "no update channel: LPanel itself is upgraded by running a new bundle's upgrade.sh"
fi
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
    # Self-signed until AutoSSL gets the hostname one (Admin panel → SSL/TLS).
    openssl req -x509 -newkey rsa:3072 -nodes -days 825 -subj "/CN=${HOST}" \
        -addext "subjectAltName=DNS:${HOST}" \
        -keyout /etc/lpanel/tls/panel.key -out /etc/lpanel/tls/panel.crt 2>/dev/null
    chmod 0600 /etc/lpanel/tls/panel.key
    note "self-signed certificate for ${HOST} (AutoSSL replaces it once DNS points here)"
else
    note "existing certificate kept"
fi

# ---------------------------------------------------------------------------
step "phpMyAdmin $(cat "$BUNDLE/PHPMYADMIN_VERSION")"

# Unpacked beside the old copy, then swapped in. The config's secret is kept
# across upgrades (it encrypts phpMyAdmin's own cookies).
pma=/usr/local/lpanel-pma/phpmyadmin
rm -rf "$pma.new" && mkdir -p "$pma.new"
tar -C "$pma.new" --strip-components=1 --no-same-owner -xzf "$BUNDLE/phpmyadmin.tar.gz"
rm -rf "$pma.new/setup" "$pma.new/examples" "$pma.new/test" "$pma.new/doc/html/_sources"
secret=""
if [ -f "$pma/config.inc.php" ]; then
    secret=$(grep -o "sodium_hex2bin('[0-9a-f]\{64\}')" "$pma/config.inc.php" | grep -o '[0-9a-f]\{64\}' || true)
fi
[ -n "$secret" ] || secret=$(openssl rand -hex 32)
sed "s/@BLOWFISH@/$secret/" "$BUNDLE/packaging/pma-config.inc.php" > "$pma.new/config.inc.php"
install -m 0644 "$BUNDLE/packaging/pma-signon.php" "$pma.new/lpanel-signon.php"
chown -R root:root "$pma.new"
find "$pma.new" -type d -exec chmod 0755 {} + -o -type f -exec chmod 0644 {} +
chown root:lpanel-pma "$pma.new/config.inc.php" && chmod 0640 "$pma.new/config.inc.php"
rm -rf "$pma.old"
[ -d "$pma" ] && mv "$pma" "$pma.old"
mv "$pma.new" "$pma" && rm -rf "$pma.old"
note "served on the User panel's port at /phpmyadmin/, signed in to from the panel only"

# ---------------------------------------------------------------------------
step "DNS (PowerDNS)"

# Zones live in their own database. The HTTP API listens on this machine
# only, and its key is root's alone: the agent writes every zone, the panel
# never reaches PowerDNS itself (ADR-0007).
PDNS_DB_PASSWORD=$(sed -n 's/^gmysql-password=//p' /etc/pdns/pdns.conf 2>/dev/null || true)
[ -n "$PDNS_DB_PASSWORD" ] || PDNS_DB_PASSWORD=$(openssl rand -hex 24)
if ! mariadb -uroot -N -e "select 1 from information_schema.tables where table_schema = 'pdns' and table_name = 'domains'" | grep -q 1; then
    mariadb -uroot <<SQL
CREATE DATABASE IF NOT EXISTS pdns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'pdns'@'localhost' IDENTIFIED BY '${PDNS_DB_PASSWORD}';
ALTER USER 'pdns'@'localhost' IDENTIFIED BY '${PDNS_DB_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER, LOCK TABLES ON pdns.* TO 'pdns'@'localhost';
SQL
    # The base schema, not one of the upgrade scripts beside it.
    schema=$(rpm -ql pdns-backend-mysql | grep -m1 '/schema\.mysql\.sql$')
    mariadb -uroot pdns < "$schema"
    note "database pdns made from $(basename "$schema")"
else
    mariadb -uroot -e "ALTER USER 'pdns'@'localhost' IDENTIFIED BY '${PDNS_DB_PASSWORD}'"
fi
PDNS_KEY=$(cat /etc/lpa/pdns.key 2>/dev/null || true)
[ -n "$PDNS_KEY" ] || PDNS_KEY=$(openssl rand -hex 32)
umask 077
printf '%s\n' "$PDNS_KEY" > /etc/lpa/pdns.key
umask 022
cat > /etc/pdns/pdns.conf <<CONF
# Written by the LPanel installer; changed only by rerunning it.
setuid=pdns
setgid=pdns
launch=gmysql
gmysql-socket=/var/lib/mysql/mysql.sock
gmysql-dbname=pdns
gmysql-user=pdns
gmysql-password=${PDNS_DB_PASSWORD}
gmysql-dnssec=yes
local-address=0.0.0.0, ::
local-address-nonexist-fail=no
default-ttl=3600
# The API, for the agent only.
api=yes
api-key=${PDNS_KEY}
webserver=yes
webserver-address=127.0.0.1
webserver-port=8081
webserver-allow-from=127.0.0.1,::1
CONF
chown root:pdns /etc/pdns/pdns.conf
chmod 0640 /etc/pdns/pdns.conf
systemctl enable -q pdns && systemctl restart pdns
$PHP -r '$f = "/etc/lpa/lpa.json"; $c = json_decode(file_get_contents($f), true) ?: [];
    $c["dns"] = ["api" => "http://127.0.0.1:8081", "key_file" => "/etc/lpa/pdns.key"] + ($c["dns"] ?? []);
    file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
note "$(pdns_server --version 2>&1 | grep -o 'PowerDNS Authoritative Server [0-9.]*' | head -1), answering on port 53"

# ---------------------------------------------------------------------------
step "Mail (Exim and Dovecot)"
# Exim takes port 25 and the sendmail command; another mail server would
# hold them.
for mta in postfix sendmail; do
    if systemctl is-enabled --quiet "$mta" 2>/dev/null || systemctl is-active --quiet "$mta" 2>/dev/null; then
        systemctl disable -q --now "$mta" && note "$mta stopped: Exim receives and sends mail now"
    fi
done
alternatives --set mta /usr/sbin/sendmail.exim >/dev/null 2>&1 || true
# The servers' own configurations, whole; accounts' mail is data the agent
# writes into the folders below (design/ARCHITECTURE.md, "Mail").
install -m 0644 "$BUNDLE/packaging/exim.conf" /etc/exim/exim.conf
sed "s/LPANEL_HOSTNAME/$HOST/" "$BUNDLE/packaging/dovecot.conf" > /etc/dovecot/dovecot.conf
chmod 0644 /etc/dovecot/dovecot.conf
install -d -m 0750 -o root -g exim /etc/exim/lpanel /etc/exim/lpanel/owners /etc/exim/lpanel/local \
    /etc/exim/lpanel/mailboxes /etc/exim/lpanel/forwards /etc/exim/lpanel/dkim /etc/exim/lpanel/accounts
# The key forwarded mail's senders are rewritten with (SRS); kept when the
# installer runs again, so bounces to addresses made earlier still decode.
if [ ! -s /etc/exim/lpanel/srs_secret ]; then
    (umask 027; head -c 32 /dev/urandom | base64 > /etc/exim/lpanel/srs_secret)
fi
chown root:exim /etc/exim/lpanel/srs_secret && chmod 0640 /etc/exim/lpanel/srs_secret
install -d -m 0750 -o root -g dovecot /etc/dovecot/lpanel /etc/dovecot/lpanel/passwd.d /etc/dovecot/lpanel/sso.d
install -d -m 0700 -o root -g root /etc/dovecot/lpanel/sso.keep
install -d -m 0755 -o root -g root /etc/dovecot/lpanel/sieve
# Webmail's sign-on passwords: Dovecot asks this file of every domain after
# the mailbox's own, so each needs one (the agent keeps them from now on).
for f in /etc/dovecot/lpanel/passwd.d/*; do
    [ -f "$f" ] || continue
    s=/etc/dovecot/lpanel/sso.d/$(basename "$f")
    [ -e "$s" ] || install -m 0640 -o root -g dovecot /dev/null "$s"
done
[ -e /etc/dovecot/lpanel/sni.conf ] || install -m 0640 -o root -g dovecot /dev/null /etc/dovecot/lpanel/sni.conf
# The hostname certificate, readable by Exim's group (Exim reads it as its
# own user; /etc/pki/lpanel itself is root's alone). The agent copies it
# again whenever it changes.
install -d -m 0750 -o root -g exim /etc/pki/lpanel-mail /etc/pki/lpanel-mail/sni
install -m 0640 -o root -g exim /etc/lpanel/tls/panel.crt /etc/pki/lpanel-mail/host.crt
install -m 0640 -o root -g exim /etc/lpanel/tls/panel.key /etc/pki/lpanel-mail/host.key
exim -bV >/dev/null || die "Exim refused its configuration (exim -bV)"
doveconf -n >/dev/null || die "Dovecot refused its configuration (doveconf -n)"
$PHP -r '$f = "/etc/lpa/lpa.json"; $c = json_decode(file_get_contents($f), true) ?: [];
    $c["mail"] = "on";
    file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
systemctl enable -q exim dovecot && systemctl restart exim dovecot
note "$(exim -bV | head -1 | awk '{print "Exim", $3}'), Dovecot $(dovecot --version | awk '{print $1}'): SMTP 25, 465, 587; IMAP 993, 143; POP3 995, 110"

# Spam filtering: Rspamd, asked by Exim on 127.0.0.1:11333. Its own
# settings are the installer's (packaging/rspamd); the agent writes the
# rejection score and ClamAV's, and turns both on and off (Admin panel,
# Email). Rspamd's unit runs it as _rspamd, which its package does not make.
if ! id _rspamd >/dev/null 2>&1; then
    useradd -r -d /var/lib/rspamd -s /sbin/nologin -c "Rspamd" _rspamd
fi
install -d -m 0750 -o _rspamd -g _rspamd /var/lib/rspamd /var/log/rspamd
chown -R _rspamd:_rspamd /var/lib/rspamd /var/log/rspamd
install -m 0644 "$BUNDLE"/packaging/rspamd/* /etc/rspamd/local.d/
rspamadm configtest >/dev/null || die "Rspamd refused its configuration (rspamadm configtest)"
# ClamAV (off until the administrator turns it on: it needs about 1.5 GB of
# memory): clamd listens on a socket only its group opens, and Rspamd joins
# that group. clamd waits for freshclam's first download by restarting.
sed -i -e '0,/^#LocalSocket \/run\/clamd.scan\/clamd.sock/s//LocalSocket \/run\/clamd.scan\/clamd.sock/' \
    -e 's/^#LocalSocketGroup virusgroup/LocalSocketGroup virusgroup/' -e 's/^#LocalSocketMode 660/LocalSocketMode 660/' /etc/clamd.d/scan.conf
usermod -aG virusgroup _rspamd
install -d -m 0755 /etc/systemd/system/clamd@scan.service.d
cat > /etc/systemd/system/clamd@scan.service.d/lpanel.conf <<'UNIT'
# LPanel: clamd cannot start before freshclam has its first signatures, so
# it tries again every minute until it can.
[Unit]
StartLimitIntervalSec=0
[Service]
Restart=always
RestartSec=60
UNIT
systemctl daemon-reload
systemctl try-restart rspamd >/dev/null 2>&1 || true
note "Rspamd $(rspamd --version 2>/dev/null | grep -oE '[0-9]+.[0-9]+.[0-9]+' | head -1), ClamAV $(clamd --version 2>/dev/null | awk '{print $2}' | cut -d/ -f1): installed"

# ---------------------------------------------------------------------------
step "Webmail (Roundcube $(cat "$BUNDLE/ROUNDCUBE_VERSION"))"

# Unpacked beside the old copy, then swapped in; its key and database
# password are kept across upgrades (the key encrypts its sessions' copies of
# passwords).
rc=/usr/local/lpanel-webmail/roundcube
rm -rf "$rc.new" && mkdir -p "$rc.new"
tar -C "$rc.new" --strip-components=1 --no-same-owner -xzf "$BUNDLE/roundcube.tar.gz"
rm -rf "$rc.new/installer" "$rc.new/public_html/installer.php"
deskey="" dbpass=""
if [ -f "$rc/config/config.inc.php" ]; then
    deskey=$(grep -o "^\$config\['des_key'\] = '[0-9a-f]\{24\}';" "$rc/config/config.inc.php" | grep -o '[0-9a-f]\{24\}' || true)
    dbpass=$(grep -o "mysql://lpanel_webmail:[0-9a-f]\{48\}@" "$rc/config/config.inc.php" | grep -o '[0-9a-f]\{48\}' || true)
fi
[ -n "$deskey" ] || deskey=$(openssl rand -hex 12)
[ -n "$dbpass" ] || dbpass=$(openssl rand -hex 24)
mariadb -uroot <<SQL
CREATE DATABASE IF NOT EXISTS lpanel_webmail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'lpanel_webmail'@'localhost' IDENTIFIED BY '$dbpass';
ALTER USER 'lpanel_webmail'@'localhost' IDENTIFIED BY '$dbpass';
GRANT ALL PRIVILEGES ON lpanel_webmail.* TO 'lpanel_webmail'@'localhost';
SQL
sed -e "s/@DESKEY@/$deskey/" -e "s/@DBPASS@/$dbpass/" "$BUNDLE/packaging/roundcube-config.inc.php" > "$rc.new/config/config.inc.php"
rm -rf "$rc.new/plugins/lpanel_sso" && cp -r "$BUNDLE/packaging/roundcube-sso" "$rc.new/plugins/lpanel_sso"
chown -R root:root "$rc.new"
find "$rc.new" -type d -exec chmod 0755 {} + -o -type f -exec chmod 0644 {} +
chmod 0755 "$rc.new"/bin/*.sh
chown root:lpanel-webmail "$rc.new/config/config.inc.php" && chmod 0640 "$rc.new/config/config.inc.php"
rm -rf "$rc.old"
[ -d "$rc" ] && mv "$rc" "$rc.old"
mv "$rc.new" "$rc" && rm -rf "$rc.old"
# Its tables: made the first time, brought up to date afterwards.
if [ -z "$(mariadb -uroot -N lpanel_webmail -e "show tables like 'system'")" ]; then
    mariadb -uroot lpanel_webmail < "$rc/SQL/mysql.initial.sql"
else
    runuser -u lpanel-webmail -- $PHP "$rc/bin/updatedb.sh" --package=roundcube --dir="$rc/SQL" >/dev/null
fi
note "served on the User panel's port at /webmail/ (mailbox users sign in with their address)"

# ---------------------------------------------------------------------------
step "FTP (Pure-FTPd)"
# Port 21 is Pure-FTPd's; another FTP server would hold it.
for ftpd in vsftpd proftpd; do
    if systemctl is-enabled --quiet "$ftpd" 2>/dev/null || systemctl is-active --quiet "$ftpd" 2>/dev/null; then
        systemctl disable -q --now "$ftpd" && note "$ftpd stopped: Pure-FTPd serves FTP now"
    fi
done
# Accounts' FTP logins are data the agent writes here (users.d/<account>,
# joined and indexed for Pure-FTPd); pure-ftpd.conf is the agent's too, so
# it is written once the agent runs (ftp:settings --apply, below).
install -d -m 0700 -o root -g root /etc/pure-ftpd/lpanel /etc/pure-ftpd/lpanel/users.d /etc/pki/pure-ftpd
$PHP -r '$f = "/etc/lpa/lpa.json"; $c = json_decode(file_get_contents($f), true) ?: [];
    $c["ftp"] = "on";
    file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
systemctl enable -q pure-ftpd
note "Pure-FTPd $(rpm -q --qf '%{VERSION}' pure-ftpd): port 21 and passive ports 30000-30100, logins over TLS"

# ---------------------------------------------------------------------------
step "SSH and SFTP (jailed)"
# An account with SSH or SFTP logs in to lpanel-jail, which runs the shell,
# the command or the SFTP server inside the account's own sandbox
# (bubblewrap): its home, read-only /usr, no other account. Installed under
# two names; jail-sftp allows SFTP only.
install -d -m 0755 -o root -g root /usr/libexec/lpanel
install -m 0755 "$BUNDLE/bin/lpanel-jail" /usr/libexec/lpanel/jail-shell
install -m 0755 "$BUNDLE/bin/lpanel-jail" /usr/libexec/lpanel/jail-sftp
for sh in /usr/libexec/lpanel/jail-shell /usr/libexec/lpanel/jail-sftp; do
    grep -qxF "$sh" /etc/shells || echo "$sh" >> /etc/shells
done
# Every account is in lpanel-users (the agent adds new ones); sshd's drop-in
# gives the group its rules. Accounts made before this release join here.
getent group lpanel-users >/dev/null || groupadd --system lpanel-users
getent passwd | awk -F: '$5 == "lpanel account" && $3 >= 1000 { print $1 }' | while read -r u; do
    id -nG "$u" | tr ' ' '\n' | grep -qx lpanel-users || usermod --append --groups lpanel-users "$u"
done
# Their keys are the agent's to write; sshd reads them as the account.
install -d -m 0711 -o root -g root /etc/ssh/lpanel /etc/ssh/lpanel/keys
# The SFTP server must be the program, not sshd's built-in one: the built-in
# one would not go through the account's shell, and so not into its jail.
if grep -Eq '^[[:space:]]*Subsystem[[:space:]]+sftp[[:space:]]+internal-sftp' /etc/ssh/sshd_config; then
    sed -i -E 's|^([[:space:]]*Subsystem[[:space:]]+sftp[[:space:]]+)internal-sftp.*|\1/usr/libexec/openssh/sftp-server|' /etc/ssh/sshd_config
    note "sshd: SFTP now goes through the account's shell (sftp-server instead of internal-sftp)"
fi
if ! grep -Eq '^[[:space:]]*Include[[:space:]]+/etc/ssh/sshd_config\.d/\*\.conf' /etc/ssh/sshd_config; then
    note "WARNING: /etc/ssh/sshd_config does not include sshd_config.d; accounts' SSH rules are not in effect"
fi
cat > /etc/ssh/sshd_config.d/40-lpanel.conf <<'SSHD'
# LPanel's hosting accounts (written by the installer; changes are overwritten).
# Keys come from the panel only, never from the account's own ~/.ssh;
# passwords are never accepted, and nothing is forwarded.
Match Group lpanel-users
    AuthorizedKeysFile /etc/ssh/lpanel/keys/%u
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    PermitUserRC no
    AllowTcpForwarding no
    AllowStreamLocalForwarding no
    AllowAgentForwarding no
    X11Forwarding no
    PermitTunnel no
    PermitTTY yes
SSHD
chmod 0600 /etc/ssh/sshd_config.d/40-lpanel.conf
# A configuration sshd refuses would lock everyone out at its next restart.
# (sshd -t needs host keys; a fresh machine makes them at first start.)
[ -e /etc/ssh/ssh_host_ed25519_key ] || ssh-keygen -A
if /usr/sbin/sshd -t 2>/tmp/lpanel-sshd-test; then
    systemctl enable -q sshd
    if systemctl is-active --quiet sshd; then systemctl reload sshd; else systemctl start sshd; fi
    note "sshd: accounts sign in with keys from the panel, into their jail (bubblewrap $(rpm -q --qf '%{VERSION}' bubblewrap))"
else
    rm -f /etc/ssh/sshd_config.d/40-lpanel.conf
    note "FAILED: sshd refused the configuration ($(head -1 /tmp/lpanel-sshd-test)); accounts cannot use SSH until it is fixed"
fi
rm -f /tmp/lpanel-sshd-test

# ---------------------------------------------------------------------------
step "Backups (restic)"
# The agent's: destinations with their credentials and each repository's
# password (root's alone), the stage where an account's database dumps wait
# while it is backed up, and restic's cache. Destinations are set up in the
# Admin panel, Backups.
install -d -m 0700 -o root -g root /etc/lpa/backup /var/lib/lpa/backup /var/cache/lpa /var/cache/lpa/restic
note "restic $(restic version 2>/dev/null | awk '{print $2}'): add a destination in the Admin panel, Backups"

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
    restorecon -R "$PANEL" /etc/lpanel /var/log/lpanel /var/lib/lpanel /usr/local/lpa /etc/pki/lpanel /var/www/lpanel-acme         /usr/local/lpanel-pma /var/lib/lpanel-pma /usr/local/lpanel-webmail /var/lib/lpanel-webmail /etc/exim /etc/dovecot /etc/pki/lpanel-mail /etc/pure-ftpd /etc/pki/pure-ftpd /etc/ssh/lpanel /usr/libexec/lpanel
    install -d -m 0755 /etc/httpd/lpanel && restorecon -R /etc/httpd/lpanel
    for d in /etc/nginx/lpanel /var/log/nginx/lpanel /etc/nginx/nginx.conf; do
        if [ -e "$d" ]; then restorecon -R "$d"; fi
    done
    for d in "$HOMES"/*/public_html "$HOMES"/*/tmp "$HOMES"/*/logs "$HOMES"/*/mail; do
        if [ -d "$d" ]; then restorecon -R "$d"; fi
    done
    for d in /run/lpa /run/lpanel /run/lpanel-nginx /run/lpanel-authd; do
        if [ -e "$d" ]; then restorecon -R "$d"; fi
    done
    note "SELinux: lpanel policy module and labels ($(getenforce))"
fi

if systemctl is-active --quiet firewalld; then
    # Services and ports in separate calls: firewall-cmd refuses both in one.
    firewall-cmd -q --permanent --add-service=http --add-service=https --add-service=dns \
        --add-service=smtp --add-service=smtps --add-service=smtp-submission \
        --add-service=imap --add-service=imaps --add-service=pop3 --add-service=pop3s \
        --add-service=ftp --add-service=ssh
    firewall-cmd -q --permanent --add-port=7443/tcp --add-port=7444/tcp --add-port=30000-30100/tcp
    firewall-cmd -q --reload
    # The Admin panel's firewall manager works through firewalld.
    $PHP -r '$f = "/etc/lpa/lpa.json"; $c = json_decode(file_get_contents($f), true) ?: [];
        $c["firewall"] = "on";
        file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
    note "firewalld:7443 and 7444 (the panel), 80 and 443 (sites), 53 (DNS), mail (25, 465, 587, 143, 993, 110, 995), FTP (21, 30000-30100) and SSH (22) open"
fi

step "Database tables"
runuser -u lpanel -- $PHP "$PANEL/laika" migrate

# Cron runs the accounts' scheduled jobs (/etc/cron.d/lpanel-<account>, written by the agent).
systemctl enable -q --now crond
systemctl enable -q --now lpa.service lpanel-authd.socket lpanel-php-fpm.service lpanel-nginx.service lpanel-scheduler.timer
# Restarted even when already running, so a rerun brings new binaries, units
# and SELinux domains into effect (authd's socket is made again with its label).
systemctl restart lpa.service lpanel-authd.socket lpanel-php-fpm.service lpanel-nginx.service
systemctl try-restart lpanel-authd.service   # a running one keeps its old domain otherwise
for _ in $(seq 50); do [ -S /run/lpa/lpa.sock ] && [ -S /run/lpanel/php-fpm.sock ] && break; sleep 0.2; done

# The hostname AutoSSL gets the panel's own certificate for: --hostname, or
# this server's name the first time.
if [ -n "$HOSTNAME_ARG" ] || runuser -u lpanel -- $PHP "$PANEL/laika" ssl:settings 2>/dev/null | grep -q '^hostname  *-'; then
    case "$HOST" in *.*) runuser -u lpanel -- $PHP "$PANEL/laika" ssl:settings --hostname "$HOST" >/dev/null && note "AutoSSL looks after the certificate of $HOST" ;; esac
fi

# DNS: the address new zones point at (this server's first one, until the
# Admin panel says otherwise), then a zone for every account that has none
# (accounts made before DNS came).
if runuser -u lpanel -- $PHP "$PANEL/laika" dns:settings 2>/dev/null | grep -q 'IPv4 address *(not set)'; then
    IP4=$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -m1 -E '^[0-9]+(\.[0-9]+){3}$' || true)
    [ -n "$IP4" ] && runuser -u lpanel -- $PHP "$PANEL/laika" dns:settings --ip "$IP4" >/dev/null && note "new zones point at $IP4"
fi
runuser -u lpanel -- $PHP "$PANEL/laika" dns:sync >/dev/null || note "some zones are not on the DNS server yet; dns:settle retries them every minute"
# Mail: every account's domains (accounts made before mail came), their
# DKIM keys and DNS records.
runuser -u lpanel -- $PHP "$PANEL/laika" mail:sync --all >/dev/null || note "some accounts' mail is not on the server yet; mail:settle retries it every minute"
# FTP: pure-ftpd.conf as the panel's settings say (the defaults the first
# time), the hostname certificate, then every account's logins again.
runuser -u lpanel -- $PHP "$PANEL/laika" ftp:settings --apply >/dev/null && note "Pure-FTPd runs with LPanel's settings" \
    || note "FAILED: Pure-FTPd did not start; see Admin panel, FTP, and journalctl -u pure-ftpd"
runuser -u lpanel -- $PHP "$PANEL/laika" ftp:sync --all >/dev/null || note "some accounts' FTP logins are not on the server yet; ftp:settle retries them every minute"
# Account isolation (M9d): other people's processes hidden in /proc and the
# kernel's link protections, on unless the Admin panel turned them off. A shell
# outside the SSH jail keeps its temporary files in the account's own folder.
cat > /etc/profile.d/lpanel-tmp.sh <<'PROFILE'
# LPanel: temporary files of an account's shell go in the account's own folder.
if [ "$(id -u)" -ge 1000 ] && [ -d "$HOME/tmp" ] && [ -w "$HOME/tmp" ]; then export TMPDIR="$HOME/tmp"; fi
PROFILE
runuser -u lpanel -- $PHP "$PANEL/laika" isolation:sync >/dev/null && note "account isolation: other users' processes hidden, links protected" \
    || note "account isolation is not in force; see Admin panel, Security, Account isolation"
# SSH: every account's keys, then each account's shell as its package says
# (accounts made before M7c had the server's own shell; now the jail).
runuser -u lpanel -- $PHP "$PANEL/laika" ssh:sync --all >/dev/null || note "some accounts' SSH keys are not on the server yet; ssh:settle retries them every minute"
runuser -u lpanel -- $PHP "$PANEL/laika" accounts:reapply >/dev/null || note "some accounts did not get their package's shell yet; accounts:settle retries them every minute"
# Sites whose state changed with this release (such as mail clients' setup
# and webmail names) are written again; the rest are left alone.
runuser -u lpanel -- $PHP "$PANEL/laika" web:sync --all --changed >/dev/null || note "some sites are not up to date yet; web:settle retries them every minute"
# Spam filtering is on from the first installation; afterwards it stays as
# the administrator left it.
if [ -z "$(mariadb -uroot -N lpanel -e "select value from mail_settings where name = 'spam'" 2>/dev/null)" ]; then
    runuser -u lpanel -- $PHP "$PANEL/laika" mail:filtering --spam=on >/dev/null && note "spam filtering is on (Rspamd)" \
        || note "spam filtering could not be turned on; see Admin panel, Email"
fi

# After a switch of stack, every account's sites are written for the new one
# (the agent also removes the old stack's files).
if [ -n "$WEB_BEFORE" ] && [ "$WEB_BEFORE" != "$WEB" ]; then
    runuser -u lpanel -- $PHP "$PANEL/laika" web:sync --all && note "every site moved from $WEB_BEFORE to $WEB" \
        || note "some sites did not move yet; web:settle retries them every minute"
fi

# ---------------------------------------------------------------------------
step "Checking the installation"

ok=1
curl -ksf -o /dev/null https://127.0.0.1:7443/health && note "Admin panel answers on 7443" || { note "FAILED: nothing answers on 7443"; ok=0; }
curl -ksf -o /dev/null https://127.0.0.1:7444/health && note "User panel answers on 7444" || { note "FAILED: nothing answers on 7444"; ok=0; }
if runuser -u lpanel -- $PHP "$PANEL/laika" agent:ping >/dev/null 2>&1; then note "the panel reaches the agent"; else note "FAILED: the panel cannot reach the agent"; ok=0; fi
if command -v selinuxenabled >/dev/null 2>&1 && selinuxenabled; then
    # A program left in init_t (systemd's own domain) answers, but is refused
    # much of its work: certificates, sites, Let's Encrypt, root's password.
    # authd starts on its first sign-in, so it is checked only when it runs.
    ctx() { local pid; pid=$(systemctl show -p MainPID --value "$1"); [ "${pid:-0}" != 0 ] && ps -o label= -p "$pid" 2>/dev/null; }
    lpa_ctx=$(ctx lpa.service || true)
    authd_ctx=$(ctx lpanel-authd.service || true)
    case "$lpa_ctx" in *:lpanel_agent_t:*) note "the agent runs in its SELinux domain" ;; *) note "FAILED: the agent runs as ${lpa_ctx:-nothing}, not lpanel_agent_t"; ok=0 ;; esac
    case "$authd_ctx" in *:lpanel_authd_t:*|"") ;; *) note "FAILED: the root password check runs as $authd_ctx, not lpanel_authd_t"; ok=0 ;; esac
fi
[ "$ok" -eq 1 ] || die "the installation finished but does not work yet; see journalctl -u lpa -u lpanel-php-fpm -u lpanel-nginx."

cat <<DONE

LPanel is installed.

    Admin panel:  https://${HOST}:7443   sign in as root with this server's root password
    User panel:   https://${HOST}:7444

The certificate is self-signed for now, so the browser will warn once.
DONE
