<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;

/**
 * The audit log: who changed what, from where, and whether it worked.
 *
 * Every module records its changes here. Entries are never edited; they are
 * pruned after `Audit.retention_days` (one year by default).
 */
final class AuditLog
{
    public const OK = 'ok';
    public const FAILED = 'failed';
    public const DENIED = 'denied';

    public function __construct(private readonly ConnectionManager $connections) {}

    /**
     * @param array<string, scalar|null> $details never a password, token or secret
     */
    public function record(
        string $action,
        ?Identity $actor,
        ?Request $request,
        string $subject = '',
        string $outcome = self::OK,
        array $details = [],
    ): void {
        $actor = $actor !== null && !$actor->isGuest() ? $actor : null;
        $onBehalf = $actor?->attribute('impersonator');

        $this->table()->insert([
            'created_at' => \gmdate('Y-m-d H:i:s'),
            'actor_id' => $actor?->id,
            'actor_name' => $actor === null ? '' : \mb_substr((string) ($actor->attribute('username') ?? $actor->name), 0, 120),
            'actor_kind' => (string) ($actor?->attribute('kind') ?? ''),
            'on_behalf_of' => \is_string($onBehalf) ? $onBehalf : null,
            'ip' => $request?->ip(),
            'action' => \mb_substr($action, 0, 64),
            'subject' => \mb_substr($subject, 0, 190),
            'outcome' => $outcome,
            'details' => $details === [] ? null : \json_encode($details, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * One page of entries, newest first.
     *
     * @param array{actor?: string|null, q?: string|null, outcome?: string|null} $filters
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function page(array $filters, int $page, int $perPage = 50): array
    {
        $query = $this->table();

        if (($filters['actor'] ?? null) !== null) {
            $query = $query->where('actor_id', $filters['actor']);
        }

        if (\in_array($filters['outcome'] ?? null, [self::OK, self::FAILED, self::DENIED], true)) {
            $query = $query->where('outcome', $filters['outcome']);
        }

        $search = \trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $like = '%' . \addcslashes($search, '%_\\') . '%';
            $query = $query->where(static fn(QueryBuilder $q): QueryBuilder => $q
                ->where('action', 'like', $like)
                ->orWhere('actor_name', 'like', $like)
                ->orWhere('subject', 'like', $like)
                ->orWhere('ip', 'like', $like));
        }

        $total = $query->count();
        $pages = \max(1, (int) \ceil($total / $perPage));
        $page = \min(\max(1, $page), $pages);
        $rows = [];

        foreach ($query->orderByDesc('id')->limit($perPage)->offset(($page - 1) * $perPage)->get() as $row) {
            $details = \is_string($row['details'] ?? null) ? \json_decode($row['details'], true) : null;
            $row['details'] = \is_array($details) ? $details : [];
            $rows[] = $row;
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return list<array<string, mixed>> this person's latest entries */
    public function latestFor(string $actorId, int $limit = 5): array
    {
        return \array_values($this->table()->where('actor_id', $actorId)->orderByDesc('id')->limit($limit)->get());
    }

    /** Remove entries older than $days. Returns how many went. */
    public function prune(int $days): int
    {
        return $this->table()
            ->where('created_at', '<', \gmdate('Y-m-d H:i:s', \time() - $days * 86400))
            ->delete();
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('audit_log');
    }
}
