<?php

declare(strict_types=1);

namespace App\Modules\Websites\Sites;

/**
 * A website's certificate as the panel knows it (ADR-0015): the one its
 * server serves over HTTPS, if any, and when LPanel next asks for one on its
 * own. The key stays on the server.
 *
 * LPanel asks on its own for every website (auto) until someone removes the
 * certificate: first within minutes of the website being served, again two
 * thirds of the way through each certificate's life, and at once when a
 * domain it does not cover is added. A failure waits 1 hour, then 6, then a
 * day each time; a first certificate is given up on after a week of them.
 */
final class Certificate
{
    /** Failures in a row after which LPanel stops trying for a first certificate on its own. */
    public const GIVE_UP_AFTER = 9;

    /** Days before expiry it is shown as expiring. */
    public const EXPIRING_DAYS = 14;

    /** What the screens call each state. */
    public const NONE = 'none';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public const ACTIVE = 'active';

    public const EXPIRING = 'expiring';

    public const EXPIRED = 'expired';

    /**
     * @param list<string> $domains
     */
    public function __construct(
        public readonly int $websiteId,
        public readonly bool $auto,
        public readonly array $domains,
        public readonly ?string $issuer,
        public readonly ?string $serial,
        public readonly ?\DateTimeImmutable $notBefore,
        public readonly ?\DateTimeImmutable $notAfter,
        public readonly ?\DateTimeImmutable $issuedAt,
        public readonly int $attempts,
        public readonly ?\DateTimeImmutable $nextAttemptAt,
        public readonly ?\DateTimeImmutable $lastAttemptAt,
        public readonly ?string $lastErrorCode,
        public readonly ?string $lastError,
        public readonly bool $stored = true,
    ) {}

    /** A website LPanel has not asked for yet. */
    public static function none(int $websiteId): self
    {
        return new self($websiteId, true, [], null, null, null, null, null, 0, null, null, null, null, false);
    }

    /** Whether the server serves the website with one. */
    public function exists(): bool
    {
        return $this->serial !== null && $this->notAfter !== null;
    }

    /** @param list<string> $domains */
    public function covers(array $domains): bool
    {
        return $this->exists() && \array_diff($domains, $this->domains) === [];
    }

    /** Two thirds of the way through its life, as Let's Encrypt advises. */
    public function renewAt(): ?\DateTimeImmutable
    {
        if ($this->notBefore === null || $this->notAfter === null) {
            return null;
        }

        $life = $this->notAfter->getTimestamp() - $this->notBefore->getTimestamp();

        return $this->notBefore->setTimestamp($this->notBefore->getTimestamp() + intdiv(2 * \max(0, $life), 3));
    }

    /** The wait after the $attempts-th failure in a row. */
    public static function backoff(int $attempts): \DateInterval
    {
        return new \DateInterval(match (true) {
            $attempts <= 1 => 'PT1H',
            $attempts === 2 => 'PT6H',
            default => 'P1D',
        });
    }

    /** @param list<string> $domains the website's */
    public function state(\DateTimeImmutable $now, array $domains): string
    {
        if (!$this->exists()) {
            return $this->attempts > 0 ? self::FAILED : ($this->auto ? self::PENDING : self::NONE);
        }

        if ($this->notAfter <= $now) {
            return self::EXPIRED;
        }

        if ($this->notAfter <= $now->modify('+' . self::EXPIRING_DAYS . ' days') || !$this->covers($domains)) {
            return self::EXPIRING;
        }

        return self::ACTIVE;
    }

    /**
     * @param list<string> $domains the website's
     *
     * @return list<string> those it does not cover
     */
    public function uncovered(array $domains): array
    {
        return $this->exists() ? \array_values(\array_diff($domains, $this->domains)) : [];
    }

    /**
     * @param list<string> $domains the website's
     *
     * @return array<string, mixed> what the API shows
     */
    public function toArray(\DateTimeImmutable $now, array $domains): array
    {
        $at = static fn(?\DateTimeImmutable $d): ?string => $d?->format(\DATE_ATOM);

        return [
            'state' => $this->state($now, $domains),
            'automatic' => $this->auto,
            'domains' => $this->domains,
            'uncovered' => $this->uncovered($domains),
            'issuer' => $this->issuer,
            'serial' => $this->serial,
            'not_before' => $at($this->notBefore),
            'not_after' => $at($this->notAfter),
            'renew_at' => $at($this->renewAt()),
            'issued_at' => $at($this->issuedAt),
            'attempts' => $this->attempts,
            'next_attempt_at' => $at($this->nextAttemptAt),
            'last_error' => $this->lastErrorCode === null ? null : ['code' => $this->lastErrorCode, 'message' => $this->lastError],
        ];
    }
}
