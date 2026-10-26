#!/usr/bin/env bash
# Build and test lpanel-authd in a throwaway Debian 12 container (the oldest
# glibc in the matrix, so the binary runs on all four supported systems).
#   helpers/authd/test/run.sh
set -euo pipefail
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
if ! command -v docker >/dev/null 2>&1; then
	for d in "/c/Users/$USERNAME/AppData/Local/Programs/DockerDesktop/resources/bin" "/c/Program Files/Docker/Docker/resources/bin"; do
		[ -x "$d/docker.exe" ] && PATH="$d:$PATH"
	done
fi
export MSYS_NO_PATHCONV=1

name=lpanel-authd-test-$$
docker run -d --name "$name" golang:1.27-bookworm sleep 1800 >/dev/null
trap 'docker rm -f "$name" >/dev/null 2>&1 || true' EXIT
docker exec "$name" mkdir -p /src
cd "$HERE" && docker cp . "$name:/src/"

docker exec -w /src -e GOFLAGS=-buildvcs=false "$name" bash -euc '
	apt-get update -qq >/dev/null && apt-get install -y -qq libpam0g-dev >/dev/null
	printf "@include common-auth\n@include common-account\n" > /etc/pam.d/lpanel
	useradd --create-home authdtest && echo "authdtest:Correct-Horse-9" | chpasswd
	go vet ./...
	echo "--- unit tests, as root"
	go test -count=1 -race ./...
	echo "--- unit tests, as an unprivileged user (peer-credential checks)"
	useradd --create-home tester && cp -r /src /home/tester/src && chown -R tester /home/tester/src
	su tester -c "cd /home/tester/src && HOME=/home/tester GOFLAGS=-buildvcs=false GOCACHE=/tmp/gocache-tester /usr/local/go/bin/go test -count=1 ./..."
	echo "--- real PAM"
	go test -count=1 -tags pamtest -run TestRealPAM -v ./... 2>&1 | grep -E "^(=== RUN|--- |PASS|FAIL|ok)"
	echo "--- build"
	CGO_ENABLED=1 go build -trimpath -ldflags "-s -w" -o /tmp/lpanel-authd . && ldd /tmp/lpanel-authd | sed "s/^/  /"
'
