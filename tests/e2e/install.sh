#!/usr/bin/env bash
# End to end: install LPanel from its bundle on a fresh AlmaLinux 9 machine
# (a systemd container), then use it as a person would: sign in as root with
# the real root password through real PAM, and see live data from the agent.
#
#   dist/lpa/build.sh && dist/authd/test/run.sh && dist/installer/bundle.sh
#   tests/e2e/install.sh          (KEEP=1 keeps the container for a look inside)
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
# HOME_FS=xfs tests/e2e/install.sh puts /home on XFS with uquota instead of ext4.
docker exec -e HOME_FS="${HOME_FS:-ext4}" "$name" bash /e2e/install-inside.sh
