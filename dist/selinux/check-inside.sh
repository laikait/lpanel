#!/usr/bin/env bash
# Runs inside the build container (see build.sh), after lpanel.pp is built.
set -uo pipefail

pass=0
fail=0
check() { # name, command...
    local name="$1"; shift
    if "$@" >/tmp/out 2>&1; then
        pass=$((pass + 1)); echo "  ok    $name"
    else
        fail=$((fail + 1)); echo "  FAIL  $name"; sed 's/^/        /' /tmp/out | head -10
    fi
}

# The same settings the installer makes, without a kernel to load them into.
LPANEL_SELINUX_NO_RELOAD=1 bash setup.sh lpanel.pp || { echo "setup.sh failed"; exit 1; }
P=$(ls /etc/selinux/targeted/policy/policy.* | tail -1)

# Each helper takes a tool's whole output before looking at it: grep -q in a
# pipe would close it early, and the Python tools fail on a broken pipe.
rules()    { sesearch -A "$@" "$P"; }                    # -s -t -c -p
allowed()  { local r; r=$(rules "$@") && [ -n "$r" ] && echo "$r"; }
refused()  { local r; r=$(rules "$@") && [ -z "$r" ] || { echo "$r"; return 1; }; }
# No rule, or only rules that hold while a boolean that is off by default is
# on. Conditional rules end in "[ expression ]:True".
unconditionally_refused() {
    local b="$1" r bools; shift
    r=$(rules "$@" | grep -v ']:True$') || true
    [ -z "$r" ] || { echo "$r"; return 1; }
    bools=$(semanage boolean -l)
    grep -Eq "^$b +\(off *, *off\)" <<<"$bools" || { echo "$b is not off by default"; return 1; }
}
label()    { local got; got=$(matchpathcon -n "$1" | cut -d: -f3); [ "$got" = "$2" ] || { echo "$1 is $got, want $2"; return 1; }; }
attr_has() { local r; r=$(seinfo -a "$1" -x "$P"); grep -qw "$2" <<<"$r"; }
trans()    { local r; r=$(sesearch -T -s "$1" -t "$2" -c process "$P"); grep -qw "$3" <<<"$r"; }

echo "== the module"
check "it is installed" bash -c 'semodule -l | grep -qx lpanel'

echo "== labels"
check "lpa runs from lpanel_agent_exec_t"        label /usr/local/lpa/bin/lpa lpanel_agent_exec_t
check "authd runs from lpanel_authd_exec_t"      label /usr/local/lpa/bin/lpanel-authd lpanel_authd_exec_t
check "the agent's socket is lpanel_sock_t"      label /run/lpa/lpa.sock lpanel_sock_t
check "authd's socket is lpanel_sock_t"          label /run/lpanel-authd/authd.sock lpanel_sock_t
check "the panel's code is read-only web content" label /usr/local/lpanel/public/index.php httpd_sys_content_t
check "its .env too (file mode keeps others out)" label /usr/local/lpanel/.env httpd_sys_content_t
check "system/ is writable web content"          label /usr/local/lpanel/system/cache/x httpd_sys_rw_content_t
check "the PHP-FPM socket"                       label /run/lpanel/php-fpm.sock httpd_var_run_t
check "nginx's pid file"                         label /run/lpanel-nginx/nginx.pid httpd_var_run_t
check "the logs"                                 label /var/log/lpanel/nginx-error.log httpd_log_t
check "nginx's temporary files"                  label /var/lib/lpanel/nginx/body httpd_var_lib_t
check "phpMyAdmin's code is read-only web content" label /usr/local/lpanel-pma/phpmyadmin/index.php httpd_sys_content_t
check "its sessions and sign-on tickets are writable" label /var/lib/lpanel-pma/tickets/abc httpd_sys_rw_content_t
check "the panel's PHP-FPM binary is httpd_exec_t" label /opt/remi/php83/root/usr/sbin/php-fpm httpd_exec_t
check "an account's public_html is writable web content" label /home/shop1/public_html/wp-content/uploads/a.jpg httpd_user_rw_content_t
check "its PHP sessions in tmp/ too"             label /home/shop1/tmp/sess_abc httpd_user_rw_content_t
check "and its PHP error log"                    label /home/shop1/logs/php-error.log httpd_user_rw_content_t
check "but not the rest of the home"             label /home/shop1/.ssh/authorized_keys ssh_home_t
check "nor the home itself"                      label /home/shop1 user_home_dir_t
check "the PHP-FPM sockets of accounts' pools"   label /var/opt/remi/php83/run/php-fpm/lpanel-shop1.sock httpd_var_run_t
check "Apache's site files are its config"       label /etc/httpd/lpanel/sites/shop1.conf httpd_config_t

echo "== the two root programs"
check "systemd starts lpa in lpanel_agent_t"     trans init_t lpanel_agent_exec_t lpanel_agent_t
check "and authd in lpanel_authd_t"              trans init_t lpanel_authd_exec_t lpanel_authd_t
check "lpa keeps root's powers (unconfined)"     attr_has unconfined_domain_type lpanel_agent_t
check "authd too"                                attr_has unconfined_domain_type lpanel_authd_t
check "systemd can make authd's socket"          allowed -s init_t -t lpanel_sock_t -c sock_file -p create
check "and its folder"                           allowed -s init_t -t lpanel_sock_t -c dir -p create

echo "== what the panel (httpd_t) needs"
check "connect to the agent"                     allowed -s httpd_t -t lpanel_agent_t -c unix_stream_socket -p connectto
check "connect to authd"                         allowed -s httpd_t -t lpanel_authd_t -c unix_stream_socket -p connectto
check "write to their sockets"                   allowed -s httpd_t -t lpanel_sock_t -c sock_file -p write
check "look in their folders"                    allowed -s httpd_t -t lpanel_sock_t -c dir -p search
check "MariaDB over its socket"                  allowed -s httpd_t -t mysqld_t -c unix_stream_socket -p connectto
check "listen on 7443/7444 once they are http_port_t" allowed -s httpd_t -t http_port_t -c tcp_socket -p name_bind
check "read its code"                            allowed -s httpd_t -t httpd_sys_content_t -c file -p read
check "write its cache"                          allowed -s httpd_t -t httpd_sys_rw_content_t -c file -p write
check "read the signing key and TLS key (etc_t)" allowed -s httpd_t -t etc_t -c file -p read
check "create and append its logs"               allowed -s httpd_t -t httpd_log_t -c file -p create,append
check "nginx's temporary files"                  allowed -s httpd_t -t httpd_var_lib_t -c file -p create,write,unlink
check "PHP-FPM makes its socket"                 allowed -s httpd_t -t httpd_var_run_t -c sock_file -p create
check "nginx connects to PHP-FPM"                allowed -s httpd_t -t httpd_t -c unix_stream_socket -p connectto

echo "== what accounts' sites (Apache and their PHP-FPM, also httpd_t) need"
on_by_default() { local r; r=$(semanage boolean -l); grep -Eq "^$1 +\([a-z]+ *, *on\)" <<<"$r" || { grep -E "^$1 " <<<"$r"; return 1; }; }
check "7443 and 7444 are web ports"              bash -c 'semanage port -l | grep -E "^http_port_t" | grep -q 7443'
check "8080, Apache behind nginx, is a web port"     bash -c 'semanage port -l | grep -E "^http_port_t" | grep -q 8080'
check "Apache may enter homes (httpd_enable_homedirs)" on_by_default httpd_enable_homedirs
check "PHP may call other sites (httpd_can_network_connect)" on_by_default httpd_can_network_connect
check "PHP may use databases over TCP"           on_by_default httpd_can_network_connect_db
check "PHP may send mail"                        on_by_default httpd_can_sendmail
check "PHP may write the account's web content"  allowed -s httpd_t -t httpd_user_rw_content_t -c file -p write -b httpd_builtin_scripting
check "httpd_builtin_scripting is on"            on_by_default httpd_builtin_scripting
check "Apache reads its site files"              allowed -s httpd_t -t httpd_config_t -c file -p read
check "Apache connects to the pools"             allowed -s httpd_t -t httpd_t -c unix_stream_socket -p connectto

echo "== what the panel (httpd_t) is not given"
check "no other unconfined service"              refused -s httpd_t -t unconfined_service_t -c unix_stream_socket -p connectto
check "no shadow"                                refused -s httpd_t -t shadow_t -c file -p read
check "no writing its own code (httpd_unified stays off)" unconditionally_refused httpd_unified -s httpd_t -t httpd_sys_content_t -c file -p write
check "no running the root programs"             refused -s httpd_t -t lpanel_agent_exec_t -c file -p execute
check "no other generic run-time sockets"        refused -s httpd_t -t var_run_t -c sock_file -p write

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
