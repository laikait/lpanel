<?php

declare(strict_types=1);

namespace App\Modules\Web;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentReply;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;

/**
 * The server's PHP versions and their extensions: installing, removing and
 * choosing the default for new sites.
 *
 * Installing takes minutes, so the agent runs it in the background. A job is
 * recorded with its operation id before it is sent; web:settle (every
 * minute) and every look at the page send the same id again until the
 * agent's journal has the answer. One job runs at a time, as dnf does.
 *
 * The agent knows the catalogue (versions and extensions) and refuses
 * anything else; the panel's checks are there to explain, not to protect.
 */
final class PhpVersions
{
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** Versions PHP no longer supports (security fixes ended), as of this release. */
    public const END_OF_LIFE = ['74', '80', '81'];

    /** Agent answers that mean "not now". */
    private const TRANSIENT = ['busy', 'stale', 'bad_signature'];

    /** @var array<string, mixed>|null|false php.info, once per request; false before asking */
    private array|null|false $info = false;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly AgentClient $agent,
        private readonly Domains $domains,
        private readonly WebSettings $settings,
        private readonly WebSync $sync,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Every version of the catalogue, as the agent sees it.
     *
     * @return array{versions: list<array<string, mixed>>, extensions: list<string>, defaults: list<string>}|null null when the agent cannot be asked
     */
    public function info(): ?array
    {
        if ($this->info === false) {
            try {
                $reply = $this->agent->call('php.info', [], timeout: 30.0);
                $this->info = $reply->done() ? $reply->result : null;
            } catch (AgentUnavailable) {
                $this->info = null;
            }
        }

        if (!\is_array($this->info) || !\is_array($this->info['versions'] ?? null)) {
            return null;
        }

        return [
            'versions' => \array_values(\array_filter($this->info['versions'], 'is_array')),
            'extensions' => \array_values(\array_filter((array) ($this->info['extensions'] ?? []), 'is_string')),
            'defaults' => \array_values(\array_filter((array) ($this->info['defaults'] ?? []), 'is_string')),
        ];
    }

    /** @return array<string, mixed>|null one version from info() */
    public function version(string $version): ?array
    {
        foreach ($this->info()['versions'] ?? [] as $v) {
            if (($v['version'] ?? null) === $version) {
                return $v;
            }
        }

        return null;
    }

    /** @return array<string, int> version => how many sites use it */
    public function sitesByVersion(): array
    {
        $counts = [];

        foreach ($this->domains->phpInUse() as $version => $count) {
            $counts[(string) $version] = $count;
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> the latest jobs, newest first */
    public function jobs(int $limit = 8): array
    {
        return \array_values($this->table()->orderBy('id', 'desc')->limit($limit)->get());
    }

    /** @return array<string, mixed>|null */
    public function job(int $id): ?array
    {
        return $this->table()->where('id', $id)->first();
    }

    /** @return array<string, mixed>|null the job in progress, if any */
    public function running(): ?array
    {
        return $this->table()->where('state', self::RUNNING)->orderBy('id')->first();
    }

    /**
     * Install a version with the default extensions.
     *
     * @return array{0: ?array<string, mixed>, 1: ?string} the job, or a lang key saying why not
     */
    public function install(Identity $actor, ?Request $request, string $version): array
    {
        $v = $this->version($version);
        $why = match (true) {
            $this->info() === null => 'Web.php_agent_down',
            $v === null => 'Web.php_unknown_version',
            (bool) $v['installed'] => 'Web.php_already',
            default => null,
        };

        return $why !== null ? [null, $why] : $this->start($actor, $request, 'php.install', $version, ['version' => $version]);
    }

    /** @return array{0: ?array<string, mixed>, 1: ?string} */
    public function remove(Identity $actor, ?Request $request, string $version): array
    {
        $v = $this->version($version);
        $why = match (true) {
            $this->info() === null => 'Web.php_agent_down',
            $v === null => 'Web.php_unknown_version',
            !$v['installed'] => 'Web.php_not_installed',
            (bool) $v['panel'] => 'Web.php_panel',
            ($this->sitesByVersion()[$version] ?? 0) > 0 || (int) $v['pools'] > 0 => 'Web.php_in_use',
            $version === $this->defaultVersion() => 'Web.php_is_default',
            default => null,
        };

        return $why !== null ? [null, $why] : $this->start($actor, $request, 'php.remove', $version, ['version' => $version]);
    }

    /**
     * Make a version's extensions exactly $wanted.
     *
     * @param list<string> $wanted
     *
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    public function extensions(Identity $actor, ?Request $request, string $version, array $wanted): array
    {
        $v = $this->version($version);

        if ($this->info() === null || $v === null || !$v['installed']) {
            return [null, $this->info() === null ? 'Web.php_agent_down' : ($v === null ? 'Web.php_unknown_version' : 'Web.php_not_installed')];
        }

        $offered = $this->info()['extensions'] ?? [];
        $wanted = \array_values(\array_unique($wanted));

        if (\array_diff($wanted, $offered) !== []) {
            return [null, 'Web.php_unknown_extension'];
        }

        $have = (array) $v['extensions'];
        $install = \array_values(\array_diff($wanted, $have));
        $remove = \array_values(\array_diff($have, $wanted));

        if (\array_intersect($remove, (array) $v['locked']) !== []) {
            return [null, 'Web.php_locked'];
        }

        if ($install === [] && $remove === []) {
            return [null, 'Web.php_no_change'];
        }

        \sort($install);
        \sort($remove);

        return $this->start($actor, $request, 'php.extensions', $version, ['version' => $version, 'install' => $install, 'remove' => $remove]);
    }

    /** @return string|null a lang key saying why not */
    public function setDefault(Identity $actor, ?Request $request, string $version): ?string
    {
        // A version that has just finished installing counts.
        $this->settleAll();
        $v = $this->version($version);

        if ($v === null || !$v['installed']) {
            return $this->info() === null ? 'Web.php_agent_down' : 'Web.php_not_installed';
        }

        $before = $this->defaultVersion();
        $this->settings->set('default_php', $version);
        $this->audit->record('php.default', $actor, $request, WebSync::phpLabel($version), AuditLog::OK, ['from' => WebSync::phpLabel($before)]);

        return null;
    }

    /** The version new sites get. */
    public function defaultVersion(): string
    {
        return $this->sync->defaultPhp();
    }

    /**
     * Ask again about every job still running.
     *
     * @return array<string, int> state => count
     */
    public function settleAll(): array
    {
        $counts = [];

        foreach ($this->table()->where('state', self::RUNNING)->get() as $row) {
            $row = $this->send($row);
            $counts[(string) $row['state']] = ($counts[(string) $row['state']] ?? 0) + 1;
        }

        return $counts;
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function start(Identity $actor, ?Request $request, string $op, string $version, array $args): array
    {
        // Whatever finished meanwhile is no longer in the way.
        $this->settleAll();

        if ($this->running() !== null) {
            return [null, 'Web.php_busy'];
        }

        $id = $this->table()->insert([
            'op' => $op,
            'version' => $version,
            'op_id' => Protocol::newId(),
            'op_args' => \json_encode($args, \JSON_THROW_ON_ERROR),
            'state' => self::RUNNING,
            'actor_id' => \ctype_digit($actor->id) ? (int) $actor->id : null,
            'actor_name' => \mb_substr((string) ($actor->attribute('username') ?? $actor->name), 0, 120),
            'created_at' => Accounts::now(),
        ], 'id');

        $this->audit->record($op, $actor, $request, 'PHP ' . WebSync::phpLabel($version), AuditLog::OK, ['job' => (int) $id, 'state' => 'started'] + self::flat($args));
        $row = $this->send($this->job((int) $id) ?? []);

        return [$row, null];
    }

    /**
     * Send a job (again) and record what the agent says.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed> the job as it is now
     */
    private function send(array $row): array
    {
        $args = \json_decode((string) $row['op_args'], true);

        try {
            $reply = $this->agent->call((string) $row['op'], \is_array($args) ? $args : [], id: (string) $row['op_id'], timeout: 30.0);
        } catch (AgentUnavailable) {
            return $row;
        }

        return $this->apply($row, $reply);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function apply(array $row, AgentReply $reply): array
    {
        if ($reply->status === 'accepted' || $reply->status === 'running'
            || ($reply->status === 'refused' && \in_array($reply->errorCode, self::TRANSIENT, true))) {
            return $row;
        }

        $failed = !$reply->done();
        $changes = [
            'state' => $failed ? self::FAILED : self::DONE,
            'error' => $failed ? ($reply->errorCode ?? 'failed') . ': ' . ($reply->errorMessage ?? 'The agent gave no reason.') : null,
            'finished_at' => Accounts::now(),
        ];
        $this->table()->where('id', (int) $row['id'])->update($changes);
        $this->info = false;
        $this->sync->forgetInfo();

        $this->audit->record((string) $row['op'], null, null, 'PHP ' . WebSync::phpLabel((string) $row['version']), $failed ? AuditLog::FAILED : AuditLog::OK, [
            'job' => (int) $row['id'], 'state' => $changes['state'], 'by' => (string) $row['actor_name'],
        ] + ($failed ? ['error' => (string) $changes['error']] : []));

        // A removed version cannot stay the default.
        if (!$failed && $row['op'] === 'php.remove' && $this->settings->get('default_php') === $row['version']) {
            $this->settings->set('default_php', '');
        }

        return $changes + $row;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, scalar>
     */
    private static function flat(array $args): array
    {
        $out = [];

        foreach ($args as $k => $v) {
            $out[(string) $k] = \is_array($v) ? \implode(',', \array_map('strval', $v)) : (\is_scalar($v) ? $v : '');
        }

        return $out;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('php_jobs');
    }
}
