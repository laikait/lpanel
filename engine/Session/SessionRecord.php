<?php

declare(strict_types=1);

namespace App\Engine\Session;

/**
 * What a store holds: an id, some data, and three timestamps.
 *
 * The timestamps are separate on purpose, because they answer three different
 * questions and a single "updated at" answers none of them well.
 *
 * - createdAt bounds the ABSOLUTE lifetime. A session that has been alive for
 *   a week is suspicious however busy it has been; without this, a session kept
 *   warm by a background poll never expires at all.
 * - touchedAt bounds the IDLE lifetime, which is the one users experience as
 *   "it logged me out while I was at lunch".
 * - successor is the grace pointer left behind by regenerate(). See there.
 *
 * A plain class rather than an array because a store that receives an array
 * eventually receives one with a key missing, and the failure surfaces three
 * layers away as "undefined index" in something that only reads sessions.
 */
final class SessionRecord
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly array $payload = [],
        public readonly int $createdAt = 0,
        public readonly int $touchedAt = 0,
        public readonly ?string $successor = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fresh(string $id, array $payload = [], ?int $now = null): self
    {
        $now ??= \time();

        return new self($id, $payload, $now, $now);
    }

    /** @param array<string, mixed> $payload */
    public function withPayload(array $payload, ?int $now = null): self
    {
        return new self($this->id, $payload, $this->createdAt, $now ?? \time(), $this->successor);
    }

    /**
     * The tombstone regenerate() leaves behind.
     *
     * The payload is dropped: a record that exists only to redirect must not
     * also be a second copy of the data, or a stolen old id would keep working
     * against stale data for the whole grace window.
     */
    public function replacedBy(string $successor, ?int $now = null): self
    {
        return new self($this->id, [], $this->createdAt, $now ?? \time(), $successor);
    }

    public function isPointer(): bool
    {
        return $this->successor !== null;
    }

    /**
     * Expired by either clock.
     *
     * $absolute of zero means "no absolute limit", which is the default: it is
     * the setting that most often gets switched on after an incident rather
     * than before one, and a framework that forced a value would have to pick
     * one that is wrong for both a kiosk and an internal tool.
     */
    public function hasExpired(int $idle, int $absolute = 0, ?int $now = null): bool
    {
        $now ??= \time();

        if ($idle > 0 && $this->touchedAt + $idle <= $now) {
            return true;
        }

        return $absolute > 0 && $this->createdAt + $absolute <= $now;
    }

    /**
     * The record as a store writes it: JSON, and only JSON.
     *
     * **A session payload must be JSON-serialisable.** That is a real
     * constraint and it is deliberate. PHP's own sessions use serialize(), so
     * an object dropped into $_SESSION comes back as an object -- usually a
     * stale copy of a model whose row changed an hour ago, and occasionally a
     * class that no longer exists, which is a fatal error on a page nobody
     * touched. JSON refuses at the moment somebody writes the object, with a
     * message saying which key it was.
     *
     * It is also what makes the stores interchangeable: a JSON string fits a
     * file, a TEXT column and a Redis value without a single store inventing
     * its own encoding.
     */
    public function encode(): string
    {
        $stored = $this->toArray();

        // The id itself is not written, only its hash. A session id is a live
        // login, and a store's contents get read by more people than its
        // purpose suggests -- a backup, a support query on the sessions table,
        // a directory listing. The store always knows the id it is reading
        // (it looked the record up by it), so decode() is handed it back.
        unset($stored['id']);
        $stored = ['key' => self::keyFor($this->id), ...$stored];

        try {
            return \json_encode($stored, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw SessionException::unserialisablePayload($this->unserialisableKey(), $e);
        }
    }

    /** What a record is stored under, and written in place of the id: its SHA-256. */
    public static function keyFor(string $id): string
    {
        return \hash('sha256', $id);
    }

    /**
     * Null for anything that is not a record this framework wrote for this id.
     *
     * $id is the id the store looked the record up by. A record written by
     * encode() carries only the id's hash, which must match; one written
     * before that carries the id itself, which is accepted when it is the
     * same, so sessions survive the upgrade.
     */
    public static function decode(string $contents, ?string $id = null): ?self
    {
        $data = self::parse($contents);

        if ($data === null) {
            return null;
        }

        if (isset($data['key'])) {
            if ($id === null || !\is_string($data['key']) || !\hash_equals($data['key'], self::keyFor($id))) {
                return null;
            }

            $data['id'] = $id;
        } elseif ($id !== null && (!\is_string($data['id'] ?? null) || !\hash_equals($data['id'], $id))) {
            return null;
        }

        return self::fromArray($data);
    }

    /**
     * Enough of a record to decide whether a sweep removes it, without its id.
     *
     * A file store sweeps by reading every file, and has only the file's name
     * -- the hash -- not the id that would decode it. The id of what comes back
     * is empty; it is good for hasExpired() and nothing else.
     */
    public static function forSweep(string $contents): ?self
    {
        $data = self::parse($contents);

        if ($data === null || (!isset($data['key']) && !isset($data['id']))) {
            return null;
        }

        return new self(
            '',
            [],
            isset($data['created']) && \is_int($data['created']) ? $data['created'] : 0,
            isset($data['touched']) && \is_int($data['touched']) ? $data['touched'] : 0,
        );
    }

    /** @return array<array-key, mixed>|null */
    private static function parse(string $contents): ?array
    {
        if (\trim($contents) === '') {
            return null;
        }

        try {
            /** @var mixed $data */
            $data = \json_decode($contents, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }

    /**
     * Which key could not be encoded.
     *
     * Worth the second pass: "Malformed UTF-8 characters" with no key named is
     * one of the less helpful messages PHP produces, and the whole point of
     * refusing here is that somebody can fix it.
     */
    private function unserialisableKey(): string
    {
        foreach ($this->payload as $key => $value) {
            if (\json_encode($value) === false) {
                return $key;
            }
        }

        return '?';
    }

    /** @return array{id: string, payload: array<string, mixed>, created: int, touched: int, successor: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'payload' => $this->payload,
            'created' => $this->createdAt,
            'touched' => $this->touchedAt,
            'successor' => $this->successor,
        ];
    }

    /**
     * Rebuild from whatever a store had written, refusing anything malformed.
     *
     * Returns null rather than throwing, and that is the important half. A
     * corrupt session file -- a half-written one, a leftover from an older
     * format, something an operator edited -- must log the user out, not take
     * the site down. Null reaches the manager as "no session", which starts a
     * fresh one.
     *
     * @param mixed $data
     */
    public static function fromArray($data): ?self
    {
        if (!\is_array($data) || !isset($data['id']) || !\is_string($data['id'])) {
            return null;
        }

        if (!SessionId::isValid($data['id'])) {
            return null;
        }

        $payload = $data['payload'] ?? [];
        $successor = $data['successor'] ?? null;

        if (!\is_array($payload)) {
            return null;
        }

        if ($successor !== null && (!\is_string($successor) || !SessionId::isValid($successor))) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return new self(
            $data['id'],
            $payload,
            isset($data['created']) && \is_int($data['created']) ? $data['created'] : 0,
            isset($data['touched']) && \is_int($data['touched']) ? $data['touched'] : 0,
            $successor,
        );
    }
}
