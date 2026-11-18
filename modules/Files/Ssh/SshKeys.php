<?php

declare(strict_types=1);

namespace App\Modules\Files\Ssh;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\Accounts;

/**
 * Each account's SSH public keys, as the panel keeps them, and the rules a
 * key follows (the agent checks the same before sshd sees it).
 */
final class SshKeys
{
    public const MAX_KEYS = 50;
    public const MAX_NAME = 64;
    public const MIN_RSA_BITS = 2048;

    /** Key types accepted, with the curve an ECDSA key must name. No DSA. */
    public const TYPES = [
        'ssh-ed25519' => null,
        'ecdsa-sha2-nistp256' => 'nistp256',
        'ecdsa-sha2-nistp384' => 'nistp384',
        'ecdsa-sha2-nistp521' => 'nistp521',
        'ssh-rsa' => null,
        'sk-ssh-ed25519@openssh.com' => null,
        'sk-ecdsa-sha2-nistp256@openssh.com' => 'nistp256',
    ];

    public function __construct(private readonly ConnectionManager $connections) {}

    /** @return list<array<string, mixed>> the account's keys, oldest first */
    public function keys(int $accountId): array
    {
        return $this->table()->where('account_id', $accountId)->orderBy('id')->get();
    }

    /** @return array<string, mixed>|null by id, fingerprint or name */
    public function key(int $accountId, int|string $ref): ?array
    {
        $q = $this->table()->where('account_id', $accountId);

        if (\is_int($ref)) {
            return $q->where('id', $ref)->first();
        }

        return (\str_starts_with($ref, 'SHA256:') ? $q->where('fingerprint', $ref) : $q->where('name', $ref))->first();
    }

    public function count(int $accountId): int
    {
        return \count($this->keys($accountId));
    }

    /** @param array{type: string, key: string, fingerprint: string, bits: int} $parsed */
    public function add(int $accountId, string $name, array $parsed): int
    {
        return (int) $this->table()->insert([
            'account_id' => $accountId,
            'name' => $name,
            'type' => $parsed['type'],
            'public_key' => $parsed['key'],
            'fingerprint' => $parsed['fingerprint'],
            'bits' => $parsed['bits'],
            'created_at' => Accounts::now(),
        ], 'id');
    }

    /** @param array<string, mixed> $row a row as it was, to put back */
    public function restore(array $row): int
    {
        unset($row['id']);

        return (int) $this->table()->insert($row, 'id');
    }

    public function delete(int $id): void
    {
        $this->table()->where('id', $id)->delete();
    }

    public function deleteAccount(int $accountId): void
    {
        $this->table()->where('account_id', $accountId)->delete();
    }

    /** @return list<int> accounts that have a key */
    public function accountIds(): array
    {
        return \array_values(\array_unique(\array_map(static fn(array $r): int => (int) $r['account_id'], $this->table()->get())));
    }

    /**
     * The account's whole list as ssh.apply takes it.
     *
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    public function state(array $account): array
    {
        return [
            'username' => (string) $account['username'],
            'keys' => \array_map(static fn(array $k): array => [
                'type' => (string) $k['type'],
                'key' => (string) $k['public_key'],
                'comment' => (string) $k['name'],
            ], $this->keys((int) $account['id'])),
        ];
    }

    /**
     * The line to paste into authorized_keys elsewhere, or to keep.
     *
     * @param array<string, mixed> $key a row of ssh_keys
     */
    public static function line(array $key): string
    {
        return $key['type'] . ' ' . $key['public_key'] . ' ' . $key['name'];
    }

    // -------------------------------------------------------------------------
    // Rules

    /** @return string|null a language key when the name is not one */
    public static function nameError(string $name): ?string
    {
        return \preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._@+()-]{0,63}$/u', $name) === 1 && \mb_strlen($name) <= self::MAX_NAME
            ? null : 'Files.ssh_error_name';
    }

    /**
     * Read a public key as pasted ("type base64 [comment]", as in a .pub
     * file or authorized_keys), without options.
     *
     * @return array{0: array{type: string, key: string, fingerprint: string, bits: int, comment: string}|null, 1: string|null} the key, or a language key saying why not
     */
    public static function parse(string $input): array
    {
        $input = \trim($input);

        if ($input === '' || \strlen($input) > 16384) {
            return [null, 'Files.ssh_error_key'];
        }

        if (\str_contains($input, 'PRIVATE KEY') || \str_contains($input, 'PuTTY-User-Key-File')) {
            return [null, 'Files.ssh_error_private'];
        }

        if (\preg_match('/[\r\n]/', $input) === 1) {
            return [null, 'Files.ssh_error_one_key'];
        }

        if (\preg_match('/^(\S+)\s+([A-Za-z0-9+\/]+={0,2})(?:\s+(.*))?$/', $input, $m) !== 1) {
            return [null, 'Files.ssh_error_key'];
        }

        [$type, $b64, $comment] = [$m[1], $m[2], $m[3] ?? ''];

        if (!\array_key_exists($type, self::TYPES)) {
            return [null, $type === 'ssh-dss' ? 'Files.ssh_error_dsa' : 'Files.ssh_error_type'];
        }

        $blob = \base64_decode($b64, true);

        if ($blob === false) {
            return [null, 'Files.ssh_error_key'];
        }

        $fields = self::fields($blob);

        if ($fields === null || ($fields[0] ?? null) !== $type) {
            return [null, 'Files.ssh_error_key'];
        }

        $bits = match (true) {
            $type === 'ssh-ed25519', $type === 'sk-ssh-ed25519@openssh.com' => \strlen($fields[1] ?? '') === 32 ? 256 : 0,
            $type === 'ssh-rsa' => \count($fields) === 3 ? self::bits($fields[2]) : 0,
            default => ($fields[1] ?? null) === self::TYPES[$type] ? (int) \substr((string) self::TYPES[$type], 5) : 0,
        };

        if ($bits === 0) {
            return [null, 'Files.ssh_error_key'];
        }

        if ($type === 'ssh-rsa' && $bits < self::MIN_RSA_BITS) {
            return [null, 'Files.ssh_error_rsa'];
        }

        return [[
            'type' => $type,
            'key' => $b64,
            'fingerprint' => self::fingerprint($blob),
            'bits' => $bits,
            'comment' => \trim($comment),
        ], null];
    }

    /** SHA256:<unpadded base64>, as ssh-keygen -l shows it. */
    public static function fingerprint(string $blob): string
    {
        return 'SHA256:' . \rtrim(\base64_encode(\hash('sha256', $blob, true)), '=');
    }

    /**
     * The length-prefixed strings a key blob is made of, or null when it is
     * not made of them exactly.
     *
     * @return list<string>|null
     */
    private static function fields(string $blob): ?array
    {
        $out = [];
        $at = 0;
        $length = \strlen($blob);

        while ($at < $length) {
            if ($at + 4 > $length || \count($out) > 8) {
                return null;
            }

            $n = (\unpack('N', \substr($blob, $at, 4)) ?: [])[1] ?? null;

            if (!\is_int($n)) {
                return null;
            }

            $at += 4;

            if ($n > $length - $at) {
                return null;
            }

            $out[] = \substr($blob, $at, $n);
            $at += $n;
        }

        return $out;
    }

    /** The bit length of an RSA modulus (a big-endian mpint). */
    private static function bits(string $n): int
    {
        $n = \ltrim($n, "\0");

        if ($n === '') {
            return 0;
        }

        return (\strlen($n) - 1) * 8 + \strlen(\decbin(\ord($n[0])));
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('ssh_keys');
    }
}
