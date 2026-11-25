#!/usr/bin/env bash
# Publish an LPanel release to an update channel: the files a web server
# serves at the address lpa.json's updates.url names.
#
#   dist/installer/release.sh keygen <dir>
#       Makes a release signing key: <dir>/release.key (private; keep it
#       offline) and <dir>/release.pub (the public half, one base64 line, for
#       LPANEL_RELEASE_PUB when building bundles).
#
#   dist/installer/release.sh sign <bundle.tar.gz> <release.key> <channel-dir> [notes]
#       Copies the bundle into <channel-dir> and writes latest.json (the
#       manifest: version, file, SHA-256, size, notes) and latest.json.sig
#       (Ed25519 over "lpanel-release-v1\n" and the manifest, base64).
#
# A release bundle is built with its channel and key in it:
#   LPANEL_UPDATE_URL=https://updates.example.com/stable/latest.json \
#   LPANEL_RELEASE_PUB=keys/release.pub dist/installer/bundle.sh 1.2.0
# Needs OpenSSL 3 (Ed25519 signing of raw input).
set -euo pipefail

die() { echo "release.sh: $*" >&2; exit 1; }

case "${1:-}" in
    keygen)
        dir=${2:?usage: release.sh keygen <dir>}
        mkdir -p "$dir"
        [ ! -e "$dir/release.key" ] || die "$dir/release.key exists; not replacing a signing key"
        (umask 077 && openssl genpkey -algorithm ed25519 -out "$dir/release.key")
        openssl pkey -in "$dir/release.key" -pubout -outform DER | tail -c 32 | base64 > "$dir/release.pub"
        echo "$dir/release.key (private), $dir/release.pub: $(cat "$dir/release.pub")"
        ;;
    sign)
        bundle=${2:?usage: release.sh sign <bundle.tar.gz> <release.key> <channel-dir> [notes]}
        key=${3:?usage: release.sh sign <bundle.tar.gz> <release.key> <channel-dir> [notes]}
        out=${4:?usage: release.sh sign <bundle.tar.gz> <release.key> <channel-dir> [notes]}
        notes=${5:-}
        file=$(basename "$bundle")
        [[ "$file" =~ ^lpanel-([0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?)\.tar\.gz$ ]] || die "$file is not an lpanel-<version>.tar.gz bundle"
        version=${BASH_REMATCH[1]}
        [ "$(tar -xOzf "$bundle" "lpanel-$version/VERSION" | tr -d "[:space:]")" = "$version" ] || die "$file does not hold release $version"
        mkdir -p "$out"
        [ "$(cd "$(dirname "$bundle")" && pwd)/$file" = "$(cd "$out" && pwd)/$file" ] || cp "$bundle" "$out/$file"
        sum=$(sha256sum "$out/$file" | cut -d' ' -f1)
        size=$(stat -c %s "$out/$file")
        # Notes are text: escaped for JSON by PHP if it is there, else kept to plain characters.
        if command -v php >/dev/null 2>&1; then
            notes_json=$(php -r 'echo json_encode($argv[1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);' "$notes")
        else
            notes_json="\"$(printf '%s' "$notes" | tr -cd 'A-Za-z0-9 .,:;()/_-')\""
        fi
        printf '{"product":"lpanel","version":"%s","file":"%s","sha256":"%s","size":%s,"released":"%s","notes":%s}\n' \
            "$version" "$file" "$sum" "$size" "$(date -u +%Y-%m-%d)" "$notes_json" > "$out/latest.json.new"
        msg=$(mktemp)
        trap 'rm -f "$msg"' EXIT
        { printf 'lpanel-release-v1\n'; cat "$out/latest.json.new"; } > "$msg"
        # OpenSSL 3 wants -rawin for a message; 1.1.1 (version 8) signs it as it is.
        { openssl pkeyutl -sign -inkey "$key" -rawin -in "$msg" 2>/dev/null || openssl pkeyutl -sign -inkey "$key" -in "$msg"; } | base64 -w0 > "$out/latest.json.sig.new"
        echo >> "$out/latest.json.sig.new"
        # The signature first, then the manifest: a client between the two
        # renames sees a mismatch and tries again later, never a wrong release.
        mv -f "$out/latest.json.sig.new" "$out/latest.json.sig"
        mv -f "$out/latest.json.new" "$out/latest.json"
        echo "$out: release $version ($size bytes, sha256 $sum)"
        ;;
    *)
        sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'
        exit 1
        ;;
esac
