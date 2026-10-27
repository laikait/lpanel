<?php

declare(strict_types=1);

namespace App\Modules\Operations\Catalogue;

/**
 * Every operation the panel can ask an agent for. The agent's registry is the
 * allowlist that matters; this is its mirror, and an operation missing here
 * cannot be requested. Adding one is a change on both sides, reviewed
 * together (THREAT_MODEL.md test 4).
 *
 * The read-only operations need servers.read. The account operations
 * (ADR-0013) change the server: they need accounts.manage, act on one
 * account, and are asked for by the Hosting module, never from a server's screen.
 */
final class Catalogue
{
    private const TEXT = '/^[^\x00-\x1f\x7f]*$/u';

    private const STATE = '/^[a-z-]*$/';

    /** An account's Linux user, as the panel chooses it (ADR-0013). */
    public const SYSTEM_USER = '/^lp[a-z2-7]{8}$/';

    /** @var array<string, Definition> by op@version */
    private array $definitions = [];

    public function __construct()
    {
        $bytes = Shape::int();
        $text = static fn(int $max): Shape => Shape::string($max, self::TEXT);
        $readOnly = static fn(string $op, string $label, string $summary, int $timeout, Shape $result): Definition => new Definition(
            op: $op,
            version: 1,
            label: $label,
            summary: $summary,
            mutating: false,
            idempotent: true,
            capability: 'servers.read',
            scope: 'server',
            timeoutSeconds: $timeout,
            maxResultBytes: 65536,
            maxAttempts: 3,
            audit: Definition::AUDIT_INFO,
            payload: Shape::object(),
            result: $result,
        );

        foreach ([
            $readOnly('agent.health', 'Agent health', 'How long the agent has run, results it still has to send, and its recent errors.', 5, Shape::object([
                'started_at' => Shape::int(),
                'pending_results' => Shape::int(0, 1000000),
                'last_errors' => Shape::list(Shape::object([
                    'code' => Shape::string(64, '/^[a-z_]{1,64}$/'),
                    'at' => Shape::int(),
                ]), 50)->orNull(),
            ])),
            $readOnly('agent.capabilities', 'Agent capabilities', 'The operations and protocol versions this build of the agent has.', 5, Shape::object([
                'protocols' => Shape::list(Shape::int(1, 1000), 16)->orNull(),
                'capabilities' => Shape::map(Shape::list(Shape::int(1, 1000), 20)->orNull(), 200, '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/')->orNull(),
                'agent_version' => Shape::string(32, '/^[0-9A-Za-z.+~_-]{1,32}$/'),
                'commit' => $text(64),
                'go_version' => $text(32),
            ])),
            $readOnly('system.info', 'System information', 'The OS release, kernel, hostname, CPUs, memory and architecture.', 10, Shape::object([
                'os' => Shape::object([
                    'id' => $text(64),
                    'version_id' => $text(64),
                    'version_codename' => $text(64),
                    'pretty_name' => $text(128),
                ]),
                'kernel' => $text(128),
                'hostname' => Shape::string(253, '/^[A-Za-z0-9._-]*$/'),
                'cpu_count' => Shape::int(0, 100000),
                'memory_total_bytes' => $bytes,
                'architecture' => Shape::string(32, '/^[a-z0-9_]*$/'),
            ])),
            $readOnly('system.metrics', 'Resource usage', 'Load, memory and swap, uptime, and disk and inode use per filesystem.', 15, Shape::object([
                'load' => Shape::object(['one' => Shape::number(), 'five' => Shape::number(), 'fifteen' => Shape::number()]),
                'memory' => Shape::object([
                    'total_bytes' => $bytes,
                    'available_bytes' => $bytes,
                    'swap_total_bytes' => $bytes,
                    'swap_free_bytes' => $bytes,
                ]),
                'uptime_seconds' => Shape::int(),
                'disks' => Shape::list(Shape::object([
                    'mount' => $text(4096),
                    'fs_type' => Shape::string(32, '/^[a-z0-9._-]*$/'),
                    'total_bytes' => $bytes,
                    'available_bytes' => $bytes,
                    'used_bytes' => $bytes,
                    'total_inodes' => $bytes,
                    'free_inodes' => $bytes,
                ]), 64)->orNull(),
            ])),
            $readOnly('service.list_status', 'Service status', 'Whether the services LPanel manages are running: Nginx, Apache, PHP-FPM, MariaDB and the agent.', 15, Shape::object([
                'services' => Shape::list(Shape::object([
                    'unit' => Shape::string(256, '/^[A-Za-z0-9@._-]+$/'),
                    'load_state' => Shape::string(32, self::STATE),
                    'active_state' => Shape::string(32, self::STATE),
                    'sub_state' => Shape::string(32, self::STATE),
                    'unit_file_state' => Shape::string(32, self::STATE),
                ]), 64)->orNull(),
            ])),
        ] as $definition) {
            $this->definitions[$definition->op . '@' . $definition->version] = $definition;
        }

        $user = Shape::string(10, self::SYSTEM_USER);
        $account = static fn(string $op, string $label, string $summary, int $timeout, string $audit, Shape $result): Definition => new Definition(
            op: $op,
            version: 1,
            label: $label,
            summary: $summary,
            mutating: true,
            idempotent: true,
            capability: 'accounts.manage',
            scope: 'account',
            timeoutSeconds: $timeout,
            maxResultBytes: 4096,
            maxAttempts: 3,
            audit: $audit,
            payload: Shape::object(['system_user' => $user]),
            result: $result,
        );
        $changed = static fn(string $state): Shape => Shape::object(['system_user' => $user, 'state' => Shape::string(16, '/^' . $state . '$/')]);

        foreach ([
            $account('account.create', 'Create account', 'A Linux user and group, a root-owned home with web, tmp and logs, and a PHP-FPM pool that runs as the account.', 120, Definition::AUDIT_NOTICE, Shape::object([
                'system_user' => $user,
                'uid' => Shape::int(20000, 59999),
                'gid' => Shape::int(20000, 59999),
                'home' => Shape::string(16, '/^\/home\/lp[a-z2-7]{8}$/'),
                'php_version' => Shape::string(8, '/^[0-9]+\.[0-9]+$/'),
                'pool_socket' => Shape::string(40, '/^\/run\/php\/lpanel-lp[a-z2-7]{8}\.sock$/'),
                'state' => Shape::string(16, '/^active$/'),
            ])),
            $account('account.suspend', 'Suspend account', 'No logins, no PHP and no running processes for the account. Its files stay.', 120, Definition::AUDIT_NOTICE, $changed('suspended')),
            $account('account.unsuspend', 'Unsuspend account', 'Lets the account in again and writes its PHP-FPM pool afresh.', 120, Definition::AUDIT_NOTICE, $changed('active')),
            $account('account.delete', 'Delete account', 'Removes the account\'s user, group, PHP-FPM pool and home, with every file in it.', 300, Definition::AUDIT_CRITICAL, $changed('deleted')),
        ] as $definition) {
            $this->definitions[$definition->op . '@' . $definition->version] = $definition;
        }

        \ksort($this->definitions);
    }

    /** @return list<Definition> by name, then version */
    public function all(): array
    {
        return \array_values($this->definitions);
    }

    public function find(string $op, int $version): ?Definition
    {
        return $this->definitions[$op . '@' . $version] ?? null;
    }

    /** The newest version of $op this panel knows. */
    public function latest(string $op): ?Definition
    {
        $found = null;

        foreach ($this->definitions as $definition) {
            if ($definition->op === $op && ($found === null || $definition->version > $found->version)) {
                $found = $definition;
            }
        }

        return $found;
    }
}
