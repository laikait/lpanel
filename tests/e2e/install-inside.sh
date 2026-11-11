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

echo "== install"
check "the installer finishes" bash -c '/bundle/install.sh --hostname e2e-panel.test > /tmp/install.log 2>&1 || { tail -40 /tmp/install.log; exit 1; }'
grep -q "LPanel is installed." /tmp/install.log && echo "        (installer printed the sign-in details)"

echo "== services"
for unit in mariadb lpa lpanel-authd.socket lpanel-php-fpm lpanel-nginx lpanel-scheduler.timer; do
    check "$unit is active" systemctl is-active --quiet "$unit"
done
check "the stock nginx stays off" bash -c '! systemctl is-active --quiet nginx'
check "the panel's PHP is not among customers' pools" bash -c '! grep -rqs "lpanel/php-fpm.sock" /etc/opt/remi/php*/php-fpm.d/'
check "Apache is running for customers' sites" systemctl is-active --quiet httpd
check "Remi's sample pools are emptied" bash -c '! grep -qs "^\[www\]" /etc/opt/remi/php82/php-fpm.d/www.conf /etc/opt/remi/php83/php-fpm.d/www.conf'
check "the agent is told to use Apache" grep -q '"web": "apache"' /etc/lpa/lpa.json
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
t_main()    { [ -f /etc/httpd/lpanel/sites/shop1.conf ] && put shop1 /tmp/who.php /home/shop1/public_html/index.php && says shop.example.test "8.3 as shop1"; }
t_addon()   { lpanel domains:add shop1 other.example.test --php=8.2 && put shop1 /tmp/who.php /home/shop1/public_html/other.example.test/index.php && says other.example.test "8.2 as shop1"; }
t_sub()     { lpanel domains:add shop1 blog.shop.example.test --type=sub && put shop1 /tmp/who.php /home/shop1/public_html/blog/index.php && says blog.shop.example.test "8.3 as shop1"; }
t_alias()   { lpanel domains:add shop1 shop-alias.example.test --type=alias --target=shop.example.test && says shop-alias.example.test "8.3 as shop1" && says www.shop-alias.example.test "8.3 as shop1"; }
t_list()    { lpanel domains:list shop1 | tee /dev/stderr | grep -Eq '^other\.example\.test +addon +public_html/other\.example\.test +8\.2'; }
t_isolated() { put shop3 /tmp/peek.php /home/shop3/public_html/index.php && says three.example.test "bool(false)"; }
t_suspend() { lpanel accounts:suspend shop3 && [ "$(site three.example.test)" = 503 ] && ! ls /etc/opt/remi/php*/php-fpm.d/lpanel-shop3.conf 2>/dev/null; }
t_unsuspend() { lpanel accounts:unsuspend shop3 && says three.example.test "bool(false)"; }
t_gone()    { [ ! -e /etc/httpd/lpanel/sites/shop2.conf ] && ! ls /etc/opt/remi/php*/php-fpm.d/lpanel-shop2.conf 2>/dev/null && [ "$(site two.example.test)" = 404 ]; }
t_broken()  { echo "ThisIsNotADirective" > /etc/httpd/conf.d/zz-broken.conf
              ! lpanel domains:add shop1 broken.example.test 2>&1 | tee /dev/stderr | grep -q added
              local ok=$?; rm -f /etc/httpd/conf.d/zz-broken.conf
              [ $ok = 0 ] && ! lpanel domains:list shop1 | grep -q broken && systemctl is-active --quiet httpd && says shop.example.test "8.3 as shop1"; }
t_link()    { runuser -u shop1 -- ln -s /etc /home/shop1/public_html/evil
              ! lpanel domains:add shop1 evil.example.test --docroot=public_html/evil/x && [ ! -e /etc/x ] && ! lpanel domains:list shop1 | grep -q evil; }
t_page()    { signin_as 7444 /panel shop1 Owner-Pass-Word-1 >/dev/null && curl -ks -b /tmp/jar2 https://127.0.0.1:7444/panel/domains | grep -q "blog.shop.example.test"; }
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
                && lpanel domains:error-page shop1 $O 404 /missing.html && [ "$(get $O /nothing-here)" = "404 " ] && grep -q "custom missing page" /tmp/site.out; }
t_protect()   { : > /tmp/get.log; mkdir -p $OD/private && echo "members only" > $OD/private/index.html && chown -R shop1:shop1 $OD/private \
                && echo "Ann-Pass-Word-1" | lpanel domains:protect shop1 $O private --realm=Members --login=ann \
                && [ "$(get $O /private/)" = "401 " ] && [ "$(get $O /private/ -u ann:wrong-password)" = "401 " ] \
                && [ "$(get $O /private/ -u ann:Ann-Pass-Word-1)" = "200 " ] && grep -q "members only" /tmp/site.out \
                && [ "$(get $O /)" = "200 " ]; }
t_logins()    { local f; f=$(ls /etc/httpd/lpanel/htpasswd/shop1-*.htpasswd) && [ "$(stat -c '%a %U %G' "$f")" = "640 root apache" ] \
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
check "its logins are bcrypt hashes, 640 root:apache, unreadable to others" t_logins
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
logged() { cat /var/log/httpd/lpanel/shop1.access.log* 2>/dev/null | awk 'match($0, /" [0-9][0-9][0-9] [0-9]+ [0-9]+ "/) { split(substr($0, RSTART + 2, RLENGTH - 3), f, " "); s += f[2] + f[3] } END { print s + 0 }'; }
counted() { lpanel web:bandwidth shop1 | awk '/^used/ { print $2 }'; }
export -f logged counted
head -c 600000 /dev/urandom > /tmp/big.bin
t_counted()  { put shop1 /tmp/big.bin $OD/big.bin && for _ in 1 2 3; do get $O /big.bin >/dev/null; done \
               && lpanel web:traffic && [ "$(counted)" = "$(logged)" ] && [ "$(counted)" -gt 1800000 ]; }
t_twice()    { local before; before=$(counted) && lpanel web:traffic && [ "$(counted)" = "$before" ]; }
t_rotated()  { logrotate -f /etc/logrotate.d/lpanel && [ -f /var/log/httpd/lpanel/shop1.access.log.1 ] && get $O /big.bin >/dev/null \
               && lpanel web:traffic && [ "$(counted)" = "$(logged)" ]; }
# (A fresh machine has no logrotate state file yet; -d says so, harmlessly.)
t_rotate_cfg() { logrotate -d /etc/logrotate.d/lpanel > /tmp/logrotate.out 2>&1; grep -q 'httpd/lpanel' /tmp/logrotate.out && ! grep '^error:' /tmp/logrotate.out | grep -v 'state file'; }
t_gone_logs()  { echo "Owner-Pass-Word-5" | lpanel accounts:create shop5 five.example.test Starter && get five.example.test / >/dev/null \
                 && [ -f /var/log/httpd/lpanel/shop5.access.log ] && lpanel accounts:terminate shop5 --yes \
                 && for _ in $(seq 60); do getent passwd shop5 >/dev/null || break; lpanel accounts:settle >/dev/null; sleep 2; done \
                 && ! getent passwd shop5 && ! ls /var/log/httpd/lpanel/shop5.* 2>/dev/null; }
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
