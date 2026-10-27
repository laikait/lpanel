#!/usr/bin/env bash
# LPanel's PHPUnit suite against each supported MariaDB (ADR-0008: 10.11 and
# 11.8), in Docker. Nothing touches the host's databases.
#
#     test/mariadb/run.sh            # both versions
#     test/mariadb/run.sh 11.8       # one
#
# Each run gets its own network and a throwaway MariaDB; the tests empty that
# database before every test (tests/Support/ApplicationTestCase.php).
set -euo pipefail

cd "$(dirname "$0")/../.."

VERSIONS=("${@:-10.11 11.8}")
read -r -a VERSIONS <<<"${VERSIONS[*]}"
IMAGE=lpanel-test-php:8.2
PASSWORD=lpanel-test-only

# Git Bash on Windows rewrites "/app" into a Windows path unless told not to.
export MSYS_NO_PATHCONV=1
SOURCE=$(pwd -W 2>/dev/null || pwd)

echo "==> building $IMAGE"
docker build -q -t "$IMAGE" test/mariadb >/dev/null

status=0

for version in "${VERSIONS[@]}"; do
    suffix="$(echo "$version" | tr -c 'a-z0-9' '-')$$"
    network="lpanel-mdb-$suffix"
    db="lpanel-mdb-$suffix"

    cleanup() {
        docker rm -f "$db" >/dev/null 2>&1 || true
        docker network rm "$network" >/dev/null 2>&1 || true
    }
    trap cleanup EXIT

    echo "==> MariaDB $version"
    docker network create "$network" >/dev/null
    docker run -d --name "$db" --network "$network" \
        -e MARIADB_ROOT_PASSWORD="$PASSWORD" \
        -e MARIADB_DATABASE=lpanel_test \
        -e MARIADB_USER=lpanel \
        -e MARIADB_PASSWORD="$PASSWORD" \
        "mariadb:$version" >/dev/null

    for _ in $(seq 1 60); do
        if docker exec "$db" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then
            break
        fi
        sleep 1
    done

    docker exec "$db" mariadb -ulpanel -p"$PASSWORD" -N -e 'SELECT VERSION()' lpanel_test

    if docker run --rm --network "$network" -v "$SOURCE:/app" \
        -e LPANEL_TEST_DB_DSN="mysql:host=$db;dbname=lpanel_test;charset=utf8mb4" \
        -e LPANEL_TEST_DB_USERNAME=lpanel \
        -e LPANEL_TEST_DB_PASSWORD="$PASSWORD" \
        "$IMAGE" php -d memory_limit=512M vendor/bin/phpunit --no-progress --do-not-cache-result; then
        echo "==> MariaDB $version: passed"
    else
        echo "==> MariaDB $version: FAILED"
        status=1
    fi

    cleanup
    trap - EXIT
done

exit "$status"
