#!/usr/bin/env bash
# Runs inside an AlmaLinux 9 container (see agent.sh): the real lpa binary,
# the real panel CLI and PHP client, real users and socket permissions.
set -uo pipefail

pass=0
fail=0
check() { # name, command...
    local name="$1"; shift
    if "$@" >/tmp/out 2>&1; then
        pass=$((pass + 1)); echo "  ok    $name"
    else
        fail=$((fail + 1)); echo "  FAIL  $name"; sed 's/^/        /' /tmp/out | head -20
    fi
}
has() { grep -q -- "$1" /tmp/out; }

echo "== setting up"
# The panel's own PHP: Remi's php83, installed beside (not instead of) any PHP
# the customers' sites use. AppStream's PHP has no sodium on EL9.
dnf -y -q install epel-release >/dev/null 2>&1
dnf -y -q install https://rpms.remirepo.net/enterprise/remi-release-9.rpm >/dev/null 2>&1
dnf -y -q install php83-php-cli php83-php-intl php83-php-mbstring php83-php-pdo php83-php-sodium php83-php-process procps-ng >/dev/null 2>&1 || { echo "php install failed"; exit 1; }
ln -sf /usr/bin/php83 /usr/local/bin/php
php -v | head -1

# /home on its own small ext4 filesystem with user quotas, as ADR-0008 asks of
# a real server, so the kernel's enforcement is tested and not assumed.
dnf -y -q install e2fsprogs >/dev/null 2>&1 || { echo "e2fsprogs install failed"; exit 1; }
truncate -s 256M /homes.img
mkfs.ext4 -q -O quota -E quotatype=usrquota /homes.img
mount -o loop,usrquota /homes.img /home || { echo "cannot mount a quota filesystem (run with --privileged)"; exit 1; }
rm -rf /home/lost+found

useradd --system --home-dir /usr/local/lpanel --shell /sbin/nologin lpanel
useradd --create-home intruder          # an ordinary local user
useradd --create-home sneaky
usermod -aG lpanel sneaky                # can open the socket, but is not the panel's uid

mkdir -p /usr/local/lpanel /usr/local/lpa/bin /etc/lpanel /etc/lpa
cp -a /src/. /usr/local/lpanel/
rm -f /usr/local/lpanel/.env             # the host's development settings do not belong here
chown -R lpanel:lpanel /usr/local/lpanel/system
install -m 0755 /src/dist/lpa/bin/lpa_linux_amd64 /usr/local/lpa/bin/lpa
install -m 0644 /src/dist/lpa/packaging/lpa.json /etc/lpa/lpa.json

export APP_ENV=production LPANEL_AGENT_KEY_FILE=/etc/lpanel/agent.key
panel() { runuser -u lpanel -- env APP_ENV=production LPANEL_AGENT_KEY_FILE=/etc/lpanel/agent.key php /usr/local/lpanel/laika "$@"; }
as() { local who="$1"; shift; runuser -u "$who" -- env LPANEL_AGENT_KEY_FILE=/etc/lpanel/agent.key "$@"; }

echo "== keys"
check "agent:keygen writes the pair" php /usr/local/lpanel/laika agent:keygen
chown lpanel:lpanel /etc/lpanel/agent.key
check "the signing key is 0600 lpanel" bash -c '[ "$(stat -c "%a %U" /etc/lpanel/agent.key)" = "600 lpanel" ]'
check "keygen refuses to overwrite" bash -c '! php /usr/local/lpanel/laika agent:keygen'

echo "== agent"
/usr/local/lpa/bin/lpa serve -config /etc/lpa/lpa.json 2>/tmp/lpa.log &
LPA=$!
for _ in $(seq 50); do [ -S /run/lpa/lpa.sock ] && break; sleep 0.1; done
check "the socket is root:lpanel 0660" bash -c '[ "$(stat -c "%a %U %G" /run/lpa/lpa.sock)" = "660 root lpanel" ]'
check "lpa version" bash -c '/usr/local/lpa/bin/lpa version | grep -qx 0.1.0'
check "lpa ops lists the operations" bash -c '/usr/local/lpa/bin/lpa ops | grep -q system.facts'

echo "== the panel asks"
check "agent:ping as the panel" panel agent:ping
has "lpa 0.1.0 answered" || { fail=$((fail + 1)); echo "  FAIL  ping output"; }
check "agent:facts as the panel" panel agent:facts
has "\"hostname\": \"$(hostname)\"" || { fail=$((fail + 1)); echo "  FAIL  facts hostname"; cat /tmp/out; }
has '"pretty_name": "AlmaLinux' || { fail=$((fail + 1)); echo "  FAIL  facts os"; }

echo "== who may not"
check "an ordinary user cannot open the socket" bash -c "as_out=\$(runuser -u intruder -- php /usr/local/lpanel/tests/e2e/probe.php raw '{}'); echo \"\$as_out\"; [[ \"\$as_out\" == NO-CONNECT* ]]"
check "an ordinary user cannot read the key" bash -c '! runuser -u intruder -- cat /etc/lpanel/agent.key'
check "a member of the lpanel group gets no answer (wrong uid)" bash -c "[ \"\$(runuser -u sneaky -- php /usr/local/lpanel/tests/e2e/probe.php raw '{}')\" = NO-ANSWER ]"

echo "== what the panel uid may not do"
check "a request signed with another key is refused" bash -c "[ \"\$(runuser -u lpanel -- php /usr/local/lpanel/tests/e2e/probe.php forged)\" = bad_signature ]"
check "a replayed request is refused" bash -c "[ \"\$(runuser -u lpanel -- php /usr/local/lpanel/tests/e2e/probe.php replay /etc/lpanel/agent.key)\" = 'done replay' ]"
check "a stale request is refused" bash -c "[ \"\$(runuser -u lpanel -- php /usr/local/lpanel/tests/e2e/probe.php stale /etc/lpanel/agent.key)\" = stale ]"
check "garbage is a bad request" bash -c "runuser -u lpanel -- php /usr/local/lpanel/tests/e2e/probe.php raw 'rm -rf /' | grep -q bad_request"
check "the agent logged the refusals" bash -c 'grep -q "refused a connection from a process that is not the panel" /tmp/lpa.log && grep -q bad_signature /tmp/lpa.log'
check "the log never contains the key or args" bash -c "! grep -q \"\$(cat /etc/lpanel/agent.key)\" /tmp/lpa.log"

echo "== hosting accounts"
call() { runuser -u lpanel -- php /usr/local/lpanel/tests/e2e/probe.php call /etc/lpanel/agent.key "$@"; }
# field PATH: one value from the last answer, e.g. status or error.code
field() { php -r '$r = json_decode(file_get_contents("/tmp/answer"), true); foreach (explode(".", $argv[1]) as $k) { $r = is_array($r) ? ($r[$k] ?? null) : null; } echo is_string($r) ? $r : json_encode($r);' "$1"; }
ask() { call "$@" > /tmp/answer; cat /tmp/answer; }
is() { [ "$(field "$1")" = "$2" ]; }
newid() { php -r 'echo bin2hex(random_bytes(16));'; }

SHOP='{"username":"shop1","shell":"none","quota":{"blocks_kib":2048,"inodes":1000}}'
ID1=$(newid)
t_create()   { ask account.create "$SHOP" "$ID1" && is status done && is result.home /home/shop1; }
t_marked()   { getent passwd shop1 | grep -q ":lpanel account:/home/shop1:/sbin/nologin$"; }
t_modes()    { [ "$(stat -c '%a %U' /home/shop1)" = "711 shop1" ] && [ "$(stat -c '%a %U %G' /home/shop1/public_html)" = "750 shop1 shop1" ]; }
t_same_id()  { ask account.create "$SHOP" "$ID1" && is status done && [ "$(getent passwd shop1 | wc -l)" -eq 1 ]; }
t_exists()   { ask account.create "$SHOP" && is error.code exists; }
t_path()     { ask account.create '{"username":"../etc","shell":"none","quota":{"blocks_kib":0,"inodes":0}}' && is error.code bad_args; }
t_enforced() { ! runuser -u shop1 -- dd if=/dev/zero of=/home/shop1/public_html/big bs=1M count=5 2>/tmp/dd; grep -q "Disk quota exceeded" /tmp/dd; }
t_info()     { ask account.info '{"usernames":["shop1"]}' && is result.accounts.0.usage.limit_kib 2048 && [ "$(field result.accounts.0.usage.used_bytes)" -gt 1000000 ]; }
t_modify()   { ask account.modify '{"username":"shop1","quota":{"blocks_kib":20480,"inodes":1000}}' && is status done && runuser -u shop1 -- dd if=/dev/zero of=/home/shop1/public_html/big bs=1M count=5 2>&1; }
t_suspend()  { ask account.suspend '{"username":"shop1"}' && is result.suspended true && sleep 0.3 && ! pgrep -u shop1; }
# root's own su skips PAM's account check on EL, so look at what that check reads.
t_no_login() { chage -l shop1 | tee /dev/stderr | grep "Account expires" | grep -q "Jan 02, 1970"; }
t_unsuspend() { ask account.unsuspend '{"username":"shop1"}' && is result.suspended false && chage -l shop1 | grep "Account expires" | grep -q never; }
t_root()     { ask account.suspend '{"username":"root"}' && is error.code not_ours && ! chage -l root | grep -q "Jan 02, 1970"; }
t_intruder() { ask account.terminate '{"username":"intruder"}'; sleep 0.5; getent passwd intruder && [ -d /home/intruder ]; }

check "account.create makes the user" t_create
check "it is marked as LPanel's, with no login shell" t_marked
check "home 0711, public_html 0750, both the user's" t_modes
check "the same id again gets the first answer, not a second user" t_same_id
check "a new request for the same name is refused: exists" t_exists
check "a name that is a path is refused" t_path
check "the kernel enforces the 2 MB quota" t_enforced
check "account.info reports the use and the limit" t_info
check "account.modify raises the quota" t_modify
(runuser -u shop1 -- sleep 600 >/dev/null 2>&1 &)
sleep 0.3
check "account.suspend expires it and ends its processes" t_suspend
check "a suspended account is expired, so PAM refuses its logins" t_no_login
check "account.unsuspend lets it in again" t_unsuspend
check "root is not the agent's to suspend" t_root
check "nor is an ordinary local user, who keeps working" t_intruder

echo keep-me > /root/precious
ln -s /root/precious /home/shop1/public_html/link
ID2=$(newid)
t_terminate() { ask account.terminate '{"username":"shop1"}' "$ID2" && is status accepted; }
t_done()      { for _ in $(seq 50); do ask op.status "{\"id\":\"$ID2\"}" >/dev/null; is status done && break; sleep 0.2; done; is status done && is result.existed true; }
t_gone()      { ! getent passwd shop1 && ! getent group shop1 && [ ! -e /home/shop1 ]; }
t_kept()      { [ "$(cat /root/precious)" = keep-me ]; }
check "account.terminate runs in the background" t_terminate
check "op.status reports it done" t_done
check "the user, group and home are gone" t_gone
check "a link in the home did not lead the removal out of it" t_kept

echo "== stopping"
kill -TERM "$LPA"
check "SIGTERM stops it cleanly" bash -c "wait $LPA 2>/dev/null; sleep 0.5; ! kill -0 $LPA 2>/dev/null && grep -q 'lpa stopped' /tmp/lpa.log"
check "the panel says so when the agent is down" bash -c '! runuser -u lpanel -- env LPANEL_AGENT_KEY_FILE=/etc/lpanel/agent.key php /usr/local/lpanel/laika agent:ping 2>&1 | tee /dev/stderr | grep -q "answered"'

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
