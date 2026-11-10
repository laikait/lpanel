#!/usr/bin/env bash
# End to end: the real lpa and the real panel client in an AlmaLinux 9
# container. Run from the repository root:
#     tests/e2e/agent.sh
# Needs Docker and a built agent (dist/lpa/build.sh).
set -euo pipefail
cd "$(dirname "$0")/../.."

[ -x dist/lpa/bin/lpa_linux_amd64 ] || [ -f dist/lpa/bin/lpa_linux_amd64 ] || { echo "build the agent first: dist/lpa/build.sh"; exit 1; }

src="$(pwd -W 2>/dev/null || pwd)"
# --privileged: the accounts checks mount an ext4 image with user quotas on
# /home, so the kernel's enforcement is tested for real.
MSYS_NO_PATHCONV=1 docker run --rm --privileged \
    --hostname e2e-web-01 \
    -v "$src:/src:ro" \
    almalinux:9 bash /src/tests/e2e/agent-inside.sh
