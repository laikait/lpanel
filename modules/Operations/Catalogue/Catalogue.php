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
 * account, and are asked for by the Hosting module, never from a server's
 * screen. The website operations (ADR-0014) are the Websites module's, the
 * same way; websites.check acts on the whole server.
 */
final class Catalogue
{
    private const TEXT = '/^[^\x00-\x1f\x7f]*$/u';

    private const STATE = '/^[a-z-]*$/';

    /** An account's Linux user, as the panel chooses it (ADR-0013). */
    public const SYSTEM_USER = '/^lp[a-z2-7]{8}$/';

    public const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    /*
     * A website's managed settings (ADR-0014), as the agent's grammar has
     * them (lpa internal/websites/settings.go). The agent decides; these let
     * the panel refuse the same things first, with a better message.
     */
    public const DOMAIN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public const DOCUMENT_ROOT = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,62}(?:\/[A-Za-z0-9_][A-Za-z0-9._-]{0,62}){0,3}$/';

    public const INDEX_FILE = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,63}$/';

    public const REDIRECT_FROM = '/^\/[A-Za-z0-9._~+\/-]{0,254}$/';

    public const REDIRECT_TO = '/^(?:https?:\/\/[A-Za-z0-9.-]+(?::[0-9]{1,5})?)?(?:\/[A-Za-z0-9._~%\/?&=+#:@!,*\'()-]*)?$/';

    public const HEADER_NAME = '/^[A-Za-z][A-Za-z0-9-]{0,63}$/';

    /** Printable ASCII but " $ \ { }: one quoted Nginx string, no variables. */
    public const HEADER_VALUE = '/^[\x20\x21\x23\x25-\x5b\x5d-\x7a\x7c\x7e]{1,1024}$/';

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

        $ulid = Shape::string(26, self::ULID);
        $owner = ['account_id' => $ulid, 'system_user' => $user];
        $settings = $owner + [
            'domains' => Shape::list(Shape::string(253, self::DOMAIN), 10),
            'document_root' => Shape::string(255, self::DOCUMENT_ROOT),
            'php' => Shape::bool(),
            'index' => Shape::list(Shape::string(64, self::INDEX_FILE), 8),
            'redirects' => Shape::list(Shape::object([
                'from' => Shape::string(255, self::REDIRECT_FROM),
                'to' => Shape::string(2048, self::REDIRECT_TO),
                'code' => Shape::int(301, 308),
            ]), 50),
            'headers' => Shape::list(Shape::object([
                'name' => Shape::string(64, self::HEADER_NAME),
                'value' => Shape::string(1024, self::HEADER_VALUE),
            ]), 20),
            'https_redirect' => Shape::bool(),
        ];
        // The certificate a website is served with over HTTPS (ADR-0015), or null.
        $certificate = Shape::object([
            'domains' => Shape::list(Shape::string(253, self::DOMAIN), 10),
            'issuer' => Shape::string(80, self::TEXT),
            'serial' => Shape::string(64, '/^[0-9a-f]{0,64}$/'),
            'not_before' => Shape::int(),
            'not_after' => Shape::int(),
        ])->orNull();
        $served = Shape::object([
            'website_id' => $ulid,
            'system_user' => $user,
            'state' => Shape::string(16, '/^(active|suspended)$/'),
            'revision' => Shape::string(16, '/^[0-9a-f]{16}$/'),
            'document_root' => Shape::string(300, '/^\/home\/lp[a-z2-7]{8}\/web\/[A-Za-z0-9._\/-]{1,255}$/'),
            'certificate' => $certificate,
        ]);
        $website = static fn(string $op, string $label, string $summary, string $capability, string $audit, Shape $payload, Shape $result, int $timeout = 120): Definition => new Definition(
            op: $op,
            version: 1,
            label: $label,
            summary: $summary,
            mutating: true,
            idempotent: true,
            capability: $capability,
            scope: 'website',
            timeoutSeconds: $timeout,
            maxResultBytes: 4096,
            maxAttempts: 3,
            audit: $audit,
            payload: $payload,
            result: $result,
        );

        foreach ([
            $website('website.create', 'Create website', 'Its Nginx server block, staged and tested with nginx -t, then served; its folder in the account\'s web/.', 'websites.manage', Definition::AUDIT_NOTICE, Shape::object($settings), $served),
            // A customer may change its own website's settings; the Websites module checks whose it is.
            $website('website.update', 'Change website settings', 'Renders the website again from its settings; a file edited by hand is replaced only when an administrator restores it.', 'websites.configure', Definition::AUDIT_NOTICE, Shape::object($settings + ['replace_edits' => Shape::bool()]), $served),
            $website('website.suspend', 'Suspend website', 'Every request to the website is answered 503. Its files stay.', 'websites.manage', Definition::AUDIT_NOTICE, Shape::object($owner), $served),
            $website('website.unsuspend', 'Unsuspend website', 'Serves the website again.', 'websites.manage', Definition::AUDIT_NOTICE, Shape::object($owner), $served),
            $website('website.delete', 'Delete website', 'Removes the website\'s Nginx configuration. Its files stay in the account\'s web/ folder.', 'websites.manage', Definition::AUDIT_CRITICAL, Shape::object($owner), Shape::object([
                'website_id' => $ulid,
                'system_user' => $user,
                'state' => Shape::string(16, '/^deleted$/'),
                'certificate' => $certificate,
            ])),
            // The owner may secure its own website, as with its settings; the Websites module checks whose it is.
            $website('website.certificate.issue', 'Get certificate', 'Gets a certificate for every domain of the website from Let\'s Encrypt (HTTP-01), then serves it over HTTPS through the same tested change. Also renews.', 'websites.configure', Definition::AUDIT_NOTICE, Shape::object($owner), $served, 300),
            $website('website.certificate.remove', 'Remove certificate', 'Serves the website over HTTP only again and deletes its key.', 'websites.configure', Definition::AUDIT_NOTICE, Shape::object($owner), $served),
            new Definition(
                op: 'websites.check',
                version: 1,
                label: 'Check websites\' Nginx files',
                summary: 'Compares each website\'s Nginx file with what LPanel wrote. A hand edit that passes nginx -t is reloaded and reported, never overwritten.',
                mutating: true,
                idempotent: true,
                capability: 'websites.manage',
                scope: 'server',
                timeoutSeconds: 120,
                maxResultBytes: 65536,
                maxAttempts: 3,
                audit: Definition::AUDIT_NOTICE,
                payload: Shape::object(),
                result: Shape::object([
                    'checked' => Shape::int(0, 100000),
                    'edited' => Shape::list($ulid, 2000)->orNull(),
                    'missing' => Shape::list($ulid, 2000)->orNull(),
                    'unknown_files' => Shape::list(Shape::string(80, self::TEXT), 20)->orNull(),
                    'certificates' => Shape::list($ulid, 2000)->orNull(),
                    'include' => Shape::string(8, '/^(ok|edited|missing|none)$/'),
                    'nginx_test' => Shape::string(8, '/^(passed|failed|not_run)$/'),
                    'reloaded' => Shape::bool(),
                ]),
            ),
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
