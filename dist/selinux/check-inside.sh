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
check "Roundcube's code is read-only web content" label /usr/local/lpanel-webmail/roundcube/public_html/index.php httpd_sys_content_t
check "its sessions, logs and sign-on tickets are writable" label /var/lib/lpanel-webmail/tickets/abc httpd_sys_rw_content_t
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
check "7449, mail clients' setup from sites, is a web port" bash -c 'semanage port -l | grep -E "^http_port_t" | grep -q 7449'
check "webmail reaches IMAP and SMTP on this machine" allowed -s httpd_t -t pop_port_t -c tcp_socket -p name_connect
check "Apache may enter homes (httpd_enable_homedirs)" on_by_default httpd_enable_homedirs
check "PHP may call other sites (httpd_can_network_connect)" on_by_default httpd_can_network_connect
check "PHP may use databases over TCP"           on_by_default httpd_can_network_connect_db
check "PHP may send mail"                        on_by_default httpd_can_sendmail
check "PHP may write the account's web content"  allowed -s httpd_t -t httpd_user_rw_content_t -c file -p write -b httpd_builtin_scripting
check "httpd_builtin_scripting is on"            on_by_default httpd_builtin_scripting
check "Apache reads its site files"              allowed -s httpd_t -t httpd_config_t -c file -p read
check "Apache connects to the pools"             allowed -s httpd_t -t httpd_t -c unix_stream_socket -p connectto

echo "== mail (Exim and Dovecot, from EL's own policy)"
check "an account's mail is mail_home_rw_t"      label /home/shop1/mail/example.com/info/Maildir/cur/1 mail_home_rw_t
check "the mail folder itself too"               label /home/shop1/mail mail_home_rw_t
check "Dovecot writes it"                        allowed -s dovecot_t -t mail_home_rw_t -c file -p create,write,rename,unlink
check "and makes its folders"                    allowed -s dovecot_t -t mail_home_rw_t -c dir -p create,add_name
check "Dovecot finds it in the home"             allowed -s dovecot_t -t user_home_dir_t -c dir -p search
check "Dovecot reads its data (passwd.d, sieve)" bash -c 'matchpathcon /etc/dovecot/lpanel/passwd.d/example.com | grep -q dovecot_etc_t'
check "Exim reads its data"                      bash -c 't=$(matchpathcon -n /etc/exim/lpanel/owners/example.com); sesearch -A -s exim_t -t "$t" -c file -p read | grep -q .'
check "Exim delivers to Dovecot's LMTP socket"   allowed -s exim_t -t dovecot_t -c unix_stream_socket -p connectto
check "and checks logins on its auth socket"     allowed -s exim_t -t dovecot_var_run_t -c sock_file -p write
check "both read the hostname certificate copy"  bash -c 'matchpathcon /etc/pki/lpanel-mail/host.key | grep -q cert_t'
check "Exim reads cert_t"                        allowed -s exim_t -t cert_t -c file -p read
check "Dovecot reads cert_t"                     allowed -s dovecot_t -t cert_t -c file -p read
check "accounts' PHP runs Exim as system_mail_t" bash -c 'sesearch -T -s httpd_t -t exim_exec_t -c process | grep -q system_mail_t'
check "which becomes exim_t"                     bash -c 'sesearch -T -s system_mail_t -t exim_exec_t -c process | grep -q exim_t'
check "autoresponders (Dovecot) send through it" bash -c 'sesearch -T -s dovecot_t -t exim_exec_t -c process | grep -q sendmail_t'
check "Exim reads sending limits and the SRS key" bash -c 't=$(matchpathcon -n /etc/exim/lpanel/accounts/shop1 | cut -d: -f3); u=$(matchpathcon -n /etc/exim/lpanel/srs_secret | cut -d: -f3); sesearch -A -s exim_t -t "$t" -c file -p read | grep -q . && sesearch -A -s exim_t -t "$u" -c file -p read | grep -q .'

echo "== spam and virus filtering (Rspamd, ClamAV)"
check "Rspamd's worker port is spamd_port_t"     bash -c 'semanage port -l | grep -E "^spamd_port_t" | grep -qw 11333'
check "Exim connects to it"                      allowed -s exim_t -t spamd_port_t -c tcp_socket -p name_connect
check "Rspamd runs unconfined, started by systemd" trans init_t bin_t unconfined_service_t
check "ClamAV's socket"                          label /run/clamd.scan/clamd.sock antivirus_var_run_t
check "its signatures"                           label /var/lib/clamav/main.cvd antivirus_db_t
check "Rspamd reaches ClamAV's socket"           allowed -s unconfined_service_t -t antivirus_t -c unix_stream_socket -p connectto

echo "== FTP (Pure-FTPd; EL's policy has no domain for it)"
check "Pure-FTPd's binary is plain bin_t"        label /usr/sbin/pure-ftpd bin_t
check "so it runs unconfined, started by systemd" trans init_t bin_t unconfined_service_t
check "its certificate file is cert_t"           label /etc/pki/pure-ftpd/pure-ftpd.pem cert_t
check "the panel cannot reach its files"         refused -s httpd_t -t cert_t -c file -p write

echo "== SSH and SFTP (the jail is a login shell, bubblewrap a plain program)"
check "the jail shell is plain bin_t"            label /usr/libexec/lpanel/jail-shell bin_t
check "the SFTP-only shell too"                  label /usr/libexec/lpanel/jail-sftp bin_t
check "bubblewrap is plain bin_t"                label /usr/bin/bwrap bin_t
check "accounts' keys are etc_t"                 label /etc/ssh/lpanel/keys/shop7 etc_t
check "sshd may read them"                       allowed -s sshd_t -t etc_t -c file -p read
check "the panel cannot write them"              refused -s httpd_t -t etc_t -c file -p write

echo "== what the panel (httpd_t) is not given"
check "no other unconfined service"              refused -s httpd_t -t unconfined_service_t -c unix_stream_socket -p connectto
check "no shadow"                                refused -s httpd_t -t shadow_t -c file -p read
check "no writing its own code (httpd_unified stays off)" unconditionally_refused httpd_unified -s httpd_t -t httpd_sys_content_t -c file -p write
check "no running the root programs"             refused -s httpd_t -t lpanel_agent_exec_t -c file -p execute
check "no other generic run-time sockets"        refused -s httpd_t -t var_run_t -c sock_file -p write

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
