#!/usr/bin/env bash
# Build lpa for Linux (amd64 and arm64) into dist/lpa/bin/.
#   ./build.sh            version from the VERSION file
#   ./build.sh 0.2.0      a given version
set -euo pipefail
cd "$(dirname "$0")"

version="${1:-$(cat VERSION)}"
mkdir -p bin
for arch in amd64 arm64; do
    CGO_ENABLED=0 GOOS=linux GOARCH="$arch" go build -trimpath \
        -ldflags "-s -w -X main.version=${version}" \
        -o "bin/lpa_linux_${arch}" ./cmd/lpa
    echo "bin/lpa_linux_${arch} (${version})"
done
