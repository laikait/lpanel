#!/usr/bin/env bash
# Builds each template's stylesheet with the Tailwind v4 standalone CLI
# (no Node): templates/<area>/<name>/assets/src/app.css, scanning that
# template's own .twig files, into assets/css/app.css (minified).
#
#   dist/themes/build.sh            build every template
#   dist/themes/build.sh --check    build to a temporary file and fail if the committed CSS differs
#
# The CLI is downloaded once into dist/build/tools/ at a pinned version and
# checked against the release's published checksums. The built CSS is
# committed, so servers (and the bundle) never build anything.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

VERSION=v4.1.11
TOOLS=dist/build/tools
case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*) ASSET=tailwindcss-windows-x64.exe; BIN=$TOOLS/tw-win.exe ;;
    Linux)                ASSET=tailwindcss-linux-x64;       BIN=$TOOLS/tw-linux ;;
    *) echo "no Tailwind CLI for $(uname -s)"; exit 1 ;;
esac

if [ ! -x "$BIN" ]; then
    mkdir -p "$TOOLS"
    base=https://github.com/tailwindlabs/tailwindcss/releases/download/$VERSION
    curl -fsSL -o "$BIN" "$base/$ASSET"
    curl -fsSL -o "$TOOLS/sums.txt" "$base/sha256sums.txt"
    want=$(awk -v a="./$ASSET" '$2 == a { print $1 }' "$TOOLS/sums.txt")
    got=$(sha256sum "$BIN" | cut -d' ' -f1)
    [ -n "$want" ] && [ "$want" = "$got" ] || { rm -f "$BIN"; echo "the Tailwind CLI does not match its published checksum"; exit 1; }
    chmod +x "$BIN"
fi

check=0
[ "${1:-}" = "--check" ] && check=1
status=0
for src in templates/*/*/assets/src/app.css; do
    [ -f "$src" ] || continue
    theme=${src%/assets/src/app.css}
    out=$theme/assets/css/app.css
    mkdir -p "$(dirname "$out")"
    if [ $check = 1 ]; then
        tmp=$(mktemp)
        "$BIN" --cwd "$theme" -i assets/src/app.css -o "$tmp" --minify >/dev/null 2>&1
        cmp -s "$tmp" "$out" || { echo "stale: $out (run dist/themes/build.sh)"; status=1; }
        rm -f "$tmp"
    else
        "$BIN" --cwd "$theme" -i assets/src/app.css -o assets/css/app.css --minify
    fi
done
exit $status
