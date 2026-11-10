#!/usr/bin/env bash
# Make an installable bundle: dist/build/lpanel-<version>/ and its .tar.gz.
#
#   dist/lpa/build.sh && dist/authd/test/run.sh   # the two binaries first
#   dist/selinux/build.sh                         # and the SELinux module
#   dist/installer/bundle.sh [version]
#
# The bundle holds install.sh, the panel's code with production dependencies
# only (composer --no-dev), both binaries, and every unit and config file.
set -euo pipefail
cd "$(dirname "$0")/../.."
ROOT=$(pwd)
version="${1:-0.1.0-dev}"
out="dist/build/lpanel-${version}"

[ -f dist/lpa/bin/lpa_linux_amd64 ] || { echo "build the agent first: dist/lpa/build.sh"; exit 1; }
[ -f dist/authd/bin/lpanel-authd_linux_amd64 ] || { echo "build authd first: dist/authd/test/run.sh"; exit 1; }
[ -f dist/selinux/bin/lpanel.pp ] || { echo "build the SELinux module first: dist/selinux/build.sh"; exit 1; }

rm -rf "$out"
mkdir -p "$out/panel" "$out/bin" "$out/packaging"

echo "== panel code"
# Everything the panel runs, nothing it does not: no tests, no design notes,
# no agent sources, no local settings, no runtime state.
tar -cf - \
    --exclude=./.git --exclude=./dist --exclude=./tests --exclude=./design \
    --exclude=./docs --exclude=./vendor --exclude=./.env --exclude='./.env.*' \
    --exclude=./system/Cache --exclude=./system/Logs --exclude=./system/Runtime \
    --exclude=./system/Sessions --exclude=./system/Security --exclude=./system/Queue \
    --exclude=./system/Schedule --exclude=./system/Storage --exclude=./system/Commands \
    --exclude=./.php-cs-fixer.cache --exclude=./.phpunit.cache --exclude=./.phpunit.result.cache \
    . | tar -C "$out/panel" -xf -
cp .env.example "$out/panel/.env.example"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet --working-dir="$out/panel"

echo "== binaries"
install -m 0755 dist/lpa/bin/lpa_linux_amd64 "$out/bin/lpa"
install -m 0755 dist/authd/bin/lpanel-authd_linux_amd64 "$out/bin/lpanel-authd"

echo "== packaging"
cp dist/installer/packaging/* "$out/packaging/"
cp dist/lpa/packaging/lpa.service dist/lpa/packaging/lpa.json "$out/packaging/"
cp dist/authd/packaging/authd.json dist/authd/packaging/lpanel-authd.service \
   dist/authd/packaging/lpanel-authd.socket dist/authd/packaging/pam.d-lpanel.el "$out/packaging/"
install -m 0644 dist/selinux/bin/lpanel.pp "$out/packaging/lpanel.pp"
install -m 0755 dist/installer/install.sh "$out/install.sh"
echo "$version" > "$out/VERSION"

tar -C dist/build -czf "dist/build/lpanel-${version}.tar.gz" "lpanel-${version}"
echo "dist/build/lpanel-${version}.tar.gz ($(du -h "dist/build/lpanel-${version}.tar.gz" | cut -f1))"
