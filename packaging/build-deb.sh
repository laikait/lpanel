#!/usr/bin/env bash
# Build the panel's .deb for one version (ADR-0012).
#
#   packaging/build-deb.sh 0.1.0     -> dist/lpanel_0.1.0_amd64.deb
#
# Everything happens in a Debian 12 container (the oldest glibc in the
# matrix, so lpanel-authd runs on all four systems): Composer installs the
# production dependencies, Go builds lpanel-authd against PAM, and the tree is
# assembled with the right modes and owners even when this runs on Windows.
# Servers never run Composer.
set -euo pipefail

VERSION=${1:?usage: build-deb.sh VERSION}
[[ $VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+([~+][0-9A-Za-z.]+)?$ ]] || { echo "bad version: $VERSION" >&2; exit 2; }

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
if ! command -v docker >/dev/null 2>&1; then
	for d in "/c/Users/${USERNAME:-}/AppData/Local/Programs/DockerDesktop/resources/bin" "/c/Program Files/Docker/Docker/resources/bin"; do
		[ -x "$d/docker.exe" ] && PATH="$d:$PATH"
	done
fi
export MSYS_NO_PATHCONV=1

cd "$ROOT"
mkdir -p dist

builder=lpanel-deb-builder-$$
docker run -d --name "$builder" golang:1.27-bookworm sleep 1800 >/dev/null
trap 'docker rm -f "$builder" >/dev/null 2>&1 || true' EXIT

echo "==> copying the source"
docker exec "$builder" mkdir -p /build/src
tar -cf - \
	--exclude=./.git --exclude=./vendor --exclude=./dist --exclude='./.env*' \
	--exclude='./system/*' --exclude=./test/e2e/.work --exclude=./nginx.conf . \
	| docker exec -i "$builder" tar -xf - -C /build/src

echo "==> building in the container"
docker exec -e VERSION="$VERSION" -e GOFLAGS=-buildvcs=false "$builder" bash -euo pipefail -c '
	export DEBIAN_FRONTEND=noninteractive
	apt-get update -qq >/dev/null
	apt-get install -y -qq --no-install-recommends php8.2-cli php8.2-intl php8.2-mbstring php8.2-xml php8.2-curl \
		unzip composer libpam0g-dev >/dev/null

	cd /build/src
	composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet

	( cd helpers/authd && CGO_ENABLED=1 go build -trimpath -ldflags "-s -w" -o /build/lpanel-authd . )

	S=/build/stage
	A=$S/opt/lpanel
	install -d -m 0755 $S/DEBIAN $A $S/usr/sbin $S/usr/lib/lpanel $S/usr/share/lpanel $S/usr/lib/systemd/system \
		$S/etc/lpanel $S/etc/pam.d $S/etc/logrotate.d $S/usr/share/doc/lpanel

	# The application, without what only development needs.
	tar -C /build/src -cf - \
		--exclude=./helpers --exclude=./packaging --exclude=./tests --exclude=./test --exclude=./design \
		--exclude=./docs --exclude=./system --exclude=./server --exclude=./phpunit.xml --exclude=./phpstan.neon \
		--exclude="./.php-cs-fixer*" --exclude="./.git*" --exclude=./.editorconfig . | tar -C $A -xf -
	find $A -type d -exec chmod 0755 {} +
	find $A -type f -exec chmod 0644 {} +
	echo "$VERSION" > $A/VERSION
	# Writable state and secrets live outside the read-only tree.
	ln -s /var/lib/lpanel $A/system
	ln -s /etc/lpanel/lpanel.env $A/.env

	P=/build/src/packaging
	strip() { tr -d "\r" < "$1" > "$2"; chmod "$3" "$2"; }
	strip $P/bin/lpanel $S/usr/sbin/lpanel 0755
	strip $P/bin/lpanel-setup $S/usr/sbin/lpanel-setup 0755
	install -m 0755 /build/lpanel-authd $S/usr/lib/lpanel/lpanel-authd
	for t in nginx.conf.in php-fpm.conf.in; do strip $P/templates/$t $S/usr/share/lpanel/$t 0644; done
	for u in $P/systemd/* /build/src/helpers/authd/packaging/lpanel-authd.service /build/src/helpers/authd/packaging/lpanel-authd.socket; do
		strip $u $S/usr/lib/systemd/system/$(basename $u) 0644
	done
	strip /build/src/helpers/authd/packaging/authd.json $S/etc/lpanel/authd.json 0644
	strip /build/src/helpers/authd/packaging/pam.d-lpanel $S/etc/pam.d/lpanel 0644
	strip $P/logrotate/lpanel $S/etc/logrotate.d/lpanel 0644
	echo "LPanel $VERSION. Setup: lpanel-setup. Console: lpanel. Settings: /etc/lpanel/lpanel.conf." > $S/usr/share/doc/lpanel/README

	sed "s/@VERSION@/$VERSION/" $P/deb/control | tr -d "\r" > $S/DEBIAN/control
	echo "Installed-Size: $(du -sk --exclude=DEBIAN $S | cut -f1)" >> $S/DEBIAN/control
	strip $P/deb/conffiles $S/DEBIAN/conffiles 0644
	for f in preinst postinst prerm postrm; do strip $P/deb/$f $S/DEBIAN/$f 0755; done

	dpkg-deb --root-owner-group -Zxz --build $S /build/lpanel.deb >/dev/null
	dpkg-deb --info /build/lpanel.deb | sed -n "s/^ *\(Package\|Version\|Installed-Size\|Depends\):/\1:/p"
	echo "files: $(dpkg-deb --contents /build/lpanel.deb | wc -l)"
'
docker cp "$builder:/build/lpanel.deb" "dist/lpanel_${VERSION}_amd64.deb"
echo "built dist/lpanel_${VERSION}_amd64.deb"
