#!/usr/bin/env bash
# Runs inside one systemd container. Arguments: OLD_VERSION NEW_VERSION LPA_VERSION.
set -uo pipefail
OLD=$1
NEW=$2
LPA=$3
FAILED=0
pass() { echo "  PASS  $*"; }
fail() { echo "  FAIL  $*"; FAILED=1; }
# check <what> <command...>: on failure, the end of what the command printed.
check() {
	local what=$1
	shift
	if "$@" >/opt/pkgtest/check.log 2>&1; then
		pass "$what"
	else
		fail "$what"
		tail -15 /opt/pkgtest/check.log | sed 's/^/          | /'
	fi
}
is() { [ "$2" = "$3" ] && pass "$1" || fail "$1 (got '$2', want '$3')"; }
mode_of() { stat -c '%a %U:%G' "$1" 2>/dev/null; }
quiet() { "$@"; }

URL=https://127.0.0.1:8443
AGENTS=https://127.0.0.1:8444
code() { curl -sk -o /dev/null -w '%{http_code}' "$@"; }
# Of the panel's own config only: everything else under these must not change.
snapshot() { find /etc/nginx /etc/php /etc/mysql -type f -exec sha256sum {} + 2>/dev/null | sort; }
db() { mariadb -N -B lpanel -e "$1"; }

systemctl is-system-running --wait >/dev/null 2>&1 || true   # "degraded" is fine in a container
export DEBIAN_FRONTEND=noninteractive

echo "-- distro facts (ADR-0004)"
. /etc/os-release && echo "  os: $PRETTY_NAME"
apt-get update -qq >/dev/null 2>&1

echo "-- what a hosting server already has: the distro's Nginx, PHP-FPM and MariaDB"
quiet apt-get install -y -qq --no-install-recommends nginx php-fpm php-cli php-mysql php-intl php-mbstring mariadb-server mariadb-client
PHPV=$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')
echo "  php $PHPV, $(nginx -v 2>&1 | sed 's/.*: //'), $(mariadb --version | sed 's/.*Distrib //;s/,.*//;s/ from .*//')"
systemctl enable --now nginx "php$PHPV-fpm" mariadb >/dev/null 2>&1
BEFORE=$(snapshot)

echo "-- install $OLD"
check "apt installs the package" quiet apt-get install -y -qq "/opt/pkgtest/lpanel_${OLD}_amd64.deb"
for u in lpanel-php-fpm.service lpanel-nginx.service lpanel-authd.socket lpanel-scheduler.timer mariadb.service; do
	check "$u is active and enabled" bash -c "systemctl is-active --quiet $u && systemctl is-enabled --quiet $u"
done
check "the units pass systemd-analyze verify" systemd-analyze verify /usr/lib/systemd/system/lpanel-*.service /usr/lib/systemd/system/lpanel-scheduler.timer
is "/opt/lpanel/VERSION" "$(cat /opt/lpanel/VERSION)" "$OLD"
is "the panel's PHP is the distro's default" "$(readlink /usr/lib/lpanel/php-fpm)" "/usr/sbin/php-fpm$PHPV"

echo "-- the two listeners (ADR-0012)"
check "8443 serves the sign-in page" bash -c "curl -sk $URL/login | grep -q 'Sign in'"
is "8443 serves assets without PHP" "$(code $URL/assets/core/css/app.css)" 200
is "8443: /composer.json is the application's 404" "$(code $URL/composer.json)" 404
is "8443: /.env is refused" "$(code $URL/.env)" 403
is "8443 sends HSTS" "$(curl -skI $URL/login | grep -ci '^strict-transport-security')" 1
is "8443 does not serve the agents' API" "$(code -X POST $URL/agent/v1/heartbeat)" 404
is "8444 serves nothing but the agents' API" "$(code $AGENTS/login)" 404
check "8444 reaches the agents' API" bash -c "curl -sk -X POST $AGENTS/agent/v1/heartbeat | grep -q '\"code\":\"bad_request\"'"
check "the two listeners have different keys" bash -c '! cmp -s <(openssl x509 -in /etc/lpanel/tls/panel.crt -pubkey -noout) <(openssl x509 -in /etc/lpanel/tls/agent.crt -pubkey -noout)'

echo "-- users, files and the database"
check "lpanel and lpanel-web are system users with no shell" bash -c 'for u in lpanel lpanel-web; do [ "$(id -u $u)" -lt 1000 ] && getent passwd $u | grep -q ":/usr/sbin/nologin$" || exit 1; done'
is "/opt/lpanel" "$(mode_of /opt/lpanel)" "755 root:root"
is "/var/lib/lpanel" "$(mode_of /var/lib/lpanel)" "750 lpanel:lpanel"
is "/etc/lpanel/lpanel.env" "$(mode_of /etc/lpanel/lpanel.env)" "640 root:lpanel"
is "/etc/lpanel/migrate.env" "$(mode_of /etc/lpanel/migrate.env)" "600 root:root"
is "/etc/lpanel/keys/operations.key" "$(mode_of /etc/lpanel/keys/operations.key)" "640 root:lpanel"
is "/etc/lpanel/tls/agent.key" "$(mode_of /etc/lpanel/tls/agent.key)" "600 root:root"
check "the panel's user cannot change the application" bash -c '! runuser -u lpanel -- touch /opt/lpanel/x'
check "the panel's user cannot read the migration password" bash -c '! runuser -u lpanel -- cat /etc/lpanel/migrate.env'
check "Nginx's workers cannot read the panel's secrets or state" bash -c '! runuser -u lpanel-web -- cat /etc/lpanel/lpanel.env && ! runuser -u lpanel-web -- ls /var/lib/lpanel'
APP_PW=$(sed -n 's/^DB_PASSWORD=//p' /etc/lpanel/lpanel.env)
check "the application's account reads and writes rows" bash -c "MYSQL_PWD='$APP_PW' mariadb -u lpanel_app lpanel -e 'SELECT COUNT(*) FROM operations'"
check "the application's account cannot change tables" bash -c "! MYSQL_PWD='$APP_PW' mariadb -u lpanel_app lpanel -e 'CREATE TABLE x (i INT)'"
check "every migration ran" bash -c 'lpanel migrate:status | grep -qx "0 pending."'
check "lpanel security:check passes" quiet lpanel security:check

echo "-- separation from the hosting Nginx and PHP-FPM (ADR-0003, ADR-0012)"
is "nothing under /etc/nginx, /etc/php or /etc/mysql changed" "$(snapshot | sha256sum)" "$(echo "$BEFORE" | sha256sum)"
echo 'this is not nginx;' > /etc/nginx/conf.d/broken.conf
echo '[broken' > "/etc/php/$PHPV/fpm/pool.d/broken.conf"
check "a broken hosting Nginx config fails its own test" bash -c '! nginx -t'
check "  ...and panel Nginx still reloads" systemctl reload lpanel-nginx.service
check "a broken hosting PHP-FPM pool fails its own restart" bash -c "! systemctl restart php$PHPV-fpm.service"
check "  ...and the panel's PHP-FPM still restarts" systemctl restart lpanel-php-fpm.service
systemctl stop nginx.service >/dev/null 2>&1
is "with the hosting Nginx and PHP-FPM down, the panel still answers" "$(code $URL/login)" 200
rm -f /etc/nginx/conf.d/broken.conf "/etc/php/$PHPV/fpm/pool.d/broken.conf"
systemctl start nginx.service "php$PHPV-fpm.service" >/dev/null 2>&1

echo "-- root signs in with the machine's root password (lpanel-authd, PAM)"
echo 'root:Pkg-Test-Root-9' | chpasswd
JAR=/opt/pkgtest/jar
login() {
	rm -f $JAR
	local token
	token=$(curl -sk -c $JAR $URL/login | sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' | head -1)
	curl -sk -b $JAR -c $JAR -o /dev/null -w '%{http_code}' --data-urlencode "_token=$token" \
		--data-urlencode login=root --data-urlencode "password=$1" $URL/login
}
WRONG=$(login wrong-password)
[ "$WRONG" != 303 ] && pass "a wrong password is refused ($WRONG)" || fail "a wrong password signed in"
is "the right one signs in" "$(login Pkg-Test-Root-9)" 303
check "the dashboard shows" bash -c "curl -sk -b $JAR $URL/ | grep -q 'Welcome back'"

echo "-- a real agent pairs over 8444 and runs an operation"
check "apt installs the agent" quiet apt-get install -y -qq "/opt/pkgtest/lpa_${LPA}_amd64.deb"
PAIR=$(lpanel servers:add pkgtest | sed -n 's/^ *sudo //p')
echo "  $(echo "$PAIR" | sed 's/--token .*/--token …/')"
check "the pairing command names port 8444 and the agents' pin" bash -c "echo '$PAIR' | grep -q 'https://panel.pkgtest.example:8444 --pin sha256/'"
check "lpa enroll pairs with the packaged panel" quiet $PAIR
check "the agent starts" systemctl restart lpa-agent.service
online() { lpanel servers:list --json | grep -q '"status":"online".*system.info'; }
for _ in $(seq 30); do online && break; sleep 1; done
check "the panel sees it online, with its operations" online
check "system.info runs on it, through the panel" quiet lpanel operations:run pkgtest system.info --wait --timeout=40
check "the scheduler's run succeeds" bash -c 'systemctl start lpanel-scheduler.service && [ "$(systemctl show -p Result --value lpanel-scheduler.service)" = success ]'

echo "-- hosting accounts, from the panel through the agent and lpa-exec (ADR-0013)"
PANEL_CONF=$(find /etc/lpanel -type f -exec sha256sum {} + | sort | sha256sum)
hosting_snapshot() { snapshot | grep -v '/fpm/pool.d/lpanel-lp'; }
CSRF=$(curl -sk -b $JAR -c $JAR $URL/ | sed -n 's/.*name="csrf-token" content="\([^"]*\)".*/\1/p')
api() { curl -sk -b $JAR -c $JAR -H "X-CSRF-TOKEN: $CSRF" -H 'Content-Type: application/json' -H 'Accept: application/json' "$@"; }
is "root makes a customer through the API" "$(api -o /dev/null -w '%{http_code}' -X POST $URL/api/v1/users -d '{"login":"carol","name":"Carol Pkg","password":"Carol-Pkg-Test-1","roles":["customer"]}')" 201
check "lpa-exec's socket is ready" systemctl is-active --quiet lpa-exec.socket
check "accounts:create sets one up, and the panel sees it active" quiet lpanel accounts:create pkgtest carol "Acme Ltd" --wait --timeout=90
U=$(lpanel accounts:list --json | grep -o '"system_user":"lp[a-z2-7]*"' | head -1 | cut -d'"' -f4)
echo "  info  account user: ${U:-none}"
check "  ...a Linux user in the account range" bash -c "u=\$(id -u '$U') && [ \$u -ge 20000 ] && [ \$u -le 59999 ]"
is "  ...whose home is root's, the account's group only" "$(mode_of /home/$U)" "750 root:$U"
is "  ...with its own PHP-FPM pool" "$(stat -c '%a %U:%G %F' /run/php/lpanel-$U.sock 2>&1)" "660 $U:www-data socket"
is "  ...whose uid the panel recorded" "$(db "SELECT system_uid FROM hosting_accounts WHERE system_user='$U'")" "$(id -u $U)"
is "the panel's own configuration is byte-identical" "$(find /etc/lpanel -type f -exec sha256sum {} + | sort | sha256sum)" "$PANEL_CONF"
is "nothing else under /etc/nginx, /etc/php or /etc/mysql changed" "$(hosting_snapshot | sha256sum)" "$(echo "$BEFORE" | sha256sum)"
is "the panel answers while the hosting PHP-FPM reloads" "$(code $URL/login)" 200
check "accounts:suspend" quiet lpanel accounts:suspend "$U" --wait --timeout=90
check "  ...switches its pool off" test ! -e /run/php/lpanel-$U.sock
check "accounts:unsuspend" quiet lpanel accounts:unsuspend "$U" --wait --timeout=90
check "  ...brings it back" test -S /run/php/lpanel-$U.sock
check "accounts:delete refuses without the typed user name" bash -c "! lpanel accounts:delete '$U' --confirm=wrong"
check "accounts:delete, confirmed" quiet lpanel accounts:delete "$U" --confirm="$U" --wait --timeout=180
check "  ...removes the user, its group, home and pool" bash -c "! getent passwd '$U' && ! getent group '$U' && [ ! -e /home/$U ] && [ ! -e /run/php/lpanel-$U.sock ]"
is "  ...and the panel keeps the record as deleted" "$(db "SELECT status FROM hosting_accounts WHERE system_user='$U'")" deleted
is "every account change is in the audit log" "$(db "SELECT COUNT(*) FROM audit_events WHERE action LIKE 'account.%' AND outcome='succeeded'")" 4
is "afterwards, /etc/nginx, /etc/php and /etc/mysql are as they were" "$(snapshot | sha256sum)" "$(echo "$BEFORE" | sha256sum)"

echo "-- upgrade to $NEW"
APP_KEY=$(grep ^APP_KEY= /etc/lpanel/lpanel.env)
KEYS=$(sha256sum /etc/lpanel/keys/operations.key /etc/lpanel/tls/*)
OPS=$(db 'SELECT COUNT(*) FROM operations')
check "apt upgrades the package" quiet apt-get install -y -qq "/opt/pkgtest/lpanel_${NEW}_amd64.deb"
is "/opt/lpanel/VERSION" "$(cat /opt/lpanel/VERSION)" "$NEW"
is "the panel is out of maintenance" "$(code $URL/login)" 200
check "no upgrade marker is left" test ! -e /var/lib/lpanel/Runtime/lpanel-upgrading
is "APP_KEY is kept" "$(grep ^APP_KEY= /etc/lpanel/lpanel.env)" "$APP_KEY"
is "keys and certificates are kept" "$(sha256sum /etc/lpanel/keys/operations.key /etc/lpanel/tls/*)" "$KEYS"
is "operations are kept" "$(db 'SELECT COUNT(*) FROM operations')" "$OPS"
check "root's session survives the upgrade" bash -c "curl -sk -b $JAR $URL/ | grep -q 'Welcome back'"
check "the agent keeps working with the upgraded panel" quiet lpanel operations:run pkgtest agent.health --wait --timeout=40
is "still nothing under /etc/nginx, /etc/php or /etc/mysql changed" "$(snapshot | sha256sum)" "$(echo "$BEFORE" | sha256sum)"

echo "-- remove, reinstall, purge"
check "apt removes the package" quiet apt-get remove -y -qq lpanel
check "its services are gone" bash -c '! systemctl is-active --quiet lpanel-nginx.service && ! systemctl is-active --quiet lpanel-php-fpm.service && ! systemctl is-active --quiet lpanel-scheduler.timer'
check "ports 8443 and 8444 are free" bash -c '! ss -Hltn "( sport = :8443 or sport = :8444 )" | grep -q .'
check "the hosting Nginx is still running" systemctl is-active --quiet nginx.service
check "settings, keys and data are kept" test -f /etc/lpanel/lpanel.env -a -f /etc/lpanel/keys/operations.key -a -d /var/lib/lpanel/Sessions
check "reinstalling picks them up" quiet apt-get install -y -qq "/opt/pkgtest/lpanel_${NEW}_amd64.deb"
is "  ...with the same APP_KEY" "$(grep ^APP_KEY= /etc/lpanel/lpanel.env)" "$APP_KEY"
check "  ...and the paired server" bash -c 'lpanel servers:list --json | grep -q "\"name\":\"pkgtest\""'
check "apt purges the package" quiet apt-get purge -y -qq lpanel
check "settings, keys, state and logs are gone" bash -c '[ ! -e /etc/lpanel ] && [ ! -e /var/lib/lpanel ] && [ ! -e /var/log/lpanel ] && [ ! -e /opt/lpanel ]'
check "the database is kept (it is data)" bash -c 'mariadb -N -e "SHOW DATABASES" | grep -qx lpanel'

[ "$FAILED" = 0 ]
