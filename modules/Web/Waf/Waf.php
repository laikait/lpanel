<?php

declare(strict_types=1);

namespace App\Modules\Web\Waf;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Http\Request;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;
use App\Modules\Web\WebSettings;
use App\Modules\Web\WebSync;

/**
 * The web application firewall (ModSecurity with the OWASP Core Rule Set, in
 * Apache): what the Admin panel sets for the whole server, and what the rules
 * have said.
 *
 * A site's own mode and the rules it does not want are part of its web state
 * (SiteFeatures); here are the server's default mode, how strict the rules
 * are (the paranoia level, 1 to 4), and the log of requests the rules did not
 * like, which the agent reads from each account's error log.
 */
final class Waf
{
    public const MODES = ['off', 'detect', 'block'];

    /** How long what the rules said is kept, in seconds. */
    private const KEEP = 1209600;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly WebSettings $settings,
        private readonly ConnectionManager $connections,
        private readonly AuditLog $audit,
        private readonly WebSync $sync,
    ) {}

    /** @return array{default: string, paranoia: int} */
    public function settings(): array
    {
        $mode = (string) $this->settings->get('waf_default', 'detect');
        $level = (int) $this->settings->get('waf_paranoia', '1');

        return [
            'default' => \in_array($mode, self::MODES, true) ? $mode : 'detect',
            'paranoia' => $level >= 1 && $level <= 4 ? $level : 1,
        ];
    }

    /**
     * What the server says about the firewall, or null when the agent does not answer.
     *
     * @return array<string, mixed>|null
     */
    public function info(): ?array
    {
        try {
            $reply = $this->agent->call('waf.info', [], timeout: 30.0);
        } catch (AgentUnavailable) {
            return null;
        }

        return $reply->done() ? $reply->result : null;
    }

    /**
     * The mode sites get when they have not chosen one. Every account's sites
     * are written again, so it reaches the server now.
     *
     * @return string|null a language key saying what is wrong, or null when done
     */
    public function setDefault(?Identity $actor, ?Request $request, string $mode): ?string
    {
        if (!\in_array($mode, self::MODES, true)) {
            return 'Web.waf_error_mode';
        }

        $before = $this->settings()['default'];
        $this->settings->set('waf_default', $mode);
        $failed = 0;

        if ($before !== $mode) {
            foreach ($this->sync->touchAll() as $status => $n) {
                if (\in_array($status, ['failed', 'pending'], true)) {
                    $failed += $n;
                }
            }
        }

        $this->audit->record('waf.default', $actor, $request, 'waf', $failed === 0 ? AuditLog::OK : AuditLog::FAILED, ['mode' => $mode, 'before' => $before, 'not_applied' => $failed]);

        return null;
    }

    /**
     * How strict the rules are: 1 (the fewest false alarms) to 4.
     *
     * @return array{0: bool, 1: string|null} done, and why not (a language key, or the agent's words)
     */
    public function setParanoia(?Identity $actor, ?Request $request, int $level): array
    {
        if ($level < 1 || $level > 4) {
            return [false, 'Web.waf_error_paranoia'];
        }

        try {
            $reply = $this->agent->call('waf.settings', ['paranoia' => $level], id: Protocol::newId(), timeout: 130.0);
        } catch (AgentUnavailable) {
            return [false, 'Web.waf_error_unreachable'];
        }

        $why = $reply->done() ? null : ($reply->errorCode === 'waf_unavailable' ? 'Web.waf_error_unavailable' : (string) ($reply->errorMessage ?? 'Web.waf_error_failed'));

        if ($reply->done()) {
            $this->settings->set('waf_paranoia', (string) $level);
        }

        $this->audit->record('waf.paranoia', $actor, $request, 'waf', $reply->done() ? AuditLog::OK : AuditLog::FAILED, ['level' => $level] + ($why === null ? [] : ['error' => $why]));

        return [$reply->done(), $why];
    }

    /**
     * Read what the rules said since last time and keep it.
     *
     * @return array{events: int, error: string|null}
     */
    public function scan(): array
    {
        $out = ['events' => 0, 'error' => null];
        $raw = \json_decode((string) $this->settings->get('waf_cursors', '{}'), true);

        try {
            $reply = $this->agent->call('waf.events', ['cursors' => (object) (\is_array($raw) ? $raw : [])], timeout: 60.0);
        } catch (AgentUnavailable) {
            $out['error'] = 'Web.waf_error_unreachable';

            return $out;
        }

        if (!$reply->done()) {
            $out['error'] = (string) ($reply->errorMessage ?? 'Web.waf_error_failed');

            return $out;
        }

        $table = $this->connections->connection()->table('waf_events');

        foreach ((array) ($reply->result['events'] ?? []) as $e) {
            if (!\is_array($e)) {
                continue;
            }

            $when = \strtotime((string) ($e['time'] ?? ''));
            $rules = \array_values(\array_filter(\array_map(
                static fn(mixed $r): ?array => \is_array($r) ? ['id' => (int) ($r['id'] ?? 0), 'msg' => \mb_substr((string) ($r['msg'] ?? ''), 0, 160)] : null,
                (array) ($e['rules'] ?? []),
            )));
            $table->insert([
                'seen_at' => \gmdate('Y-m-d H:i:s', $when === false ? \time() : $when),
                'account' => \mb_substr((string) ($e['account'] ?? ''), 0, 32),
                'host' => \mb_substr((string) ($e['host'] ?? ''), 0, 255),
                'ip' => \mb_substr((string) ($e['ip'] ?? ''), 0, 45),
                'uri' => \mb_substr((string) ($e['uri'] ?? ''), 0, 300),
                'blocked' => !empty($e['blocked']) ? 1 : 0,
                'score' => (int) ($e['score'] ?? 0),
                'rules' => (string) \json_encode($rules),
            ]);
            $out['events']++;
        }

        $cursors = (array) ($reply->result['cursors'] ?? []);
        $this->settings->set('waf_cursors', (string) \json_encode($cursors === [] ? new \stdClass() : $cursors));
        $table->where('seen_at', '<', \gmdate('Y-m-d H:i:s', \time() - self::KEEP))->delete();

        return $out;
    }

    /**
     * The latest requests the rules disliked.
     *
     * @param list<string> $hosts only these site names (with account); empty is every site
     *
     * @return list<array<string, mixed>>
     */
    public function recent(array $hosts = [], ?string $account = null, int $limit = 20): array
    {
        $query = $this->connections->connection()->table('waf_events');

        if ($account !== null) {
            $query = $query->where('account', $account);
        }

        if ($hosts !== []) {
            $query = $query->whereIn('host', $hosts);
        }

        $rows = $query->orderByDesc('id')->limit($limit)->get();

        return \array_map(static function (array $r): array {
            $rules = \json_decode((string) $r['rules'], true);
            $r['blocked'] = (bool) $r['blocked'];
            $r['rules'] = \is_array($rules) ? $rules : [];

            return $r;
        }, \array_values($rows));
    }

    /** @return array{day: int, blocked: int} requests the rules disliked in the last day, and how many were refused */
    public function counts(): array
    {
        $since = \gmdate('Y-m-d H:i:s', \time() - 86400);
        $table = fn() => $this->connections->connection()->table('waf_events')->where('seen_at', '>=', $since);

        return ['day' => $table()->count(), 'blocked' => $table()->where('blocked', 1)->count()];
    }
}
