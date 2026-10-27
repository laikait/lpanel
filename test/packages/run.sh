#!/usr/bin/env bash
# The package test: install, use, upgrade, remove, reinstall and purge the
# panel package on every supported OS (ADR-0004), each in a throwaway
# container booted with systemd, with a real agent paired over port 8444 that
# makes, suspends and deletes a hosting account through lpa-exec (M6a).
#
#   test/packages/run.sh                 # all four
#   test/packages/run.sh debian:13       # one
#
# LPA_DIR (default ../lpa) is where the agent package is built from.
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
for d in "/c/Users/${USERNAME:-}/AppData/Local/Programs/DockerDesktop/resources/bin" "/c/Program Files/Docker/Docker/resources/bin" "/c/Program Files/Go/bin"; do
	[ -d "$d" ] && PATH="$PATH:$d"
done
export MSYS_NO_PATHCONV=1

IMAGES=("$@")
[ ${#IMAGES[@]} -gt 0 ] || IMAGES=(ubuntu:24.04 ubuntu:26.04 debian:12 debian:13)
OLD=0.1.0
NEW=0.1.1
LPA_VERSION=0.1.2
LPA_DIR=${LPA_DIR:-$ROOT/../lpa}

cd "$ROOT"
"$ROOT/packaging/build-deb.sh" "$OLD"
"$ROOT/packaging/build-deb.sh" "$NEW"
(cd "$LPA_DIR" && scripts/build-deb.sh "$LPA_VERSION" >/dev/null)
cp "$LPA_DIR/dist/lpa_${LPA_VERSION}_amd64.deb" dist/

declare -A RESULT
for image in "${IMAGES[@]}"; do
	tag=$(echo "$image" | tr ':.' '--')
	name=lpanel-pkgtest-$tag-$$
	echo
	echo "======== $image ========"
	docker build -q -t "lpanel-pkgtest:$tag" --build-arg BASE="$image" test/packages >/dev/null
	docker run -d --name "$name" --hostname panel.pkgtest.example --privileged --cgroupns=private \
		--tmpfs /run --tmpfs /run/lock "lpanel-pkgtest:$tag" >/dev/null
	# Not /tmp: Debian 13 mounts a fresh tmpfs there at boot, hiding anything copied in.
	docker exec "$name" mkdir -p /opt/pkgtest
	for f in "dist/lpanel_${OLD}_amd64.deb" "dist/lpanel_${NEW}_amd64.deb" "dist/lpa_${LPA_VERSION}_amd64.deb" test/packages/inside.sh; do
		docker cp "$f" "$name:/opt/pkgtest/"
	done
	if docker exec "$name" bash -c "tr -d '\r' < /opt/pkgtest/inside.sh > /opt/pkgtest/inside-lf.sh && bash /opt/pkgtest/inside-lf.sh $OLD $NEW $LPA_VERSION"; then
		RESULT[$image]=PASS
	else
		RESULT[$image]=FAIL
		docker exec "$name" bash -c 'journalctl -u "lpanel-*" -u lpa-agent -u lpa-exec --no-pager -n 60; tail -20 /var/log/lpanel/php/*.log /var/log/lpanel/nginx/error.log 2>/dev/null' || true
	fi
	docker rm -f "$name" >/dev/null
done

echo
echo "======== summary ========"
status=0
for image in "${IMAGES[@]}"; do
	echo "${RESULT[$image]}  $image"
	[ "${RESULT[$image]}" = PASS ] || status=1
done
exit $status
