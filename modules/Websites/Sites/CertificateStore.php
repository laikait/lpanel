<?php

declare(strict_types=1);

namespace App\Modules\Websites\Sites;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;

/**
 * website_certificates: the only code that touches it. One row per website,
 * written the first time LPanel asks for its certificate (ADR-0015).
 *
 * The query builder is immutable: every condition is chained or assigned back.
 */
final class CertificateStore
{
    public function __construct(private readonly ConnectionManager $connections) {}

    private function db(): Connection
    {
        return $this->connections->connection();
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function forWebsite(int $websiteId): Certificate
    {
        return self::hydrate($this->db()->table('website_certificates')->where('website_id', $websiteId)->first()) ?? Certificate::none($websiteId);
    }

    /**
     * @param list<int> $websiteIds
     *
     * @return array<int, Certificate> by website id, every one of them
     */
    public function forWebsites(array $websiteIds): array
    {
        $found = [];

        if ($websiteIds !== []) {
            foreach ($this->db()->table('website_certificates')->whereIn('website_id', $websiteIds)->get() as $row) {
                $c = self::hydrate($row);

                if ($c !== null) {
                    $found[$c->websiteId] = $c;
                }
            }
        }

        $all = [];

        foreach ($websiteIds as $id) {
            $all[$id] = $found[$id] ?? Certificate::none($id);
        }

        return $all;
    }

    /**
     * The server serves the website with $cert (an operation's result). Its
     * renewal is due two thirds of the way through its life.
     *
     * @param array<string, mixed> $cert
     */
    public function installed(int $websiteId, array $cert): void
    {
        $now = self::now();
        $from = $now->setTimestamp((int) ($cert['not_before'] ?? 0));
        $until = $now->setTimestamp((int) ($cert['not_after'] ?? 0));
        $c = new Certificate($websiteId, true, [], null, 'x', $from, $until, null, 0, null, null, null, null);
        $this->put($websiteId, [
            'auto' => true,
            'domains' => \json_encode(\array_values(\array_map('strval', (array) ($cert['domains'] ?? []))), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            'issuer' => \mb_substr((string) ($cert['issuer'] ?? ''), 0, 100),
            'serial' => \substr((string) ($cert['serial'] ?? ''), 0, 64),
            'not_before' => $from,
            'not_after' => $until,
            'issued_at' => $now,
            'attempts' => 0,
            'next_attempt_at' => $c->renewAt(),
            'last_attempt_at' => $now,
            'last_error_code' => null,
            'last_error' => null,
        ]);
    }

    /** It is served over HTTP only; $auto says whether LPanel should ask again on its own. */
    public function cleared(int $websiteId, bool $auto): void
    {
        $this->put($websiteId, [
            'auto' => $auto,
            'domains' => null,
            'issuer' => null,
            'serial' => null,
            'not_before' => null,
            'not_after' => null,
            'issued_at' => null,
            'attempts' => 0,
            'next_attempt_at' => $auto ? self::now() : null,
            'last_error_code' => null,
            'last_error' => null,
        ]);
    }

    /**
     * Asking failed. The next try waits (Certificate::backoff); a first
     * certificate is given up on after GIVE_UP_AFTER failures, a renewal never.
     */
    public function failed(int $websiteId, string $code, string $message): void
    {
        $now = self::now();
        $c = $this->forWebsite($websiteId);
        $attempts = $c->attempts + 1;
        $next = $c->auto && ($c->exists() || $attempts < Certificate::GIVE_UP_AFTER) ? $now->add(Certificate::backoff($attempts)) : null;
        $this->put($websiteId, [
            'attempts' => $attempts,
            'next_attempt_at' => $next,
            'last_attempt_at' => $now,
            'last_error_code' => \substr($code, 0, 64),
            'last_error' => \mb_substr($message, 0, 500),
        ]);
    }

    /**
     * Someone asked for one, the website is new or gained a domain it does
     * not cover, or its file went missing on the server: LPanel asks at $at
     * (now, by default), afresh.
     */
    public function wanted(int $websiteId, ?\DateTimeImmutable $at = null): void
    {
        $this->put($websiteId, ['auto' => true, 'next_attempt_at' => $at ?? self::now(), 'attempts' => 0]);
    }

    /**
     * Websites LPanel should ask for now, oldest wish first.
     *
     * @return list<Certificate>
     */
    public function due(\DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->db()->table('website_certificates')
            ->where('auto', true)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', $now)
            ->orderBy('next_attempt_at')
            ->limit(\max(1, $limit))
            ->get();

        return \array_values(\array_filter(\array_map(self::hydrate(...), $rows)));
    }

    /**
     * Of $websiteIds, those LPanel has never asked for.
     *
     * @param list<int> $websiteIds
     *
     * @return list<int>
     */
    public function unknown(array $websiteIds): array
    {
        if ($websiteIds === []) {
            return [];
        }

        $known = \array_map(static fn(array $row): int => (int) $row['website_id'], $this->db()->table('website_certificates')->whereIn('website_id', $websiteIds)->get());

        return \array_values(\array_diff($websiteIds, $known));
    }

    /** @param array<string, mixed> $changes */
    private function put(int $websiteId, array $changes): void
    {
        $now = self::now();
        $table = $this->db()->table('website_certificates');

        if ($table->where('website_id', $websiteId)->update($changes + ['updated_at' => $now]) === 0
            && !$this->db()->table('website_certificates')->where('website_id', $websiteId)->exists()) {
            $this->db()->table('website_certificates')->insert($changes + ['website_id' => $websiteId, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /** @param array<string, mixed>|null $row */
    private static function hydrate(?array $row): ?Certificate
    {
        if ($row === null) {
            return null;
        }

        $text = static fn(mixed $v): ?string => $v === null || $v === '' ? null : (string) $v;
        $at = static fn(mixed $v): ?\DateTimeImmutable => $v === null || $v === '' ? null : new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC'));
        $domains = \json_decode((string) ($row['domains'] ?? '[]'), true);

        return new Certificate(
            websiteId: (int) $row['website_id'],
            auto: (bool) $row['auto'],
            domains: \is_array($domains) ? \array_values(\array_map('strval', $domains)) : [],
            issuer: $text($row['issuer'] ?? null),
            serial: $text($row['serial'] ?? null),
            notBefore: $at($row['not_before'] ?? null),
            notAfter: $at($row['not_after'] ?? null),
            issuedAt: $at($row['issued_at'] ?? null),
            attempts: (int) ($row['attempts'] ?? 0),
            nextAttemptAt: $at($row['next_attempt_at'] ?? null),
            lastAttemptAt: $at($row['last_attempt_at'] ?? null),
            lastErrorCode: $text($row['last_error_code'] ?? null),
            lastError: $text($row['last_error'] ?? null),
        );
    }
}
