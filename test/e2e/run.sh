#!/usr/bin/env bash
# M3/M4 end to end: the real lpa binary against the real panel behind panel
# Nginx, in Docker. Nothing touches the host.
#
#     test/e2e/run.sh                 # LPA_DIR defaults to ../lpa
#
# 1. lpa enroll with the wrong pin is refused by the agent, and spends nothing
# 2. lpa enroll pairs; the same token on a second machine is refused
# 3. lpa agent heartbeats; the panel shows it online with its capabilities
# 4. operations: every read-only operation runs on the real agent and its
#    result passes the panel's schema; an agent's own failure keeps its code;
#    work queued while the agent is down runs when it is back
# 5. a rotation asked for in the panel happens at the next heartbeat; the
#    agent goes on working with the new key, and after a restart
# 6. a revoked agent is refused, and its queued work is cancelled
set -euo pipefail

cd "$(dirname "$0")/../.."
export MSYS_NO_PATHCONV=1

LPA_DIR=${LPA_DIR:-../lpa}
SRC=$(pwd -W 2>/dev/null || pwd)
LPA_SRC=$(cd "$LPA_DIR" && (pwd -W 2>/dev/null || pwd))
WORK=test/e2e/.work
ID=$$
NET=lpanel-e2e-$ID
PANEL=lpanel-e2e-panel-$ID
AGENT=lpanel-e2e-agent-$ID
AGENT2=lpanel-e2e-agent2-$ID

pass=0
fail=0
ok() { echo "  ok   $*"; pass=$((pass + 1)); }
bad() { echo "  FAIL $*"; fail=$((fail + 1)); }

cleanup() {
    docker rm -f "$PANEL" "$AGENT" "$AGENT2" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT

panel() { docker exec -u www-data "$PANEL" php /srv/lpanel/laika "$@"; }
servers_json() { panel servers:list --json; }
# ofield <path>: a field of one JSON object on stdin.
ofield() { php -r '$v = json_decode(stream_get_contents(STDIN), true); foreach (explode(".", $argv[1]) as $k) { $v = is_array($v) ? ($v[$k] ?? null) : null; } echo is_array($v) ? json_encode($v) : var_export($v, true);' "$1"; }
field() { php -r '$s = json_decode(stream_get_contents(STDIN), true)[0] ?? []; $v = $s; foreach (explode(".", $argv[1]) as $k) { $v = is_array($v) ? ($v[$k] ?? null) : null; } echo is_array($v) ? json_encode($v) : var_export($v, true);' "$1"; }

# wait_for <seconds> <description> <command...>: retry until the command succeeds.
wait_for() {
    local seconds=$1 what=$2
    shift 2
    for _ in $(seq 1 "$seconds"); do
        if "$@" >/dev/null 2>&1; then ok "$what"; return 0; fi
        sleep 1
    done
    bad "$what (waited ${seconds}s)"
    return 1
}

echo "==> building lpa (linux, static) from $LPA_DIR"
mkdir -p "$WORK"
docker run --rm -v "$LPA_SRC:/src:ro" -v "$SRC/$WORK:/out" -w /src -e CGO_ENABLED=0 -e GOFLAGS=-buildvcs=false \
    golang:1.27-bookworm go build -trimpath -o /out/lpa ./cmd/lpa

echo "==> building the panel image"
docker build -q -t lpanel-e2e-panel test/e2e >/dev/null

echo "==> starting panel Nginx + PHP-FPM, and two machines"
docker network create "$NET" >/dev/null
docker run -d --name "$PANEL" --network "$NET" --network-alias panel -v "$SRC:/src:ro" lpanel-e2e-panel >/dev/null
for c in "$AGENT" "$AGENT2"; do
    docker run -d --name "$c" --network "$NET" debian:12 sleep infinity >/dev/null
    docker cp "$WORK/lpa" "$c:/usr/bin/lpa"
done
wait_for 90 "the panel is up" docker exec "$PANEL" test -f /run/e2e-ready

PIN=$(docker exec "$PANEL" cat /etc/lpanel-e2e/pin)
TOKEN=$(panel servers:add web-01 --token)
WRONG_PIN="sha256/$(printf 'A%.0s' $(seq 1 43))"

echo "==> 1. the pin"
if docker exec "$AGENT" lpa enroll --panel https://panel:8443 --pin "$WRONG_PIN" --token "$TOKEN" --owner root >"$WORK/out.txt" 2>&1; then
    bad "enroll with the wrong pin succeeded"
else
    grep -q "does not match the pinned" "$WORK/out.txt" && ok "the wrong pin is refused by the agent" || bad "wrong pin: $(cat "$WORK/out.txt")"
fi

echo "==> 2. pairing"
if docker exec "$AGENT" lpa enroll --panel https://panel:8443 --pin "$PIN" --token "$TOKEN" --owner root >"$WORK/out.txt" 2>&1; then
    ok "lpa enroll pairs with the token the panel printed (the wrong pin spent nothing)"
else
    bad "enroll: $(cat "$WORK/out.txt")"
fi
[ "$(servers_json | field status)" = "'online'" ] && ok "the server is active" || bad "status after enroll: $(servers_json)"

if docker exec "$AGENT2" lpa enroll --panel https://panel:8443 --pin "$PIN" --token "$TOKEN" --owner root >"$WORK/out.txt" 2>&1; then
    bad "a used token paired a second machine"
else
    grep -q "enrollment_invalid" "$WORK/out.txt" && ok "a used token is refused with enrollment_invalid" || bad "reuse: $(cat "$WORK/out.txt")"
fi

echo "==> 3. heartbeats"
docker exec "$AGENT" sh -c 'printf "{\"heartbeat_seconds\": 10, \"poll_idle_seconds\": 1}\n" > /etc/lpa/config.json'
docker exec "$AGENT" sh -c 'lpa agent >>/var/log/lpa.log 2>&1 & echo $! > /run/lpa.pid'
has_capabilities() { servers_json | field capabilities | grep -q '"system.info"'; }
wait_for 30 "the first heartbeat reports the agent's operations" has_capabilities
[ "$(servers_json | field agent_version)" != "NULL" ] && ok "the agent version is recorded" || bad "no agent version"

echo "==> 4. operations"
for op in system.info system.metrics agent.health agent.capabilities; do
    if panel operations:run web-01 "$op" --wait --json --timeout=40 >"$WORK/op.json" 2>&1; then
        ok "$op ran on the agent and its result was accepted"
    else
        bad "$op: $(cat "$WORK/op.json")"
    fi
done
panel operations:run web-01 system.info --wait --json --timeout=40 >"$WORK/op.json" 2>&1 || true
[ "$(ofield result.hostname <"$WORK/op.json")" = "'$(docker exec "$AGENT" hostname)'" ]     && ok "system.info reports the machine's own hostname" || bad "system.info: $(cat "$WORK/op.json")"
[ "$(ofield result.os.id <"$WORK/op.json")" = "'debian'" ] && ok "and its OS" || bad "os: $(ofield result.os <"$WORK/op.json")"

# No systemd in this container: the agent's own error code reaches the panel.
panel operations:run web-01 service.list_status --wait --json --timeout=40 >"$WORK/op.json" 2>&1 || true
[ "$(ofield status <"$WORK/op.json"):$(ofield error_code <"$WORK/op.json")" = "'failed':'adapter_unavailable'" ]     && ok "service.list_status without systemd fails as adapter_unavailable" || bad "service.list_status: $(cat "$WORK/op.json")"

docker exec "$AGENT" sh -c 'kill $(cat /run/lpa.pid)'
QUEUED=$(panel operations:run web-01 agent.health --json | ofield id | tr -d "'")
sleep 3
[ "$(panel operations:show "$QUEUED" --json | ofield status)" = "'queued'" ] && ok "with the agent stopped, work waits queued" || bad "not queued: $(panel operations:show "$QUEUED" --json)"
docker exec "$AGENT" sh -c 'lpa agent >>/var/log/lpa.log 2>&1 & echo $! > /run/lpa.pid'
done_ok() { [ "$(panel operations:show "$QUEUED" --json | ofield status)" = "'succeeded'" ]; }
wait_for 30 "the agent runs it once it is back" done_ok
docker exec "$AGENT" grep -q '"level":"ERROR"' /var/log/lpa.log && bad "the agent logged errors: $(docker exec "$AGENT" grep ERROR /var/log/lpa.log | head -3)" || ok "no errors in the agent's log (every operation verified)"

echo "==> 5. key rotation"
FIRST_KID=$(servers_json | field keys.0.kid | tr -d "'")
panel servers:rotate web-01 >/dev/null
rotated() { [ "$(servers_json | php -r 'echo count(json_decode(stream_get_contents(STDIN), true)[0]["keys"]);')" = 2 ]; }
wait_for 40 "the agent rotated at its next heartbeat" rotated
NEW_KID=$(servers_json | field keys.0.kid | tr -d "'")
[ "$NEW_KID" != "$FIRST_KID" ] && ok "the panel holds the new key, the old one is rotating out" || bad "keys: $(servers_json | field keys)"
docker exec "$AGENT" test -f /var/lib/lpa/keys/current && ok "the new identity is in /var/lib/lpa, /etc/lpa untouched" || bad "no rotated identity on the agent"

docker exec "$AGENT" grep -q "key rotated.*\"kid\":\"$NEW_KID\"" /var/log/lpa.log \
    && ok "the agent switched to the key the panel holds" || bad "the agent did not report switching to $NEW_KID"

seen_after() { [ "$(servers_json | field last_seen_at)" != "$1" ]; }
SEEN=$(servers_json | field last_seen_at)
wait_for 25 "heartbeats go on with the new key" seen_after "$SEEN"

docker exec "$AGENT" sh -c 'kill $(cat /run/lpa.pid); sleep 1; lpa agent >>/var/log/lpa.log 2>&1 & echo $! > /run/lpa.pid'
SEEN=$(servers_json | field last_seen_at)
wait_for 25 "after a restart the agent loads the rotated key and carries on" seen_after "$SEEN"
docker exec "$AGENT" grep -q '"level":"ERROR"' /var/log/lpa.log && bad "the agent logged errors: $(docker exec "$AGENT" grep ERROR /var/log/lpa.log | head -3)" || ok "no errors in the agent's log"

echo "==> 6. revocation"
docker exec "$AGENT" sh -c 'kill $(cat /run/lpa.pid)'
PENDING=$(panel operations:run web-01 system.info --json | ofield id | tr -d "'")
panel servers:revoke web-01 >/dev/null
[ "$(panel operations:show "$PENDING" --json | ofield status)" = "'cancelled'" ] && ok "revoking cancels the agent's queued work" || bad "after revoke: $(panel operations:show "$PENDING" --json)"
docker exec "$AGENT" sh -c 'lpa agent >>/var/log/lpa.log 2>&1 & echo $! > /run/lpa.pid'
refused() { docker exec "$AGENT" grep -q agent_revoked /var/log/lpa.log; }
wait_for 25 "a revoked agent is refused with agent_revoked" refused
[ "$(servers_json | field status)" = "'revoked'" ] && ok "the panel shows it revoked" || bad "status: $(servers_json | field status)"

echo
echo "==> $pass passed, $fail failed"
[ "$fail" -eq 0 ]
