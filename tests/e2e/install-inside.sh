#!/usr/bin/env bash
# Runs inside a fresh AlmaLinux 9 systemd container (see install.sh).
set -uo pipefail

ROOT_PASSWORD='Root-Test-Pass-91'
pass=0
fail=0
check() { # name, command...
    local name="$1"; shift
    if "$@" >/tmp/out 2>&1; then
        pass=$((pass + 1)); echo "  ok    $name"
    else
        fail=$((fail + 1)); echo "  FAIL  $name"; sed 's/^/        /' /tmp/out | tail -25
    fi
}
status() { curl -ks -o /dev/null -w '%{http_code}' "$1"; }
location() { curl -ks -o /dev/null -w '%{redirect_url}' "$1"; }

echo "$ROOT_PASSWORD" | passwd --stdin root >/dev/null
dnf -y -q install procps-ng e2fsprogs >/dev/null 2>&1

# /home on an ext4 filesystem with user quotas, as ADR-0008 requires, so the
# installer takes the real path (no --allow-no-quota) and disk limits are
# enforced by the kernel in the checks below.
truncate -s 512M /homes.img
mkfs.ext4 -q -O quota -E quotatype=usrquota /homes.img
mount -o loop,usrquota /homes.img /home || { echo "cannot mount a quota filesystem"; exit 1; }
rm -rf /home/lost+found

echo "== install"
check "the installer finishes" bash -c '/bundle/install.sh --hostname e2e-panel.test > /tmp/install.log 2>&1 || { tail -40 /tmp/install.log; exit 1; }'
grep -q "LPanel is installed." /tmp/install.log && echo "        (installer printed the sign-in details)"

echo "== services"
for unit in mariadb lpa lpanel-authd.socket lpanel-php-fpm lpanel-nginx lpanel-scheduler.timer; do
    check "$unit is active" systemctl is-active --quiet "$unit"
done
check "the stock nginx and php-fpm stay off" bash -c '! systemctl is-active --quiet nginx && ! systemctl is-active --quiet php83-php-fpm'
check "scheduled tasks run" systemctl start lpanel-scheduler.service
check "the agent is told quotas are on" grep -q '"quota": "on"' /etc/lpa/lpa.json
check "root has the lpanel command" bash -c 'lpanel help | grep -q accounts:create'

echo "== files and permissions"
perm() { [ "$(stat -c '%a %U %G' "$1")" = "$2" ] || { stat -c '%a %U %G %n' "$1"; return 1; }; }
export -f perm
check ".env is 640 root:lpanel" perm /usr/local/lpanel/.env "640 root lpanel"
check "the agent key is 600 lpanel" perm /etc/lpanel/agent.key "600 lpanel lpanel"
check "the TLS key is 600 root" perm /etc/lpanel/tls/panel.key "600 root root"
check "the agent socket is 660 root:lpanel" perm /run/lpa/lpa.sock "660 root lpanel"
check "the authd socket is 660 root:lpanel" perm /run/lpanel-authd/authd.sock "660 root lpanel"
check "the panel cannot change its own code" bash -c '! runuser -u lpanel -- touch /usr/local/lpanel/modules/Panel/module.php'
check "an ordinary user cannot read the panel's settings" bash -c 'useradd -m intruder; ! runuser -u intruder -- cat /usr/local/lpanel/.env'
check "an ordinary user cannot open the authd or agent socket" bash -c '! runuser -u intruder -- test -w /run/lpanel-authd/authd.sock && ! runuser -u intruder -- test -w /run/lpa/lpa.sock'

export -f status location
echo "== the two interfaces"
check "7443 / goes to the Admin panel" bash -c '[ "$(location https://127.0.0.1:7443/)" = "https://127.0.0.1:7443/admin" ]'
check "7444 / goes to the User panel" bash -c '[ "$(location https://127.0.0.1:7444/)" = "https://127.0.0.1:7444/panel" ]'
check "the Admin panel sign-in page" bash -c 'curl -ksf https://127.0.0.1:7443/admin/login | grep -q "Sign in"'
check "the User panel sign-in page" bash -c 'curl -ksf https://127.0.0.1:7444/panel/login | grep -q "User panel"'
check "7443 does not serve the User panel" bash -c '[ "$(status https://127.0.0.1:7443/panel/login)" = 404 ]'
check "7444 does not serve the Admin panel" bash -c '[ "$(status https://127.0.0.1:7444/admin/login)" = 404 ]'
check "module assets are served" bash -c 'curl -ksfI https://127.0.0.1:7443/assets/module/Panel/css/panel.css | grep -qi "content-type: text/css"'
for path in composer.json .env vendor/autoload.php engine/Core/Application.php modules/Identity/module.php laika; do
    check "/$path cannot be downloaded" bash -c "! curl -ks https://127.0.0.1:7443/$path | grep -qE 'APP_KEY|\"require\"|<\?php'"
done
check "plain HTTP is not spoken on 7443" bash -c '! curl -sf -o /dev/null http://127.0.0.1:7443/health'

echo "== root signs in through PAM and sees the server"
jar=/tmp/jar
token() { grep -o 'name="_token" value="[^"]*"' "$1" | head -1 | sed 's/.*value="//;s/"$//'; }
signin() { # password -> prints status and Location
    curl -ks -c $jar -b $jar https://127.0.0.1:7443/admin/login -o /tmp/login.html
    curl -ks -c $jar -b $jar -o /tmp/answer.html -w '%{http_code} %{redirect_url}' \
        --data-urlencode "_token=$(token /tmp/login.html)" --data-urlencode "username=root" \
        --data-urlencode "password=$1" https://127.0.0.1:7443/admin/login
}
export jar
export -f status location token signin perm
rm -f $jar
check "a wrong root password is refused" bash -c "[ \"\$(signin wrong-password)\" = '422 ' ]"
check "the right root password signs in" bash -c "[ \"\$(signin '$ROOT_PASSWORD')\" = '303 https://127.0.0.1:7443/admin' ]"
curl -ks -c $jar -b $jar https://127.0.0.1:7443/admin -o /tmp/dashboard.html
check "the dashboard shows this server, live from the agent" grep -q "e2e-panel.test" /tmp/dashboard.html
check "the dashboard shows the agent version" grep -q "Agent 0.1.0" /tmp/dashboard.html
check "the dashboard shows AlmaLinux" grep -q "AlmaLinux" /tmp/dashboard.html
check "the sign-ins are in the audit log" bash -c 'mariadb -uroot -N lpanel -e "select action from audit_log order by id" | tr "\n" " " | grep -q "auth.failed auth.login"'
check "the password never reached a log" bash -c "! grep -rq '$ROOT_PASSWORD' /var/log/lpanel /var/log/messages /usr/local/lpanel/system/Logs 2>/dev/null && ! journalctl --no-pager | grep -q '$ROOT_PASSWORD'"
check "authd checked the password (journal)" bash -c 'journalctl -u lpanel-authd --no-pager | grep -q "password accepted"'

echo "== hosting accounts, end to end"
signin_as() { # port prefix username password -> status and Location
    rm -f /tmp/jar2
    curl -ks -c /tmp/jar2 -b /tmp/jar2 "https://127.0.0.1:$1$2/login" -o /tmp/login2.html
    curl -ks -c /tmp/jar2 -b /tmp/jar2 -o /dev/null -w '%{http_code} %{redirect_url}' \
        --data-urlencode "_token=$(token /tmp/login2.html)" --data-urlencode "username=$3" \
        --data-urlencode "password=$4" "https://127.0.0.1:$1$2/login"
}
t_pkg()       { lpanel packages:create Starter --disk=3 --inodes=2000; }
t_create()    { echo "Owner-Pass-Word-1" | lpanel accounts:create shop1 shop.example.test Starter --email=owner@example.test; }
t_active()    { lpanel accounts:list | tee /dev/stderr | grep -Eq '^shop1 +shop\.example\.test +Starter +active'; }
t_user()      { getent passwd shop1 | grep -q ':lpanel account:/home/shop1:' && [ "$(stat -c '%a %U' /home/shop1)" = "711 shop1" ]; }
t_quota()     { ! runuser -u shop1 -- dd if=/dev/zero of=/home/shop1/public_html/f bs=1M count=10 2>/tmp/dd; grep -q "Disk quota exceeded" /tmp/dd; }
t_usage()     { lpanel accounts:usage && [ "$(mariadb -uroot -N lpanel -e "select disk_used_bytes from accounts where username='shop1'")" -gt 2000000 ]; }
t_owner_in()  { [ "$(signin_as 7444 /panel shop1 Owner-Pass-Word-1)" = "303 https://127.0.0.1:7444/panel" ]; }
t_owner_sees() { curl -ks -b /tmp/jar2 https://127.0.0.1:7444/panel | grep -q "shop.example.test"; }
check "lpanel packages:create" t_pkg
check "lpanel accounts:create" t_create
check "the account is active" t_active
check "the Linux user and its home exist" t_user
check "the kernel holds it to its 3 MB" t_quota
check "lpanel accounts:usage reads the use back" t_usage
check "the owner signs in to the User panel on 7444" t_owner_in
check "the User panel shows their account" t_owner_sees

TOKEN=$(lpanel identity:token root e2e-billing | tail -1)
api() { curl -ks -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" "$@"; }
t_api_list()   { api https://127.0.0.1:7443/admin/api/v1/accounts | grep -q '"username":"shop1"'; }
t_api_create() { [ "$(api -o /tmp/api.json -w '%{http_code}' -H 'Content-Type: application/json' -d '{"username":"shop2","domain":"two.example.test","password":"Owner-Pass-Word-2","package":"Starter"}' https://127.0.0.1:7443/admin/api/v1/accounts)" = 201 ] && getent passwd shop2; }
t_api_suspend() { [ "$(api -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d '{"reason":"e2e"}' https://127.0.0.1:7443/admin/api/v1/accounts/shop2/suspend)" = 200 ] && chage -l shop2 | grep -q "Jan 02, 1970"; }
t_suspended_out() { [ "$(signin_as 7444 /panel shop2 Owner-Pass-Word-2)" = "422 " ]; }
t_cookie_no()  { [ "$(curl -ks -b $jar -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://127.0.0.1:7443/admin/api/v1/accounts)" = 401 ]; }
t_api_7444()   { [ "$(api -o /dev/null -w '%{http_code}' https://127.0.0.1:7444/admin/api/v1/accounts)" = 404 ]; }
t_terminate()  { lpanel accounts:terminate shop2 --yes; }
t_settled()    { for _ in $(seq 30); do systemctl start lpanel-scheduler.service; getent passwd shop2 >/dev/null || break; sleep 1; done
                 ! getent passwd shop2 && [ ! -e /home/shop2 ] && ! lpanel accounts:list | grep -q shop2; }
t_audited()    { mariadb -uroot -N lpanel -e "select action from audit_log where action not like 'auth.%' order by id" | tr '\n' ' ' | tee /dev/stderr | grep -q "package.create account.create api_token.create account.create account.suspend reseller.create package.create account.create account.terminate"; }
check "the API lists accounts with a token" t_api_list
check "the API creates an account" t_api_create
check "the API suspends it, and the system expires it" t_api_suspend
check "a suspended owner cannot sign in" t_suspended_out
check "a signed-in browser's cookie is not an API credential" t_cookie_no
check "the API is not served on the User panel's port" t_api_7444
echo "== both interfaces in one browser, and log in as"
# One cookie jar, as one browser: root on 7443 and the owner on 7444 at once.
both=/tmp/both.jar
form_token() { curl -ks -b $both -c $both "$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
post() { # page-with-the-form url field=value... -> status and Location
    local tok; tok=$(form_token "$1"); shift
    local url="$1"; shift
    local args=(); for kv in "$@"; do args+=(--data-urlencode "$kv"); done
    curl -ks -b $both -c $both -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "_token=$tok" "${args[@]}" "$url"
}
t_both_in()   { rm -f $both
                post https://127.0.0.1:7443/admin/login https://127.0.0.1:7443/admin/login username=root "password=$ROOT_PASSWORD" >/dev/null
                post https://127.0.0.1:7444/panel/login https://127.0.0.1:7444/panel/login username=shop1 password=Owner-Pass-Word-1 >/dev/null
                curl -ks -b $both https://127.0.0.1:7443/admin | grep -q "Welcome, root" && curl -ks -b $both https://127.0.0.1:7444/panel | grep -q "shop.example.test"; }
t_two_cookies() { grep -q lpanel_admin $both && grep -q lpanel_user $both; }
SHOP1_LOGIN=$(mariadb -uroot -N lpanel -e "select login_id from accounts where username='shop1'")
t_login_as()  { local out; out=$(post https://127.0.0.1:7443/admin "https://127.0.0.1:7443/admin/login-as/$SHOP1_LOGIN"); echo "$out"
                [[ "$out" == "303 https://127.0.0.1:7444/panel/login-as?ticket="* ]] || return 1
                curl -ks -b $both -c $both -o /dev/null "${out#303 }"
                curl -ks -b $both https://127.0.0.1:7444/panel | grep -q "You are signed in as shop1. You are really root"; }
t_still_root() { curl -ks -b $both https://127.0.0.1:7443/admin | grep -q "Welcome, root"; }
t_on_behalf() { [ "$(mariadb -uroot -N lpanel -e "select count(*) from audit_log where action='auth.login_as' and actor_name='root' and subject='shop1'")" = 1 ]; }
check "one browser is signed in to both interfaces at once" t_both_in
check "each interface has its own session cookie" t_two_cookies
check "root logs in as the owner, across ports, with a ticket" t_login_as
check "and is still root in the Admin panel" t_still_root
check "log in as is in the audit log" t_on_behalf

echo "== resellers"
t_reseller()  { echo "Reseller-Pass-1" | lpanel resellers:create rex --accounts=1 --disk=10; }
t_rex_pkg()   { lpanel packages:create Mini --owner=rex --disk=3; }
t_rex_one()   { echo "Owner-Pass-Word-3" | lpanel accounts:create shop3 three.example.test Mini --owner=rex && getent passwd shop3; }
t_rex_two()   { ! echo "Owner-Pass-Word-4" | lpanel accounts:create shop4 four.example.test Mini --owner=rex 2>&1 | tee /dev/stderr | grep -q created; ! getent passwd shop4; }
t_rex_list()  { lpanel resellers:list | grep -Eq '^rex +yes +1/1 +3/10'; }
check "lpanel resellers:create" t_reseller
check "a reseller's own package" t_rex_pkg
check "an account for the reseller" t_rex_one
check "the reseller's account limit holds" t_rex_two
check "lpanel resellers:list shows the use" t_rex_list

check "lpanel accounts:terminate" t_terminate
check "the scheduler finishes the removal" t_settled
check "every change is in the audit log" t_audited

echo "== running the installer again"
sum_before=$(sha256sum /usr/local/lpanel/.env /etc/lpanel/agent.key /etc/lpanel/tls/panel.crt)
check "a second run finishes" bash -c '/bundle/install.sh --hostname e2e-panel.test > /tmp/install2.log 2>&1 || { tail -30 /tmp/install2.log; exit 1; }'
check "and keeps every secret" bash -c "[ \"\$(sha256sum /usr/local/lpanel/.env /etc/lpanel/agent.key /etc/lpanel/tls/panel.crt)\" = '$sum_before' ]"
check "root can still sign in" bash -c "rm -f $jar; [ \"\$(signin '$ROOT_PASSWORD')\" = '303 https://127.0.0.1:7443/admin' ]"
check "and the accounts are still there" t_active

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
