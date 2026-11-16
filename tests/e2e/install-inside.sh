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

echo "== DNS (PowerDNS)"
IP4=$(lpanel dns:settings | awk '$1 == "IPv4" { print $3 }')
q() { dig @127.0.0.1 +short +norecurse "$@" 2>&1; }     # name [type]
zid() { mariadb -uroot -N lpanel -e "select r.id from dns_records r join dns_zones z on z.id = r.zone_id where z.name = '$1' and r.name = '$2' and r.type = '$3' limit 1"; }
t_pdns_up()     { systemctl is-active --quiet pdns && ss -ltnp | grep -q '127.0.0.1:8081' && ! ss -ltn | grep -qE '(0\.0\.0\.0|\*|\[::\]):8081 ' \
                  && [ "$(stat -c '%a %U' /etc/lpa/pdns.key)" = "600 root" ] && [ "$(stat -c '%a %U %G' /etc/pdns/pdns.conf)" = "640 root pdns" ]; }
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
dnf -y -q install swaks python3-dkimpy >/dev/null 2>&1 || dnf -y -q install swaks python3-dkimpy >/dev/null 2>&1
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
    c, _ = s.accept(); f = c.makefile("rwb")
    def out(x): f.write(x.encode() + b"\r\n"); f.flush()
    out("220 catcher")
    while True:
        line = f.readline()
        if not line: break
        u = line.decode(errors="replace").strip().upper()
        if u.startswith("EHLO") or u.startswith("HELO"): out("250 catcher")
        elif u == "DATA":
            out("354 go"); buf = b""
            while True:
                l = f.readline()
                if l in (b".\r\n", b".\n", b""): break
                buf += l[1:] if l.startswith(b"..") else l
            n += 1; open("/tmp/caught/%03d.eml" % n, "wb").write(buf); out("250 OK")
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
    out = subprocess.run(["dig", "@127.0.0.1", "+short", "+norecurse", "TXT", name.decode().rstrip(".")], capture_output=True, text=True).stdout
    return "".join(part for line in out.splitlines() for part in line.split('"')[1::2]).encode()
msg = open(sys.argv[1], "rb").read()
sys.exit(0 if dkim.verify(msg, dnsfunc=dns) else 1)
PY
sw() { swaks --helo e2e-client.test --timeout 20 "$@" > /tmp/swaks.out 2>&1; local c=$?; grep -E '^ *<[~*-]* *[0-9]{3}[ -]' /tmp/swaks.out | tail -4 >&2; return $c; }
refused() { ! sw "$@" && grep -qE "^ *<[~*]* *$WANT" /tmp/swaks.out; }   # WANT: the refusal's code and words
caught() { grep -l "$1" /tmp/caught/*.eml 2>/dev/null | tail -1; }
waitfor() { for _ in $(seq 120); do eval "$1" && return 0; sleep 0.5; done; eval "$1"; }
maildir() { echo "/home/$1/mail/$2/$3/Maildir"; }
count_new() { find "$(maildir "$1" "$2" "$3")"/new "$(maildir "$1" "$2" "$3")"/cur -type f 2>/dev/null | wc -l; }
lpanel packages:create Mailer --disk=50 >/dev/null
echo "Owner-Pass-Word-9" | lpanel accounts:create shop9 nine.example.test Mailer >/dev/null
lpanel mail:settings --smarthost 127.0.0.1:2526 >/dev/null
export MIP MPW WANT
export -f sw refused caught waitfor maildir count_new

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
                  && [ ! -e /etc/dovecot/lpanel/sieve/nine.example.test ]; }
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
                  for want in mail.mailbox_create:ok mail.mailbox_delete:ok mail.forwarder_add:ok mail.forwarder_remove:ok mail.responder_on:ok mail.responder_off:ok mail.domain_settings:ok mail.settings:ok; do
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
check "the owner sees the mailboxes and makes one in the User panel" t_mail_panel
check "the API lists and deletes mailboxes" t_mail_api
check "a deleted mailbox's mail is deleted" t_mail_delete
check "terminating an account removes its mail" t_mail_gone
check "mail changes are in the audit log" t_mail_audit
lpanel mail:settings --smarthost none >/dev/null

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
