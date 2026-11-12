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
t_hostname()  { issuer e2e-panel.test 7443 | grep -q "Pebble Intermediate" && lpanel ssl:hostname > /tmp/ssl.host && grep -q "names     e2e-panel.test" /tmp/ssl.host; }
t_default443() { case "$WEB" in
                     apache) [ "$(sget nobody.example.test /)" = "404 " ] && issuer nobody.example.test | grep -q "Pebble Intermediate" ;;
                     *) ! curl -sk -o /dev/null --resolve nobody.example.test:443:127.0.0.1 https://nobody.example.test/ ;;
                 esac; }
t_renew()     { local before; before=$(issuer shop.example.test | grep serial)
                mariadb -uroot lpanel -e "update ssl_sites s join domains d on d.id = s.domain_id set s.not_before = now() - interval 80 day, s.not_after = now() + interval 10 day where d.name = 'shop.example.test'"
                lpanel ssl:autossl --wait >/dev/null && local after; after=$(issuer shop.example.test | grep serial)
                echo "$before -> $after" >&2; [ -n "$after" ] && [ "$before" != "$after" ] && [ "$(sget shop.example.test /)" = "200 " ]; }
t_fallback()  { acme https://127.0.0.1:14999/dir \
                && lpanel ssl:settings --eab-kid kid-1 --eab-hmac zWNDZM6eQGHWpSRTPal5eIUYFTu7EajVIoguysqZ9wG44nMEtx3MUAsUDkMTQ12W > /tmp/ssl.settings \
                && grep -q "letsencrypt then zerossl" /tmp/ssl.settings \
                && lpanel ssl:issue shop1 blog.shop.example.test && [ "$(ssl_row blog.shop.example.test authority)" = zerossl ] \
                && [ "$(sget blog.shop.example.test /)" = "200 " ] && grep -q "8.3 as shop1" /tmp/site.out; }
# A certificate from an authority of the owner's own (not self-signed, so
# AutoSSL leaves it in place), given as key, leaf and issuer on stdin.
t_custom()    { openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=E2E Own CA" -keyout /tmp/ca.key -out /tmp/ca.crt 2>/dev/null \
                && openssl req -newkey rsa:2048 -nodes -subj "/CN=other.example.test" -addext "subjectAltName=DNS:other.example.test,DNS:www.other.example.test" \
                    -keyout /tmp/own.key -out /tmp/own.csr 2>/dev/null \
                && openssl x509 -req -in /tmp/own.csr -CA /tmp/ca.crt -CAkey /tmp/ca.key -CAcreateserial -days 30 -copy_extensions copy -out /tmp/own.crt 2>/dev/null \
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
t_db_page()    { curl -ks -b /tmp/jar2 https://127.0.0.1:7444/panel/databases | grep -q 'shop1_app' && curl -ks -b $jar https://127.0.0.1:7443/admin/databases | grep -q 'shop1_shop'; }
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

# Each stack switches to another, and every site moves with it.
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
