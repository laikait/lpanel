#!/usr/bin/env bash
# The install e2e on a real AlmaLinux or Rocky 9 machine with SELinux
# enforcing: the same checks as tests/e2e/install.sh, plus the SELinux ones
# at the end of install-inside.sh, ending with "nothing was denied".
#
#   dist/lpa/build.sh && dist/authd/test/run.sh && dist/selinux/build.sh
#   dist/installer/bundle.sh
#   tests/vm/run.sh root@192.0.2.10 [ssh options, e.g. -i ~/.ssh/lpanel-vm -p 22]
#
# Use a fresh, throwaway machine: the run installs LPanel, sets root's
# password to a test one, and (when /home has no user quotas) mounts an XFS
# image with uquota over /home. Root must be reachable over SSH with a key.
# design/DEVELOPMENT.md, "Testing on a VM", says how to make one.
set -euo pipefail
cd "$(dirname "$0")/../.."

target="${1:?usage: tests/vm/run.sh root@host [ssh options]}"
shift
ssh_opts=(-o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ServerAliveInterval=30 "$@")
r() { ssh "${ssh_opts[@]}" "$target" "$@"; }

bundle=$(ls -d dist/build/lpanel-*/ 2>/dev/null | head -1)
[ -n "$bundle" ] || { echo "make a bundle first: dist/installer/bundle.sh"; exit 1; }
[ -f "${bundle%/}/packaging/lpanel.pp" ] || { echo "the bundle has no SELinux module: dist/selinux/build.sh, then bundle.sh again"; exit 1; }

echo "== $target"
r bash -s <<'EOF'
set -e
. /etc/os-release
echo "  $PRETTY_NAME, kernel $(uname -r)"
case "$ID:$VERSION_ID" in
    almalinux:9*|rocky:9*) ;;
    *) echo "  needs AlmaLinux or Rocky 9"; exit 1 ;;
esac
mode=$(getenforce 2>/dev/null || echo Disabled)
if [ "$mode" != Enforcing ]; then
    echo "  SELinux is $mode. To turn it on (then run this again):"
    echo "    sed -i 's/^SELINUX=.*/SELINUX=enforcing/' /etc/selinux/config"
    echo "    grubby --update-kernel ALL --remove-args selinux"
    echo "    touch /.autorelabel && reboot"
    exit 1
fi
echo "  SELinux: Enforcing"
if [ -e /usr/local/lpanel ]; then
    echo "  LPanel is already installed here; use a fresh machine"
    exit 1
fi
EOF

echo "== copying the bundle and the checks"
tar -C "$bundle" -czf - . | r 'rm -rf /bundle /e2e && mkdir -p /bundle /e2e && tar -C /bundle -xzf -'
tar -C tests/e2e -cf - install-inside.sh quota-home.sh | r 'tar -C /e2e -xf -'

# auto: /home as it is if it has user quotas, otherwise an XFS image with
# uquota, which is EL9's own filesystem.
r "HOME_FS=${HOME_FS:-auto} bash /e2e/install-inside.sh"
