#!/usr/bin/env bash
# End to end: install LPanel from its bundle on a fresh AlmaLinux 9 machine
# (a systemd container), then use it as a person would: sign in as root with
# the real root password through real PAM, and see live data from the agent.
#
#   dist/lpa/build.sh && dist/authd/test/run.sh && dist/installer/bundle.sh
#   tests/e2e/install.sh          (KEEP=1 keeps the container for a look inside;
#                                  WEB=nginx|hybrid tests that web stack)
set -euo pipefail
cd "$(dirname "$0")/../.."
if ! command -v docker >/dev/null 2>&1; then
    for d in "/c/Users/$USERNAME/AppData/Local/Programs/DockerDesktop/resources/bin" "/c/Program Files/Docker/Docker/resources/bin"; do
        [ -x "$d/docker.exe" ] && PATH="$d:$PATH"
    done
fi
export MSYS_NO_PATHCONV=1

bundle=$(ls -d dist/build/lpanel-*/ 2>/dev/null | head -1)
[ -n "$bundle" ] || { echo "make a bundle first: dist/installer/bundle.sh"; exit 1; }

# Pebble, Let's Encrypt's test authority, for the certificate checks: built
# once for Linux from its Go module, with its test TLS certificate.
pebble=dist/build/pebble
if [ ! -s "$pebble/pebble" ] || [ ! -s "$pebble/pebble.minica.pem" ]; then
    mkdir -p "$pebble"
    GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go install github.com/letsencrypt/pebble/v2/cmd/pebble@v2.8.0
    gobin=$(go env GOPATH); modcache=$(go env GOMODCACHE)
    if command -v cygpath >/dev/null 2>&1; then gobin=$(cygpath -u "$gobin"); modcache=$(cygpath -u "$modcache"); fi
    cp "$gobin/bin/linux_amd64/pebble" "$pebble/pebble" 2>/dev/null || cp "$gobin/bin/pebble" "$pebble/pebble"
    certs="$modcache/github.com/letsencrypt/pebble/v2@v2.8.0/test/certs"
    cp -f "$certs/pebble.minica.pem" "$certs/localhost/cert.pem" "$certs/localhost/key.pem" "$pebble/" && chmod u+w "$pebble"/*.pem
fi

name=lpanel-install-e2e-$$
docker run -d --name "$name" --hostname e2e-panel.test --privileged --cgroupns=host \
    -v /sys/fs/cgroup:/sys/fs/cgroup:rw almalinux/9-init >/dev/null
# KEEP=1 leaves the container running afterwards, to look around in it.
[ "${KEEP:-}" = 1 ] && echo "container: $name" || trap 'docker rm -f "$name" >/dev/null 2>&1 || true' EXIT
for _ in $(seq 30); do docker exec "$name" systemctl is-system-running >/dev/null 2>&1 && break; sleep 1; done

docker exec "$name" mkdir -p /bundle /e2e
docker cp "${bundle%/}/." "$name:/bundle/"
docker cp tests/e2e/install-inside.sh "$name:/e2e/install-inside.sh"
docker cp tests/e2e/quota-home.sh "$name:/e2e/quota-home.sh"
docker cp "$pebble" "$name:/e2e/pebble"
# HOME_FS=xfs tests/e2e/install.sh puts /home on XFS with uquota instead of ext4.
# WEB=nginx or WEB=hybrid tests/e2e/install.sh installs that web stack instead of Apache.
docker exec -e HOME_FS="${HOME_FS:-ext4}" -e WEB="${WEB:-apache}" "$name" bash /e2e/install-inside.sh
