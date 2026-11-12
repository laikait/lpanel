#!/usr/bin/env bash
# LPanel's SELinux settings, in one place: install.sh runs it on a server
# with SELinux on (enforcing or permissive), and build.sh's check runs it in
# a container against a policy store without a kernel (LPANEL_SELINUX_NO_RELOAD=1).
#
#   setup.sh <lpanel.pp> [panel folder] [homes folder]
set -euo pipefail
pp="${1:?usage: setup.sh lpanel.pp [panel folder] [homes folder]}"
panel="${2:-/usr/local/lpanel}"
homes="${3:-/home}"
noreload=()
[ "${LPANEL_SELINUX_NO_RELOAD:-0}" = 1 ] && noreload=(-N)

# The module: lpa and authd in their own domains, their sockets reachable by
# the panel's PHP-FPM, and the panel's own paths (lpanel.te, lpanel.fc).
if [ "${#noreload[@]}" -gt 0 ]; then semodule -n -i "$pp"; else semodule -i "$pp"; fi

# The panel's two ports, and Apache's behind nginx (hybrid; 8080 is otherwise
# a proxy-cache port), are web ports.
for port in 7443 7444 8080; do
    semanage port "${noreload[@]}" -a -t http_port_t -p tcp "$port" 2>/dev/null \
        || semanage port "${noreload[@]}" -m -t http_port_t -p tcp "$port"
done

# Releases before the module labelled the panel's code with local rules; the
# module's own rules replace them.
semanage fcontext "${noreload[@]}" -d "$panel(/.*)?" 2>/dev/null || true
semanage fcontext "${noreload[@]}" -d "$panel/system(/.*)?" 2>/dev/null || true

# Accounts' sites: what Apache serves and the account's PHP writes (uploads,
# caches, sessions in tmp/, its error log in logs/). The policy's own rule
# makes public_html read-only web content; a local rule is applied after the
# policy's, so this one wins.
web="$homes/[^/]+/(public_html|tmp|logs)(/.*)?"
semanage fcontext "${noreload[@]}" -a -t httpd_user_rw_content_t "$web" 2>/dev/null \
    || semanage fcontext "${noreload[@]}" -m -t httpd_user_rw_content_t "$web"

# What customers' sites need: Apache into homes; PHP reaching other sites
# (updates, payment APIs), databases over TCP, and the local mail server.
# Between accounts, isolation comes from each pool running as the account's
# own user, not from SELinux.
for b in httpd_enable_homedirs httpd_can_network_connect httpd_can_network_connect_db httpd_can_sendmail; do
    semanage boolean "${noreload[@]}" -m --on "$b"
done
