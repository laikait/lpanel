#!/usr/bin/env bash
# Runs inside a fresh AlmaLinux 9 systemd container (see install.sh).
# No pipefail: a check like `curl page | grep -q text` fails (curl: broken pipe) whenever grep
# finds its text and quits before curl has written the whole page, more often the bigger
# the page is. What the checks look at is the text, not the producer's exit status.
set -u

ROOT_PASSWORD='Root-Test-Pass-91'
pass=0
fail=0
check() { # name, command...
    local name="$1"; shift
    if "$@" >/tmp/out 2>&1; then
        pass=$((pass + 1)); echo "  ok    $name"
    else
        fail=$((fail + 1)); echo "  FAIL  $name"; sed 's/^/        /' /tmp/out | tail -25
        # A mail check that fails shows what Exim did last.
        case "$1" in t_mail_*) echo "        --- exim"; grep -vE 'Start queue|End queue' /var/log/exim/main.log 2>/dev/null | tail -12 | cut -c1-300 | sed 's/^/        /' ;; esac
    fi
}
status() { curl -ks -o /dev/null -w '%{http_code}' "$1"; }
location() { curl -ks -o /dev/null -w '%{redirect_url}' "$1"; }

echo "$ROOT_PASSWORD" | passwd --stdin root >/dev/null
# fs.protected_fifos belongs to the kernel every container on this machine shares:
# note when it changes, so a surprise can be explained (see the isolation check).
( last=x; while :; do v=$(cat /proc/sys/fs/protected_fifos 2>/dev/null); if [ "$v" != "$last" ]; then { echo "$(date +%T) $v"; if [ "$last" != x ]; then ps -eo pid,etimes,args --sort=etimes | head -8 | cut -c1-110; journalctl --no-pager --since "12 seconds ago" 2>/dev/null | tail -12 | cut -c20-170; fi; } >> /tmp/fifos.log; last=$v; fi; sleep 0.3; done ) >/dev/null 2>&1 &
# On a VM with SELinux on (tests/vm/run.sh), every denial from here on fails
# the run at the end.
started=$(date '+%m/%d/%Y %H:%M:%S')
dnf -y -q install procps-ng >/dev/null 2>&1

# /home on a filesystem with user quotas (ext4, or XFS with HOME_FS=xfs), as
# ADR-0008 requires, so the installer takes the real path (no
# --allow-no-quota) and disk limits are enforced by the kernel below.
source /e2e/quota-home.sh
quota_home 512M || exit 1

# The web stack under test (WEB=apache, nginx or hybrid) and where its
# files are: the front server's sites and logs, and the logins with the
# server that checks them.
WEB="${WEB:-apache}"
case "$WEB" in
    apache) SITES=/etc/httpd/lpanel/sites; LOGINS=/etc/httpd/lpanel/htpasswd; LOGIN_GROUP=apache; LOGS=/var/log/httpd/lpanel; CONFD=/etc/httpd/conf.d; FRONT=httpd ;;
    nginx)  SITES=/etc/nginx/lpanel/sites; LOGINS=/etc/nginx/lpanel/htpasswd; LOGIN_GROUP=nginx; LOGS=/var/log/nginx/lpanel; CONFD=/etc/nginx/conf.d; FRONT=nginx ;;
    hybrid) SITES=/etc/nginx/lpanel/sites; LOGINS=/etc/httpd/lpanel/htpasswd; LOGIN_GROUP=apache; LOGS=/var/log/nginx/lpanel; CONFD=/etc/httpd/conf.d; FRONT=nginx ;;
    *) echo "WEB must be apache, nginx or hybrid"; exit 1 ;;
esac
export WEB SITES LOGINS LOGIN_GROUP LOGS CONFD FRONT
echo "   web stack: $WEB"

echo "== install"
check "the installer finishes" bash -c '/bundle/install.sh --hostname e2e-panel.test --web=$WEB > /tmp/install.log 2>&1 || { tail -40 /tmp/install.log; exit 1; }'
grep -q "LPanel is installed." /tmp/install.log && echo "        (installer printed the sign-in details)"
# AutoSSL would ask the real Let's Encrypt (every five minutes, from the
# scheduler) for names that point nowhere; it waits for the SSL section.
lpanel ssl:settings --enabled=no --host-autossl=no >/dev/null
# Nothing is installed by itself during the run (the nightly hour could fall in
# it); the updates section asks for what it tests.
lpanel updates:settings --os=off --php=no >/dev/null

echo "== services"
for unit in mariadb lpa lpanel-authd.socket lpanel-php-fpm lpanel-nginx lpanel-scheduler.timer; do
    check "$unit is active" systemctl is-active --quiet "$unit"
done
check "the panel's PHP is not among customers' pools" bash -c '! grep -rqs "lpanel/php-fpm.sock" /etc/opt/remi/php*/php-fpm.d/'
case "$WEB" in
    apache)
        check "the sites' nginx stays off" bash -c '! systemctl is-active --quiet nginx'
        check "Apache serves customers' sites on :80" bash -c 'systemctl is-active --quiet httpd && grep -q "^Listen 80$" /etc/httpd/conf/httpd.conf' ;;
    nginx)
        check "nginx serves customers' sites" systemctl is-active --quiet nginx
        check "Apache stays off" bash -c '! systemctl is-active --quiet httpd' ;;
    hybrid)
        check "nginx is in front, Apache behind it on 127.0.0.1:8080" bash -c 'systemctl is-active --quiet nginx && systemctl is-active --quiet httpd && grep -q "^Listen 127.0.0.1:8080$" /etc/httpd/conf/httpd.conf'
        check "nginx may read what Apache may (its group)" bash -c 'id -nG nginx | grep -qw apache' ;;
esac
check "Remi's sample pools are emptied" bash -c '! grep -qs "^\[www\]" /etc/opt/remi/php82/php-fpm.d/www.conf /etc/opt/remi/php83/php-fpm.d/www.conf'
check "the agent is told which stack serves the sites" grep -q "\"web\": \"$WEB\"" /etc/lpa/lpa.json
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
check "template assets are served (both panels)" bash -c 'curl -ksfI https://127.0.0.1:7443/assets/template/admin/nova/css/app.css | grep -qi "content-type: text/css" && curl -ksfI https://127.0.0.1:7444/assets/template/user/nova/css/app.css | grep -qi "content-type: text/css"'
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
t_owner_sees() { local code; code=$(curl -ks -b /tmp/jar2 -o /tmp/owner-dash.html -w '%{http_code}' https://127.0.0.1:7444/panel)
                 grep -q "shop.example.test" /tmp/owner-dash.html \n                     || { echo "dashboard: HTTP $code, $(wc -c < /tmp/owner-dash.html) bytes"; grep -o '<title>[^<]*' /tmp/owner-dash.html; head -c 400 /tmp/owner-dash.html; tail -n 8 /var/log/lpanel/*.log 2>/dev/null | cut -c1-300; return 1; }; }
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
t_settled()    { for _ in $(seq 30); do systemctl start lpanel-scheduler.service; getent passwd shop2 >/dev/null || lpanel accounts:list | grep -q shop2 || break; sleep 1; done
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
                local r1 r2
                r1=$(post https://127.0.0.1:7443/admin/login https://127.0.0.1:7443/admin/login username=root "password=$ROOT_PASSWORD")
                r2=$(post https://127.0.0.1:7444/panel/login https://127.0.0.1:7444/panel/login username=shop1 password=Owner-Pass-Word-1)
                curl -ks -b $both https://127.0.0.1:7443/admin > /tmp/both-admin.html; curl -ks -b $both https://127.0.0.1:7444/panel > /tmp/both-panel.html
                grep -q "Welcome back, root" /tmp/both-admin.html && grep -q "shop.example.test" /tmp/both-panel.html \
                    || { echo "admin login: $r1; panel login: $r2"; grep -o '<title>[^<]*' /tmp/both-admin.html /tmp/both-panel.html; grep -ao 'alert[^>]*>[^<]*<p>[^<]*' /tmp/both-admin.html /tmp/both-panel.html | head -3; return 1; }; }
t_two_cookies() { grep -q lpanel_admin $both && grep -q lpanel_user $both; }
SHOP1_LOGIN=$(mariadb -uroot -N lpanel -e "select login_id from accounts where username='shop1'")
t_login_as()  { local out; out=$(post https://127.0.0.1:7443/admin "https://127.0.0.1:7443/admin/login-as/$SHOP1_LOGIN"); echo "$out"
                [[ "$out" == "303 https://127.0.0.1:7444/panel/login-as?ticket="* ]] || return 1
                curl -ks -b $both -c $both -o /dev/null "${out#303 }"
                curl -ks -b $both https://127.0.0.1:7444/panel | grep -q "You are signed in as shop1. You are really root"; }
t_still_root() { local code; code=$(curl -ks -b $both -o /tmp/still-root.html -w '%{http_code}' https://127.0.0.1:7443/admin)
                  grep -q "Welcome back, root" /tmp/still-root.html || { echo "HTTP $code, $(wc -c < /tmp/still-root.html) bytes"; grep -o '<title>[^<]*' /tmp/still-root.html; grep -ao 'Welcome[^<]*' /tmp/still-root.html | head -2; head -c 300 /tmp/still-root.html; echo; cat $both | cut -c1-120; return 1; }; }
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

echo "== websites"
rm -f /home/shop1/public_html/f     # the quota check above filled it
# A page that says which PHP runs it, and as which user.
cat > /tmp/who.php <<'PHP'
<?php echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' as ' . posix_getpwuid(posix_geteuid())['name'];
PHP
cat > /tmp/peek.php <<'PHP'
<?php var_dump(@file_get_contents('/home/shop1/public_html/index.php'));
PHP
put()  { install -o "$1" -g "$1" -m 0644 "$2" "$3"; }      # user, source, target
site() { curl -s -o /tmp/site.out -w '%{http_code}' -H "Host: $1" http://127.0.0.1/; }
says() { [ "$(site "$1")" = 200 ] && grep -q "$2" /tmp/site.out || { echo "$1: $(cat /tmp/site.out)"; return 1; }; }
export -f put site says
t_main()    { [ -f $SITES/shop1.conf ] && put shop1 /tmp/who.php /home/shop1/public_html/index.php && says shop.example.test "8.3 as shop1"; }
t_addon()   { lpanel domains:add shop1 other.example.test --php=8.2 && put shop1 /tmp/who.php /home/shop1/public_html/other.example.test/index.php && says other.example.test "8.2 as shop1"; }
t_sub()     { lpanel domains:add shop1 blog.shop.example.test --type=sub && put shop1 /tmp/who.php /home/shop1/public_html/blog/index.php && says blog.shop.example.test "8.3 as shop1"; }
t_alias()   { lpanel domains:add shop1 shop-alias.example.test --type=alias --target=shop.example.test && says shop-alias.example.test "8.3 as shop1" && says www.shop-alias.example.test "8.3 as shop1"; }
t_list()    { lpanel domains:list shop1 | tee /dev/stderr | grep -Eq '^other\.example\.test +addon +public_html/other\.example\.test +8\.2'; }
t_isolated() { put shop3 /tmp/peek.php /home/shop3/public_html/index.php && says three.example.test "bool(false)"; }
t_suspend() { lpanel accounts:suspend shop3 && [ "$(site three.example.test)" = 503 ] && ! ls /etc/opt/remi/php*/php-fpm.d/lpanel-shop3.conf 2>/dev/null; }
t_unsuspend() { lpanel accounts:unsuspend shop3 && says three.example.test "bool(false)"; }
t_gone()    { [ ! -e $SITES/shop2.conf ] && ! ls /etc/opt/remi/php*/php-fpm.d/lpanel-shop2.conf 2>/dev/null && [ "$(site two.example.test)" = 404 ]; }
t_broken()  { echo "ThisIsNotADirective" > $CONFD/zz-broken.conf
              ! lpanel domains:add shop1 broken.example.test 2>&1 | tee /dev/stderr | grep -q added
              local ok=$?; rm -f $CONFD/zz-broken.conf
              [ $ok = 0 ] && ! lpanel domains:list shop1 | grep -q broken && systemctl is-active --quiet $FRONT && says shop.example.test "8.3 as shop1"; }
t_link()    { runuser -u shop1 -- ln -s /etc /home/shop1/public_html/evil
              ! lpanel domains:add shop1 evil.example.test --docroot=public_html/evil/x && [ ! -e /etc/x ] && ! lpanel domains:list shop1 | grep -q evil; }
# Once it came back without the list in one run of many; it says what it got
# and tries once more, so a second miss is a real one.
t_page()    { local i code; for i in 1 2; do
                  signin_as 7444 /panel shop1 Owner-Pass-Word-1 >/dev/null
                  code=$(curl -ks -b /tmp/jar2 -o /tmp/domains.html -w '%{http_code}' https://127.0.0.1:7444/panel/domains)
                  grep -q "blog.shop.example.test" /tmp/domains.html && return 0
                  echo "try $i: $code $(grep -o '<title>[^<]*' /tmp/domains.html)"; sleep 3
              done; return 1; }
t_domain_audit() { [ "$(mariadb -uroot -N lpanel -e "select count(*) from audit_log where action='domain.add' and outcome='ok'")" = 3 ]; }
check "the main site runs PHP 8.3 as the account's own user" t_main
check "www. answers too" says www.shop.example.test "8.3 as shop1"
check "a name nobody serves is a 404, not someone's site" bash -c '[ "$(site nobody.example.test)" = 404 ]'
check "lpanel domains:add: a website on PHP 8.2" t_addon
check "a subdomain with its own folder" t_sub
check "an alias shows its domain's site" t_alias
check "PHP 8.2 and 8.3 run side by side" bash -c 'systemctl is-active --quiet php82-php-fpm && systemctl is-active --quiet php83-php-fpm'
check "lpanel domains:list" t_list
check "one account's PHP cannot read another's files" t_isolated
check "a suspended account's sites say so and run no PHP" t_suspend
check "unsuspending brings them back" t_unsuspend
check "terminating took the account's site and pools away" t_gone
check "a broken Apache config stops the change, and every site keeps running" t_broken
check "a link planted in public_html is refused, not followed" t_link
check "the owner's Domains page lists the sites" t_page
check "the changes are in the audit log" t_domain_audit

echo "== site features"
O=other.example.test
OD=/home/shop1/public_html/other.example.test
get()  { curl -s -o /tmp/site.out -w '%{http_code} %{redirect_url}' -H "Host: $1" "${@:3}" "http://127.0.0.1$2" | tee -a /tmp/get.log; echo " <- $*" >> /tmp/get.log; }
export -f get
t_redirect()  { lpanel domains:redirect shop1 $O /old https://example.org/new && [ "$(get $O /old/page)" = "301 https://example.org/new/page" ]; }
t_temporary() { lpanel domains:redirect shop1 $O /sale https://example.org/sale --temporary && [ "$(get $O /sale)" = "302 https://example.org/sale" ]; }
t_canonical() { lpanel domains:set shop1 $O --canonical=www && [ "$(get $O /a?b=1)" = "301 http://www.$O/a?b=1" ] && says www.$O "8.2 as shop1"; }
t_either()    { lpanel domains:set shop1 $O --canonical=either && says $O "8.2 as shop1"; }
t_errorpage() { echo "custom missing page" > /tmp/missing.html && put shop1 /tmp/missing.html $OD/missing.html \
                && lpanel domains:error-page shop1 $O 404 /missing.html && [ "$(get $O /nothing-here.html)" = "404 " ] && grep -q "custom missing page" /tmp/site.out; }
t_protect()   { : > /tmp/get.log; mkdir -p $OD/private && echo "members only" > $OD/private/index.html && chown -R shop1:shop1 $OD/private \
                && echo "Ann-Pass-Word-1" | lpanel domains:protect shop1 $O private --realm=Members --login=ann \
                && [ "$(get $O /private/)" = "401 " ] && [ "$(get $O /private/ -u ann:wrong-password)" = "401 " ] \
                && [ "$(get $O /private/ -u ann:Ann-Pass-Word-1)" = "200 " ] && grep -q "members only" /tmp/site.out \
                && [ "$(get $O /)" = "200 " ]; }
t_logins()    { local f; f=$(ls $LOGINS/shop1-*.htpasswd) && [ "$(stat -c '%a %U %G' "$f")" = "640 root $LOGIN_GROUP" ] \
                && grep -q '^ann:\$2y\$' "$f" && ! grep -q "Ann-Pass-Word-1" "$f" \
                && ! runuser -u intruder -- cat "$f" 2>/dev/null; }
t_logout()    { : > /tmp/get.log; lpanel domains:protect-login shop1 $O private ann --remove && [ "$(get $O /private/ -u ann:Ann-Pass-Word-1)" = "401 " ]; }
t_hotlink()   { printf "not really a png" > /tmp/logo.png && put shop1 /tmp/logo.png $OD/logo.png && lpanel domains:hotlink shop1 $O --extensions=png,jpg \
                && [ "$(get $O /logo.png -e http://elsewhere.test/page)" = "403 " ] \
                && [ "$(get $O /logo.png -e http://$O/page)" = "200 " ] && [ "$(get $O /logo.png)" = "200 " ]; }
t_phpini()    { printf '<?php echo ini_get("memory_limit"), " ", ini_get("date.timezone");' > /tmp/ini.php && put shop1 /tmp/ini.php $OD/ini.php \
                && lpanel php:settings shop1 8.2 --set=memory_limit=200M,date.timezone=Asia/Dhaka \
                && [ "$(get $O /ini.php)" = "200 " ] && grep -q "200M Asia/Dhaka" /tmp/site.out \
                && grep -q 'php_value\[memory_limit\] = 200M' /etc/opt/remi/php82/php-fpm.d/lpanel-shop1.conf; }
t_bad_ini()   { ! lpanel php:settings shop1 8.2 --set=disable_functions=x && ! lpanel php:settings shop1 8.2 --set='memory_limit=1M php_admin_value'; }
t_shown()     { local out; out=$(lpanel domains:show shop1 $O) && echo "$out" >&2 && grep -q 'redirect   /old -> https://example.org/new (301)' <<<"$out" \
                && grep -q 'protected  /private "Members": no logins' <<<"$out"; }
check "a redirect keeps the rest of the path" t_redirect
check "a temporary redirect is a 302" t_temporary
check "visitors can be sent to www." t_canonical
check "and back to either" t_either
check "a custom error page" t_errorpage
check "a protected folder asks for its password, and takes only the right one" t_protect
check "its logins are bcrypt hashes, 640 root and the web group, unreadable to others" t_logins
check "a removed login no longer gets in" t_logout
check "hotlink protection refuses other sites, not the site or direct visits" t_hotlink
check "php.ini settings reach PHP for that version" t_phpini
check "settings that are not offered are refused" t_bad_ini
check "lpanel domains:show lists it all" t_shown

echo "== PHP versions"
# Which PHP runs the page, and whether it has the soap extension.
cat > /tmp/ext.php <<'PHP'
<?php echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, extension_loaded('soap') ? ' soap' : ' no-soap';
PHP
N=new84.example.test
ND=/home/shop1/public_html/new84.example.test
t_php_list()    { local out; out=$(lpanel php:list) && echo "$out" >&2 && grep -Eq '^8\.3 +running +[0-9]+ +default, the panel runs on it' <<<"$out" && grep -Eq '^8\.4 +- ' <<<"$out"; }
t_php_stays()   { ! lpanel php:remove 8.3 && ! lpanel php:remove 8.2 && rpm -q php83-php-fpm php82-php-fpm >/dev/null; }
t_php_install() { lpanel php:install 8.4 && [ -x /opt/remi/php84/root/usr/sbin/php-fpm ] && rpm -q php84-php-pecl-zip php84-php-mysqlnd >/dev/null \
                  && ! grep -q '^\[www\]' /etc/opt/remi/php84/php-fpm.d/www.conf; }
t_php_default() { lpanel php:default 8.4 && lpanel domains:add shop1 $N && put shop1 /tmp/ext.php $ND/index.php && says $N "8.4 no-soap"; }
t_php_ext()     { lpanel php:extensions 8.4 --add=soap && rpm -q php84-php-soap >/dev/null && says $N "8.4 soap"; }
t_php_ext_off() { lpanel php:extensions 8.4 --remove=soap && ! rpm -q php84-php-soap >/dev/null && says $N "8.4 no-soap"; }
t_php_inuse()   { ! lpanel php:remove 8.4 && rpm -q php84-php-fpm >/dev/null; }
t_php_panel_ext() { ! lpanel php:extensions 8.3 --remove=mysqlnd && rpm -q php83-php-mysqlnd >/dev/null; }
t_php_remove()  { lpanel domains:remove shop1 $N && lpanel php:default 8.3 && lpanel php:remove 8.4 && ! rpm -q php84-php-fpm >/dev/null \
                  && [ ! -e /opt/remi/php84/root/usr/sbin/php-fpm ] && says $O "8.2 as shop1"; }
t_php_audited() { mariadb -uroot -N lpanel -e "select concat(action, ':', outcome) from audit_log where action like 'php.%' order by id" | tr '\n' ' ' | tee /dev/stderr \
                  | grep -q "php.install:ok php.install:ok php.default:ok php.extensions:ok php.extensions:ok php.extensions:ok php.extensions:ok php.default:ok php.remove:ok php.remove:ok"; }
check "lpanel php:list shows each version, the default and the panel's own" t_php_list
check "the panel's PHP and a version in use cannot be removed" t_php_stays
check "lpanel php:install 8.4: a new version with the usual extensions" t_php_install
check "the default version is what a new site gets" t_php_default
check "an extension added reaches the sites on that version" t_php_ext
check "and removed, goes" t_php_ext_off
check "a version a site uses stays" t_php_inuse
check "the panel's own extensions stay" t_php_panel_ext
check "a version no site uses is removed, and other sites keep running" t_php_remove
check "PHP changes are in the audit log" t_php_audited

echo "== bandwidth and log rotation"
# Bytes sent plus received in an access log, as Apache wrote them (%O %I
# after the status), to compare with what the panel counted.
logged() { cat $LOGS/shop1.access.log* 2>/dev/null | awk 'match($0, /" [0-9][0-9][0-9] [0-9]+ [0-9]+ "/) { split(substr($0, RSTART + 2, RLENGTH - 3), f, " "); s += f[2] + f[3] } END { print s + 0 }'; }
counted() { lpanel web:bandwidth shop1 | awk '/^used/ { print $2 }'; }
export -f logged counted
head -c 600000 /dev/urandom > /tmp/big.bin
t_counted()  { put shop1 /tmp/big.bin $OD/big.bin && for _ in 1 2 3; do get $O /big.bin >/dev/null; done \
               && lpanel web:traffic && [ "$(counted)" = "$(logged)" ] && [ "$(counted)" -gt 1800000 ]; }
t_twice()    { local before; before=$(counted) && lpanel web:traffic && [ "$(counted)" = "$before" ]; }
t_rotated()  { logrotate -f /etc/logrotate.d/lpanel && [ -f $LOGS/shop1.access.log.1 ] && get $O /big.bin >/dev/null \
               && lpanel web:traffic && [ "$(counted)" = "$(logged)" ]; }
# (A fresh machine has no logrotate state file yet; -d says so, harmlessly.)
t_rotate_cfg() { logrotate -d /etc/logrotate.d/lpanel > /tmp/logrotate.out 2>&1; grep -q "$LOGS" /tmp/logrotate.out && ! grep '^error:' /tmp/logrotate.out | grep -v 'state file'; }
t_gone_logs()  { echo "Owner-Pass-Word-5" | lpanel accounts:create shop5 five.example.test Starter && get five.example.test / >/dev/null \
                 && [ -f $LOGS/shop5.access.log ] && lpanel accounts:terminate shop5 --yes \
                 && for _ in $(seq 60); do getent passwd shop5 >/dev/null || break; lpanel accounts:settle >/dev/null; sleep 2; done \
                 && ! getent passwd shop5 && ! ls /var/log/*/lpanel/shop5.* 2>/dev/null; }
t_limited()  { lpanel packages:create Tiny --disk=3 --inodes=2000 --bandwidth=1 && lpanel accounts:package shop1 Tiny && lpanel web:traffic \
               && [ "$(get $O /)" = "503 " ] && grep -q "used its bandwidth for this month" /tmp/site.out \
               && lpanel web:bandwidth shop1 | grep -q "stopped"; }
t_unlimited() { lpanel accounts:package shop1 Starter && says $O "8.2 as shop1" && lpanel web:bandwidth shop1 | grep -q "running"; }
t_bw_audited() { mariadb -uroot -N lpanel -e "select action from audit_log where action like 'account.bandwidth%' order by id" | tr '\n' ' ' | grep -q "account.bandwidth_limited account.bandwidth_restored"; }
check "each byte the sites sent and received is counted" t_counted
check "counting again counts nothing twice" t_twice
check "logrotate's own check accepts the configuration" t_rotate_cfg
check "after the logs rotate, nothing is lost or counted twice" t_rotated
check "over a blocking limit, the sites stop and say why" t_limited
check "a package with room starts them again at once" t_unlimited
check "both are in the audit log" t_bw_audited
check "a terminated account takes its logs with it" t_gone_logs

echo "== running the installer again"
sum_before=$(sha256sum /usr/local/lpanel/.env /etc/lpanel/agent.key /etc/lpanel/tls/panel.crt)
check "a second run finishes" bash -c '/bundle/install.sh --hostname e2e-panel.test > /tmp/install2.log 2>&1 || { tail -30 /tmp/install2.log; exit 1; }'
check "and keeps every secret" bash -c "[ \"\$(sha256sum /usr/local/lpanel/.env /etc/lpanel/agent.key /etc/lpanel/tls/panel.crt)\" = '$sum_before' ]"
check "root can still sign in" bash -c "rm -f $jar; [ \"\$(signin '$ROOT_PASSWORD')\" = '303 https://127.0.0.1:7443/admin' ]"
check "and the accounts are still there" t_active
check "and their sites are still served" says other.example.test "8.2 as shop1"
check "and on the same web stack" grep -q "\"web\": \"$WEB\"" /etc/lpa/lpa.json

echo "== the web stack ($WEB)"
ME=$(ip -4 -o addr show scope global | awk '{ split($4, a, "/"); print a[1]; exit }')   # this machine, not 127.0.0.1
export ME O OD
cat > /tmp/ip.php <<'PHP'
<?php echo 'from ', $_SERVER['REMOTE_ADDR'];
PHP
put shop1 /tmp/ip.php $OD/ip.php
printf 'body { color: red; }\n' > /tmp/x.css && put shop1 /tmp/x.css $OD/x.css
headers() { curl -s -o /dev/null -D - -H "Host: $1" "http://127.0.0.1$2" | tr -d '\r'; }
export -f headers
case "$WEB" in
    nginx)
        check "an app's own addresses reach its index.php (there is no .htaccess)" bash -c '[ "$(get $O /shop/cart)" = "200 " ] && grep -q "8.2 as shop1" /tmp/site.out'
        check "PHP sees the visitor's address" bash -c 'curl -s -H "Host: $O" "http://$ME/ip.php" | grep -qx "from $ME"' ;;
    hybrid)
        check "nginx serves static files itself" bash -c 'h=$(headers $O /x.css) && grep -q "^X-LPanel-Front:" <<<"$h" && ! grep -q "^X-LPanel-Revision:" <<<"$h"'
        check "and passes PHP to Apache" bash -c 'h=$(headers $O /ip.php) && grep -q "^X-LPanel-Front:" <<<"$h" && grep -q "^X-LPanel-Revision:" <<<"$h"'
        check "Apache sees the visitor's address, not nginx's" bash -c 'curl -s -H "Host: $O" "http://$ME/ip.php" | grep -qx "from $ME"'
        check "and a visitor cannot claim another one" bash -c 'curl -s -H "Host: $O" -H "X-Forwarded-For: 203.0.113.9" "http://$ME/ip.php" | grep -qx "from $ME"'
        check "Apache is not reachable from outside" bash -c '! curl -s -m 3 -o /dev/null "http://$ME:8080/"'
        check "a password still guards a static file in a protected folder" bash -c 'echo x > '"$OD"'/private/note.txt && chown shop1: '"$OD"'/private/note.txt && [ "$(get $O /private/note.txt)" = "401 " ]' ;;
    apache)
        check "PHP sees the visitor's address" bash -c 'curl -s -H "Host: $O" "http://$ME/ip.php" | grep -qx "from $ME"' ;;
esac

echo "== SSL/TLS (AutoSSL against Pebble)"
# Two Pebbles: one in Let's Encrypt's place, one that asks for an external
# account binding in ZeroSSL's. Both check names over HTTP on port 80, as
# the real ones do, and find them through /etc/hosts.
pebble_conf() { # file, port, management port, tls port, eab
    local eab=""
    [ "$5" = eab ] && eab='"externalAccountBindingRequired": true, "externalAccountMACKeys": {"kid-1": "zWNDZM6eQGHWpSRTPal5eIUYFTu7EajVIoguysqZ9wG44nMEtx3MUAsUDkMTQ12W"},'
    cat > "$1" <<JSON
{"pebble": {"listenAddress": "127.0.0.1:$2", "managementListenAddress": "127.0.0.1:$3",
  "certificate": "/e2e/pebble/cert.pem", "privateKey": "/e2e/pebble/key.pem",
  "httpPort": 80, "tlsPort": $4, "ocspResponderURL": "", $eab
  "profiles": {"default": {"description": "90 days", "validityPeriod": 7776000}}}}
JSON
}
chmod 0755 /e2e/pebble/pebble
pebble_conf /tmp/pebble-le.json 14000 15000 5001 none
pebble_conf /tmp/pebble-zs.json 14001 15001 5003 eab
for c in le zs; do
    PEBBLE_VA_NOSLEEP=1 PEBBLE_WFE_NONCEREJECT=0 PEBBLE_AUTHZREUSE=0 \
        nohup /e2e/pebble/pebble -config /tmp/pebble-$c.json > /tmp/pebble-$c.log 2>&1 &
done
for _ in $(seq 30); do curl -s --cacert /e2e/pebble/pebble.minica.pem -o /dev/null https://127.0.0.1:14000/dir && curl -s --cacert /e2e/pebble/pebble.minica.pem -o /dev/null https://127.0.0.1:14001/dir && break; sleep 0.5; done
acme() { # letsencrypt directory
    php83 -r '$f = "/etc/lpa/lpa.json"; $c = json_decode(file_get_contents($f), true);
        $c["acme"] = ["letsencrypt" => $argv[1], "zerossl" => "https://127.0.0.1:14001/dir", "roots" => "/e2e/pebble/pebble.minica.pem"];
        file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' "$1"
    systemctl restart lpa && for _ in $(seq 50); do [ -S /run/lpa/lpa.sock ] && break; sleep 0.2; done
}
acme https://127.0.0.1:14000/dir
# What points here: the main site, its www., the website with every feature
# and a subdomain; the alias and the reseller's account do not.
printf '127.0.0.1 shop.example.test www.shop.example.test other.example.test www.other.example.test blog.shop.example.test e2e-panel.test\n' >> /etc/hosts
lpanel ssl:settings --enabled=yes --host-autossl=yes >/dev/null
cat > /tmp/https.php <<'PHP'
<?php echo 'https=', $_SERVER['HTTPS'] ?? 'off';
PHP
put shop1 /tmp/https.php /home/shop1/public_html/https.php
sget()   { curl -sk -o /tmp/site.out -w '%{http_code} %{redirect_url}' --resolve "$1:443:127.0.0.1" "${@:3}" "https://$1$2"; }
issuer() { echo | openssl s_client -connect "127.0.0.1:${2:-443}" -servername "$1" 2>/dev/null | openssl x509 -noout -issuer -serial; }
ssl_row() { mariadb -uroot -N lpanel -e "select $2 from ssl_sites s join domains d on d.id = s.domain_id where d.name = '$1'"; }
export -f sget issuer ssl_row
t_autossl()   { lpanel ssl:autossl --wait > /tmp/autossl.out 2>&1; cat /tmp/autossl.out >&2
                # Saved first: grep -q would close the pipe before the command is done writing.
                lpanel ssl:list shop1 > /tmp/ssl.list; cat /tmp/ssl.list >&2
                grep -Eq '^shop\.example\.test +auto +[0-9-]{10} .* ok$' /tmp/ssl.list \
                && grep -Eq '^other\.example\.test +auto .* ok$' /tmp/ssl.list && grep -Eq '^blog\.shop\.example\.test +auto .* ok$' /tmp/ssl.list; }
t_https()     { [ "$(sget shop.example.test /)" = "200 " ] && grep -q "8.3 as shop1" /tmp/site.out && issuer shop.example.test | grep -q "Pebble Intermediate"; }
t_tohttps()   { [ "$(get shop.example.test /a?b=1)" = "301 https://shop.example.test/a?b=1" ]; }
t_php_https() { [ "$(sget shop.example.test /https.php)" = "200 " ] && grep -qx "https=on" /tmp/site.out \
                && lpanel ssl:options shop1 shop.example.test --force=no && [ "$(get shop.example.test /https.php)" = "200 " ] && grep -qx "https=off" /tmp/site.out; }
t_features()  { [ "$(sget other.example.test /old/page)" = "301 https://example.org/new/page" ] && [ "$(sget other.example.test /private/)" = "401 " ] \
                && [ "$(sget other.example.test /logo.png -e http://elsewhere.test/page)" = "403 " ] && [ "$(sget other.example.test /)" = "200 " ]; }
t_left_out()  { lpanel ssl:list shop1 > /tmp/ssl.list && grep -q "left out shop-alias.example.test: it has no DNS address" /tmp/ssl.list \
                && ssl_row shop.example.test names | grep -q "www.shop.example.test" && ! ssl_row shop.example.test names | grep -q "shop-alias"; }
t_backoff()   { lpanel ssl:list shop3 > /tmp/ssl.list && cat /tmp/ssl.list >&2 && grep -q "failed, next try" /tmp/ssl.list && local n; n=$(mariadb -uroot -N lpanel -e "select count(*) from ssl_orders o join domains d on d.id = o.domain_id where d.name = 'three.example.test'") \
                && lpanel ssl:autossl --wait >/dev/null && [ "$(mariadb -uroot -N lpanel -e "select count(*) from ssl_orders o join domains d on d.id = o.domain_id where d.name = 'three.example.test'")" = "$n" ] \
                && [ "$(ssl_row three.example.test failures)" = 1 ]; }
t_hostname()  { issuer e2e-panel.test 7443 | grep -q "Pebble Intermediate" && lpanel ssl:hostname > /tmp/ssl.host && grep -q "names     e2e-panel.test" /tmp/ssl.host && return
                # Diagnostics for a rare failure: why the hostname's issue failed.
                cat /tmp/ssl.host >&2; mariadb -uroot -N lpanel -e "select concat(action, ':', outcome, ' ', coalesce(details, '')) from audit_log where action like 'ssl.host%' order by id" >&2; return 1; }
t_default443() { case "$WEB" in
                     apache) [ "$(sget nobody.example.test /)" = "404 " ] && issuer nobody.example.test | grep -q "Pebble Intermediate" ;;
                     *) ! curl -sk -o /dev/null --resolve nobody.example.test:443:127.0.0.1 https://nobody.example.test/ ;;
                 esac; }
t_renew()     { local before; before=$(issuer shop.example.test | grep serial)
                mariadb -uroot lpanel -e "update ssl_sites s join domains d on d.id = s.domain_id set s.not_before = now() - interval 80 day, s.not_after = now() + interval 10 day where d.name = 'shop.example.test'"
                lpanel ssl:autossl --wait > /tmp/renew.out 2>&1; local after; after=$(issuer shop.example.test | grep serial)
                echo "$before -> $after" >&2; [ "$before" != "$after" ] || { cat /tmp/renew.out >&2; ssl_row shop.example.test "concat_ws(' ', cert_id, not_after, failures, retry_at, last_error)" >&2
                    ls -l --time-style=+%T /etc/pki/lpanel/sites/shop1/ >&2; mariadb -uroot -N lpanel -e "select id, kind, state, error, created_at, finished_at from ssl_orders order by id desc limit 3" >&2 || true; }
                [ -n "$after" ] && [ "$before" != "$after" ] && [ "$(sget shop.example.test /)" = "200 " ]; }
t_fallback()  { acme https://127.0.0.1:14999/dir \
                && lpanel ssl:settings --eab-kid kid-1 --eab-hmac zWNDZM6eQGHWpSRTPal5eIUYFTu7EajVIoguysqZ9wG44nMEtx3MUAsUDkMTQ12W > /tmp/ssl.settings \
                && grep -q "letsencrypt then zerossl" /tmp/ssl.settings \
                && lpanel ssl:issue shop1 blog.shop.example.test && [ "$(ssl_row blog.shop.example.test authority)" = zerossl ] \
                && [ "$(sget blog.shop.example.test /)" = "200 " ] && grep -q "8.3 as shop1" /tmp/site.out; }
# A certificate from an authority of the owner's own (not self-signed, so
# AutoSSL leaves it in place), given as key, leaf and issuer on stdin.
t_custom()    { openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=E2E Own CA" -keyout /tmp/ca.key -out /tmp/ca.crt 2>/dev/null \
                && openssl req -newkey rsa:2048 -nodes -subj "/CN=other.example.test" -keyout /tmp/own.key -out /tmp/own.csr 2>/dev/null \
                && printf 'subjectAltName=DNS:other.example.test,DNS:www.other.example.test\n' > /tmp/own.ext \
                && openssl x509 -req -in /tmp/own.csr -CA /tmp/ca.crt -CAkey /tmp/ca.key -CAcreateserial -days 30 -extfile /tmp/own.ext -out /tmp/own.crt 2>/dev/null \
                && cat /tmp/own.key /tmp/own.crt /tmp/ca.crt | lpanel ssl:install shop1 other.example.test \
                && issuer other.example.test | grep -q "CN *= *E2E Own CA" && [ "$(ssl_row other.example.test source)" = custom ] \
                && [ "$(stat -c '%a %U' /etc/pki/lpanel/sites/shop1)" = "700 root" ] \
                && [ "$(find /etc/pki/lpanel/sites/shop1 -name '*.key' ! -perm 600 | wc -l)" = 0 ]; }
t_csr()       { lpanel ssl:csr shop1 shop.example.test --country=BD --org="Shop Ltd" > /tmp/req.csr && openssl req -in /tmp/req.csr -noout -text | tee /dev/stderr \
                | grep -q "DNS:shop.example.test, DNS:www.shop.example.test" && ls /etc/pki/lpanel/sites/shop1/csr-*.key >/dev/null; }
t_ssl_audit() { local got; got=$(mariadb -uroot -N lpanel -e "select distinct concat(action, ':', outcome) from audit_log where action like 'ssl.%'" | tr '\n' ' '); echo "$got" >&2
                for want in ssl.settings:ok ssl.issue:ok ssl.host_issue:ok ssl.issue:failed ssl.options:ok ssl.install:ok ssl.csr:ok; do
                    grep -qw "$want" <<<"$got" || { echo "missing $want"; return 1; }
                done; }
check "AutoSSL gets every site that points here a certificate" t_autossl
check "the site answers over HTTPS with Pebble's certificate" t_https
check "plain HTTP goes to HTTPS" t_tohttps
check "PHP knows the visit came over HTTPS; HTTP stays HTTP when the owner says so" t_php_https
check "redirects, the password and hotlink protection hold over HTTPS" t_features
check "a name that does not point here is left out, and said why" t_left_out
check "an account whose names do not point here fails, and waits before trying again" t_backoff
check "the hostname gets a certificate, served on the panel's port" t_hostname
check "a name nobody serves gets no customer's certificate" t_default443
check "a certificate near its end is renewed and served" t_renew
check "ZeroSSL issues when Let's Encrypt cannot be reached" t_fallback
check "the owner's own certificate is installed; keys stay root's alone" t_custom
check "lpanel ssl:csr makes a request for every name of the site" t_csr
check "certificate changes are in the audit log" t_ssl_audit
acme https://127.0.0.1:14000/dir
# The checks after the switch below ask over plain HTTP.
lpanel ssl:options shop1 other.example.test --force=no >/dev/null

echo "== databases (MariaDB, phpMyAdmin)"
DBPW='Db-Pass-Word-77'
asapp() { mariadb -ushop1_app "-p$1" -N "${@:2}"; }   # password, mariadb args...
t_db_create()  { lpanel db:create shop1 store && [ "$(mariadb -uroot -N -e "show databases like 'shop1\_store'")" = shop1_store ]; }
t_db_user()    { echo "$DBPW" | lpanel db:user-create shop1 app --grant=store \
                 && asapp "$DBPW" shop1_store -e 'create table t (id int); insert into t values (1), (2)' && [ "$(asapp "$DBPW" shop1_store -e 'select count(*) from t')" = 2 ]; }
t_db_hash()    { [ "$(mariadb -uroot -N -e "select authentication_string from mysql.user where user='shop1_app' and host='localhost'")" = "$(mariadb -uroot -N lpanel -e "select hash from db_users where name='shop1_app'")" ] \
                 && ! grep -rq "$DBPW" /var/log/lpanel /usr/local/lpanel/system/Logs /var/lib/lpa 2>/dev/null && ! mariadb -uroot -N lpanel -e 'select * from db_users, audit_log' | grep -q "$DBPW"; }
t_db_alone()   { [ "$(asapp "$DBPW" -e 'show databases' | grep -v information_schema)" = shop1_store ] \
                 && ! asapp "$DBPW" -e 'create database shop1_sneaky' 2>/dev/null && ! asapp "$DBPW" -e 'select * from lpanel.accounts' 2>/dev/null; }
# A grant on shop1_store must not reach shop1xstore: _ is a wildcard unless escaped.
t_db_escape()  { mariadb -uroot -e 'create database shop1xstore' && ! asapp "$DBPW" shop1xstore -e 'select 1' 2>/dev/null; local r=$?; mariadb -uroot -e 'drop database shop1xstore'; return $r; }
t_db_php()     { printf '<?php $db = new mysqli("localhost", "shop1_app", "%s", "shop1_store"); echo "rows=", $db->query("select count(*) from t")->fetch_row()[0];' "$DBPW" > /tmp/db.php \
                 && put shop1 /tmp/db.php /home/shop1/public_html/db.php && [ "$(sget shop.example.test /db.php)" = "200 " ] && grep -q "rows=2" /tmp/site.out; }
t_db_select()  { lpanel db:grant shop1 app store --privileges=SELECT && asapp "$DBPW" shop1_store -e 'select * from t' >/dev/null && ! asapp "$DBPW" shop1_store -e 'insert into t values (3)' 2>/dev/null \
                 && lpanel db:grant shop1 app store && asapp "$DBPW" shop1_store -e 'insert into t values (3)'; }
t_db_password(){ echo 'New-Db-Pass-88' | lpanel db:user-password shop1 app && ! asapp "$DBPW" -e 'select 1' 2>/dev/null && asapp 'New-Db-Pass-88' -e 'select 1' >/dev/null; }
DBPW2='New-Db-Pass-88'
t_db_host()    { lpanel db:host-add shop1 203.0.113.9/24 --note=office && mariadb -uroot -N -e "select host from mysql.user where user='shop1_app'" | grep -qx '203.0.113.0/255.255.255.0' \
                 && lpanel db:host-remove shop1 203.0.113.0/24 && ! mariadb -uroot -N -e "select host from mysql.user where user='shop1_app'" | grep -q 203.0.113; }
t_db_rename()  { lpanel db:rename shop1 store shop && [ "$(asapp "$DBPW2" shop1_shop -e 'select count(*) from t')" = 3 ] && ! mariadb -uroot -N -e "show databases like 'shop1\_store'" | grep -q .; }
t_db_kill()    { (asapp "$DBPW2" -e 'select sleep(120)' >/dev/null 2>&1; echo done > /tmp/sleeper) & sleep 2
                 local id; id=$(lpanel db:info | awk '$2 == "shop1_app" { print $1 }' | head -1); [ -n "$id" ] || { lpanel db:info; return 1; }
                 lpanel db:kill "$id" && for _ in 1 2 3 4 5; do [ -f /tmp/sleeper ] && return 0; sleep 1; done; return 1; }
t_db_kill_root() { (mariadb -uroot -e 'select sleep(8)' >/dev/null 2>&1 &); sleep 1
                 local id; id=$(mariadb -uroot -N -e "select id from information_schema.processlist where info = 'select sleep(8)'" | head -1)
                 [ -n "$id" ] && ! lpanel db:kill "$id" 2>/dev/null && mariadb -uroot -N -e "select count(*) from information_schema.processlist where id = $id" | grep -qx 1; }
# 2.5 MB of data comes off the home's 3 MB quota, so a 1 MB file no longer fits.
t_db_quota()   { rm -f /home/shop1/tmp/q && runuser -u shop1 -- dd if=/dev/zero of=/home/shop1/tmp/q bs=1M count=1 2>/dev/null && rm -f /home/shop1/tmp/q \
                 && asapp "$DBPW2" shop1_shop -e "create table big (b longblob); insert into big values (repeat('x', 2600000))" \
                 && lpanel db:usage && [ "$(mariadb -uroot -N lpanel -e "select db_used_bytes from accounts where username='shop1'")" -gt 2500000 ] \
                 && ! runuser -u shop1 -- dd if=/dev/zero of=/home/shop1/tmp/q bs=1M count=1 2>/dev/null; local r=$?; rm -f /home/shop1/tmp/q; return $r; }
t_db_unquota() { asapp "$DBPW2" shop1_shop -e 'drop table big' && lpanel db:usage \
                 && runuser -u shop1 -- dd if=/dev/zero of=/home/shop1/tmp/q bs=1M count=1 2>/dev/null; local r=$?; rm -f /home/shop1/tmp/q; return $r; }
t_db_suspend() { lpanel db:create shop3 data && echo "$DBPW" | lpanel db:user-create shop3 app --grant=data \
                 && mariadb -ushop3_app "-p$DBPW" -e 'select 1' >/dev/null && lpanel accounts:suspend shop3 \
                 && ! mariadb -ushop3_app "-p$DBPW" -e 'select 1' 2>/dev/null && lpanel accounts:unsuspend shop3 && mariadb -ushop3_app "-p$DBPW" -e 'select 1' >/dev/null; }
pma_get() { curl -ks -b /tmp/jar2 -c /tmp/jar2 -o /tmp/pma.html -w '%{http_code} %{redirect_url}' "$@"; }
t_pma_sso()    { [ "$(signin_as 7444 /panel shop1 Owner-Pass-Word-1)" = "303 https://127.0.0.1:7444/panel" ] || return 1
                 curl -ks -b /tmp/jar2 -c /tmp/jar2 https://127.0.0.1:7444/panel/databases -o /tmp/dbpage.html
                 local loc; loc=$(curl -ks -b /tmp/jar2 -c /tmp/jar2 -o /dev/null -w '%{redirect_url}' --data-urlencode "_token=$(token /tmp/dbpage.html)" https://127.0.0.1:7444/panel/databases/phpmyadmin)
                 echo "sign-on: $loc"; [[ "$loc" == https://127.0.0.1:7444/phpmyadmin/lpanel-signon.php\?ticket=* ]] || return 1
                 echo "$loc" > /tmp/ticket.url
                 [ "$(pma_get "$loc")" = "303 https://127.0.0.1:7444/phpmyadmin/index.php?route=/" ] || return 1
                 pma_get 'https://127.0.0.1:7444/phpmyadmin/index.php?route=/' >/dev/null
                 grep -q 'shop1_shop' /tmp/pma.html && ! grep -q 'name="pma_username"' /tmp/pma.html || { grep -o '<title>[^<]*' /tmp/pma.html; return 1; }; }
t_pma_once()   { [ "$(pma_get "$(cat /tmp/ticket.url)" | cut -d' ' -f2)" = https://127.0.0.1:7444/panel/databases ]; }
t_pma_only()   { pma_get 'https://127.0.0.1:7444/phpmyadmin/index.php?route=/server/databases' >/dev/null; ! grep -q 'shop3_data' /tmp/pma.html && ! grep -q '>lpanel<' /tmp/pma.html; }
t_pma_closed() { [ "$(status https://127.0.0.1:7444/phpmyadmin/config.inc.php)" = 404 ] && [ "$(status https://127.0.0.1:7444/phpmyadmin/setup/)" = 404 ] \
                 && [ "$(status https://127.0.0.1:7443/phpmyadmin/)" != 200 ] && [ "$(curl -ks -o /dev/null -w '%{redirect_url}' https://127.0.0.1:7444/phpmyadmin/index.php)" = https://127.0.0.1:7444/panel/databases ]; }
t_pma_apart()  { [ "$(stat -c '%a %U %G' /var/lib/lpanel-pma/tickets)" = "2770 lpanel lpanel-pma" ] && ! runuser -u lpanel-pma -- cat /usr/local/lpanel/.env 2>/dev/null \
                 && ! runuser -u lpanel -- cat /usr/local/lpanel-pma/phpmyadmin/config.inc.php 2>/dev/null && ps -o user:20= -C php-fpm | grep -qx lpanel-pma; }
t_db_page()    { curl -ks -b /tmp/jar2 -o /tmp/dbp1.html https://127.0.0.1:7444/panel/databases && curl -ks -b $jar -o /tmp/dbp2.html https://127.0.0.1:7443/admin/databases \
                 && grep -q 'shop1_app' /tmp/dbp1.html && grep -q 'shop1_shop' /tmp/dbp2.html; }
t_db_drop()    { lpanel db:user-drop shop1 app && lpanel db:drop shop1 shop && ! mariadb -uroot -N -e "show databases like 'shop1\_%'" | grep -q . \
                 && ! mariadb -uroot -N -e "select user from mysql.user where user like 'shop1\_%'" | grep -q .; }
t_db_terminate() { echo "Owner-Pass-Word-6" | lpanel accounts:create shop6 six.example.test Starter >/dev/null && lpanel db:create shop6 gone && echo "$DBPW" | lpanel db:user-create shop6 app --grant=gone \
                 && lpanel accounts:terminate shop6 --yes                  && for _ in $(seq 60); do getent passwd shop6 >/dev/null || break; lpanel accounts:settle >/dev/null; sleep 2; done                  && ! getent passwd shop6 && lpanel accounts:settle >/dev/null && [ "$(mariadb -uroot -N lpanel -e "select count(*) from db_databases d join accounts a on a.id = d.account_id where a.username = 'shop6'")" = 0 ]                  && ! mariadb -uroot -N -e "show databases like 'shop6\_%'" | grep -q . && ! mariadb -uroot -N -e "select user from mysql.user where user like 'shop6\_%' or user = 'lpsso-shop6'" | grep -q .; }
t_db_audit()   { local got; got=$(mariadb -uroot -N lpanel -e "select distinct concat(action, ':', outcome) from audit_log where action like 'database.%'" | tr '\n' ' '); echo "$got" >&2
                 for want in database.create:ok database.user_create:ok database.grant:ok database.user_password:ok database.host_add:ok database.host_remove:ok \
                             database.rename:ok database.kill:ok database.phpmyadmin:ok database.user_drop:ok database.drop:ok; do
                     grep -qw "$want" <<<"$got" || { echo "missing $want"; return 1; }
                 done; }
export -f asapp
check "lpanel db:create makes the database, with the account's prefix" t_db_create
check "a database user signs in with its password and uses its database" t_db_user
check "only MariaDB's hash is kept; the password reached no log" t_db_hash
check "the user sees its own database only, and cannot make others" t_db_alone
check "a grant on shop1_store does not reach shop1xstore" t_db_escape
check "the site's PHP reaches its database" t_db_php
check "privileges narrow to SELECT, and back to all" t_db_select
check "a new password replaces the old one" t_db_password
check "a remote network becomes MariaDB's address/netmask, and goes again" t_db_host
check "a rename moves the tables, and the user keeps its privileges" t_db_rename
check "an account's running query is ended from the panel" t_db_kill
check "root's and MariaDB's own connections cannot be" t_db_kill_root
check "database space comes off the home's quota" t_db_quota
check "and goes back when the data goes" t_db_unquota
check "suspension locks the account's database users, and unsuspending unlocks them" t_db_suspend
check "phpMyAdmin opens signed in, from the User panel" t_pma_sso
check "a sign-on ticket works once" t_pma_once
check "phpMyAdmin shows the account's databases only" t_pma_only
check "phpMyAdmin's config and setup are not served, nor is it on the Admin panel's port" t_pma_closed
check "phpMyAdmin runs as its own user, apart from the panel" t_pma_apart
check "both panels list the databases" t_db_page
check "dropping removes the database and the user" t_db_drop
check "terminating an account drops its databases and users" t_db_terminate
check "database changes are in the audit log" t_db_audit

echo "== DNS (PowerDNS)"
IP4=$(lpanel dns:settings | awk '$1 == "IPv4" { print $3 }')
q() { dig @127.0.0.1 +short +norecurse "$@" 2>&1; }     # name [type]
zid() { mariadb -uroot -N lpanel -e "select r.id from dns_records r join dns_zones z on z.id = r.zone_id where z.name = '$1' and r.name = '$2' and r.type = '$3' limit 1"; }
t_pdns_up()     { local why=""
                  systemctl is-active --quiet pdns || why="$why not-active($(systemctl is-active pdns));"
                  ss -ltnp | grep -q '127.0.0.1:8081' || why="$why not-listening;"
                  ! ss -ltn | grep -qE '(0\.0\.0\.0|\*|\[::\]):8081 ' || why="$why listens-everywhere;"
                  [ "$(stat -c '%a %U' /etc/lpa/pdns.key)" = "600 root" ] || why="$why key-mode($(stat -c '%a %U' /etc/lpa/pdns.key));"
                  [ "$(stat -c '%a %U %G' /etc/pdns/pdns.conf)" = "640 root pdns" ] || why="$why conf-mode($(stat -c '%a %U %G' /etc/pdns/pdns.conf));"
                  [ -z "$why" ] || { echo "pdns:$why"; systemctl status pdns --no-pager | head -12 | cut -c1-160; journalctl -u pdns --no-pager | tail -6 | cut -c1-200; ss -ltnp | grep 8081; return 1; }; }
t_api_key()     { [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8081/api/v1/servers/localhost/zones)" = 401 ] && lpanel dns:info | grep -q 'Running *yes'; }
t_zone_made()   { [ -n "$IP4" ] && [ "$(q shop.example.test A)" = "$IP4" ] && [ "$(q www.shop.example.test A)" = "$IP4" ] \
                  && q shop.example.test MX | grep -qx '10 mail.shop.example.test.' && q shop.example.test TXT | grep -q 'v=spf1 a mx ~all' \
                  && q shop.example.test NS | grep -qx 'ns1.shop.example.test.'; }
t_sub_addon()   { [ "$(q blog.shop.example.test A)" = "$IP4" ] && q other.example.test SOA | grep -q '^ns1.other.example.test. hostmaster.other.example.test. 20'; }
t_dns_add()     { lpanel dns:add shop.example.test api A 192.0.2.44 --ttl=300 && [ "$(q api.shop.example.test A)" = 192.0.2.44 ] \
                  && dig @127.0.0.1 +norecurse api.shop.example.test A | grep -qE '^api\.shop\.example\.test\.\s+300\s'; }
t_dns_txt()     { lpanel dns:add shop.example.test evil TXT 'a" "v=spf1 +all' && [ "$(q evil.shop.example.test TXT)" = '"a\" \"v=spf1 +all"' ]; }
t_dns_refused() { ! lpanel dns:add shop.example.test x A 999.1.1.1 2>/dev/null && ! lpanel dns:add shop.example.test api CNAME other.test 2>/dev/null \
                  && ! lpanel dns:add shop.example.test @ NS ns.evil.test 2>/dev/null && [ -z "$(q x.shop.example.test A)" ]; }
t_dns_set()     { lpanel dns:set shop.example.test "$(zid shop.example.test api A)" --content=192.0.2.45 && [ "$(q api.shop.example.test A)" = 192.0.2.45 ] \
                  && lpanel dns:remove shop.example.test "$(zid shop.example.test api A)" && [ -z "$(q api.shop.example.test A)" ]; }
t_serial()      { local a b; a=$(q shop.example.test SOA | awk '{print $3}'); lpanel dns:add shop.example.test tick TXT tock >/dev/null; b=$(q shop.example.test SOA | awk '{print $3}'); [ "$b" -gt "$a" ]; }
t_owner_page()  { local got; got=$(signin_as 7444 /panel shop1 Owner-Pass-Word-1); echo "sign-in: $got"; [ "$got" = "303 https://127.0.0.1:7444/panel" ] \
                  && curl -ks -b /tmp/jar2 https://127.0.0.1:7444/panel/dns -o /tmp/dnslist.html -w 'list: %{http_code}\n' && grep -q 'other.example.test' /tmp/dnslist.html \
                  && curl -ks -b /tmp/jar2 "https://127.0.0.1:7444/panel/dns/$(mariadb -uroot -N lpanel -e "select id from dns_zones where name='shop.example.test'")" -o /tmp/dnszone.html -w 'zone: %{http_code}\n' \
                  && { grep -q 'v=spf1 a mx ~all' /tmp/dnszone.html || { grep -o 'v=spf1[^<]*' /tmp/dnszone.html; grep -o '<title>[^<]*' /tmp/dnszone.html; false; }; }; }
t_owner_add()   { local id page tok; id=$(mariadb -uroot -N lpanel -e "select id from dns_zones where name='shop.example.test'")
                  curl -ks -b /tmp/jar2 -c /tmp/jar2 "https://127.0.0.1:7444/panel/dns/$id" -o /tmp/zone.html
                  curl -ks -b /tmp/jar2 -c /tmp/jar2 -o /dev/null --data-urlencode "_token=$(token /tmp/zone.html)" -d name=owner -d type=A -d content=192.0.2.66 -d ttl=300 "https://127.0.0.1:7444/panel/dns/$id/records"
                  [ "$(q owner.shop.example.test A)" = 192.0.2.66 ]; }
t_server_ns()   { lpanel dns:settings --ns ns1.e2e-panel.test,ns2.e2e-panel.test >/dev/null && q shop.example.test NS | sort | tr '\n' ' ' | grep -qx 'ns1.e2e-panel.test. ns2.e2e-panel.test. ' \
                  && q shop.example.test SOA | grep -q '^ns1.e2e-panel.test. ' && q other.example.test NS | grep -qx 'ns1.e2e-panel.test.'; }
t_glue()        { lpanel dns:zone-create e2e-panel.test && [ "$(q ns1.e2e-panel.test A)" = "$IP4" ] && [ "$(q ns2.e2e-panel.test A)" = "$IP4" ]; }
t_reseller_ns() { lpanel dns:nameservers rex --ns ns1.rex.test,ns2.rex.test >/dev/null && q three.example.test NS | sort | tr '\n' ' ' | grep -qx 'ns1.rex.test. ns2.rex.test. ' \
                  && q shop.example.test NS | grep -qx 'ns1.e2e-panel.test.'; }
t_reverse()     { lpanel dns:zone-create 113.0.203.in-addr.arpa && lpanel dns:add 113.0.203.in-addr.arpa 10 PTR server.e2e-panel.test >/dev/null \
                  && [ "$(dig @127.0.0.1 +short +norecurse -x 203.0.113.10)" = server.e2e-panel.test. ]; }
t_reset()       { lpanel dns:reset shop.example.test --yes && [ -z "$(q evil.shop.example.test TXT)" ] && [ -z "$(q owner.shop.example.test A)" ] && [ "$(q www.shop.example.test A)" = "$IP4" ]; }
t_zone_gone()   { lpanel domains:add shop1 dnsonly.example.test >/dev/null && q dnsonly.example.test SOA | grep -q hostmaster \
                  && lpanel domains:remove shop1 dnsonly.example.test >/dev/null && dig @127.0.0.1 +norecurse dnsonly.example.test SOA | grep -q 'status: REFUSED'; }
t_isolated()    { ! curl -s -H "X-API-Key: $(cat /etc/lpa/pdns.key)" -o /dev/null --max-time 3 "http://$IP4:8081/api/v1/servers" 2>/dev/null; }
t_terminated()  { echo "Owner-Pass-Word-7" | lpanel accounts:create shop7 seven.example.test Starter >/dev/null && q seven.example.test SOA | grep -q hostmaster \
                  && lpanel accounts:terminate shop7 --yes >/dev/null \
                  && for _ in $(seq 60); do getent passwd shop7 >/dev/null || break; lpanel accounts:settle >/dev/null; sleep 2; done \
                  && lpanel accounts:settle >/dev/null && dig @127.0.0.1 +norecurse seven.example.test SOA | grep -q 'status: REFUSED' \
                  && [ "$(mariadb -uroot -N lpanel -e "select count(*) from dns_zones where name = 'seven.example.test'")" = 0 ]; }
# A wildcard certificate is only checked through DNS (DNS-01). A third
# Pebble asks this server's PowerDNS, as a real authority would ask the
# domain's nameservers; the agent's own lookups go there too.
pebble_conf /tmp/pebble-dns.json 14002 15002 5005 none
nohup /e2e/pebble/pebble -config /tmp/pebble-dns.json -dnsserver 127.0.0.1:53 > /tmp/pebble-dns.log 2>&1 &
for _ in $(seq 30); do curl -s --cacert /e2e/pebble/pebble.minica.pem -o /dev/null https://127.0.0.1:14002/dir && break; sleep 0.5; done
php83 -r '$f = "/etc/lpa/lpa.json"; $c = json_decode(file_get_contents($f), true); $c["dns"]["resolver"] = "127.0.0.1:53";
    file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
acme https://127.0.0.1:14002/dir
t_wildcard()    { lpanel domains:set shop1 shop.example.test --wildcard=yes >/dev/null && lpanel ssl:issue shop1 shop.example.test \
                  && ssl_row shop.example.test names | grep -q '\*.shop.example.test' && echo | openssl s_client -connect 127.0.0.1:443 -servername anything.shop.example.test 2>/dev/null \
                  | openssl x509 -noout -ext subjectAltName | grep -q 'DNS:\*.shop.example.test'; }
t_challenge_gone() { [ -z "$(q _acme-challenge.shop.example.test TXT)" ] && ! mariadb -uroot -N pdns -e "select name from records where name like '_lpanel-%' or name like '_acme-challenge.%'" | grep -q .; }
t_dns_audit()   { local got; got=$(mariadb -uroot -N lpanel -e "select distinct concat(action, ':', outcome) from audit_log where action like 'dns.%'" | tr '\n' ' '); echo "$got" >&2
                  for want in dns.settings:ok dns.nameservers:ok dns.record_add:ok dns.record_change:ok dns.record_remove:ok dns.zone_reset:ok dns.zone_create:ok; do
                      grep -qw "$want" <<<"$got" || { echo "missing $want"; return 1; }
                  done; }
check "PowerDNS runs, its API only on 127.0.0.1, its key root's alone" t_pdns_up
check "the API refuses anyone without the key" t_api_key
check "an account's zone is made from the template, and answers" t_zone_made
check "a subdomain's address is in its zone; an addon domain has its own" t_sub_addon
check "lpanel dns:add: a record answers at once, with its TTL" t_dns_add
check "a quote in TXT text stays inside its record" t_dns_txt
check "a bad address, a CNAME beside others and the zone's own NS are refused" t_dns_refused
check "lpanel dns:set and dns:remove" t_dns_set
check "every change raises the serial" t_serial
check "the owner sees the zones and records" t_owner_page
check "the owner adds a record from the User panel" t_owner_add
check "the server's nameservers go into every zone, and its SOA" t_server_ns
check "the nameservers' own zone gets their addresses (glue)" t_glue
check "a reseller's nameservers go into their accounts' zones only" t_reseller_ns
check "a reverse zone answers PTR lookups" t_reverse
check "lpanel dns:reset puts a zone back to its template" t_reset
check "a removed domain takes its zone with it" t_zone_gone
check "PowerDNS's API is not reachable from outside" t_isolated
check "terminating an account removes its zones" t_terminated
check "a wildcard certificate comes through a DNS check, and serves any subdomain" t_wildcard
check "the check's records are gone afterwards" t_challenge_gone
check "DNS changes are in the audit log" t_dns_audit
acme https://127.0.0.1:14000/dir
lpanel domains:set shop1 shop.example.test --wildcard=no >/dev/null

# Each stack switches to another, and every site moves with it.
echo "== mail (Exim, Dovecot)"
# The mirrors now and then fail for a minute: try again before every mail check fails for it.
for _try in 1 2 3 4 5 6; do
    dnf -y -q install swaks python3 >/tmp/dnf-swaks.log 2>&1 && command -v swaks >/dev/null && break
    sleep 15
done
# The check of a DKIM signature uses dkimpy: a package on 9 and 10, from PyPI on 8.
python3 -c 'import dkim' 2>/dev/null || dnf -y -q install python3-dkimpy >>/tmp/dnf-swaks.log 2>&1 || pip3 install -q dkimpy >>/tmp/dnf-swaks.log 2>&1
command -v swaks >/dev/null || { echo "swaks could not be installed:"; tail -5 /tmp/dnf-swaks.log; }
MIP=$(hostname -I | tr ' ' '\n' | grep -m1 -E '^[0-9]+(\.[0-9]+){3}$')   # this machine's own address: not a relay host
MPW='Mail-Pass-Word-1'
# Outgoing mail goes to a catcher on this machine (the relay host), which
# keeps each message whole in /tmp/caught.
mkdir -p /tmp/caught
cat > /tmp/catcher.py <<'PY'
import socket, os
s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); s.bind(("127.0.0.1", 2526)); s.listen(5)
n = 0
while True:
    c, _ = s.accept(); f = c.makefile("rwb"); sender = ""; rcpts = []
    def out(x): f.write(x.encode() + b"\r\n"); f.flush()
    out("220 catcher")
    while True:
        line = f.readline()
        if not line: break
        u = line.decode(errors="replace").strip().upper()
        if u.startswith("EHLO") or u.startswith("HELO"): out("250 catcher")
        elif u.startswith("MAIL FROM:"): sender = line.decode(errors="replace").strip()[10:]; rcpts = []; out("250 OK")
        elif u.startswith("RCPT TO:"): rcpts.append(line.decode(errors="replace").strip()[8:]); out("250 OK")
        elif u == "DATA":
            out("354 go"); buf = b""
            while True:
                l = f.readline()
                if l in (b".\r\n", b".\n", b""): break
                buf += l[1:] if l.startswith(b"..") else l
            n += 1; open("/tmp/caught/%03d.eml" % n, "wb").write(b"X-Envelope-From: " + sender.encode() + b"\r\nX-Envelope-To: " + " ".join(rcpts).encode() + b"\r\n" + buf); out("250 OK")
        elif u == "QUIT": out("221 bye"); break
        else: out("250 OK")
    c.close()
PY
nohup python3 /tmp/catcher.py >/tmp/catcher.log 2>&1 &
cat > /tmp/imap.py <<'PY'
import imaplib, poplib, ssl, sys
ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
proto, host, user, pw = sys.argv[1:5]
try:
    if proto == "imaps":
        c = imaplib.IMAP4_SSL(host, 993, ssl_context=ctx); c.login(user, pw); print(c.status("INBOX", "(MESSAGES)")[1][0].decode()); c.logout()
    elif proto == "imap":
        c = imaplib.IMAP4(host, 143); c.login(user, pw); print("logged in without TLS"); c.logout()
    elif proto == "imap-starttls":
        c = imaplib.IMAP4(host, 143); c.starttls(ssl_context=ctx); c.login(user, pw); print("logged in after STARTTLS"); c.logout()
    elif proto == "pop3s":
        p = poplib.POP3_SSL(host, 995, context=ctx); p.user(user); p.pass_(pw); print("messages", p.stat()[0]); p.quit()
except Exception as e:
    print("refused:", e); sys.exit(1)
PY
cat > /tmp/dkim-check.py <<'PY'
import dkim, subprocess, sys
def dns(name, timeout=5):
    out = subprocess.run(["dig", "@127.0.0.1", "+short", "+norecurse", "TXT", name.decode().rstrip(".")], stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True).stdout
    return "".join(part for line in out.splitlines() for part in line.split('"')[1::2]).encode()
msg = open(sys.argv[1], "rb").read()
sys.exit(0 if dkim.verify(msg, dnsfunc=dns) else 1)
PY
sw() { swaks --helo e2e-client.test --timeout 20 "$@" > /tmp/swaks.out 2>&1; local c=$?; grep -E '^ *<[~*-]* *[0-9]{3}[ -]' /tmp/swaks.out | tail -4 >&2; return $c; }
refused() { ! sw "$@" && grep -qE "^ *<[~*]* *$WANT" /tmp/swaks.out; }   # WANT: the refusal's code and words
caught() { grep -l "$1" /tmp/caught/*.eml 2>/dev/null | tail -1; }
caught_to() { grep -l "$1" /tmp/caught/*.eml 2>/dev/null | xargs -r grep -l "^X-Envelope-To: .*<$2>" | tail -1; }   # a message, and who it went to
waitfor() { for _ in $(seq 120); do eval "$1" && return 0; sleep 0.5; done; eval "$1"; }
maildir() { echo "/home/$1/mail/$2/$3/Maildir"; }
count_new() { find "$(maildir "$1" "$2" "$3")"/new "$(maildir "$1" "$2" "$3")"/cur -type f 2>/dev/null | wc -l; }
lpanel packages:create Mailer --disk=50 >/dev/null
echo "Owner-Pass-Word-9" | lpanel accounts:create shop9 nine.example.test Mailer >/dev/null
lpanel mail:settings --smarthost 127.0.0.1:2526 >/dev/null
export MIP MPW WANT
export -f sw refused caught caught_to waitfor maildir count_new

t_mail_up()     { systemctl is-active --quiet exim dovecot && for p in 25 465 587 143 993 110 995; do ss -ltn | grep -qE "[:.]$p " || { echo "nothing on $p"; return 1; }; done \
                  && lpanel mail:info > /tmp/mi.out && grep -qE 'Exim +running 4\.' /tmp/mi.out && grep -qE 'Dovecot +running 2\.' /tmp/mi.out; }
t_mail_files()  { stat -c '%a %U %G %n' /etc/pki/lpanel-mail/host.key /etc/exim/lpanel/dkim /etc/dovecot/lpanel/passwd.d; grep mail /etc/lpa/lpa.json
                  openssl x509 -in /etc/pki/lpanel-mail/host.crt -noout -serial; openssl x509 -in /etc/lpanel/tls/panel.crt -noout -serial; echo | openssl s_client -connect 127.0.0.1:993 2>/dev/null | openssl x509 -noout -serial
                  [ "$(stat -c '%a %U %G' /etc/pki/lpanel-mail/host.key)" = "640 root exim" ] && [ "$(stat -c '%a %U %G' /etc/exim/lpanel/dkim)" = "750 root exim" ] \
                  && [ "$(stat -c '%a %U %G' /etc/dovecot/lpanel/passwd.d)" = "750 root dovecot" ] && grep -q '"mail": "on"' /etc/lpa/lpa.json \
                  && [ "$(openssl x509 -in /etc/pki/lpanel-mail/host.crt -noout -serial)" = "$(openssl x509 -in /etc/lpanel/tls/panel.crt -noout -serial)" ] \
                  && echo | openssl s_client -connect 127.0.0.1:993 2>/dev/null | openssl x509 -noout -serial | grep -qx "$(openssl x509 -in /etc/lpanel/tls/panel.crt -noout -serial)"; }
t_mail_domains() { grep -qx 'owner: shop9' /etc/exim/lpanel/owners/nine.example.test && grep -qx 'owner: shop1' /etc/exim/lpanel/owners/other.example.test \
                  && grep -qx 'owner: shop1' /etc/exim/lpanel/owners/blog.shop.example.test && [ "$(stat -c '%a %U %G' /etc/exim/lpanel/dkim/nine.example.test)" = "640 root exim" ]; }
t_mail_dns()    { local want; want=$(openssl pkey -in /etc/exim/lpanel/dkim/nine.example.test -pubout -outform DER | base64 -w0)
                  dig @127.0.0.1 +short +norecurse TXT default._domainkey.nine.example.test | tr -d '" \n' | grep -q "p=$want" \
                  && [ "$(dig @127.0.0.1 +short +norecurse TXT _dmarc.nine.example.test)" = '"v=DMARC1; p=none"' ] \
                  && dig @127.0.0.1 +short +norecurse TXT blog.shop.example.test | grep -q 'v=spf1 a mx ~all'; }
t_mail_create() { echo "$MPW" | lpanel mail:create shop9 info@nine.example.test --quota=1 && echo "$MPW" | lpanel mail:create shop9 bob@nine.example.test --quota=0 \
                  && echo "$MPW" | lpanel mail:create shop3 sender@three.example.test \
                  && grep -q '^info:{BLF-CRYPT}\$2y\$' /etc/dovecot/lpanel/passwd.d/nine.example.test && ! grep -rq "$MPW" /etc/dovecot/lpanel /etc/exim/lpanel \
                  && [ "$(stat -c '%a %U %G' /etc/dovecot/lpanel/passwd.d/nine.example.test)" = "640 root dovecot" ] && grep -qx 'info: shop9' /etc/exim/lpanel/mailboxes/nine.example.test; }
t_mail_in()     { sw -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "hello info" \
                  && waitfor '[ "$(count_new shop9 nine.example.test info)" = 1 ]' && grep -rq "hello info" "$(maildir shop9 nine.example.test info)" \
                  && [ "$(stat -c %U "$(maildir shop9 nine.example.test info)")" = shop9 ]; }
t_mail_imap()   { python3 /tmp/imap.py imaps 127.0.0.1 info@nine.example.test "$MPW" | grep -q 'MESSAGES 1' && python3 /tmp/imap.py pop3s 127.0.0.1 INFO@Nine.Example.Test "$MPW" | grep -q 'messages 1' \
                  && ! python3 /tmp/imap.py imaps 127.0.0.1 info@nine.example.test wrong-password && ! python3 /tmp/imap.py imaps 127.0.0.1 shop9 Owner-Pass-Word-9 \
                  && ! python3 /tmp/imap.py imaps 127.0.0.1 root "$ROOT_PASSWORD"; }
# Dovecot counts a connection from the machine's own address as secure, so
# its refusal of passwords before TLS is checked in its configuration.
t_mail_plain()  { doveconf -h disable_plaintext_auth | grep -qx yes && doveconf -h ssl | grep -qx required && python3 /tmp/imap.py imap-starttls "$MIP" info@nine.example.test "$MPW" \
                  && ! swaks --helo x -s "$MIP" -p 587 --quit-after EHLO 2>&1 | grep -q '250-AUTH'; }
t_mail_out()    { sw -s "$MIP" -p 587 -tls --auth PLAIN --auth-user info@nine.example.test --auth-password "$MPW" --from info@nine.example.test --to someone@example.net --h-From '"Info" <info@nine.example.test>' --h-Subject "out 587" \
                  && sw -s "$MIP" -p 465 -tlsc --auth LOGIN --auth-user bob@nine.example.test --auth-password "$MPW" --from bob@nine.example.test --to someone@example.net --h-Subject "out 465" \
                  && waitfor '[ -n "$(caught "out 587")" ] && [ -n "$(caught "out 465")" ]'; }
t_mail_dkim()   { python3 /tmp/dkim-check.py "$(caught 'out 587')" && grep -q 'd=nine.example.test; s=default' "$(caught 'out 587')"; }
t_mail_site()   { su -s /bin/bash shop9 -c 'printf "From: info@nine.example.test\nTo: a@example.net\nSubject: from php\n\nhi\n" | /usr/sbin/sendmail -t' \
                  && su -s /bin/bash shop3 -c 'printf "From: info@nine.example.test\nTo: a@example.net\nSubject: forged by shop3\n\nhi\n" | /usr/sbin/sendmail -t' \
                  && waitfor '[ -n "$(caught "from php")" ] && [ -n "$(caught "forged by shop3")" ]' \
                  && python3 /tmp/dkim-check.py "$(caught 'from php')" && ! grep -q '^DKIM-Signature' "$(caught 'forged by shop3')"; }
t_mail_noauth() { WANT='550 Log in' refused -s "$MIP" -p 587 -tls --from info@nine.example.test --to someone@example.net \
                  && WANT='550 relay not permitted' refused -s "$MIP" -p 25 --from sender@three.example.test --to someone@example.net \
                  && WANT='535' refused -s "$MIP" -p 587 -tls --auth PLAIN --auth-user info@nine.example.test --auth-password wrong-password --from info@nine.example.test --to someone@example.net; }
t_mail_spoof()  { WANT='550 sender@three.example.test is not an address of your account' refused -s "$MIP" -p 587 -tls --auth PLAIN --auth-user info@nine.example.test --auth-password "$MPW" --from sender@three.example.test --to someone@example.net \
                  && WANT='550 The From: address belongs to another account' refused -s "$MIP" -p 587 -tls --auth PLAIN --auth-user info@nine.example.test --auth-password "$MPW" --from info@nine.example.test --h-From sender@three.example.test --to someone@example.net; }
t_mail_unknown() { WANT='550' refused -s "$MIP" -p 25 --from sender@three.example.test --to nobody@nine.example.test \
                  && lpanel mail:domain shop9 nine.example.test --catchall=bob@nine.example.test >/dev/null && sw -s "$MIP" -p 25 --from sender@three.example.test --to nobody@nine.example.test --h-Subject "to anyone" \
                  && waitfor 'grep -rq "to anyone" "$(maildir shop9 nine.example.test bob)"' && lpanel mail:domain shop9 nine.example.test --catchall=refuse >/dev/null; }
t_mail_plus()   { sw -s "$MIP" -p 25 --from sender@three.example.test --to info+news@nine.example.test --h-Subject "tagged" && waitfor 'grep -rq "tagged" "$(maildir shop9 nine.example.test info)"'; }
t_mail_forward() { lpanel mail:forward shop9 info@nine.example.test boss@example.net >/dev/null && lpanel mail:forward shop9 sales@nine.example.test bob@nine.example.test >/dev/null \
                  && sw -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "copy please" \
                  && sw -s "$MIP" -p 25 --from sender@three.example.test --to sales@nine.example.test --h-Subject "for sales" \
                  && waitfor 'grep -rq "copy please" "$(maildir shop9 nine.example.test info)" && [ -n "$(caught "copy please")" ] && grep -rq "for sales" "$(maildir shop9 nine.example.test bob)"' \
                  && lpanel mail:unforward shop9 info@nine.example.test boss@example.net >/dev/null; }
t_mail_reply()  { printf 'Back on Monday.\nInfo' | lpanel mail:responder shop9 info@nine.example.test --subject="Away now" --days=2 >/dev/null \
                  && [ -s /etc/dovecot/lpanel/sieve/nine.example.test/info.svbin ] && [ "$(stat -c '%a %U %G' /etc/dovecot/lpanel/sieve/nine.example.test/info.sieve)" = "640 root shop9" ] \
                  && sw -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "are you there" \
                  && waitfor 'grep -rlq "Away now" "$(maildir shop3 three.example.test sender)"' \
                  && sw -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "still there" && sleep 3 \
                  && [ "$(grep -l "Away now" "$(maildir shop3 three.example.test sender)"/new/* "$(maildir shop3 three.example.test sender)"/cur/* 2>/dev/null | wc -l)" = 1 ] && lpanel mail:responder shop9 info@nine.example.test --off >/dev/null \
                  && ! grep -qs 'vacation :days' /etc/dovecot/lpanel/sieve/nine.example.test/info.sieve; }
t_mail_full()   { local before; before=$(count_new shop9 nine.example.test info); head -c 1500000 /dev/urandom | base64 > /tmp/big.txt \
                  && sw -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --attach @/tmp/big.txt --h-Subject "too big" \
                  && waitfor 'grep -q "info@nine.example.test.*Quota exceeded\|info@nine.example.test.*Mailbox is full\|info@nine.example.test.*quota" /var/log/exim/main.log' \
                  && [ "$(count_new shop9 nine.example.test info)" = "$before" ]; }
t_mail_usage()  { (cd /usr/local/lpanel && runuser -u lpanel -- env APP_DEBUG=1 php83 laika mail:usage) && [ "$(mariadb -uroot -N lpanel -e "select used_bytes > 0 from mail_boxes b join mail_domains d on d.id = b.mail_domain_id where d.name = 'nine.example.test' and b.local = 'info'")" = 1 ] \
                  && lpanel mail:list shop9 > /tmp/ml.out && grep -qE '^info@nine.example.test +[0-9.]+ (B|KB|MB)' /tmp/ml.out; }
t_mail_suspend() { lpanel accounts:suspend shop9 >/dev/null && grep -qx 'suspended: yes' /etc/exim/lpanel/owners/nine.example.test && sleep 1.2 \
                  && ! python3 /tmp/imap.py imaps 127.0.0.1 info@nine.example.test "$MPW" \
                  && WANT='550 This account is suspended' refused -s "$MIP" -p 587 -tls --auth PLAIN --auth-user info@nine.example.test --auth-password "$MPW" --from info@nine.example.test --to someone@example.net \
                  && sw -s "$MIP" -p 25 --from sender@three.example.test --to bob@nine.example.test --h-Subject "while away" && waitfor 'grep -rq "while away" "$(maildir shop9 nine.example.test bob)"' \
                  && lpanel accounts:unsuspend shop9 >/dev/null && sleep 1.2 && python3 /tmp/imap.py imaps 127.0.0.1 info@nine.example.test "$MPW" >/dev/null
                  local r=$?; lpanel accounts:unsuspend shop9 >/dev/null 2>&1; return $r; }
# --- M6b: filtering, filters, limits, SRS, the relay host's login, the queue, tracking
# An e2e-only Rspamd rule makes "e2e-spammy" in a subject score 8: above the
# Junk score (6) and below the refusal score (15), whatever else it scores.
printf '%s\n' 'E2E_SPAMMY { type = "header"; header = "Subject"; regexp = true; map = "/etc/rspamd/e2e-spammy.map"; score = 8.0; }' > /etc/rspamd/local.d/multimap.conf
printf '%s\n' '/e2e-spammy/i' > /etc/rspamd/e2e-spammy.map
systemctl restart rspamd
GTUBE='XJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X'
EICAR='X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
export GTUBE EICAR
junk() { echo "$(maildir shop9 nine.example.test info)/.Junk"; }
export -f junk
# A relay host that wants TLS and a login, and records the login it got.
openssl req -x509 -newkey rsa:2048 -nodes -subj /CN=relay.test -keyout /tmp/relay.key -out /tmp/relay.crt -days 2 2>/dev/null
mkdir -p /tmp/caught2
cat > /tmp/relay.py <<'PY'
import socket, ssl, base64
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain("/tmp/relay.crt", "/tmp/relay.key")
s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); s.bind(("127.0.0.1", 2527)); s.listen(5)
n = 0
while True:
    c, _ = s.accept(); st = {"c": c, "f": c.makefile("rwb"), "tls": False, "auth": ""}
    def out(x): st["f"].write(x.encode() + b"\r\n"); st["f"].flush()
    out("220 relay")
    while True:
        line = st["f"].readline()
        if not line: break
        u = line.decode(errors="replace").strip(); U = u.upper()
        if U.startswith("EHLO"):
            st["f"].write(b"250-relay\r\n" + (b"250-AUTH PLAIN\r\n" if st["tls"] else b"250-STARTTLS\r\n")); out("250 SIZE 100000000")
        elif U == "STARTTLS":
            out("220 go"); st["c"] = ctx.wrap_socket(st["c"], server_side=True); st["f"] = st["c"].makefile("rwb"); st["tls"] = True
        elif U.startswith("AUTH PLAIN"):
            parts = u.split(" ")
            if len(parts) < 3:
                out("334 "); parts.append(st["f"].readline().decode().strip())
            st["auth"] = base64.b64decode(parts[2]).decode(errors="replace").replace("\0", "|"); out("235 ok")
        elif U.startswith("MAIL FROM"):
            out("250 ok" if st["auth"] and st["tls"] else "530 log in first")
        elif U == "DATA":
            out("354 go"); buf = b""
            while True:
                l = st["f"].readline()
                if l in (b".\r\n", b".\n", b""): break
                buf += l
            n += 1; open("/tmp/caught2/%03d.eml" % n, "wb").write(b"X-Relay-Login: " + st["auth"].encode() + b"\r\n" + buf); out("250 OK")
        elif U == "QUIT": out("221 bye"); break
        else: out("250 OK")
    st["c"].close()
PY
nohup python3 /tmp/relay.py >/tmp/relay.log 2>&1 &

t_mail_filtering() { lpanel mail:filtering > /tmp/mf.out && grep -qE 'Spam filtering +on' /tmp/mf.out && systemctl is-active --quiet rspamd && ss -ltn | grep -q '127.0.0.1:11333 ' \
                  && [ -e /etc/exim/lpanel/filter ] && lpanel mail:info | grep -qE 'Rspamd +running' && ! ss -ltn | grep -qE ':1133[24] '; }
t_mail_scored() { sw -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "scored mail" --h-X-LPanel-Spam-Score 999 \
                  && waitfor 'grep -rlq "scored mail" "$(maildir shop9 nine.example.test info)/new"' \
                  && f=$(grep -rl "scored mail" "$(maildir shop9 nine.example.test info)/new" | head -1) && grep -c '^X-LPanel-Spam-Score:' "$f" | grep -qx 1 && ! grep -q '^X-LPanel-Spam-Score: 999' "$f"; }
t_mail_gtube()  { WANT='550 This message was refused as spam' refused -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "gtube" --body "$GTUBE" \
                  && grep -q 'rejected after DATA: refused as spam.*for info@nine.example.test' /var/log/exim/main.log; }
t_mail_junk()   { sw -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "e2e-spammy offer" \
                  && waitfor 'grep -rq "e2e-spammy offer" "$(junk)"' && ! grep -rq "e2e-spammy offer" "$(maildir shop9 nine.example.test info)/new"; }
t_mail_allow()  { lpanel mail:spam shop9 --allow=sender@three.example.test >/dev/null && grep -q 'sender@three.example.test' /etc/dovecot/lpanel/sieve/nine.example.test/info.sieve \
                  && sw -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "e2e-spammy but allowed" \
                  && waitfor 'grep -rq "e2e-spammy but allowed" "$(maildir shop9 nine.example.test info)/new"' && lpanel mail:spam shop9 --allow=none >/dev/null; }
t_mail_block()  { lpanel mail:spam shop9 --block=@three.example.test >/dev/null && local before; before=$(count_new shop9 nine.example.test info) \
                  && sw -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "from a blocked sender" && sleep 3 \
                  && ! grep -rq "from a blocked sender" "$(maildir shop9 nine.example.test info)" && [ "$(count_new shop9 nine.example.test info)" = "$before" ] \
                  && lpanel mail:spam shop9 --block=none >/dev/null; }
t_mail_filter() { lpanel mail:filter shop9 info@nine.example.test --field=subject --test=contains --value="Invoice" --action=folder --target=Billing --stop >/dev/null \
                  && lpanel mail:filter shop9 info@nine.example.test --field=from --test=is --value=sender@three.example.test --action=forward --target=copies@example.net >/dev/null \
                  && lpanel mail:filter shop9 info@nine.example.test > /tmp/mfl.out && grep -q 'Billing' /tmp/mfl.out && [ -s /etc/dovecot/lpanel/sieve/nine.example.test/info.svbin ] \
                  && sw -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "Invoice 42" \
                  && waitfor 'grep -rq "Invoice 42" "$(maildir shop9 nine.example.test info)/.Billing"' \
                  && sw -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "please copy me" \
                  && waitfor 'caught_to "please copy me" copies@example.net | grep -q .' && grep -q '^X-Envelope-From: <info@nine.example.test>' "$(caught_to 'please copy me' copies@example.net)" \
                  && waitfor 'grep -rq "please copy me" "$(maildir shop9 nine.example.test info)/new"'
                  local id; lpanel mail:filter shop9 info@nine.example.test > /tmp/mfl.out; for id in $(awk 'NR > 1 {print $1}' /tmp/mfl.out); do lpanel mail:filter shop9 info@nine.example.test --remove="$id" >/dev/null; done
                  [ ! -e /etc/dovecot/lpanel/sieve/nine.example.test/info.sieve ] || ! grep -q Billing /etc/dovecot/lpanel/sieve/nine.example.test/info.sieve; }
t_mail_srs()    { lpanel mail:forward shop9 info@nine.example.test boss@example.net >/dev/null 2>&1; sw -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "forward with srs" \
                  && waitfor 'caught_to "forward with srs" boss@example.net | grep -q .' && local env; env=$(grep -m1 '^X-Envelope-From:' "$(caught_to 'forward with srs' boss@example.net)" | sed 's/^X-Envelope-From: <\(.*\)>.*/\1/'); echo "envelope: $env" \
                  && [[ "$env" == SRS0=*=three.example.test=sender@nine.example.test ]] \
                  && sw -s "$MIP" -p 25 --from '<>' --to "$env" --h-Subject "bounce back to sender" \
                  && waitfor 'grep -rq "bounce back to sender" "$(maildir shop3 three.example.test sender)"' \
                  && WANT='550' refused -s "$MIP" -p 25 --from '<>' --to "SRS0=abcd=xx=three.example.test=sender@nine.example.test"
                  local r=$?; lpanel mail:unforward shop9 info@nine.example.test boss@example.net >/dev/null 2>&1; return $r; }
t_mail_limit()  { lpanel packages:create Limited --disk=50 --mail-per-hour=3 >/dev/null && echo "Owner-Pass-Word-8" | lpanel accounts:create shop8 eight.example.test Limited >/dev/null \
                  && echo "$MPW" | lpanel mail:create shop8 info@eight.example.test >/dev/null && grep -qx 'limit: 3' /etc/exim/lpanel/accounts/shop8 \
                  && for i in 1 2 3; do sw -s "$MIP" -p 587 -tls --auth PLAIN --auth-user info@eight.example.test --auth-password "$MPW" --from info@eight.example.test --to "n$i@example.net" --h-Subject "limit $i" || return 1; done \
                  && WANT='451 Your account has sent to its limit of 3' refused -s "$MIP" -p 587 -tls --auth PLAIN --auth-user info@eight.example.test --auth-password "$MPW" --from info@eight.example.test --to n4@example.net \
                  && ! su -s /bin/bash shop8 -c 'printf "From: info@eight.example.test\nTo: n5@example.net\nSubject: site over the limit\n\nhi\n" | /usr/sbin/sendmail -t' 2>/dev/null \
                  && grep -q 'lpanel-limit: shop8 reached 3' /var/log/exim/main.log \
                  && sw -s "$MIP" -p 587 -tls --auth PLAIN --auth-user info@nine.example.test --auth-password "$MPW" --from info@nine.example.test --to someone@example.net --h-Subject "others are not limited"; }
t_mail_relaylogin() { relay_steps; local r=$?; lpanel mail:settings --smarthost 127.0.0.1:2526 --relay-user none >/dev/null 2>&1; return $r; }
relay_steps() {
    printf 'Relay:Pass^word "1"' | lpanel mail:settings --smarthost 127.0.0.1:2527 --relay-user relayuser >/dev/null || { echo "settings failed"; return 1; }
    [ "$(stat -c '%a %U %G' /etc/exim/lpanel/smarthost_auth)" = "640 root exim" ] || { echo "smarthost_auth: $(stat -c '%a %U %G' /etc/exim/lpanel/smarthost_auth)"; return 1; }
    sw -s "$MIP" -p 587 -tls --auth PLAIN --auth-user info@nine.example.test --auth-password "$MPW" --from info@nine.example.test --to someone@example.net --h-Subject "through the login" || { echo "not accepted"; return 1; }
    waitfor 'grep -rlq "through the login" /tmp/caught2' || { echo "the relay got nothing"; cat /tmp/relay.log; return 1; }
    local got; got=$(grep -h '^X-Relay-Login:' $(grep -rl 'through the login' /tmp/caught2) | tr -d '\r'); echo "$got"
    [ "$got" = 'X-Relay-Login: |relayuser|Relay:Pass^word "1"' ] || { echo "wrong login"; return 1; }
    ! mariadb -uroot -N lpanel -e "select value from mail_settings union all select details from audit_log" | grep -q 'Pass^word' || { echo "the password is in the database"; return 1; }
    lpanel mail:settings --smarthost 127.0.0.1:2526 --relay-user none >/dev/null && [ ! -e /etc/exim/lpanel/smarthost_auth ]
}
export -f relay_steps
t_mail_queue()  { printf 'Subject: queued e2e\n\nwaiting\n' | /usr/sbin/sendmail -odq -f root@e2e-panel.test queued-e2e@example.net \
                  && printf 'Subject: deliver me e2e\n\nwaiting\n' | /usr/sbin/sendmail -odq -f root@e2e-panel.test deliver-e2e@example.net \
                  && lpanel mail:queue list --search=e2e@example.net > /tmp/mq.out && cat /tmp/mq.out >&2 \
                  && local q d; q=$(awk '/queued-e2e/ {print $1}' /tmp/mq.out) && d=$(awk '/deliver-e2e/ {print $1}' /tmp/mq.out) && [ -n "$q" ] && [ -n "$d" ] \
                  && lpanel mail:queue show "$q" | grep -q 'Subject: queued e2e' \
                  && lpanel mail:queue freeze "$q" >/dev/null && exim -bp | grep "$q" | grep -q frozen \
                  && lpanel mail:queue thaw "$q" >/dev/null && ! exim -bp | grep "$q" | grep -q frozen \
                  && ! lpanel mail:queue remove "$q" >/dev/null 2>&1 && lpanel mail:queue remove "$q" --yes >/dev/null && ! exim -bp | grep -q "$q" \
                  && lpanel mail:queue deliver "$d" >/dev/null && waitfor 'caught "deliver me e2e" | grep -q .' && waitfor '! exim -bp | grep -q "$d"'; }
t_mail_track()  { lpanel mail:track --account=shop9 --search=someone@example.net > /tmp/mt.out && grep -qE 'delivered +info@nine.example.test +someone@example.net' /tmp/mt.out \
                  && lpanel mail:track --account=shop9 --status=rejected > /tmp/mt2.out && grep -q 'refused as spam' /tmp/mt2.out \
                  && lpanel mail:track --account=shop9 > /tmp/mt3.out && ! grep -q 'n1@example.net' /tmp/mt3.out \
                  && lpanel mail:track --account=shop8 > /tmp/mt4.out && grep -q 'n1@example.net' /tmp/mt4.out \
                  && lpanel mail:track > /tmp/mt5.out && grep -q 'n1@example.net' /tmp/mt5.out; }
t_mail_virus()  { mkdir -p /etc/systemd/system/clamav-freshclam.service.d && printf '[Service]\nExecStart=\nExecStart=/usr/bin/sleep infinity\n' > /etc/systemd/system/clamav-freshclam.service.d/e2e.conf \
                  && systemctl daemon-reload && printf '%s' "$EICAR" > /tmp/eicar.com \
                  && echo "$(md5sum < /tmp/eicar.com | cut -d' ' -f1):$(stat -c %s /tmp/eicar.com):E2E.EICAR" > /var/lib/clamav/e2e.hdb && chmod 0644 /var/lib/clamav/e2e.hdb \
                  && lpanel mail:filtering --virus=on >/dev/null && grep -q 'clamav {' /etc/rspamd/local.d/antivirus.conf \
                  && waitfor '[ -S /run/clamd.scan/clamd.sock ]' && id -nG _rspamd | grep -qw virusgroup \
                  && WANT='550 This message carries a virus' refused -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "a virus" --attach @/tmp/eicar.com \
                  && sw -s "$MIP" -p 25 --from sender@three.example.test --to info@nine.example.test --h-Subject "clean attachment" --attach @/etc/hostname \
                  && lpanel mail:filtering --virus=off >/dev/null && ! systemctl is-active --quiet clamd@scan; local r=$?; lpanel mail:filtering --virus=off >/dev/null 2>&1; return $r; }
t_mail_m6b_api() { [ "$(api -o /tmp/mspam.json -w '%{http_code}' -X PUT -H 'Content-Type: application/json' -d '{"junk": 7}' https://127.0.0.1:7443/admin/api/v1/accounts/shop9/email/spam)" = 200 ] \
                  && grep -q '"junk":7' /tmp/mspam.json && [ "$(api -o /tmp/mq.json -w '%{http_code}' https://127.0.0.1:7443/admin/api/v1/email/queue)" = 200 ] && grep -q '"total"' /tmp/mq.json \
                  && [ "$(api -o /tmp/mtr.json -w '%{http_code}' 'https://127.0.0.1:7443/admin/api/v1/accounts/shop9/email/track?days=1')" = 200 ] && grep -q '"deliveries"' /tmp/mtr.json; }

# M6c: mail clients' setup through the sites, webmail (Roundcube) and its
# sign-on, a certificate for mail.<domain>, the deliverability checks.
AD='<?xml version="1.0"?><Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/requestschema/2006"><Request><EMailAddress>info@nine.example.test</EMailAddress><AcceptableResponseSchema>http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a</AcceptableResponseSchema></Request></Autodiscover>'
export AD
t_mail_autoconf() { curl -s -H 'Host: autoconfig.nine.example.test' http://127.0.0.1/mail/config-v1.1.xml -o /tmp/ac.xml && cat /tmp/ac.xml >&2 \
                  && grep -q '<emailProvider id="nine.example.test">' /tmp/ac.xml && grep -q '<hostname>e2e-panel.test</hostname>' /tmp/ac.xml \
                  && curl -s -H 'Host: nine.example.test' 'http://127.0.0.1/.well-known/autoconfig/mail/config-v1.1.xml?emailaddress=info%40nine.example.test' | grep -q '<domain>nine.example.test</domain>' \
                  && curl -s -H 'Host: autodiscover.nine.example.test' -H 'Content-Type: text/xml' --data "$AD" http://127.0.0.1/autodiscover/autodiscover.xml -o /tmp/ad.xml \
                  && grep -q '<LoginName>info@nine.example.test</LoginName>' /tmp/ad.xml \
                  && [ "$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: autoconfig.nobody.example.test' http://127.0.0.1/mail/config-v1.1.xml)" = 404 ] \
                  && [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:7449/panel/login)" = 404 ] && ! ss -ltn | grep -E ':7449 ' | grep -qv '127.0.0.1:7449'; }
t_webmail_names() { [ "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -H 'Host: webmail.nine.example.test' http://127.0.0.1/)" = "302 https://e2e-panel.test:7444/webmail/" ] \
                  && grep -q 'mail.nine.example.test autoconfig.nine.example.test autodiscover.nine.example.test webmail.nine.example.test' $SITES/shop9.conf \
                  && [ -n "$(q autoconfig.nine.example.test A)" ] && [ -n "$(q webmail.nine.example.test A)" ]; }
t_webmail_up()  { curl -ks https://127.0.0.1:7444/webmail/ -o /tmp/rc.html && grep -q 'rcmloginuser' /tmp/rc.html \
                  && [ "$(status https://127.0.0.1:7444/webmail/installer.php)" = 404 ] && [ "$(status https://127.0.0.1:7444/webmail/config/config.inc.php)" = 404 ] \
                  && [ "$(stat -c '%a %U %G' /usr/local/lpanel-webmail/roundcube/config/config.inc.php)" = "640 root lpanel-webmail" ] \
                  && ps -eo user:20,args | grep -q '^lpanel-webmail .*php-fpm: pool webmail'; }
rc_login() { # address password -> the Location after logging in to Roundcube's own form
    rm -f /tmp/rcjar; curl -ks -c /tmp/rcjar -b /tmp/rcjar https://127.0.0.1:7444/webmail/ -o /tmp/rc.html
    curl -ks -c /tmp/rcjar -b /tmp/rcjar -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "_token=$(token /tmp/rc.html)" \
        -d _task=login -d _action=login -d _timezone=UTC -d _url= --data-urlencode "_user=$1" --data-urlencode "_pass=$2" 'https://127.0.0.1:7444/webmail/?_task=login'
}
export -f rc_login
t_webmail_login() { local r; r=$(rc_login info@nine.example.test "$MPW"); echo "$r" >&2; grep -q '^302 .*_task=mail' <<<"$r" \
                  && curl -ks -b /tmp/rcjar 'https://127.0.0.1:7444/webmail/?_task=mail&_mbox=INBOX' -o /tmp/rcmail.html && { grep -q 'task-mail' /tmp/rcmail.html || { grep -o '<body[^>]*>' /tmp/rcmail.html; return 1; }; } \
                  && r=$(rc_login info@nine.example.test wrong-password) && ! grep -q '_task=mail' <<<"$r"; }
t_webmail_sso() { local url; url=$(lpanel mail:webmail shop9 info@nine.example.test) && echo "$url" >&2 && grep -q '^https://e2e-panel.test:7444/webmail/?_task=login&_lpanel=[0-9a-f]\{64\}$' <<<"$url" \
                  && grep -q '^info:{SSHA256.b64}' /etc/dovecot/lpanel/sso.d/nine.example.test && [ "$(stat -c '%a %U %G' /etc/dovecot/lpanel/sso.d/nine.example.test)" = "640 root dovecot" ] \
                  && [ "$(stat -c '%a %U' /etc/dovecot/lpanel/sso.keep/nine.example.test)" = "600 root" ] \
                  && rm -f /tmp/ssojar && local r; r=$(curl -ks --resolve e2e-panel.test:7444:127.0.0.1 -c /tmp/ssojar -b /tmp/ssojar -o /dev/null -w '%{http_code} %{redirect_url}' "$url") && echo "$r" >&2 \
                  && grep -q '^302 .*_task=mail' <<<"$r" && curl -ks --resolve e2e-panel.test:7444:127.0.0.1 -b /tmp/ssojar 'https://e2e-panel.test:7444/webmail/?_task=mail&_mbox=INBOX' -o /tmp/rcmail.html && { grep -q 'task-mail' /tmp/rcmail.html || { grep -o '<body[^>]*>' /tmp/rcmail.html; return 1; }; } \
                  && [ -z "$(ls -A /var/lib/lpanel-webmail/tickets)" ] \
                  && r=$(curl -ks --resolve e2e-panel.test:7444:127.0.0.1 -o /dev/null -w '%{http_code} %{redirect_url}' "$url") && ! grep -q '_task=mail' <<<"$r" \
                  && echo "Mail-Pass-Word-2" | lpanel mail:password shop9 info@nine.example.test >/dev/null && ! grep -q '^info:' /etc/dovecot/lpanel/sso.d/nine.example.test \
                  && echo "$MPW" | lpanel mail:password shop9 info@nine.example.test >/dev/null; }
t_mail_sni()    { printf '127.0.0.1 nine.example.test www.nine.example.test mail.nine.example.test autoconfig.nine.example.test autodiscover.nine.example.test webmail.nine.example.test\n' >> /etc/hosts \
                  && lpanel ssl:issue shop9 nine.example.test >/dev/null && ssl_row nine.example.test names | grep -q 'mail.nine.example.test' \
                  && waitfor '[ -s /etc/pki/lpanel-mail/sni/mail.nine.example.test/crt ]' && [ "$(stat -c '%a %U %G' /etc/pki/lpanel-mail/sni/mail.nine.example.test/key)" = "640 root exim" ] \
                  && grep -q 'local_name mail.nine.example.test' /etc/dovecot/lpanel/sni.conf \
                  && echo | openssl s_client -connect 127.0.0.1:993 -servername mail.nine.example.test 2>/dev/null | openssl x509 -noout -ext subjectAltName | grep -q 'DNS:mail.nine.example.test' \
                  && echo | openssl s_client -connect 127.0.0.1:465 -servername mail.nine.example.test 2>/dev/null | openssl x509 -noout -ext subjectAltName | grep -q 'DNS:mail.nine.example.test' \
                  && echo | openssl s_client -connect 127.0.0.1:587 -starttls smtp -servername mail.nine.example.test 2>/dev/null | openssl x509 -noout -ext subjectAltName | grep -q 'DNS:mail.nine.example.test' \
                  && ! echo | openssl s_client -connect 127.0.0.1:993 -servername other.name.test 2>/dev/null | openssl x509 -noout -ext subjectAltName | grep -q 'mail.nine.example.test' \
                  && python3 /tmp/imap.py imaps 127.0.0.1 info@nine.example.test "$MPW" >/dev/null \
                  && lpanel mail:setup shop9 info@nine.example.test | grep -Eq '^Server +mail.nine.example.test$' \
                  && curl -s -H 'Host: autoconfig.nine.example.test' http://127.0.0.1/mail/config-v1.1.xml | grep -q '<hostname>mail.nine.example.test</hostname>'; }
t_mail_check()  { lpanel mail:check shop9 > /tmp/mcheck.out; local c=$?; cat /tmp/mcheck.out >&2; [ $c = 0 ] \
                  && grep -Eq '^  ok +SPF ' /tmp/mcheck.out && grep -Eq '^  ok +DKIM ' /tmp/mcheck.out && grep -Eq '^  ok +DMARC ' /tmp/mcheck.out && grep -Eq '^  ok +MX ' /tmp/mcheck.out \
                  && local rid; rid=$(mariadb -uroot -N lpanel -e "select r.id from dns_records r join dns_zones z on z.id = r.zone_id where z.name = 'nine.example.test' and r.name = '_dmarc' and r.type = 'TXT'") \
                  && lpanel dns:remove nine.example.test "$rid" >/dev/null && waitfor '[ -z "$(q _dmarc.nine.example.test TXT)" ]' \
                  && { lpanel mail:check shop9 --domain=nine.example.test > /tmp/mcheck2.out; cat /tmp/mcheck2.out >&2; grep -Eq '^  BAD +DMARC ' /tmp/mcheck2.out; } \
                  && lpanel dns:add nine.example.test _dmarc TXT 'v=DMARC1; p=none' >/dev/null; }
t_mail_m6c_pages() { [ "$(signin_as 7444 /panel shop9 Owner-Pass-Word-9)" = "303 https://127.0.0.1:7444/panel" ] \
                  && local id; id=$(mariadb -uroot -N lpanel -e "select b.id from mail_boxes b join mail_domains d on d.id = b.mail_domain_id where d.name = 'nine.example.test' and b.local = 'info'") \
                  && curl -ks -b /tmp/jar2 "https://127.0.0.1:7444/panel/email/mailboxes/$id/setup" -o /tmp/msetup.html && { grep -q 'mail.nine.example.test' /tmp/msetup.html || { echo "setup page"; return 1; }; } \
                  && curl -ks -b /tmp/jar2 "https://127.0.0.1:7444/panel/email/mailboxes/$id/setup/profile" -o /tmp/mprofile.xml && { grep -q 'com.apple.mail.managed' /tmp/mprofile.xml || { echo "profile"; return 1; }; } \
                  && curl -ks -b /tmp/jar2 https://127.0.0.1:7444/panel/email/checks -o /tmp/mchecks.html && { grep -q 'The published key matches' /tmp/mchecks.html || { echo "checks page"; grep -o '<p>[^<]*</p>' /tmp/mchecks.html | head; return 1; }; } \
                  && local code; code=$(api -o /tmp/mwm.json -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' https://127.0.0.1:7443/admin/api/v1/accounts/shop9/email/mailboxes/info@nine.example.test/webmail) \
                  && { [ "$code" = 200 ] && grep -q '_task=login&_lpanel=' /tmp/mwm.json && grep -q 'e2e-panel.test:7444' /tmp/mwm.json || { echo "api $code: $(cat /tmp/mwm.json)"; return 1; }; }; }

t_mail_panel()  { [ "$(signin_as 7444 /panel shop9 Owner-Pass-Word-9)" = "303 https://127.0.0.1:7444/panel" ] \
                  && curl -ks -b /tmp/jar2 -c /tmp/jar2 https://127.0.0.1:7444/panel/email -o /tmp/email.html && grep -q 'info@nine.example.test' /tmp/email.html && grep -q 'e2e-panel.test' /tmp/email.html \
                  && local did; did=$(mariadb -uroot -N lpanel -e "select id from mail_domains where name='nine.example.test'") \
                  && curl -ks -b /tmp/jar2 -c /tmp/jar2 -o /dev/null --data-urlencode "_token=$(token /tmp/email.html)" -d local=web -d "domain=$did" -d "password=$MPW" -d quota=10 https://127.0.0.1:7444/panel/email/mailboxes \
                  && grep -qx 'web: shop9' /etc/exim/lpanel/mailboxes/nine.example.test; }
t_mail_api()    { [ "$(api -o /tmp/mapi.json -w '%{http_code}' https://127.0.0.1:7443/admin/api/v1/accounts/shop9/email)" = 200 ] && grep -q '"address":"web@nine.example.test"' /tmp/mapi.json \
                  && [ "$(api -o /dev/null -w '%{http_code}' -X DELETE https://127.0.0.1:7443/admin/api/v1/accounts/shop9/email/mailboxes/web@nine.example.test)" = 204 ] \
                  && ! grep -q '^web' /etc/exim/lpanel/mailboxes/nine.example.test; }
t_mail_delete() { [ -d "$(maildir shop9 nine.example.test bob)" ] && lpanel mail:delete shop9 bob@nine.example.test --yes >/dev/null \
                  && waitfor '[ ! -e /home/shop9/mail/nine.example.test/bob ]' && ! grep -q '^bob:' /etc/dovecot/lpanel/passwd.d/nine.example.test; }
t_mail_gone()   { lpanel accounts:terminate shop9 --yes >/dev/null \
                  && for _ in $(seq 60); do getent passwd shop9 >/dev/null || break; lpanel accounts:settle >/dev/null; sleep 2; done \
                  && lpanel accounts:settle >/dev/null && [ ! -e /etc/exim/lpanel/owners/nine.example.test ] && [ ! -e /etc/dovecot/lpanel/passwd.d/nine.example.test ] \
                  && [ ! -e /etc/exim/lpanel/dkim/nine.example.test ] && [ "$(mariadb -uroot -N lpanel -e "select count(*) from mail_domains where name = 'nine.example.test'")" = 0 ]; }
t_mail_audit()  { local got; got=$(mariadb -uroot -N lpanel -e "select distinct concat(action, ':', outcome) from audit_log where action like 'mail.%'" | tr '\n' ' '); echo "$got" >&2
                  for want in mail.mailbox_create:ok mail.mailbox_delete:ok mail.forwarder_add:ok mail.forwarder_remove:ok mail.responder_on:ok mail.responder_off:ok mail.domain_settings:ok mail.settings:ok \
                              mail.spam:ok mail.filter_add:ok mail.filter_remove:ok mail.filtering:ok mail.queue:ok mail.webmail:ok; do
                      grep -qw "$want" <<<"$got" || { echo "missing $want"; return 1; }
                  done; }
check "Exim and Dovecot run, on every mail port" t_mail_up
check "their data is root's, readable by each server's group; TLS is the hostname's certificate" t_mail_files
check "every domain of every account is a mail domain, with its own DKIM key" t_mail_domains
check "the DKIM key and DMARC policy are in the zone, SPF for a subdomain" t_mail_dns
check "lpanel mail:create: mailboxes, and only a bcrypt hash on the server" t_mail_create
check "mail from another server reaches the mailbox, owned by the account" t_mail_in
check "IMAP and POP3 over TLS; a wrong password, a system user and root are refused" t_mail_imap
check "no password before TLS from outside; STARTTLS works" t_mail_plain
check "a mailbox sends on 587 and 465 after logging in" t_mail_out
check "its mail is signed with the domain's key, and verifies against the zone" t_mail_dkim
check "a site's own mail is signed; another account's forgery is not" t_mail_site
check "no sending without a login, no relaying, no wrong password" t_mail_noauth
check "a mailbox cannot send as another account's address" t_mail_spoof
check "unknown addresses are refused, or go to the catch-all" t_mail_unknown
check "info+tag@ reaches info@" t_mail_plus
check "forwarders: a copy stays in the mailbox, one goes on" t_mail_forward
check "an autoresponder answers once per sender, and goes away" t_mail_reply
check "a full mailbox refuses mail" t_mail_full
check "lpanel mail:usage measures each mailbox" t_mail_usage
check "a suspended account receives mail but cannot log in or send" t_mail_suspend
check "spam filtering is on after the install; Rspamd answers only on 127.0.0.1:11333" t_mail_filtering
check "mail from outside gets its score; a sender's own score header is dropped" t_mail_scored
check "obvious spam (GTUBE) is refused while the sender is connected, and logged for its recipient" t_mail_gtube
check "spam goes to the Junk folder" t_mail_junk
check "an allowed sender's spam stays in the inbox" t_mail_allow
check "a blocked sender's mail is thrown away" t_mail_block
check "a mailbox's filters file mail in a folder and forward a copy" t_mail_filter
check "forwarded mail leaves with an SRS sender; a bounce to it goes back" t_mail_srs
check "the package's sending limit holds for mailboxes and sites, per account" t_mail_limit
check "the relay host gets its login, over TLS; the password is kept nowhere else" t_mail_relaylogin
check "lpanel mail:queue lists, shows, freezes, thaws, removes and delivers" t_mail_queue
check "lpanel mail:track: an account sees its own mail, refusals included" t_mail_track
check "ClamAV refuses a virus (a local test signature)" t_mail_virus
check "the API sets spam settings and reads the queue and tracking" t_mail_m6b_api
check "mail clients find their settings at any of a site's names (autoconfig, autodiscover)" t_mail_autoconf
check "webmail.<domain> goes to webmail; the mail names are the site's and in its zone" t_webmail_names
check "Roundcube is served at /webmail/, as its own user; its installer and config are not" t_webmail_up
check "a mailbox signs in to Roundcube with its own password, and not with a wrong one" t_webmail_login
check "lpanel mail:webmail opens it with no password, once; a new password ends the sign-on" t_webmail_sso
check "mail.<domain> gets the site's certificate, served by SNI on IMAP and SMTP" t_mail_sni
check "lpanel mail:check: SPF, DKIM, DMARC and MX pass, and a missing DMARC is found" t_mail_check
check "the setup page, the Apple profile, the checks page and the webmail API" t_mail_m6c_pages
check "the owner sees the mailboxes and makes one in the User panel" t_mail_panel
check "the API lists and deletes mailboxes" t_mail_api
check "a deleted mailbox's mail is deleted" t_mail_delete
check "terminating an account removes its mail" t_mail_gone
check "mail changes are in the audit log" t_mail_audit
lpanel mail:settings --smarthost none >/dev/null

# M7a: the file manager. Every operation runs as the account's own user,
# inside its home; contents go through the panel's staging folder.
echo "== files"
lpanel packages:create Filer --disk=20 >/dev/null
echo "Owner-Pass-Word-7" | lpanel accounts:create shop7 seven.example.test Filer >/dev/null
F=/home/shop7
t_files_staging() { [ "$(stat -c '%a %U %G' /var/lib/lpanel/files)" = "700 lpanel lpanel" ]; }
t_files_cli()     { lpanel files:mkdir shop7 public_html/app >/dev/null && printf 'hello\n' | lpanel files:write shop7 public_html/app/index.html >/dev/null \
                    && [ "$(lpanel files:read shop7 public_html/app/index.html)" = hello ] \
                    && [ "$(stat -c '%U %G %a' $F/public_html/app/index.html)" = "shop7 shop7 644" ] && [ "$(stat -c '%U %a' $F/public_html/app)" = "shop7 755" ] \
                    && lpanel files:list shop7 public_html/app | grep -q 'index.html' \
                    && lpanel files:rename shop7 public_html/app/index.html public_html/app/home.html >/dev/null && [ -f $F/public_html/app/home.html ] \
                    && lpanel files:copy shop7 public_html/app tmp >/dev/null && [ -f $F/tmp/app/home.html ] \
                    && lpanel files:mkdir shop7 public_html/old >/dev/null \
                    && lpanel files:move shop7 tmp/app public_html/old >/dev/null && [ -f $F/public_html/old/app/home.html ] \
                    && lpanel files:delete shop7 public_html/old >/dev/null && [ ! -e $F/public_html/old ] \
                    && lpanel files:chmod shop7 public_html/app/home.html 600 >/dev/null && [ "$(stat -c %a $F/public_html/app/home.html)" = 600 ] \
                    && lpanel files:chmod shop7 public_html/app/home.html 644 >/dev/null; }
t_files_owner()   { install -d -o root -g root -m 0755 $F/rootdir && echo root-only > $F/rootdir/file \
                    && ! echo changed | lpanel files:write shop7 rootdir/file 2>/tmp/fown.err && grep -q 'permissions do not allow' /tmp/fown.err && [ "$(cat $F/rootdir/file)" = root-only ] \
                    && ! lpanel files:delete shop7 rootdir/file 2>/dev/null && [ -f $F/rootdir/file ] \
                    && ! lpanel files:delete shop7 public_html 2>/dev/null && [ -d $F/public_html ] && rm -rf $F/rootdir; }
t_files_escape()  { runuser -u shop7 -- ln -s /etc $F/etclink && runuser -u shop7 -- ln -s /home/shop1 $F/neighbour || { echo "links"; return 1; }
                    lpanel files:read shop7 etclink/passwd >/tmp/fesc.out 2>&1 && { echo "read through the link"; return 1; }
                    grep -q 'out of your home' /tmp/fesc.out || { echo "read: $(cat /tmp/fesc.out)"; return 1; }
                    lpanel files:list shop7 neighbour >/tmp/fesc.out 2>&1 && { echo "listed a neighbour: $(cat /tmp/fesc.out)"; return 1; }
                    lpanel files:read shop7 ../shop1/public_html/index.php >/dev/null 2>&1 && { echo "read with .."; return 1; }
                    echo x | lpanel files:write shop7 etclink/lpanel-e2e >/tmp/fesc.out 2>&1 && { echo "wrote through the link"; return 1; }
                    [ ! -e /etc/lpanel-e2e ] || { echo "/etc/lpanel-e2e exists"; return 1; }
                    lpanel files:delete shop7 etclink >/tmp/fesc.out 2>&1 || { echo "delete: $(cat /tmp/fesc.out)"; return 1; }
                    [ ! -L $F/etclink ] && [ -f /etc/passwd ] || { echo "the link or its target"; return 1; }
                    rm -f $F/neighbour; }
t_files_quota()   { head -c 30000000 /dev/urandom > /tmp/big.bin && ! lpanel files:upload shop7 /tmp/big.bin public_html 2>/tmp/fq.err \
                    && grep -q 'out of disk space' /tmp/fq.err && [ ! -e $F/public_html/big.bin ] && ! ls -a $F/public_html | grep -q '^\.lpanel-' \
                    && head -c 100000 /dev/urandom > /tmp/small.bin && lpanel files:upload shop7 /tmp/small.bin public_html >/dev/null && cmp -s /tmp/small.bin $F/public_html/small.bin \
                    && rm -f /tmp/dl.bin && lpanel files:download shop7 public_html/small.bin /tmp/dl.bin >/dev/null && cmp -s /tmp/small.bin /tmp/dl.bin; }
t_files_archive() { lpanel files:compress shop7 public_html/app backup.tar.gz >/dev/null && lpanel files:compress shop7 public_html/app backup.zip >/dev/null \
                    && tar tzf $F/backup.tar.gz | grep -q '^app/home.html$' && [ "$(stat -c %U $F/backup.zip)" = shop7 ] \
                    && lpanel files:extract shop7 backup.zip restore >/dev/null && cmp -s $F/public_html/app/home.html $F/restore/app/home.html \
                    && python3 -c 'import zipfile; z = zipfile.ZipFile("/tmp/slip.zip", "w"); z.writestr("../../escaped.txt", "x"); z.writestr("ok.txt", "y"); z.close()' \
                    && install -o shop7 -g shop7 -m 0644 /tmp/slip.zip $F/slip.zip && lpanel files:extract shop7 slip.zip slip | tee /dev/stderr | grep -q 'left out' \
                    && [ -f $F/slip/ok.txt ] && [ ! -e /home/escaped.txt ] && [ ! -e $F/escaped.txt ]; }
t_files_usage()   { lpanel files:usage shop7 > /tmp/fusage.out; cat /tmp/fusage.out >&2; grep -Eq ' public_html/$' /tmp/fusage.out && grep -q ' total' /tmp/fusage.out; }
t_files_panel()   { [ "$(signin_as 7444 /panel shop7 Owner-Pass-Word-7)" = "303 https://127.0.0.1:7444/panel" ] \
                    && curl -ks -b /tmp/jar2 -c /tmp/jar2 'https://127.0.0.1:7444/panel/files?path=public_html/app' -o /tmp/files.html && grep -q 'home.html' /tmp/files.html \
                    && curl -ks -b /tmp/jar2 -c /tmp/jar2 'https://127.0.0.1:7444/panel/files/edit?path=public_html/app/home.html' -o /tmp/edit.html \
                    && local sum; sum=$(grep -o 'name="expect" value="[0-9a-f]*"' /tmp/edit.html | sed 's/.*value="//;s/"$//') && [ -n "$sum" ] \
                    && curl -ks -b /tmp/jar2 -c /tmp/jar2 -o /dev/null --data-urlencode "_token=$(token /tmp/edit.html)" -d path=public_html/app/home.html -d "expect=$sum" \
                         --data-urlencode 'content=<h1>from the panel</h1>' https://127.0.0.1:7444/panel/files/save && grep -q 'from the panel' $F/public_html/app/home.html \
                    && [ "$(curl -ks -b /tmp/jar2 -o /dev/null -w '%{http_code}' --data-urlencode "_token=$(token /tmp/edit.html)" -d path=public_html/app/home.html -d "expect=$sum" -d content=stale https://127.0.0.1:7444/panel/files/save)" = 422 ] \
                    && echo "uploaded through the panel" > /tmp/up.txt \
                    && curl -ks -b /tmp/jar2 -c /tmp/jar2 -o /dev/null -F "_token=$(token /tmp/files.html)" -F dir=public_html/app -F 'files[]=@/tmp/up.txt' https://127.0.0.1:7444/panel/files/upload \
                    && cmp -s /tmp/up.txt $F/public_html/app/up.txt && [ "$(stat -c %U $F/public_html/app/up.txt)" = shop7 ] \
                    && [ "$(curl -ks -b /tmp/jar2 'https://127.0.0.1:7444/panel/files/download?path=public_html/app/up.txt')" = "uploaded through the panel" ] \
                    && curl -ks -b /tmp/jar2 'https://127.0.0.1:7444/panel/files/usage' | grep -q 'public_html' \
                    && [ -z "$(ls -A /var/lib/lpanel/files)" ] || { echo "staging: $(ls -A /var/lib/lpanel/files)"; return 1; }; }
t_files_site()    { [ "$(curl -skL --resolve seven.example.test:80:127.0.0.1 --resolve seven.example.test:443:127.0.0.1 http://seven.example.test/app/home.html)" = '<h1>from the panel</h1>' ]; }
t_files_api()     { [ "$(api -o /tmp/fapi.json -w '%{http_code}' 'https://127.0.0.1:7443/admin/api/v1/accounts/shop7/files?path=public_html/app')" = 200 ] && grep -q '"name":"up.txt"' /tmp/fapi.json \
                    && [ "$(api -o /dev/null -w '%{http_code}' -X PUT -H 'Content-Type: application/json' -d '{"path":"public_html/api.txt","content":"from the api"}' https://127.0.0.1:7443/admin/api/v1/accounts/shop7/files/content)" = 200 ] \
                    && [ "$(cat $F/public_html/api.txt)" = "from the api" ] \
                    && [ "$(api -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{"paths":["mail"]}' https://127.0.0.1:7443/admin/api/v1/accounts/shop7/files/delete)" = 403 ] \
                    && [ "$(api 'https://127.0.0.1:7443/admin/api/v1/accounts/shop7/files/download?path=public_html/api.txt')" = "from the api" ]; }
t_files_label()   { echo moved > $F/moved.html && chown shop7:shop7 $F/moved.html && restorecon $F/moved.html \
                    && lpanel files:move shop7 moved.html public_html >/dev/null && stat -c %C $F/public_html/moved.html | grep -q httpd_user_rw_content_t \
                    || { stat -c %C $F/public_html/moved.html; return 1; }; }
t_files_audit()   { local got; got=$(mariadb -uroot -N lpanel -e "select distinct concat(action, ':', outcome) from audit_log where action like 'files.%'" | tr '\n' ' '); echo "$got" >&2
                    for want in files.mkdir:ok files.write:ok files.rename:ok files.copy:ok files.delete:ok files.chmod:ok files.upload:ok files.download:ok \
                                files.compress:ok files.extract:ok files.move:ok files.write:failed; do
                        grep -qw "$want" <<<"$got" || { echo "missing $want"; return 1; }
                    done
                    ! mariadb -uroot -N lpanel -e "select details from audit_log where action like 'files.%'" | grep -q 'from the panel'; }
check "the file manager's staging folder is the panel's alone" t_files_staging
check "lpanel files:*: make, write, read, rename, copy, delete, chmod, as the account's user" t_files_cli
check "the agent works as the account: a root-owned file and the server's folders stay" t_files_owner
check "links out of the home lead nowhere: no reading, listing or writing through them" t_files_escape
check "the account's quota holds for uploads, and nothing half-written is left" t_files_quota
check "archives: .tar.gz and .zip made and extracted; a zip-slip entry is left out" t_files_archive
check "lpanel files:usage shows what takes the space" t_files_usage
check "the User panel lists, edits (and refuses a stale save), uploads and downloads" t_files_panel
check "the site serves what the file manager saved" t_files_site
check "the API lists, saves, refuses the server's folders and downloads" t_files_api
if command -v selinuxenabled >/dev/null 2>&1 && selinuxenabled; then
    check "a file moved into public_html gets the web's label" t_files_label
fi
check "file changes are in the audit log, never their contents" t_files_audit

echo "== FTP (Pure-FTPd)"
FTPU=dev@seven.example.test
# FTPS (explicit TLS): curl's --ssl-reqd; the panel's certificate is Pebble's, so -k.
ftps() { curl -s --ssl-reqd -k --max-time 20 "$@"; }
fp()   { echo | openssl s_client ${2:-} -connect "127.0.0.1:$1" 2>/dev/null | openssl x509 -noout -fingerprint -sha256; }
t_ftp_running()  { systemctl is-active --quiet pure-ftpd && ss -ltn | grep -q ':21 ' \
                   && grep -Eq '^TLS +2$' /etc/pure-ftpd/pure-ftpd.conf && grep -Eq '^NoAnonymous +yes$' /etc/pure-ftpd/pure-ftpd.conf \
                   && [ "$(stat -c '%a %U' /etc/pure-ftpd/lpanel)" = "700 root" ] && [ "$(stat -c '%a %U' /etc/pki/pure-ftpd/pure-ftpd.pem)" = "600 root" ]; }
t_ftp_cert()     { local a b; a=$(fp 21 '-starttls ftp'); b=$(fp 7443); echo "$a / $b" >&2; [ -n "$a" ] && [ "$a" = "$b" ]; }
t_ftp_cli()      { echo Ftp-Pass-Word-1 | lpanel ftp:create shop7 $FTPU --folder=public_html/ftp >/dev/null \
                   && [ "$(stat -c '%U %a' $F/public_html/ftp)" = "shop7 755" ] && lpanel ftp:list shop7 | grep "^$FTPU *\/public_html\/ftp" >/dev/null \
                   && grep -qF "$FTPU:"'$2y$' /etc/pure-ftpd/lpanel/users.d/shop7 && [ "$(stat -c '%a %U' /etc/pure-ftpd/lpanel/users.d/shop7)" = "600 root" ]; }
t_ftp_upload()   { echo "sent over FTPS" > /tmp/ftpup.txt && ftps -T /tmp/ftpup.txt ftp://127.0.0.1/ftpup.txt -u "$FTPU:Ftp-Pass-Word-1" \
                   && [ "$(cat $F/public_html/ftp/ftpup.txt)" = "sent over FTPS" ] && [ "$(stat -c '%U %G %a' $F/public_html/ftp/ftpup.txt)" = "shop7 shop7 644" ] \
                   && ftps ftp://127.0.0.1/ -Q 'MKD made' -u "$FTPU:Ftp-Pass-Word-1" >/dev/null && [ "$(stat -c '%U %a' $F/public_html/ftp/made)" = "shop7 755" ] \
                   && [ "$(ftps "ftp://127.0.0.1/ftpup.txt" -u "$FTPU:Ftp-Pass-Word-1")" = "sent over FTPS" ]; }
t_ftp_jail()     { ftps --list-only ftp://127.0.0.1/ -u "$FTPU:Ftp-Pass-Word-1" > /tmp/ftpls; ftps --path-as-is --list-only ftp://127.0.0.1/../../ -u "$FTPU:Ftp-Pass-Word-1" >> /tmp/ftpls; cat /tmp/ftpls >&2
                   grep -qx ftpup.txt /tmp/ftpls && ! grep -Eqx 'app|public_html|mail|shop7|passwd' /tmp/ftpls \
                   && ! ftps --path-as-is "ftp://127.0.0.1/../app/home.html" -u "$FTPU:Ftp-Pass-Word-1" -o /dev/null; }
t_ftp_refused()  { ! curl -s --max-time 20 ftp://127.0.0.1/ -u "$FTPU:Ftp-Pass-Word-1" -o /dev/null \
                   && ! ftps ftp://127.0.0.1/ -u "$FTPU:wrong-password" -o /dev/null && ! ftps ftp://127.0.0.1/ -u "shop7:Owner-Pass-Word-7" -o /dev/null \
                   && ! ftps ftp://127.0.0.1/ -u "anonymous:x" -o /dev/null && ! ftps ftp://127.0.0.1/ -u "root:x" -o /dev/null; }
t_ftp_password() { echo Ftp-Pass-Word-2 | lpanel ftp:password shop7 $FTPU >/dev/null \
                   && ! ftps ftp://127.0.0.1/ -u "$FTPU:Ftp-Pass-Word-1" -o /dev/null && ftps ftp://127.0.0.1/ -u "$FTPU:Ftp-Pass-Word-2" -o /dev/null; }
t_ftp_folder()   { lpanel ftp:folder shop7 $FTPU public_html >/dev/null && ftps --list-only ftp://127.0.0.1/ -u "$FTPU:Ftp-Pass-Word-2" > /tmp/ftpls \
                   && grep -qx ftp /tmp/ftpls && grep -qx app /tmp/ftpls; }
t_ftp_quota()    { head -c 30000000 /dev/urandom > /tmp/ftpbig.bin; ! ftps -T /tmp/ftpbig.bin ftp://127.0.0.1/big.bin -u "$FTPU:Ftp-Pass-Word-2"; local r=$?
                   rm -f $F/public_html/big.bin; return $r; }
t_ftp_session()  { python3 -c '
import ftplib, ssl, time
c = ssl.create_default_context(); c.check_hostname = False; c.verify_mode = ssl.CERT_NONE
f = ftplib.FTP_TLS(context=c, timeout=20); f.connect("127.0.0.1", 21); f.login("'"$FTPU"'", "Ftp-Pass-Word-2"); f.prot_p()
time.sleep(40)' & local py=$! pid=""
                   for _ in $(seq 20); do pid=$(lpanel ftp:sessions shop7 | awk -v u="$FTPU" '$2 == u {print $1; exit}'); [ -n "$pid" ] && break; sleep 0.5; done
                   [ -n "$pid" ] || { echo "no session listed"; kill $py; return 1; }
                   lpanel ftp:sessions | grep -q "shop7" && lpanel ftp:kill "$pid" >/dev/null || { kill $py; return 1; }
                   sleep 1; ! lpanel ftp:sessions shop7 | grep -q "$FTPU"; local r=$?; kill $py 2>/dev/null; wait $py 2>/dev/null; return $r; }
t_ftp_suspend()  { lpanel accounts:suspend shop7 >/dev/null && ! ftps ftp://127.0.0.1/ -u "$FTPU:Ftp-Pass-Word-2" -o /dev/null \
                   && [ ! -s /etc/pure-ftpd/lpanel/users.d/shop7 ] \
                   && lpanel accounts:unsuspend shop7 >/dev/null && ftps ftp://127.0.0.1/ -u "$FTPU:Ftp-Pass-Word-2" -o /dev/null; }
t_ftp_settings() { ! lpanel ftp:settings --passive-from=80 >/dev/null 2>&1 \
                   && lpanel ftp:settings --tls=optional >/dev/null && curl -s --max-time 20 ftp://127.0.0.1/ -u "$FTPU:Ftp-Pass-Word-2" -o /dev/null \
                   && lpanel ftp:settings --tls=required >/dev/null && ! curl -s --max-time 20 ftp://127.0.0.1/ -u "$FTPU:Ftp-Pass-Word-2" -o /dev/null \
                   && grep -Eq '^TLS +2$' /etc/pure-ftpd/pure-ftpd.conf; }
t_ftp_panel()    { [ "$(signin_as 7444 /panel shop7 Owner-Pass-Word-7)" = "303 https://127.0.0.1:7444/panel" ]                    && curl -ks -b /tmp/jar2 -c /tmp/jar2 https://127.0.0.1:7444/panel/ftp -o /tmp/ftp.html && grep -q "dev<wbr>@seven.example.test" /tmp/ftp.html \
                   && curl -ks -b /tmp/jar2 -c /tmp/jar2 -o /dev/null --data-urlencode "_token=$(token /tmp/ftp.html)" -d name=web -d domain=seven.example.test \
                        -d password=Ftp-Pass-Word-3 -d folder=public_html/app https://127.0.0.1:7444/panel/ftp \
                   && ftps --list-only ftp://127.0.0.1/ -u "web@seven.example.test:Ftp-Pass-Word-3" | grep -qx home.html; }
t_ftp_api()      { [ "$(api -o /tmp/ftpapi.json -w '%{http_code}' https://127.0.0.1:7443/admin/api/v1/ftp)" = 200 ] \
                   && grep -q '"running":true' /tmp/ftpapi.json && grep -q "\"login\":\"$FTPU\"" /tmp/ftpapi.json \
                   && [ "$(api -o /dev/null -w '%{http_code}' -X DELETE https://127.0.0.1:7443/admin/api/v1/accounts/shop7/ftp/web@seven.example.test)" = 204 ] \
                   && ! ftps ftp://127.0.0.1/ -u "web@seven.example.test:Ftp-Pass-Word-3" -o /dev/null && [ -f $F/public_html/app/home.html ]; }
t_ftp_audit()    { local got; got=$(mariadb -uroot -N lpanel -e "select distinct concat(action, ':', outcome) from audit_log where action like 'ftp.%'" | tr '\n' ' '); echo "$got" >&2
                   for want in ftp.create:ok ftp.password:ok ftp.folder:ok ftp.kill:ok ftp.settings:ok ftp.delete:ok; do
                       grep -qw "$want" <<<"$got" || { echo "missing $want"; return 1; }
                   done
                   ! mariadb -uroot -N lpanel -e "select details from audit_log where action like 'ftp.%'" | grep -q 'Ftp-Pass-Word'; }
check "Pure-FTPd runs with LPanel's configuration: TLS for logins, no anonymous" t_ftp_running
check "FTPS serves the hostname's certificate, the panel's own" t_ftp_cert
check "lpanel ftp:create makes a login and its folder, as the account's" t_ftp_cli
check "a file uploaded over FTPS is the account's, 644; folders 755" t_ftp_upload
check "a login sees only its folder" t_ftp_jail
check "plain FTP, wrong passwords, system users and anonymous are refused" t_ftp_refused
check "a new password holds at once" t_ftp_password
check "a new folder holds at once" t_ftp_folder
check "the account's quota holds over FTP" t_ftp_quota
check "a session is listed with its account and ended" t_ftp_session
check "a suspended account's logins are shut out, and let in again" t_ftp_suspend
check "lpanel ftp:settings: plain FTP only when allowed; bad ports refused" t_ftp_settings
check "the User panel lists logins and makes one" t_ftp_panel
check "the API shows the server and removes a login (its files stay)" t_ftp_api
check "FTP changes are in the audit log, never a password" t_ftp_audit

echo "== SSH and SFTP (jailed)"
# shop7 is on Filer (the default features: SFTP, no shell), then on Sheller.
SSHO=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=yes -o IdentitiesOnly=yes -o ConnectTimeout=10 -o LogLevel=ERROR)
sshk()  { local k=$1; shift; ssh -i "$k" "${SSHO[@]}" shop7@127.0.0.1 "$@"; }
sftpk() { local k=$1; shift; sftp -i "$k" "${SSHO[@]}" -b - shop7@127.0.0.1 "$@"; }
rm -f /tmp/k1 /tmp/k1.pub /tmp/k2 /tmp/k2.pub /tmp/kgen
ssh-keygen -q -t ed25519 -N '' -C e2e@laptop -f /tmp/k1; ssh-keygen -q -t ed25519 -N '' -C own -f /tmp/k2
t_ssh_setup()    { sshd -T -C user=shop7,host=x,addr=127.0.0.1 > /tmp/sshdT; grep -Ei '^(authorizedkeysfile|allowtcpforwarding|passwordauthentication|permituserrc) ' /tmp/sshdT >&2
                   systemctl is-active --quiet sshd && { [ -f /etc/ssh/sshd_config.d/40-lpanel.conf ] || grep -q '^# BEGIN LPanel accounts' /etc/ssh/sshd_config; } \
                   && grep -qx 'authorizedkeysfile /etc/ssh/lpanel/keys/%u' /tmp/sshdT && grep -qx 'allowtcpforwarding no' /tmp/sshdT \
                   && grep -qx 'passwordauthentication no' /tmp/sshdT && grep -qx 'permituserrc no' /tmp/sshdT \
                   && sshd -T -C user=root,host=x,addr=127.0.0.1 | grep -qx 'authorizedkeysfile .ssh/authorized_keys' \
                   && id -nG shop7 | tr ' ' '\n' | grep -qx lpanel-users && [ "$(getent passwd shop7 | cut -d: -f7)" = /usr/libexec/lpanel/jail-sftp ]; }
t_ssh_key_cli()  { lpanel ssh:add shop7 < /tmp/k1.pub >/dev/null && [ "$(stat -c '%a %U %G' /etc/ssh/lpanel/keys/shop7)" = "640 root shop7" ] \
                   && grep -q "^restrict,pty ssh-ed25519 $(cut -d' ' -f2 /tmp/k1.pub) e2e@laptop$" /etc/ssh/lpanel/keys/shop7 \
                   && lpanel ssh:keys shop7 > /tmp/sshkeys && grep -qF "$(ssh-keygen -lf /tmp/k1.pub | cut -d' ' -f2)" /tmp/sshkeys && grep -q 'SFTP only' /tmp/sshkeys; }
t_ssh_sftp_only() { printf 'over sftp\n' > /tmp/sftpup.txt; printf 'put /tmp/sftpup.txt public_html/sftpup.txt\nls /home\nls /\n' | sftpk /tmp/k1 > /tmp/sftp.out; cat /tmp/sftp.out >&2
                   [ "$(cat $F/public_html/sftpup.txt)" = "over sftp" ] && [ "$(stat -c '%U %G %a' $F/public_html/sftpup.txt)" = "shop7 shop7 644" ] \
                   && grep -qx '/home/shop7 *' /tmp/sftp.out && ! grep -q '/home/shop[0-689]' /tmp/sftp.out \
                   && ! sshk /tmp/k1 id 2>/tmp/ssh.err && grep -q 'SFTP access only' /tmp/ssh.err; }
t_ssh_shell()    { lpanel packages:create Sheller --disk=20 --features=domains,file_manager,ftp,sftp,ssh >/dev/null && lpanel accounts:package shop7 Sheller >/dev/null \
                   && for _ in $(seq 30); do [ "$(getent passwd shop7 | cut -d: -f7)" = /usr/libexec/lpanel/jail-shell ] && break; lpanel accounts:settle >/dev/null; sleep 1; done \
                   && sshk /tmp/k1 'id -un; echo "home=$(ls /home)"; echo "procs=$(ps -e --no-headers | wc -l)"; echo "passwd=$(wc -l < /etc/passwd)"; touch /etc/x 2>/dev/null && echo etc-writable; echo shell-was-here > ~/public_html/shell.txt; test -S /var/lib/mysql/mysql.sock && echo db-socket; command -v git >/dev/null && echo has-git; ls /opt/remi >/dev/null 2>&1 && echo has-php' > /tmp/ssh.out; cat /tmp/ssh.out >&2
                   grep -qx shop7 /tmp/ssh.out && grep -qx 'home=shop7' /tmp/ssh.out && grep -qx 'passwd=3' /tmp/ssh.out && ! grep -q etc-writable /tmp/ssh.out \
                   && [ "$(grep -o 'procs=[0-9]*' /tmp/ssh.out | cut -d= -f2)" -lt 8 ] && grep -qx db-socket /tmp/ssh.out && grep -qx has-git /tmp/ssh.out && grep -qx has-php /tmp/ssh.out \
                   && [ "$(stat -c '%U' $F/public_html/shell.txt)" = shop7 ] && [ "$(ssh -tt -i /tmp/k1 "${SSHO[@]}" shop7@127.0.0.1 'echo tty-ok' </dev/null | tr -d '\r')" = tty-ok ]; }
t_ssh_refused()  { mkdir -p $F/.ssh && cp /tmp/k2.pub $F/.ssh/authorized_keys && chown -R shop7: $F/.ssh && chmod 700 $F/.ssh \
                   && ! sshk /tmp/k2 true 2>/dev/null && rm -rf $F/.ssh \
                   && ! ssh "${SSHO[@]}" -o PubkeyAuthentication=no shop7@127.0.0.1 true 2>/dev/null || return 1
                   ! ssh -i /tmp/k1 "${SSHO[@]}" -o ExitOnForwardFailure=yes -W 127.0.0.1:22 shop7@127.0.0.1 2>/tmp/fwd.err </dev/null || return 1
                   cat /tmp/fwd.err >&2; grep -Eqi 'prohibited|forwarding failed' /tmp/fwd.err; }
t_ssh_generate() { lpanel ssh:generate shop7 made-here > /tmp/kgen 2>/dev/null && chmod 600 /tmp/kgen && head -1 /tmp/kgen | grep -q 'BEGIN OPENSSH PRIVATE KEY' \
                   && [ "$(sshk /tmp/kgen 'id -un')" = shop7 ] && ! mariadb -uroot -N lpanel -e "select * from ssh_keys" | grep -q PRIVATE; }
t_ssh_delete()   { lpanel ssh:delete shop7 e2e@laptop >/dev/null && ! sshk /tmp/k1 true 2>/dev/null && [ "$(sshk /tmp/kgen 'echo still')" = still ] \
                   && ! grep -q "$(cut -d' ' -f2 /tmp/k1.pub)" /etc/ssh/lpanel/keys/shop7; }
t_ssh_suspend()  { lpanel accounts:suspend shop7 >/dev/null && ! sshk /tmp/kgen true 2>/dev/null \
                   && lpanel accounts:unsuspend shop7 >/dev/null && [ "$(sshk /tmp/kgen 'echo back')" = back ]; }
t_ssh_reapply()  { usermod --shell /bin/bash shop7 && lpanel accounts:reapply >/dev/null \
                   && for _ in $(seq 30); do [ "$(getent passwd shop7 | cut -d: -f7)" = /usr/libexec/lpanel/jail-shell ] && break; lpanel accounts:settle >/dev/null; sleep 1; done \
                   && [ "$(getent passwd shop7 | cut -d: -f7)" = /usr/libexec/lpanel/jail-shell ]; }
t_ssh_panel()    { [ "$(signin_as 7444 /panel shop7 Owner-Pass-Word-7)" = "303 https://127.0.0.1:7444/panel" ] \
                   && curl -ks -b /tmp/jar2 -c /tmp/jar2 https://127.0.0.1:7444/panel/ssh -o /tmp/ssh.html && grep -q 'made-here' /tmp/ssh.html && grep -q 'Shell and SFTP' /tmp/ssh.html \
                   && curl -ks -b /tmp/jar2 -c /tmp/jar2 -o /tmp/sshgen.html --data-urlencode "_token=$(token /tmp/ssh.html)" -d name=panel-made https://127.0.0.1:7444/panel/ssh/generate \
                   && grep -q 'BEGIN OPENSSH PRIVATE KEY' /tmp/sshgen.html && grep -q 'panel-made' /etc/ssh/lpanel/keys/shop7; }
t_ssh_api()      { [ "$(api -o /tmp/sshapi.json -w '%{http_code}' https://127.0.0.1:7443/admin/api/v1/accounts/shop7/ssh-keys)" = 200 ] \
                   && grep -q '"access":"jail"' /tmp/sshapi.json && grep -q '"name":"made-here"' /tmp/sshapi.json \
                   && [ "$(api -o /dev/null -w '%{http_code}' -X DELETE https://127.0.0.1:7443/admin/api/v1/accounts/shop7/ssh-keys/panel-made)" = 204 ] \
                   && ! grep -q 'panel-made' /etc/ssh/lpanel/keys/shop7; }
t_ssh_audit()    { local got; got=$(mariadb -uroot -N lpanel -e "select distinct concat(action, ':', outcome) from audit_log where action like 'ssh.%'" | tr '\n' ' '); echo "$got" >&2
                   for a in ssh.key_add:ok ssh.key_generate:ok ssh.key_delete:ok; do echo "$got" | grep -q "$a" || return 1; done
                   ! mariadb -uroot -N lpanel -e "select details from audit_log where action like 'ssh.%'" | grep -q PRIVATE; }
check "sshd takes LPanel's rules for accounts: keys from the panel only, no passwords, no forwarding" t_ssh_setup
check "lpanel ssh:add writes the key, restricted, readable by the account alone" t_ssh_key_cli
check "an SFTP-only account moves files over SFTP, sees only its home, and gets no shell" t_ssh_sftp_only
check "a jailed shell: its home only, its own processes, a read-only system, PHP, git and MariaDB" t_ssh_shell
check "a key in ~/.ssh, a password and forwarding are all refused" t_ssh_refused
check "lpanel ssh:generate makes a key that signs in, and keeps no private key" t_ssh_generate
check "a removed key no longer signs in" t_ssh_delete
check "a suspended account cannot sign in, and can again once unsuspended" t_ssh_suspend
check "lpanel accounts:reapply gives the server's own shell back to the jail" t_ssh_reapply
check "the User panel lists keys and makes one" t_ssh_panel
check "the API lists keys and removes one" t_ssh_api
check "SSH key changes are in the audit log, never a private key" t_ssh_audit

echo "== backups (restic)"
# A local destination, every account, then an SFTP one on this machine.
R() { restic --no-cache -r "$1" --password-file "/etc/lpa/backup/destinations/$2.pass" "${@:3}"; }
t_bk_dest()     { lpanel backup:destination-add disk local --path=/backup/lpanel/ > /tmp/bk.out && cat /tmp/bk.out >&2 \
                  && [ -f /backup/lpanel/config ] && [ "$(stat -c '%a %U' /etc/lpa/backup)" = "700 root" ] \
                  && [ "$(stat -c '%a %U' /etc/lpa/backup/destinations/d1.pass)" = "600 root" ] \
                  && /usr/local/lpa/bin/lpa backup-password d1 | grep -Eqx '[0-9a-f]{64}' \
                  && ! lpanel backup:destination-add home local --path=/home/x >/dev/null 2>&1 \
                  && lpanel backup:destinations | grep -q '/backup/lpanel'; }
t_bk_run()      { lpanel backup:settings --enabled=1 --time=23:59 --destination=disk --daily=1 --weekly=0 --monthly=0 >/dev/null \
                  && lpanel backup:run --wait > /tmp/bkrun.out 2>&1; local r=$?; cat /tmp/bkrun.out >&2
                  [ $r = 0 ] && grep -q '^shop7 *ok ' /tmp/bkrun.out && grep -q '^shop3 *ok ' /tmp/bkrun.out && ! grep -q FAILED /tmp/bkrun.out \
                  && [ "$(R /backup/lpanel d1 snapshots --json --tag account:shop7 | grep -o '"short_id"' | wc -l)" = 1 ]; }
t_bk_content()  { [ "$(R /backup/lpanel d1 dump --tag account:shop7 latest /home/shop7/public_html/sftpup.txt)" = "over sftp" ] \
                  && R /backup/lpanel d1 ls --tag account:shop3 latest > /tmp/bkls && grep -q '/var/lib/lpa/backup/shop3/databases/shop3_data.sql' /tmp/bkls \
                  && grep -q '/var/lib/lpa/backup/shop3/lpanel.json' /tmp/bkls && ! grep -q '^/home/shop3/tmp/.' /tmp/bkls \
                  && R /backup/lpanel d1 dump --tag account:shop3 latest /var/lib/lpa/backup/shop3/databases/shop3_data.sql | grep -q 'CREATE DATABASE.*shop3_data' \
                  && R /backup/lpanel d1 dump --tag account:shop3 latest /var/lib/lpa/backup/shop3/lpanel.json | grep -q '"username":"shop3"' \
                  && ! R /backup/lpanel d1 dump --tag account:shop3 latest /var/lib/lpa/backup/shop3/lpanel.json | grep -q totp_secret \
                  && [ -z "$(ls -A /var/lib/lpa/backup 2>/dev/null)" ]; }
t_bk_keep()     { lpanel backup:run --accounts=shop7 --wait >/dev/null 2>&1 \
                  && [ "$(R /backup/lpanel d1 snapshots --json --tag account:shop7 | grep -o '"short_id"' | wc -l)" = 1 ] \
                  && lpanel backup:snapshots shop7 | grep -Eq '^[0-9a-f]{8} '; }
t_bk_wrongpw()  { mkdir -p /backup/other && restic --no-cache -r /backup/other init --password-file <(echo someone-else) >/dev/null 2>&1 \
                  && ! lpanel backup:destination-add other local --path=/backup/other > /tmp/bkw.out 2>&1; cat /tmp/bkw.out >&2; grep -q "password does not open it" /tmp/bkw.out; }
t_bk_sftp()     { id bkp >/dev/null 2>&1 || useradd -m bkp
                  ! lpanel backup:destination-add box sftp --host=127.0.0.1 --user=bkp --path=/home/bkp/repo >/dev/null 2>&1 || return 1
                  install -d -m 700 -o bkp -g bkp /home/bkp/.ssh && cp /etc/lpa/backup/id_ed25519.pub /home/bkp/.ssh/authorized_keys \
                  && chown bkp: /home/bkp/.ssh/authorized_keys && chmod 600 /home/bkp/.ssh/authorized_keys \
                  && lpanel backup:destination-add box sftp --host=127.0.0.1 --user=bkp --path=/home/bkp/repo > /tmp/bks.out && grep -q 'ssh-ed25519' /tmp/bks.out \
                  && [ -f /home/bkp/repo/config ] && lpanel backup:run --accounts=shop7 --destination=box --wait >/dev/null 2>&1 \
                  && lpanel backup:snapshots shop7 --destination=box | grep -Eq '^[0-9a-f]{8} '; }
t_bk_panel()    { signin "$ROOT_PASSWORD" >/dev/null; curl -ks -b $jar -c $jar https://127.0.0.1:7443/admin/backups -o /tmp/bk.html && grep -q '/backup/lpanel' /tmp/bk.html \
                  && grep -q 'lpanel-backup' /tmp/bk.html && grep -q 'Daily, 23:59' /tmp/bk.html \
                  && curl -ks -b $jar -c $jar 'https://127.0.0.1:7443/admin/backups?account=shop7' -o /tmp/bk2.html && grep -Eq '<td class="mono">[0-9a-f]{8}</td>' /tmp/bk2.html; }
t_bk_api()      { [ "$(api -o /tmp/bkapi.json -w '%{http_code}' https://127.0.0.1:7443/admin/api/v1/backups)" = 200 ] && grep -q '"name":"disk"' /tmp/bkapi.json \
                  && [ "$(api -o /tmp/bkacct.json -w '%{http_code}' https://127.0.0.1:7443/admin/api/v1/accounts/shop7/backups)" = 200 ] && grep -q '"ok":true' /tmp/bkacct.json \
                  && [ "$(api -o /dev/null -w '%{http_code}' -X DELETE https://127.0.0.1:7443/admin/api/v1/backups/destinations/1)" = 409 ]; }
t_bk_audit()    { local got; got=$(mariadb -uroot -N lpanel -e "select distinct concat(action, ':', outcome) from audit_log where action like 'backup.%'" | tr '\n' ' '); echo "$got" >&2
                  for a in backup.destination_add:ok backup.destination_add:failed backup.settings:ok backup.run:ok backup.finished:ok; do echo "$got" | grep -q "$a" || return 1; done; }
check "a local destination gets an encrypted repository; its password is root's alone" t_bk_dest
check "lpanel backup:run backs every account up, one snapshot each" t_bk_run
check "a snapshot holds the home, each database's dump and the account's description" t_bk_content
check "retention keeps what the schedule says" t_bk_keep
check "a repository with another password is refused, not overwritten" t_bk_wrongpw
check "an SFTP destination works once the agent's key is authorized" t_bk_sftp
check "the Admin panel shows destinations, the schedule and an account's snapshots" t_bk_panel
check "the API shows backups, an account's last one, and protects the scheduled destination" t_bk_api
check "backup changes are in the audit log" t_bk_audit

echo "== restores"
snap() { lpanel backup:snapshots "$1" ${2:+--destination=$2} | awk 'NR == 2 { print $1 }'; }
t_rs_files()    { local s; s=$(snap shop7); [ -n "$s" ] || return 1
                  echo broken > /home/shop7/public_html/sftpup.txt && echo extra > /home/shop7/public_html/made-since.txt \
                  && chown shop7: /home/shop7/public_html/made-since.txt \
                  && ln -sfn /etc /home/shop7/public_html/evil && chown -h shop7: /home/shop7/public_html/evil \
                  && lpanel backup:restore shop7 "$s" --paths=public_html --wait > /tmp/rs.out 2>&1; local r=$?; cat /tmp/rs.out >&2
                  [ $r = 0 ] && [ "$(cat /home/shop7/public_html/sftpup.txt)" = "over sftp" ] && [ "$(stat -c %U /home/shop7/public_html/sftpup.txt)" = shop7 ] \
                  && [ -f /home/shop7/public_html/made-since.txt ] && [ -z "$(ls -A /var/lib/lpa/restore 2>/dev/null)" ] \
                  && [ ! -e /etc/sftpup.txt ] && [ -z "$(find /home/shop7/public_html -user root -print -quit)" ]; }
t_rs_folder()   { lpanel backup:restore shop7 "$(snap shop7)" --paths=public_html/sftpup.txt --folder --wait >/dev/null 2>&1 \
                  && f=$(ls -d /home/shop7/restored-*/public_html/sftpup.txt) && [ "$(cat $f)" = "over sftp" ] && [ "$(stat -c %U "$(dirname "$(dirname "$f")")")" = shop7 ]; }
t_rs_database() { mariadb -uroot shop3_data -e "create table notes (note varchar(20)); insert into notes values ('kept')" \
                  && lpanel backup:run --accounts=shop3 --wait >/dev/null 2>&1 && mariadb -uroot shop3_data -e "delete from notes" \
                  && lpanel backup:restore shop3 "$(snap shop3)" --databases=shop3_data --wait >/dev/null 2>&1 \
                  && [ "$(mariadb -ushop3_app "-p$DBPW" -N shop3_data -e 'select note from notes')" = kept ] \
                  && ! lpanel backup:restore shop3 "$(snap shop3)" --databases=shop7_x >/dev/null 2>&1; }
t_rs_mailbox()  { local d=/home/shop3/mail/three.example.test/sender; [ -d "$d" ] || return 1
                  lpanel backup:restore shop3 "$(snap shop3)" --mailbox=sender@three.example.test --wait >/dev/null 2>&1 && [ "$(stat -c %U "$d")" = shop3 ]; }
t_rs_download() { lpanel backup:download shop7 "$(snap shop7)" --path=public_html --wait > /tmp/rsd.out 2>&1; cat /tmp/rsd.out >&2
                  f=$(ls /home/shop7/lpanel-backups/*public_html.tar.gz) && [ "$(stat -c '%U %a' "$f")" = "shop7 600" ] \
                  && tar -tzf "$f" | grep -q 'public_html/sftpup.txt' \
                  && lpanel backup:download shop3 "$(snap shop3)" --database=shop3_data --wait >/dev/null 2>&1 \
                  && zcat /home/shop3/lpanel-backups/shop3_data-*.sql.gz | grep -q "INSERT INTO \`notes\`"; }
# shop1 is on Starter, which has the backups feature (shop7's package does not).
t_rs_panel()    { echo probe > /home/shop1/public_html/restore-probe.txt && chown shop1: /home/shop1/public_html/restore-probe.txt \
                  && lpanel backup:run --accounts=shop1 --wait >/dev/null 2>&1 || return 1
                  local s; s=$(snap shop1); signin "$ROOT_PASSWORD" >/dev/null
                  curl -ks -b $jar -c $jar "https://127.0.0.1:7443/admin/backups/accounts/shop1?snapshot=$s" -o /tmp/rsp.html && grep -q 'public_html' /tmp/rsp.html \
                  && [ "$(signin_as 7444 /panel shop1 Owner-Pass-Word-1)" = "303 https://127.0.0.1:7444/panel" ] \
                  && curl -ks -b /tmp/jar2 -c /tmp/jar2 "https://127.0.0.1:7444/panel/backups?snapshot=$s&path=public_html" -o /tmp/rsu.html && grep -q 'restore-probe.txt' /tmp/rsu.html \
                  && echo broken > /home/shop1/public_html/restore-probe.txt \
                  && curl -ks -b /tmp/jar2 -c /tmp/jar2 -o /dev/null --data-urlencode "_token=$(token /tmp/rsu.html)" -d "snapshot=$s" -d kind=files \
                       -d 'paths[]=public_html/restore-probe.txt' -d mode=place https://127.0.0.1:7444/panel/backups/restore \
                  && for _ in $(seq 30); do lpanel backup:settle >/dev/null; [ "$(cat /home/shop1/public_html/restore-probe.txt)" = probe ] && break; sleep 1; done \
                  && [ "$(cat /home/shop1/public_html/restore-probe.txt)" = probe ] \
                  && [ "$(signin_as 7444 /panel shop7 Owner-Pass-Word-7)" = "303 https://127.0.0.1:7444/panel" ] \
                  && [ "$(curl -ks -b /tmp/jar2 -o /dev/null -w '%{http_code}' https://127.0.0.1:7444/panel/backups)" = 403 ]; }
t_rs_api()      { local code; code=$(api -o /tmp/rsa.json -w '%{http_code}' "https://127.0.0.1:7443/admin/api/v1/accounts/shop7/backups/files?snapshot=$(snap shop7)&path=public_html") \
                  && [ "$code" = 200 ] && grep -q sftpup.txt /tmp/rsa.json \
                  && [ "$(api -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d "{\"snapshot\":\"$(snap shop7)\",\"kind\":\"files\",\"paths\":[\"../../etc\"]}" https://127.0.0.1:7443/admin/api/v1/accounts/shop7/backups/restores)" = 422 ]; }
# The exit: an account that is gone, made again from its backup through a
# destination added as another server would add it (with the repository's password).
t_rs_moved()    { /usr/local/lpa/bin/lpa backup-password d1 | lpanel backup:destination-add moved local --path=/backup/lpanel --existing > /tmp/rsm.out 2>&1; cat /tmp/rsm.out >&2
                  lpanel backup:accounts --destination=moved > /tmp/rsacc.out && grep -Eq '^shop3 +[0-9a-f]{8} .* yes$' /tmp/rsacc.out \
                  && ! { echo wrong-password | lpanel backup:destination-add moved2 local --path=/backup/lpanel --existing >/dev/null 2>&1; }; }
active_shop3()  { lpanel accounts:list > /tmp/rsl.out 2>/dev/null; grep -Eq '^shop3 +three\.example\.test .*active' /tmp/rsl.out; }
t_rs_recreate() { local s rex; s=$(snap shop3 moved); rex=$(mariadb -uroot -N lpanel -e "select id from panel_users where username='rex'")
                  lpanel accounts:terminate shop3 --yes >/dev/null && for _ in $(seq 90); do systemctl start lpanel-scheduler.service; getent passwd shop3 >/dev/null || { lpanel accounts:list > /tmp/rsl2.out 2>&1; grep -q '^shop3 ' /tmp/rsl2.out || break; }; sleep 1; done
                  ! getent passwd shop3 && ! mariadb -uroot -e 'use shop3_data' 2>/dev/null || return 1
                  lpanel backup:recreate "$s" --destination=moved --dry-run > /tmp/rsdry.out && grep -q 'reseller rex' /tmp/rsdry.out \
                  && lpanel backup:recreate "$s" --destination=moved --wait > /tmp/rsr.out 2>&1; local r=$?; [ $r = 0 ] || { tail -c 1500 /var/log/lpanel/php-error.log /usr/local/lpanel/system/Logs/* >&2 2>/dev/null; echo "--- dry run:" >&2; tail -6 /tmp/rsdry.out >&2; echo "--- recreate (exit $r):" >&2; tail -14 /tmp/rsr.out >&2; return 1; }; cat /tmp/rsr.out >&2
                  for _ in $(seq 30); do systemctl start lpanel-scheduler.service; says three.example.test "bool(false)" >/dev/null 2>&1 && break; sleep 1; done
                  for _ in $(seq 60); do systemctl start lpanel-scheduler.service; active_shop3 && break; sleep 1; done
                  local n=0; step() { n=$((n+1)); "$@" || { echo "step $n failed: $*" >&2; return 1; }; }
                  step getent passwd shop3 >/dev/null && step active_shop3 \
                  && step test "$(mariadb -uroot -N lpanel -e "select owner_id from accounts where username='shop3'")" = "$rex" \
                  && step says three.example.test "bool(false)" \
                  && step test "$(mariadb -ushop3_app "-p$DBPW" -N shop3_data -e 'select note from notes')" = kept \
                  && step eval "python3 /tmp/imap.py imaps 127.0.0.1 sender@three.example.test '$MPW' | grep -q MESSAGES" \
                  && step test "$(signin_as 7444 /panel shop3 Owner-Pass-Word-3)" = "303 https://127.0.0.1:7444/panel" \
                  && step test -z "$(find /home/shop3/public_html -user root -print -quit)"; }
t_rs_audit()    { local got; got=$(mariadb -uroot -N lpanel -e "select distinct concat(action, ':', outcome) from audit_log where action like 'backup.%' or (action = 'account.create' and details like '%restored%')" | tr '\n' ' '); echo "$got" >&2
                  for a in backup.restore:ok backup.restored:ok backup.download:ok backup.download_ready:ok backup.account_restore:ok account.create:ok; do echo "$got" | grep -q "$a" || return 1; done; }
check "files go back in place, as the owner's; newer files stay; a link out of the home is not followed" t_rs_files
check "or into a restored-<time> folder" t_rs_folder
check "a database comes back as it was; another account's is refused" t_rs_database
check "a mailbox comes back" t_rs_mailbox
check "downloads are archives in the home's lpanel-backups/" t_rs_download
check "the Admin panel and the owner's User panel browse and restore" t_rs_panel
check "the API browses a snapshot and refuses a path out of the home" t_rs_api
check "another server's repository opens with its password, and shows its accounts" t_rs_moved
check "a terminated account is made again from its backup: site, database, mail, owner's login, reseller" t_rs_recreate
check "restores are in the audit log" t_rs_audit

# A container has no firewalld, so the firewall manager must say so and change nothing;
# on a real server it works on firewalld (tried by hand, see design/ROADMAP.md M9a).
t_fw_off()      { lpanel firewall:status > /tmp/fw1.out 2>&1; grep -q "no firewalld" /tmp/fw1.out \
                  && { lpanel firewall:deny 198.51.100.9 > /tmp/fw2.out 2>&1; [ $? = 1 ]; } && grep -q "no firewalld" /tmp/fw2.out \
                  && { lpanel firewall:deny rubbish > /tmp/fw3.out 2>&1; [ $? = 1 ]; } && grep -q "not an IPv4 or IPv6" /tmp/fw3.out \
                  && [ "$(mariadb -uroot -N lpanel -e "select count(*) from firewall_entries")" = 0 ]; }
check "the firewall manager says a server without firewalld has none, and keeps nothing" t_fw_off

# Brute-force blocking reads the agent's view of the logs. A container has no
# firewalld, so nothing can be blocked here, but the real agent op, the
# cursor round trip and the counting are all exercised.
t_bf_read() {
    touch /var/log/secure && chmod 600 /var/log/secure \
        && lpanel bruteforce:scan > /tmp/bf1.out 2>&1 || { cat /tmp/bf1.out; return 1; }
    for i in 1 2 3 4 5 6 7 8 9 10; do
        echo "Nov 24 10:00:0$((i % 10)) e2e sshd[400$i]: Failed password for root from 198.51.100.9 port 40$i ssh2" >> /var/log/secure
    done
    echo "Nov 24 10:00:09 e2e sshd[4100]: Failed password for root from 127.0.0.1 port 41 ssh2" >> /var/log/secure
    echo "Nov 24 10:00:09 e2e sshd[4101]: Accepted password for root from 198.51.100.77 port 42 ssh2" >> /var/log/secure
    lpanel bruteforce:scan > /tmp/bf2.out 2>&1
    lpanel bruteforce:scan > /tmp/bf3.out 2>&1
    [ "$(mariadb -uroot -N lpanel -e "select coalesce(sum(\`count\`),0) from bruteforce_failures where ip='198.51.100.9' and service='ssh'")" = 10 ] \
        && [ "$(mariadb -uroot -N lpanel -e "select count(*) from bruteforce_failures where ip<>'198.51.100.9'")" = 0 ]
}
t_bf_status() {
    lpanel bruteforce:status > /tmp/bf4.out 2>&1 && grep -q "Blocking is on: 10 failed sign-ins within 10 minutes" /tmp/bf4.out \
        && grep -q "198.51.100.9" /tmp/bf4.out
}
t_bf_page() {
    curl -sk -o /dev/null -w '%{http_code}' https://127.0.0.1:7443/admin/security/bruteforce | grep -q '^\(302\|200\)$'
}
check "brute-force blocking counts each failed sign-in in the logs once, from the real agent" t_bf_read
check "brute-force status shows the limit and the busiest address" t_bf_status
check "the brute-force page is served" t_bf_page

# Account isolation (M9d): the installer turned it on, and everything above
# ran with other users' processes hidden in /proc. Here: an account sees only
# its own processes, root sees all, the units survive, and it can be switched
# off and on again.
procs_of() { runuser -u "$1" -- ps -e --no-headers 2>/dev/null | wc -l; }
t_iso_on() {
    lpanel isolation:status > /tmp/iso1.out 2>&1
    local why=""
    grep -q "hidepid): chosen on, server on, kept across reboots" /tmp/iso1.out || why="$why status-hidepid;"
    grep -q "Link protections: chosen on, server on, kept across reboots" /tmp/iso1.out || why="$why status-links;"
    grep -q "hidepid=" /proc/self/mounts || why="$why mount;"
    systemctl is-enabled --quiet lpanel-hidepid.service || why="$why unit;"
    [ "$(sysctl -n fs.protected_symlinks fs.protected_hardlinks fs.protected_regular fs.protected_fifos | tr '\n' ' ')" = "1 1 2 2 " ] || why="$why sysctl;"
    # fs.protected_* belong to the kernel every container on this machine shares, so another
    # container (or the host) can change them under us; sync puts them back, which is what it is for.
    if [ -n "$why" ] && [ "$why" = " status-links; sysctl;" ]; then
        lpanel isolation:sync > /tmp/iso1b.out 2>&1 && why=""
        [ "$(sysctl -n fs.protected_symlinks fs.protected_hardlinks fs.protected_regular fs.protected_fifos | tr '\n' ' ')" = "1 1 2 2 " ] || why=" sysctl-after-sync;"
    fi
    [ -z "$why" ] || { echo "not as expected:$why"; grep -a " 1$" -A21 /tmp/fifos.log | head -23; return 1; }
}
t_iso_hidden() {
    # root sees dozens of processes, an account its own ps and little else
    [ "$(ps -e --no-headers | wc -l)" -ge 15 ] && [ "$(procs_of shop1)" -le 4 ]
}
t_iso_services() {
    for u in lpa lpanel-nginx lpanel-php-fpm mariadb; do systemctl is-active --quiet $u || { echo "$u is not running"; return 1; }; done
    lpanel accounts:list > /dev/null
}
t_iso_off_on() {
    lpanel isolation:set --hidepid=off --links=off > /tmp/iso2.out 2>&1 || { cat /tmp/iso2.out; return 1; }
    [ "$(procs_of shop1)" -ge 15 ] || { echo "still hidden: $(procs_of shop1)"; return 1; }
    [ ! -e /etc/systemd/system/lpanel-hidepid.service ] && [ ! -e /etc/sysctl.d/90-lpanel-isolation.conf ] || return 1
    [ "$(sysctl -n fs.protected_fifos)" = 1 ] || return 1
    lpanel isolation:set --hidepid=on --links=on > /tmp/iso3.out 2>&1 || { cat /tmp/iso3.out; return 1; }
    [ "$(procs_of shop1)" -le 4 ] && [ "$(sysctl -n fs.protected_fifos)" = 2 ] && systemctl is-enabled --quiet lpanel-hidepid.service
}
t_iso_pool_tmp() {
    grep -q 'env\[TMPDIR\] = /home/shop1/tmp' /etc/opt/remi/php83/php-fpm.d/lpanel-shop1.conf
}
check "account isolation is on: hidepid and the link protections, kept across reboots" t_iso_on
check "an account sees only its own processes, root sees all" t_iso_hidden
check "the services still run with /proc hidden" t_iso_services
check "isolation switches off and on again" t_iso_off_on
check "PHP pools give programs the account's own temporary folder" t_iso_pool_tmp

# Operations (M10): the services page's data, the watchdog bringing back a
# service something outside the panel stopped (and leaving alone one stopped
# through the panel), the cron jobs of an account, and the agent's task list.
t_ops_services() {
    lpanel services:status > /tmp/ops1.out 2>&1 \
        && grep -q "^mariadb.service  *active" /tmp/ops1.out && grep -q "^lpa.service  *active" /tmp/ops1.out \
        && grep -q "^dovecot.service  *active  *enabled" /tmp/ops1.out
}
t_ops_refused() {
    { lpanel services:stop mariadb > /tmp/ops2.out 2>&1; [ $? = 1 ]; } && grep -q "cannot be" /tmp/ops2.out \
        && { lpanel services:stop lpa > /tmp/ops2b.out 2>&1; [ $? = 1 ]; } && systemctl is-active --quiet mariadb lpa
}
t_ops_watchdog() {
    systemctl stop dovecot
    # The first round notes that it is down; one a little later (the grace is 20 seconds) restarts it.
    lpanel watchdog:run > /tmp/ops3a.out 2>&1
    sleep 22
    lpanel watchdog:run > /tmp/ops3.out 2>&1
    systemctl is-active --quiet dovecot || { cat /tmp/ops3a.out /tmp/ops3.out; return 1; }
    # Either this round or the scheduler's restarted it; it is up and was down once.
    [ "$(mariadb -uroot -N lpanel -e "select count(*) from ops_service_events where unit='dovecot.service' and kind='restarted'")" -ge 1 ]
}
t_ops_hold() {
    lpanel services:stop dovecot > /tmp/ops4.out 2>&1 || { cat /tmp/ops4.out; return 1; }
    lpanel watchdog:run > /tmp/ops5.out 2>&1
    ! systemctl is-active --quiet dovecot || return 1
    lpanel services:start dovecot > /tmp/ops6.out 2>&1 || { cat /tmp/ops6.out; return 1; }
    systemctl is-active --quiet dovecot
}
t_ops_events() {
    [ "$(mariadb -uroot -N lpanel -e "select group_concat(kind order by id) from ops_service_events where unit='dovecot.service' and kind<>'recovered'")" = "down,restarted,admin,admin" ] \
        || { mariadb -uroot -N lpanel -e "select unit, kind, detail from ops_service_events"; return 1; }
}
t_ops_cron() {
    local n=0; step() { n=$((n+1)); "$@" || { echo "cron step $n failed: $*" >&2; return 1; }; }
    step systemctl is-active --quiet crond || return 1
    step lpanel cron:add shop1 "*/5 * * * *" "date > /home/shop1/tmp/cron-ran 2>&1" > /tmp/ops7.out 2>&1 || { cat /tmp/ops7.out; return 1; }
    step grep -q '^\*/5 \* \* \* \* shop1 date > /home/shop1/tmp/cron-ran' /etc/cron.d/lpanel-shop1 || { cat /etc/cron.d/lpanel-shop1; return 1; }
    step grep -q '^TMPDIR=/home/shop1/tmp' /etc/cron.d/lpanel-shop1 || return 1
    step test "$(stat -c '%U:%a' /etc/cron.d/lpanel-shop1)" = "root:644" || return 1
    lpanel cron:add shop1 "* * * * *" true > /tmp/ops8.out 2>&1; step test $? = 1 || return 1
    step grep -q "at most once every 5 minutes" /tmp/ops8.out || { cat /tmp/ops8.out; return 1; }
    for _ in $(seq 20); do journalctl -u crond --no-pager | grep -q "lpanel-shop1" && break; sleep 1; done
    journalctl -u crond --no-pager | grep -q "lpanel-shop1" || { echo "crond did not mention the file:"; journalctl -u crond --no-pager | tail -5; }
    step lpanel cron:remove shop1 "$(mariadb -uroot -N lpanel -e "select id from cron_jobs limit 1")" > /dev/null || return 1
    step test ! -e /etc/cron.d/lpanel-shop1
}
t_ops_tasks() {
    lpanel tasks:list --limit=100 > /tmp/ops9.out 2>&1 && grep -q "cron.apply" /tmp/ops9.out && grep -q "services.act" /tmp/ops9.out
}
check "the services are listed with their state, and the panel's own cannot be stopped" t_ops_services
check "the agent and the database refuse to be stopped from the panel" t_ops_refused
check "the watchdog restarts a service something else stopped" t_ops_watchdog
check "a service stopped through the panel is left alone until started" t_ops_hold
check "the watchdog's and the administrator's actions are in the log" t_ops_events
check "an account's cron job is written for cron to run as the account, and too frequent ones are refused" t_ops_cron
check "the task list shows what the agent has done" t_ops_tasks

# The web application firewall (ModSecurity with the Core Rule Set) lives in
# Apache, so on the apache and hybrid stacks the real thing is tried: an
# attack gets a 403 in block mode, goes through in log-only mode, and the
# agent reads what the rules said from the account's error log.
t_waf_status() {
    lpanel waf:status > /tmp/waf1.out 2>&1
    case "$WEB" in
        nginx) grep -q "nginx alone" /tmp/waf1.out ;;
        *)     grep -q "ModSecurity is loaded, Core Rule Set" /tmp/waf1.out ;;
    esac
}
check "the firewall manager knows whether this stack has ModSecurity" t_waf_status
case "$WEB" in
    apache|hybrid)
        ATTACK="/?q=%3Cscript%3Ealert(1)%3C/script%3E&id=1%20union%20select%20password%20from%20users--"
        wafcode() { curl -s -o /dev/null -w '%{http_code}' -H 'Host: shop.example.test' "http://127.0.0.1$1"; }
        t_waf_block() {
            lpanel domains:waf shop1 shop.example.test --mode=block > /tmp/waf2.out 2>&1 || { cat /tmp/waf2.out; return 1; }
            lpanel waf:scan > /tmp/waf3.out 2>&1
            [ "$(wafcode "$ATTACK")" = 403 ] && [ "$(wafcode /)" != 403 ]
        }
        t_waf_detect() {
            lpanel domains:waf shop1 shop.example.test --mode=detect > /tmp/waf4.out 2>&1 || { cat /tmp/waf4.out; return 1; }
            [ "$(wafcode "$ATTACK")" != 403 ]
        }
        t_waf_off() {
            lpanel domains:waf shop1 shop.example.test --mode=off > /tmp/waf5.out 2>&1 || return 1
            [ "$(wafcode "$ATTACK")" != 403 ]
        }
        t_waf_events() {
            lpanel domains:waf shop1 shop.example.test --mode=default > /tmp/waf6.out 2>&1 || return 1
            sleep 1
            lpanel waf:scan > /tmp/waf7.out 2>&1 || { cat /tmp/waf7.out; return 1; }
            [ "$(mariadb -uroot -N lpanel -e "select count(*) from waf_events where host='shop.example.test' and blocked=1 and rules like '%\"id\":94%'")" -ge 1 ] \
                && [ "$(mariadb -uroot -N lpanel -e "select count(*) from waf_events where host='shop.example.test' and blocked=0")" -ge 1 ]
        }
        check "an attack gets a 403 in block mode, a normal request does not" t_waf_block
        check "in log-only mode the same attack goes through" t_waf_detect
        check "with the firewall off it goes through too" t_waf_off
        check "the agent reads the rules' log: one refused request, one only logged" t_waf_events
        ;;
esac

echo "== updates (dnf, and LPanel's own releases)"
# Something the scheduler started (the daily check) may be running: wait for it.
upd_idle() {
    for _ in $(seq 150); do
        lpanel updates:settle >/dev/null 2>&1
        [ "$(mariadb -uroot -N lpanel -e "select count(*) from ops_update_runs where state in ('running','installing')")" = 0 ] && return 0
        sleep 2
    done
    return 1
}
# upd <command...>: an updates command, again if the scheduler got in first.
upd() {
    local i
    for i in 1 2 3 4 5; do
        upd_idle
        lpanel "$@" > /tmp/upd.out 2>&1 && return 0
        grep -q "being checked or installed already" /tmp/upd.out || { cat /tmp/upd.out; return 1; }
    done
    cat /tmp/upd.out; return 1
}
t_upd_version() { [ "$(cat /usr/local/lpanel/VERSION)" = "$(cat /bundle/VERSION)" ] && lpanel updates:status | grep -q "^LPanel     $(cat /bundle/VERSION)"; }
t_upd_check()   { upd updates:check --wait && grep -q "packages can be updated" /tmp/upd.out; }
# A package of our own, 1.0 installed and 1.1 in a local repository with a
# security erratum, so the update is the same on every run.
t_upd_repo() {
    dnf -y -q install rpm-build createrepo_c > /tmp/upd-rpm.log 2>&1 || { tail -5 /tmp/upd-rpm.log; return 1; }
    mkdir -p /root/rpmbuild/SPECS /srv/e2e-repo
    local v
    for v in 1.0 1.1; do
        cat > /root/rpmbuild/SPECS/dummy.spec <<SPEC
Name: lpanel-e2e-dummy
Version: $v
Release: 1
Summary: LPanel e2e test package
License: MIT
BuildArch: noarch
%description
A package the e2e run updates.
%install
mkdir -p %{buildroot}/usr/share/lpanel-e2e-dummy
echo $v > %{buildroot}/usr/share/lpanel-e2e-dummy/version
%files
/usr/share/lpanel-e2e-dummy/version
SPEC
        rpmbuild -bb /root/rpmbuild/SPECS/dummy.spec >> /tmp/upd-rpm.log 2>&1 || { tail -5 /tmp/upd-rpm.log; return 1; }
    done
    rpm -i /root/rpmbuild/RPMS/noarch/lpanel-e2e-dummy-1.0-1.noarch.rpm || return 1
    cp /root/rpmbuild/RPMS/noarch/lpanel-e2e-dummy-1.1-1.noarch.rpm /srv/e2e-repo/
    createrepo_c -q /srv/e2e-repo || return 1
    cat > /tmp/updateinfo.xml <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<updates>
  <update from="e2e@lpanel.test" status="stable" type="security" version="2.0">
    <id>LPE2E-2026:0001</id>
    <title>Important: lpanel-e2e-dummy security update</title>
    <severity>Important</severity>
    <issued date="2026-11-24 00:00:00"/>
    <description>A test erratum.</description>
    <pkglist><collection short="e2e"><name>e2e</name>
      <package name="lpanel-e2e-dummy" version="1.1" release="1" epoch="0" arch="noarch"><filename>lpanel-e2e-dummy-1.1-1.noarch.rpm</filename></package>
    </collection></pkglist>
  </update>
</updates>
XML
    modifyrepo_c --mdtype=updateinfo /tmp/updateinfo.xml /srv/e2e-repo/repodata >> /tmp/upd-rpm.log 2>&1 || { tail -5 /tmp/upd-rpm.log; return 1; }
    printf '[lpanel-e2e]\nname=LPanel e2e\nbaseurl=file:///srv/e2e-repo\nenabled=1\ngpgcheck=0\nmetadata_expire=0\n' > /etc/yum.repos.d/lpanel-e2e.repo
}
t_upd_found()   { upd updates:check --wait && grep -Eq 'lpanel-e2e-dummy +1\.1-1 +security Important' /tmp/upd.out; }
t_upd_apply()   { upd updates:apply --packages=lpanel-e2e-dummy --wait && grep -q "1 packages updated" /tmp/upd.out \
                  && [ "$(rpm -q --qf '%{VERSION}' lpanel-e2e-dummy)" = 1.1 ] && [ "$(cat /usr/share/lpanel-e2e-dummy/version)" = 1.1 ]; }
t_upd_refused() { ! upd updates:apply --packages=lpanel-e2e-absent --wait && grep -q "is not installed" /tmp/upd.out && ! rpm -q lpanel-e2e-absent >/dev/null 2>&1; }
t_upd_audited() { mariadb -uroot -N lpanel -e "select concat(action, ':', outcome) from audit_log where action like 'updates.appl%' order by id" | tr '\n' ' ' | tee /dev/stderr \
                  | grep -q "updates.apply:ok updates.applied:ok updates.apply:ok updates.applied:failed"; }
t_upd_page()    { signin "$ROOT_PASSWORD" >/dev/null; curl -ks -b $jar -c $jar -o /tmp/upd.html https://127.0.0.1:7443/admin/updates && grep -q "lpanel-e2e-dummy" /tmp/upd.html && grep -q "Installed by themselves" /tmp/upd.html; }
check "the release number is installed with the panel" t_upd_version
check "the server says what can be updated" t_upd_check
check "a test repository with an update and a security erratum is set up" t_upd_repo
check "the check finds the update and its erratum" t_upd_found
check "one named package is updated" t_upd_apply
check "a package that is not installed is refused, not installed" t_upd_refused
check "updates are in the audit log" t_upd_audited
check "the Updates page shows them" t_upd_page

# LPanel's own release channel on this machine: a signing key of our own, the
# bundle again as release 0.1.1-e2e (and 0.1.2-e2e, whose installer fails), each
# signed and served over HTTP on 127.0.0.1 (the agent allows plain HTTP there only).
REL=/srv/e2e-channel
make_release() { # version [broken]
    local v=$1 d=/tmp/rel/lpanel-$1
    rm -rf "$d" && mkdir -p /tmp/rel && cp -a /bundle "$d" || return 1
    echo "$v" > "$d/VERSION"
    cp /root/release-keys/release.pub "$d/packaging/release.pub"
    if [ "${2:-}" = broken ]; then
        sed -i 's|^step "Database tables"$|step "Database tables"; echo "e2e: this release fails here" >\&2; exit 1|' "$d/install.sh"
        grep -q "this release fails here" "$d/install.sh" || return 1
    fi
    tar -C /tmp/rel -czf "/tmp/rel/lpanel-$v.tar.gz" "lpanel-$v" && rm -rf "$d"
    bash /e2e/release.sh sign "/tmp/rel/lpanel-$v.tar.gz" /root/release-keys/release.key "$REL" "e2e release $v"
}
panel_run() { # waits for the latest upgrade to finish, as the scheduler would settle it
    local st
    for _ in $(seq 200); do
        lpanel updates:settle >/dev/null 2>&1
        st=$(mariadb -uroot -N lpanel -e "select state from ops_update_runs where kind='panel' order by id desc limit 1" 2>/dev/null)
        case "$st" in done|failed) echo "$st"; return 0 ;; esac
        sleep 3
    done
    echo "still ${st:-unknown}"
}
t_upd_channel() {
    mkdir -p "$REL" && bash /e2e/release.sh keygen /root/release-keys > /dev/null || return 1
    make_release 0.1.1-e2e > /tmp/rel.out 2>&1 || { cat /tmp/rel.out; return 1; }
    (cd "$REL" && nohup python3 -m http.server 8099 --bind 127.0.0.1 > /tmp/channel.log 2>&1 &)
    install -m 0644 /root/release-keys/release.pub /etc/lpa/release.pub
    /usr/bin/php83 -r '$f = "/etc/lpa/lpa.json"; $c = json_decode(file_get_contents($f), true);
        $c["updates"]["url"] = "http://127.0.0.1:8099/latest.json";
        file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
    systemctl restart lpa && for _ in $(seq 50); do [ -S /run/lpa/lpa.sock ] && break; sleep 0.2; done
    for _ in $(seq 30); do curl -sf -o /dev/null http://127.0.0.1:8099/latest.json.sig && return 0; sleep 0.5; done; return 1
}
t_upd_offered() { upd updates:check --wait && grep -q "LPanel 0.1.1-e2e is out" /tmp/upd.out; }
t_upd_upgrade() {
    upd updates:panel 0.1.1-e2e || return 1
    local st; st=$(panel_run)
    [ "$st" = done ] || { echo "upgrade: $st"; cat /var/lib/lpa/updates/status.json; tail -30 /var/lib/lpa/updates/upgrade.log; return 1; }
    [ "$(cat /usr/local/lpanel/VERSION)" = 0.1.1-e2e ] && grep -q '"state":"done"' /var/lib/lpa/updates/status.json \
        && grep -q "Backing up the panel" /var/lib/lpa/updates/upgrade.log && [ -s /var/lib/lpa/updates/rollback/lpanel.sql ]
}
t_upd_works()   { curl -ksf -o /dev/null https://127.0.0.1:7443/health && lpanel agent:ping >/dev/null && says shop.example.test "8.3 as shop1" \
                  && [ "$(signin_as 7443 /admin root "$ROOT_PASSWORD")" = "303 https://127.0.0.1:7443/admin" ]; }
t_upd_tampered() {
    # A manifest changed after it was signed is refused, and nothing is installed.
    cp "$REL/latest.json" /tmp/latest.json.good
    sed -i 's/e2e release 0.1.1-e2e/e2e release, changed/' "$REL/latest.json"
    upd updates:check --wait && lpanel updates:status > /tmp/upd-t.out 2>&1; grep -q "does not match any release key" /tmp/upd-t.out
    local rc=$?
    cp /tmp/latest.json.good "$REL/latest.json"
    [ "$rc" -eq 0 ] || { cat /tmp/upd-t.out; return 1; }
    # And the release running now is not installed again.
    ! lpanel updates:panel 0.1.1-e2e > /tmp/upd-t.out 2>&1 && grep -q "not newer" /tmp/upd-t.out
}
t_upd_rollback() {
    make_release 0.1.2-e2e broken > /tmp/rel.out 2>&1 || { cat /tmp/rel.out; return 1; }
    upd updates:panel 0.1.2-e2e || return 1
    local st; st=$(panel_run)
    [ "$st" = failed ] || { echo "upgrade: $st"; tail -20 /var/lib/lpa/updates/upgrade.log; return 1; }
    grep -q '"state":"rolled_back"' /var/lib/lpa/updates/status.json && grep -q "this release fails here" /var/lib/lpa/updates/upgrade.log \
        && [ "$(cat /usr/local/lpanel/VERSION)" = 0.1.1-e2e ] \
        && mariadb -uroot -N lpanel -e "select error from ops_update_runs where kind='panel' order by id desc limit 1" | grep -q "0.1.1-e2e was put back"
}
check "a signed release channel is served on this machine" t_upd_channel
check "the check sees LPanel's new release" t_upd_offered
check "LPanel upgrades itself from the channel (backup, install, done)" t_upd_upgrade
check "after the upgrade the panel, the agent, sites and sign-in work" t_upd_works
check "a manifest changed after signing is refused, the same release is not installed again" t_upd_tampered
check "a release whose installer fails is rolled back to the one before" t_upd_rollback
check "after the rollback the panel, the agent, sites and sign-in work" t_upd_works

echo "== alerts"
t_al_add()    { lpanel alerts:add email "E2E ops" --to=root@e2e-panel.test >/tmp/al.out 2>&1 && lpanel alerts:status | grep -q "E2E ops *email *root@e2e-panel.test"; }
t_al_refuse() { ! lpanel alerts:add slack Bad --url=http://hooks.example.com/x >/dev/null 2>&1 && ! lpanel alerts:add email Bad --to=nope >/dev/null 2>&1; }
t_al_test()   { lpanel alerts:test 1 && for _ in $(seq 20); do grep -rqs "lpanel@e2e-panel.test" /var/log/exim/ && return 0; sleep 1; done; echo "no mail left the panel"; tail -5 /var/log/exim/*.log 2>/dev/null; return 1; }
t_al_check()  { lpanel alerts:settings --disk=0 --memory=0 --load=0 >/dev/null && lpanel alerts:check; }
t_al_page()   { local code; code=$(curl -ks -b $jar -o /tmp/alerts.html -w '%{http_code}' https://127.0.0.1:7443/admin/alerts); [ "$code" = 200 ] && grep -q "E2E ops" /tmp/alerts.html && grep -q 'href="/admin/alerts"' /tmp/alerts.html; }
t_al_api()    { api https://127.0.0.1:7443/admin/api/v1/alerts | grep -q '"name":"E2E ops"' && ! api https://127.0.0.1:7443/admin/api/v1/alerts | grep -q '"config"'; }
t_al_audit()  { mariadb -uroot -N lpanel -e "select action from audit_log where action like 'alerts.%'" | grep -q alerts.channel_add && mariadb -uroot -N lpanel -e "select action from audit_log where action like 'alerts.%'" | grep -q alerts.test; }
check "an email channel is added" t_al_add
check "a plain-http webhook and a bad address are refused" t_al_refuse
check "a test alert leaves through the server's mail system" t_al_test
check "the five-minute check runs" t_al_check
check "the Alerts page shows the channel" t_al_page
check "the API lists channels without their secrets" t_al_api
check "channel changes are in the audit log" t_al_audit

echo "== logs and statistics"
t_lg_sources() { lpanel logs:sources | grep -q "^journal:lpa " && lpanel logs:sources | grep -q "^file:exim "; }
t_lg_journal() { lpanel logs:tail journal:lpa --lines=100 | grep -q . ; }
t_lg_filter()  { [ "$(lpanel logs:tail journal:lpa --filter=zzz-no-such-text-zzz | wc -l)" = 0 ]; }
t_lg_refuse()  { ! lpanel logs:tail file:../../etc/shadow >/dev/null 2>&1 && ! lpanel logs:tail web.access --account=../etc >/dev/null 2>&1 && ! lpanel logs:tail journal:ssh-agent >/dev/null 2>&1; }
t_lg_account() { sget shop.example.test / >/dev/null; lpanel logs:tail web.access --account=shop1 --lines=100 | grep -q "GET "; }
t_lg_stats()   { lpanel logs:stats shop1 --days=7 --refresh | tee /tmp/stats.out | grep -Eq "^requests [1-9][0-9]*, visitors [1-9]"; }
t_lg_pages()   { local a b c
    a=$(curl -ks -b $jar -o /tmp/lg.html -w '%{http_code}' 'https://127.0.0.1:7443/admin/logs?source=journal:lpa')
    b=$(curl -ks -b $jar -o /tmp/st.html -w '%{http_code}' https://127.0.0.1:7443/admin/statistics)
    signin_as 7444 /panel shop1 Owner-Pass-Word-1 >/dev/null
    c=$(curl -ks -b /tmp/jar2 -o /tmp/ust.html -w '%{http_code}' https://127.0.0.1:7444/panel/statistics)
    [ "$a $b $c" = "200 200 200" ] && grep -q 'href="/admin/logs"' /tmp/lg.html && grep -q "Requests" /tmp/st.html && grep -q "Requests" /tmp/ust.html; }
t_lg_api()     { api "https://127.0.0.1:7443/admin/api/v1/logs?source=journal:lpa&lines=100" | grep -q '"lines":\[' && [ "$(api -o /dev/null -w '%{http_code}' 'https://127.0.0.1:7443/admin/api/v1/logs?source=../../etc/passwd')" = 422 ]; }
check "the logs that can be read are listed" t_lg_sources
check "the agent's journal can be read, and filtered" t_lg_journal
check "a filter that matches nothing shows nothing" t_lg_filter
check "a path, a bad account name and an unlisted unit are refused" t_lg_refuse
check "an account's own site log shows its visits" t_lg_account
check "GoAccess counts the account's visits" t_lg_stats
check "the Logs and Statistics pages work for the Admin and the owner" t_lg_pages
check "the API reads a log and refuses one that is not listed" t_lg_api

echo "== the API describes itself"
t_oa_json()  { local i; for i in 1 2 3; do api --max-time 60 https://127.0.0.1:7443/admin/api/v1/openapi.json -o /tmp/openapi.json && grep -Eq '"openapi": ?"3.1.0"' /tmp/openapi.json && grep -q '"/admin/api/v1/accounts/{username}/domains"' /tmp/openapi.json && grep -Eq '"operationId": ?"api_updates_apply"' /tmp/openapi.json && return 0; sleep 3; done; head -c 300 /tmp/openapi.json >&2; return 1; }
t_oa_anon()  { [ "$(curl -ks -o /dev/null -w '%{http_code}' -H 'Accept: application/json' https://127.0.0.1:7443/admin/api/v1/openapi.json)" = 401 ]; }
t_oa_docs()  { api https://127.0.0.1:7443/admin/api/v1/docs -H 'Accept: text/html' | grep -qi swagger; }
t_oa_url()   { grep -Eq '"url": ?"https://' /tmp/openapi.json; }
check "the OpenAPI document is served to a token" t_oa_json
check "without a token it is not" t_oa_anon
check "the API reference page is served" t_oa_docs
check "the document says where the API is (APP_URL)" t_oa_url

NEXT=$(case "$WEB" in apache) echo hybrid ;; nginx) echo apache ;; hybrid) echo nginx ;; esac)
echo "== switching to $NEXT"
t_switched() {
    /bundle/install.sh --hostname e2e-panel.test --web=$NEXT > /tmp/install3.log 2>&1 || { tail -30 /tmp/install3.log; return 1; }
    grep -q "every site moved from $WEB to $NEXT" /tmp/install3.log && grep -q "\"web\": \"$NEXT\"" /etc/lpa/lpa.json
}
t_moved_files() {
    case "$NEXT" in
        apache) [ -f /etc/httpd/lpanel/sites/shop1.conf ] && [ ! -e /etc/nginx/lpanel/sites/shop1.conf ] && ! systemctl is-active --quiet nginx ;;
        nginx)  [ -f /etc/nginx/lpanel/sites/shop1.conf ] && [ ! -e /etc/httpd/lpanel/sites/shop1.conf ] && ! systemctl is-active --quiet httpd \
                && ls /etc/nginx/lpanel/htpasswd/shop1-*.htpasswd >/dev/null && ! ls /etc/httpd/lpanel/htpasswd/shop1-* 2>/dev/null ;;
        hybrid) [ -f /etc/httpd/lpanel/sites/shop1.conf ] && [ -f /etc/nginx/lpanel/sites/shop1.conf ] && systemctl is-active --quiet nginx httpd ;;
    esac
}
export NEXT
check "the installer switches the stack and moves every site" t_switched
check "only the new stack's files are left" t_moved_files
check "every site is served by the new stack" bash -c 'says shop.example.test "8.3 as shop1" && says other.example.test "8.2 as shop1" && says three.example.test "bool(false)"'
check "redirects, the protected folder and hotlink protection came along" bash -c '[ "$(get $O /old/page)" = "301 https://example.org/new/page" ] && [ "$(get $O /private/)" = "401 " ] && [ "$(get $O /logo.png -e http://elsewhere.test/page)" = "403 " ]'
check "a name nobody serves is still a 404" bash -c '[ "$(site nobody.example.test)" = 404 ]'
check "HTTPS came along, with each site's certificate" bash -c '[ "$(sget shop.example.test /)" = "200 " ] && grep -q "8.3 as shop1" /tmp/site.out && issuer shop.example.test | grep -q "Pebble Intermediate" && issuer other.example.test | grep -q "CN *= *E2E Own CA"'

if command -v selinuxenabled >/dev/null 2>&1 && selinuxenabled; then
    echo "== SELinux ($(getenforce))"
    domain() { # process name, domain
        local got; got=$(ps -eZ | awk -v p="$1" '$NF == p { split($1, c, ":"); print c[3] }' | sort -u)
        [ "$got" = "$2" ] || { echo "$1 runs as '${got:-nothing}', want $2"; return 1; }
    }
    labelled() { # path, type
        local got; got=$(stat -c %C "$1" | cut -d: -f3)
        [ "$got" = "$2" ] || { echo "$1 is $got, want $2"; return 1; }
    }
    denials() {
        local out; out=$(ausearch -m AVC,USER_AVC,SELINUX_ERR -ts "$started" -i 2>&1)
        grep -q '<no matches>' <<<"$out" && return 0
        echo "$out" | grep -E 'denied|SELINUX_ERR' | head -30; return 1
    }
    export -f domain labelled
    check "SELinux is enforcing" bash -c '[ "$(getenforce)" = Enforcing ]'
    check "the lpanel policy module is loaded" bash -c 'semodule -l | grep -qx lpanel'
    check "lpa runs in lpanel_agent_t" domain lpa lpanel_agent_t
    # authd is started per sign-in and may have exited by now. Root signed in
    # above, so any denial on its side shows in the last check.
    check "authd's socket is lpanel_sock_t" labelled /run/lpanel-authd/authd.sock lpanel_sock_t
    check "the panel's PHP-FPM runs in httpd_t" domain php-fpm httpd_t
    check "the panel's nginx runs in httpd_t" domain nginx httpd_t
    check "the agent's socket is lpanel_sock_t" labelled /run/lpa/lpa.sock lpanel_sock_t
    check "the panel's writable folder is httpd_sys_rw_content_t" labelled /usr/local/lpanel/system httpd_sys_rw_content_t
    check "the logs are httpd_log_t" labelled /var/log/lpanel/nginx-error.log httpd_log_t
    check "an account's public_html is httpd_user_content_t" labelled /home/shop1/public_html httpd_user_content_t
    check "nothing was denied during the whole run" denials
fi

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
