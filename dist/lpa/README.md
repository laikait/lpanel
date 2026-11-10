# lpa: the LPanel agent

`lpa` is the only part of LPanel that runs as root. The panel runs as the
unprivileged `lpanel` user. It asks the agent to change the system through a
local Unix socket, and only through a fixed list of named operations. The agent
has no shell and no way to run "any command". The design is in
[ARCHITECTURE.md](../../design/ARCHITECTURE.md) and
[ADR-0003](../../design/adr/0003-agent-over-unix-socket.md).

## How a request is checked

Each connection carries one JSON line each way. A request goes through these
checks in order:

| Check | Refused with |
|---|---|
| The caller's uid (SO_PEERCRED) is the panel's | no answer at all |
| A version-1 request with a valid id, op and nonce, and `args` a JSON object | `bad_request` |
| Ed25519 signature by the panel's key over `SignedBytes` | `bad_signature` |
| Timestamp within 60 s of the agent's clock | `stale` |
| Nonce not seen before | `replay` |
| The operation exists | `unknown_op` |
| Fewer than `max_running` operations running | `busy` |
| The operation accepts its arguments (unknown fields refused) | `bad_args` |

The signature covers this text, with the fields joined by newlines:

```
lpa-request-v1
<id>
<op>
<ts>
<nonce>
<hex sha256 of args exactly as sent>
```

Because the agent hashes the bytes it received, the panel needs no JSON
canonicalisation. `internal/protocol/testdata/vectors.json` holds golden
vectors. The panel's PHP tests sign them and must get identical signatures.

Operations that change the system are claimed in the journal first. The
journal is `/var/lib/lpa/journal`, one file per request id. A retry with the
same id returns the first outcome and does not run again. Reusing an id for
different work is refused (`id_reused`). An entry still marked running at
start-up becomes `interrupted`. Read-only operations skip the journal.

Operations marked async answer `accepted` at once. The panel then asks
`op.status {"id": …}` for the outcome.

## Layout

| Path | What |
|---|---|
| `cmd/lpa` | the command: `serve`, `facts`, `ops`, `version` |
| `internal/protocol` | wire format, signing, golden vectors |
| `internal/server` | socket, peer check, replay cache, dispatch, journal use |
| `internal/journal` | exactly-once records |
| `internal/ops` | the operation registry and the operations |
| `internal/sysinfo` | `/proc` and `/etc` readers, tested against `testdata/root` |
| `internal/peer` | SO_PEERCRED |
| `internal/config` | `/etc/lpa/lpa.json` |
| `internal/fsutil` | atomic writes |
| `internal/accounts` | hosting accounts: users, homes, suspension; only users it made itself |
| `internal/quota` | kernel user quotas through `quotactl(2)` (ext4 and XFS) |
| `internal/sysexec` | runs `useradd`, `usermod`, `userdel`, `pkill` by absolute path, no shell, fixed environment |
| `packaging/` | the systemd unit and the default configuration |

## Operations

| Operation | Kind | Does |
|---|---|---|
| `system.ping` | read-only | answers with the agent version and time |
| `system.facts` | read-only | hostname, OS, kernel, CPUs, load, memory, uptime, disks |
| `account.create` | change | `{username, shell, quota}`: user and group, 0711 home with `public_html`, quota. Undone if any step fails |
| `account.modify` | change | `{username, shell?, quota?}` |
| `account.suspend` | change | `{username}`: expires the account (PAM then refuses every login) and ends its processes |
| `account.unsuspend` | change | `{username}`: removes the expiry |
| `account.terminate` | change, background | `{username}`: ends processes, removes the user, group, home (never following links) and mail spool. A name with no user is already gone |
| `account.info` | read-only | `{usernames?}`: LPanel accounts with their disk use and limits |
| `op.status` | built in | the journalled outcome of an earlier request |

The account operations refuse (`not_ours`) any user without the comment
`lpanel account`, a uid of at least 1000 and a home of exactly
`homes_base/<name>`. Their own error codes are `exists`, `no_such_account`,
`not_ours` and `quota`.

`lpa.json` adds `homes_base` (default `/home`) and `quota`: `"on"`, or `"off"`
on a machine without quotas, which the installer writes only with
`--allow-no-quota`.

## Build and test

You need Go 1.24 or newer.

```bash
go test ./...                 # works on Windows too: the socket tests use AF_UNIX
GOOS=linux go vet ./...
./build.sh                    # bin/lpa_linux_amd64, bin/lpa_linux_arm64
go test ./internal/protocol -update   # after changing the signing rule; then run the PHP tests
```

The end-to-end test, with the real agent and the real PHP client in an
AlmaLinux 9 container, is `tests/e2e/agent.sh` in the panel's repository.
