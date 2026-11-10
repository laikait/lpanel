#!/usr/bin/env bash
# Build LPanel's SELinux module in a throwaway AlmaLinux 9 container, against
# EL9's own targeted policy. Then check it, and leave it in
# dist/selinux/bin/lpanel.pp for bundle.sh.
#
#   dist/selinux/build.sh
#
# The check loads the module into the container's policy store, without a
# kernel. It asks the compiled policy whether each access the panel needs is
# allowed, whether the broad ones it must not have are absent, and which label
# every path gets. That proves the rules and labels. Whether nothing else
# gets denied at run time is only proved on a VM with SELinux enforcing:
# tests/vm/run.sh.
set -euo pipefail
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && (pwd -W 2>/dev/null || pwd))
if ! command -v docker >/dev/null 2>&1; then
	for d in "/c/Users/$USERNAME/AppData/Local/Programs/DockerDesktop/resources/bin" "/c/Program Files/Docker/Docker/resources/bin"; do
		[ -x "$d/docker.exe" ] && PATH="$d:$PATH"
	done
fi
export MSYS_NO_PATHCONV=1

name=lpanel-selinux-build-$$
docker run -d --name "$name" almalinux:9 sleep 1800 >/dev/null
trap 'docker rm -f "$name" >/dev/null 2>&1 || true' EXIT
docker exec "$name" mkdir -p /src
docker cp "$HERE/lpanel.te" "$name:/src/"
docker cp "$HERE/lpanel.fc" "$name:/src/"
docker cp "$HERE/check-inside.sh" "$name:/src/"

docker exec -w /src "$name" bash -euc '
	dnf -y -q install selinux-policy-targeted selinux-policy-devel setools-console policycoreutils-python-utils >/dev/null
	# Remi labels its php-fpm httpd_exec_t (an equivalence rule added when
	# the package is installed); the check makes sure of it.
	dnf -y -q install https://rpms.remirepo.net/enterprise/remi-release-9.rpm >/dev/null
	dnf -y -q install php83-php-fpm >/dev/null
	echo "--- build against $(rpm -q selinux-policy-targeted)"
	make -s -f /usr/share/selinux/devel/Makefile lpanel.pp
'
docker exec -w /src "$name" bash check-inside.sh

mkdir -p "$HERE/bin"
docker cp "$name:/src/lpanel.pp" "$HERE/bin/lpanel.pp"
echo "--- dist/selinux/bin/lpanel.pp"
