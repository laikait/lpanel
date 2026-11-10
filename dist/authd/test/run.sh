#!/usr/bin/env bash
# Build and test lpanel-authd in a throwaway AlmaLinux 9 container, and leave
# the binary in dist/authd/bin/lpanel-authd_linux_amd64.
#
# EL9 has the oldest glibc LPanel supports (2.34), so a binary built here runs
# on every supported system. PAM needs cgo, so it cannot be cross-compiled.
#   dist/authd/test/run.sh
set -euo pipefail
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
if ! command -v docker >/dev/null 2>&1; then
	for d in "/c/Users/$USERNAME/AppData/Local/Programs/DockerDesktop/resources/bin" "/c/Program Files/Docker/Docker/resources/bin"; do
		[ -x "$d/docker.exe" ] && PATH="$d:$PATH"
	done
fi
export MSYS_NO_PATHCONV=1
version=$(cat "$HERE/VERSION")

name=lpanel-authd-test-$$
docker run -d --name "$name" almalinux:9 sleep 1800 >/dev/null
trap 'docker rm -f "$name" >/dev/null 2>&1 || true' EXIT
docker exec "$name" mkdir -p /src
cd "$HERE" && docker cp . "$name:/src/"

docker exec -w /src -e GOFLAGS=-buildvcs=false -e GOTOOLCHAIN=local -e VERSION="$version" "$name" bash -euc '
	dnf -y -q install golang gcc pam-devel shadow-utils >/dev/null
	go version
	install -m 0644 packaging/pam.d-lpanel.el /etc/pam.d/lpanel
	useradd --create-home authdtest && echo "authdtest:Correct-Horse-9" | chpasswd
	go vet ./...
	echo "--- unit tests, as root"
	go test -count=1 -race ./...
	echo "--- unit tests, as an unprivileged user (peer-credential checks)"
	useradd --create-home tester && cp -r /src /home/tester/src && chown -R tester /home/tester/src
	su tester -c "cd /home/tester/src && HOME=/home/tester GOFLAGS=-buildvcs=false GOTOOLCHAIN=local GOCACHE=/tmp/gocache-tester go test -count=1 ./..."
	echo "--- real PAM (EL password-auth stack)"
	go test -count=1 -tags pamtest -run TestRealPAM -v ./... 2>&1 | grep -E "^(=== RUN|--- |PASS|FAIL|ok)"
	echo "--- build"
	mkdir -p bin
	CGO_ENABLED=1 go build -trimpath -ldflags "-s -w -X main.version=$VERSION" -o bin/lpanel-authd_linux_amd64 .
	ldd bin/lpanel-authd_linux_amd64 | sed "s/^/  /"
'
mkdir -p "$HERE/bin"
dest="$HERE/bin/lpanel-authd_linux_amd64"
command -v cygpath >/dev/null 2>&1 && dest=$(cygpath -w "$dest")   # Git Bash: docker wants a Windows path
docker cp "$name:/src/bin/lpanel-authd_linux_amd64" "$dest"
echo "bin/lpanel-authd_linux_amd64 ($version)"
