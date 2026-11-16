<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\Accounts;
use App\Modules\Packages\Packages;

/**
 * Each account's mail as the panel keeps it: its mail domains, mailboxes,
 * forwarders and autoresponders; and the rules their values follow.
 *
 * The agent checks every value again and never trusts these checks; they
 * are here so a mistake is explained beside the field that holds it.
 */
final class Mailboxes
{
    public const PASSWORD_MIN = 10;
    public const PASSWORD_MAX = 128;
    public const MAX_QUOTA_MB = 1048576;
    public const DEFAULT_QUOTA_MB = 1024;
    public const MAX_TARGETS = 50;
    public const MAX_SUBJECT = 200;
    public const MAX_BODY = 10000;
    public const MAX_FROM_NAME = 100;
    public const MAX_DAYS = 30;

    /** Catch-all: what becomes of mail to an address the domain does not have. */
    public const REFUSE = '';
    public const DISCARD = ':blackhole:';

    /** Local parts of the account's own addresses: as the agent takes them. */
    private const LOCAL = '/^[a-z0-9]([a-z0-9._-]{0,62}[a-z0-9])?$/';
    /** A foreign address's local part, as the agent forwards to it. */
    private const FOREIGN_LOCAL = '/^[A-Za-z0-9._%=+-]{1,64}$/';
    private const LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';
    private const DATE = '/^20\d{2}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/';

    public function __construct(private readonly ConnectionManager $connections) {}

    // -------------------------------------------------------------------------
    // Settings

    public function setting(string $name, string $default = ''): string
    {
        $row = $this->table('mail_settings')->where('name', $name)->first();

        return $row === null ? $default : (string) $row['value'];
    }

    public function setSetting(string $name, string $value): void
    {
        if ($this->table('mail_settings')->where('name', $name)->first() === null) {
            $this->table('mail_settings')->insert(['name' => $name, 'value' => $value]);
        } else {
            $this->table('mail_settings')->where('name', $name)->update(['value' => $value]);
        }
    }

    // -------------------------------------------------------------------------
    // Reading

    /** @return list<array<string, mixed>> the account's mail domains, by name */
    public function domains(int $accountId): array
    {
        return $this->table('mail_domains')->where('account_id', $accountId)->orderBy('name')->get();
    }

    /** @return array<string, mixed>|null */
    public function domain(int $accountId, int|string $idOrName): ?array
    {
        $q = $this->table('mail_domains')->where('account_id', $accountId);

        return (\is_int($idOrName) ? $q->where('id', $idOrName) : $q->where('name', \strtolower($idOrName)))->first();
    }

    /** @return array<string, mixed>|null */
    public function domainById(int $id): ?array
    {
        return $this->table('mail_domains')->where('id', $id)->first();
    }

    /**
     * The account's mailboxes, each with its address and autoresponder.
     *
     * @return list<array<string, mixed>>
     */
    public function mailboxes(int $accountId): array
    {
        $names = $this->domainNames($accountId);
        $responders = [];

        foreach ($this->table('mail_boxes')->where('account_id', $accountId)->get() as $box) {
            $responders[(int) $box['id']] = null;
        }

        if ($responders !== []) {
            foreach ($this->table('mail_responders')->whereIn('mailbox_id', \array_keys($responders))->get() as $r) {
                $responders[(int) $r['mailbox_id']] = $r;
            }
        }

        $rows = [];

        foreach ($this->table('mail_boxes')->where('account_id', $accountId)->get() as $box) {
            $domain = $names[(int) $box['mail_domain_id']] ?? null;

            if ($domain === null) {
                continue;
            }

            $rows[] = $box + ['domain' => $domain, 'address' => $box['local'] . '@' . $domain, 'responder' => $responders[(int) $box['id']] ?? null];
        }

        \usort($rows, static fn(array $a, array $b): int => [$a['domain'], $a['local']] <=> [$b['domain'], $b['local']]);

        return $rows;
    }

    /** @return array<string, mixed>|null a mailbox of the account, by id or address */
    public function mailbox(int $accountId, int|string $idOrAddress): ?array
    {
        foreach ($this->mailboxes($accountId) as $box) {
            if (\is_int($idOrAddress) ? (int) $box['id'] === $idOrAddress : $box['address'] === \strtolower($idOrAddress)) {
                return $box;
            }
        }

        return null;
    }

    /**
     * The account's forwarders, each with its address.
     *
     * @return list<array<string, mixed>>
     */
    public function forwarders(int $accountId): array
    {
        $names = $this->domainNames($accountId);
        $rows = [];

        foreach ($this->table('mail_forwarders')->where('account_id', $accountId)->get() as $f) {
            if (isset($names[(int) $f['mail_domain_id']])) {
                $rows[] = $f + ['domain' => $names[(int) $f['mail_domain_id']], 'address' => $f['local'] . '@' . $names[(int) $f['mail_domain_id']]];
            }
        }

        \usort($rows, static fn(array $a, array $b): int => [$a['domain'], $a['local'], $a['destination']] <=> [$b['domain'], $b['local'], $b['destination']]);

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function forwarder(int $accountId, int $id): ?array
    {
        foreach ($this->forwarders($accountId) as $f) {
            if ((int) $f['id'] === $id) {
                return $f;
            }
        }

        return null;
    }

    /** @return array<int, string> domain id => kind (main, addon, alias, sub) */
    public function domainKinds(int $accountId): array
    {
        $kinds = [];

        foreach ($this->connections->connection()->table('domains')->where('account_id', $accountId)->get() as $d) {
            $kinds[(int) $d['id']] = (string) $d['kind'];
        }

        return $kinds;
    }

    public function countMailboxes(int $accountId): int
    {
        return \count($this->table('mail_boxes')->where('account_id', $accountId)->get());
    }

    /**
     * The account's whole mail state as mail.apply takes it. An account
     * whose package has no email has none.
     *
     * @param array<string, mixed>      $account
     * @param array<string, mixed>|null $package
     *
     * @return array<string, mixed>
     */
    public function state(array $account, ?array $package): array
    {
        $id = (int) $account['id'];
        $domains = [];

        if ($package === null || Packages::has($package, 'email')) {
            $boxes = [];
            $forwards = [];

            foreach ($this->mailboxes($id) as $box) {
                $r = $box['responder'];
                $boxes[(int) $box['mail_domain_id']][] = ['local' => (string) $box['local'], 'hash' => (string) $box['hash'], 'quota_mb' => (int) $box['quota_mb']] + ($r === null ? [] : ['autoresponder' => [
                    'subject' => (string) $r['subject'], 'body' => (string) $r['body'], 'from_name' => (string) ($r['from_name'] ?? ''),
                    'days' => (int) $r['days'], 'start' => (string) ($r['starts_on'] ?? ''), 'end' => (string) ($r['ends_on'] ?? ''),
                ]]);
            }

            foreach ($this->forwarders($id) as $f) {
                $forwards[(int) $f['mail_domain_id']][(string) $f['local']][] = (string) $f['destination'];
            }

            foreach ($this->domains($id) as $d) {
                $domainId = (int) $d['id'];
                $domains[] = [
                    'name' => (string) $d['name'],
                    'local' => (bool) $d['local'],
                    'catchall' => (string) ($d['catchall'] ?? ''),
                    'mailboxes' => $boxes[$domainId] ?? [],
                    'forwarders' => \array_map(static fn(string $local, array $to): array => ['local' => $local, 'to' => $to], \array_keys($forwards[$domainId] ?? []), \array_values($forwards[$domainId] ?? [])),
                ];
            }
        }

        return [
            'username' => (string) $account['username'],
            'suspended' => $account['status'] === Accounts::SUSPENDED,
            'domains' => $domains,
        ];
    }

    // -------------------------------------------------------------------------
    // Rules

    /**
     * A local part of one of the account's own addresses, from what was
     * typed ("Info", " sales ").
     *
     * @return array{0: string, 1: ?string} [local part, error key]
     */
    public static function checkLocal(string $input): array
    {
        $local = \strtolower(\trim($input));

        if ($local === '' || \preg_match(self::LOCAL, $local) !== 1 || \str_contains($local, '..')) {
            return [$local, \str_contains($local, '+') ? 'Mail.error_local_plus' : 'Mail.error_local'];
        }

        return [$local, null];
    }

    /**
     * An address mail may be forwarded to.
     *
     * @return array{0: string, 1: ?string} [address, error key]
     */
    public static function checkAddress(string $input): array
    {
        $address = \trim($input);
        $at = \strrpos($address, '@');

        if ($at === false || $at === 0 || \strlen($address) > 254) {
            return [$address, 'Mail.error_address'];
        }

        $local = \substr($address, 0, $at);
        $domain = \strtolower(\substr($address, $at + 1));

        if (\preg_match(self::FOREIGN_LOCAL, $local) !== 1 || \str_starts_with($local, '.') || \str_ends_with($local, '.') || \str_contains($local, '..') || !self::isDomain($domain)) {
            return [$address, 'Mail.error_address'];
        }

        return [$local . '@' . $domain, null];
    }

    public static function isDomain(string $name): bool
    {
        if (\strlen($name) > 253 || !\str_contains($name, '.')) {
            return false;
        }

        foreach (\explode('.', $name) as $label) {
            if (\preg_match(self::LABEL, $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    public static function passwordError(string $password): ?string
    {
        $length = \mb_strlen($password);

        return match (true) {
            $length < self::PASSWORD_MIN => 'Mail.error_password_short',
            $length > self::PASSWORD_MAX => 'Mail.error_password_long',
            \preg_match('/[\x00-\x1f\x7f]/', $password) === 1 => 'Mail.error_password_chars',
            default => null,
        };
    }

    /** The hash Dovecot checks logins against (BLF-CRYPT). */
    public static function hash(string $password): string
    {
        return \password_hash($password, \PASSWORD_BCRYPT);
    }

    /**
     * A mailbox's quota in MB: 0 (none of its own) up to the package's disk
     * space.
     *
     * @param array<string, mixed>|null $package
     *
     * @return array{0: int, 1: ?string} [MB, error key]
     */
    public static function checkQuota(mixed $input, ?array $package): array
    {
        $text = \is_scalar($input) ? \trim((string) $input) : '';

        if ($text === '' || !\ctype_digit($text)) {
            return [0, 'Mail.error_quota'];
        }

        $mb = (int) $text;
        $disk = (int) ($package['disk_mb'] ?? 0);

        if ($mb > self::MAX_QUOTA_MB || ($disk > 0 && $mb > $disk)) {
            return [$mb, 'Mail.error_quota_large'];
        }

        return [$mb, null];
    }

    /**
     * An autoresponder from a form or the API.
     *
     * @param array<string, mixed> $input subject, body, from_name, days, start, end
     *
     * @return array{0: array{subject: string, body: string, from_name: string, days: int, starts_on: ?string, ends_on: ?string}, 1: array<string, string>} [clean, errors by field]
     */
    public static function checkResponder(array $input): array
    {
        $text = static fn(string $key): string => \is_scalar($input[$key] ?? null) ? (string) $input[$key] : '';
        $subject = \trim($text('subject'));
        $body = \str_replace("\r\n", "\n", $text('body'));
        $from = \trim($text('from_name'));
        $daysText = \trim($text('days'));
        $start = \trim($text('start'));
        $end = \trim($text('end'));
        $errors = [];

        if ($subject === '' || \mb_strlen($subject) > self::MAX_SUBJECT || \preg_match('/[\x00-\x1f\x7f]/u', $subject) === 1 || !\mb_check_encoding($subject, 'UTF-8')) {
            $errors['subject'] = 'Mail.error_subject';
        }

        if (\trim($body) === '' || \mb_strlen($body) > self::MAX_BODY || \preg_match('/[\x00-\x08\x0b-\x1f\x7f]/u', $body) === 1 || !\mb_check_encoding($body, 'UTF-8')) {
            $errors['body'] = 'Mail.error_body';
        }

        if (\mb_strlen($from) > self::MAX_FROM_NAME || \preg_match('/[\x00-\x1f\x7f"<>\\\\]/u', $from) === 1) {
            $errors['from_name'] = 'Mail.error_from_name';
        }

        $days = \ctype_digit($daysText) ? (int) $daysText : 0;

        if ($days < 1 || $days > self::MAX_DAYS) {
            $errors['days'] = 'Mail.error_days';
        }

        foreach (['start' => $start, 'end' => $end] as $field => $date) {
            if ($date !== '' && (\preg_match(self::DATE, $date) !== 1 || !\checkdate((int) \substr($date, 5, 2), (int) \substr($date, 8, 2), (int) \substr($date, 0, 4)))) {
                $errors[$field] = 'Mail.error_date';
            }
        }

        if (!isset($errors['start'], $errors['end']) && $start !== '' && $end !== '' && $end < $start) {
            $errors['end'] = 'Mail.error_date_order';
        }

        return [[
            'subject' => $subject, 'body' => $body, 'from_name' => $from, 'days' => $days,
            'starts_on' => $start === '' ? null : $start, 'ends_on' => $end === '' ? null : $end,
        ], $errors];
    }

    /**
     * A catch-all from a form or the API: "refuse", "discard", or an address.
     *
     * @return array{0: ?string, 1: ?string} [stored value (null refuses), error key]
     */
    public static function checkCatchall(string $action, string $address = ''): array
    {
        return match ($action) {
            'refuse', '' => [null, null],
            'discard' => [self::DISCARD, null],
            'forward' => (static function () use ($address): array {
                [$clean, $why] = self::checkAddress($address);

                return [$why === null ? $clean : null, $why];
            })(),
            default => [null, 'Mail.error_catchall'],
        };
    }

    // -------------------------------------------------------------------------
    // Writing

    public function addDomain(int $accountId, int $domainId, string $name): int
    {
        return (int) $this->table('mail_domains')->insert(['account_id' => $accountId, 'domain_id' => $domainId, 'name' => $name, 'local' => true, 'created_at' => Accounts::now()], 'id');
    }

    /** @param array<string, mixed> $changes */
    public function updateDomain(int $id, array $changes): void
    {
        $this->table('mail_domains')->where('id', $id)->update($changes);
    }

    /** A mail domain and everything in it. */
    public function deleteDomain(int $id): void
    {
        foreach ($this->table('mail_boxes')->where('mail_domain_id', $id)->get() as $box) {
            $this->table('mail_responders')->where('mailbox_id', (int) $box['id'])->delete();
        }

        $this->table('mail_boxes')->where('mail_domain_id', $id)->delete();
        $this->table('mail_forwarders')->where('mail_domain_id', $id)->delete();
        $this->table('mail_domains')->where('id', $id)->delete();
    }

    public function addMailbox(int $accountId, int $domainId, string $local, string $hash, int $quotaMb): int
    {
        $now = Accounts::now();

        return (int) $this->table('mail_boxes')->insert([
            'account_id' => $accountId, 'mail_domain_id' => $domainId, 'local' => $local, 'hash' => $hash,
            'quota_mb' => $quotaMb, 'used_bytes' => 0, 'created_at' => $now, 'updated_at' => $now,
        ], 'id');
    }

    /** @param array<string, mixed> $changes */
    public function updateMailbox(int $id, array $changes): void
    {
        $this->table('mail_boxes')->where('id', $id)->update($changes + ['updated_at' => Accounts::now()]);
    }

    public function deleteMailbox(int $id): void
    {
        $this->table('mail_responders')->where('mailbox_id', $id)->delete();
        $this->table('mail_boxes')->where('id', $id)->delete();
    }

    /**
     * Put a deleted mailbox back as it was (its id changes).
     *
     * @param array<string, mixed> $box as mailbox() gave it
     */
    public function restoreMailbox(array $box): int
    {
        $id = (int) $this->table('mail_boxes')->insert([
            'account_id' => $box['account_id'], 'mail_domain_id' => $box['mail_domain_id'], 'local' => $box['local'], 'hash' => $box['hash'],
            'quota_mb' => $box['quota_mb'], 'used_bytes' => $box['used_bytes'], 'created_at' => $box['created_at'], 'updated_at' => $box['updated_at'],
        ], 'id');

        if (\is_array($box['responder'] ?? null)) {
            $this->setResponder($id, $box['responder']);
        }

        return $id;
    }

    public function setUsage(int $accountId, string $address, int $bytes): void
    {
        $box = $this->mailbox($accountId, $address);

        if ($box !== null) {
            $this->table('mail_boxes')->where('id', (int) $box['id'])->update(['used_bytes' => $bytes]);
        }
    }

    /** @param array<string, mixed>|null $responder null removes it */
    public function setResponder(int $mailboxId, ?array $responder): void
    {
        $this->table('mail_responders')->where('mailbox_id', $mailboxId)->delete();

        if ($responder !== null) {
            $this->table('mail_responders')->insert([
                'mailbox_id' => $mailboxId, 'subject' => $responder['subject'], 'body' => $responder['body'],
                'from_name' => ($responder['from_name'] ?? '') === '' ? null : $responder['from_name'], 'days' => $responder['days'],
                'starts_on' => $responder['starts_on'] ?? null, 'ends_on' => $responder['ends_on'] ?? null, 'updated_at' => Accounts::now(),
            ]);
        }
    }

    public function hasForwarder(int $domainId, string $local, string $destination): bool
    {
        return $this->table('mail_forwarders')->where('mail_domain_id', $domainId)->where('local', $local)->where('destination', $destination)->first() !== null;
    }

    public function countTargets(int $domainId, string $local): int
    {
        return \count($this->table('mail_forwarders')->where('mail_domain_id', $domainId)->where('local', $local)->get());
    }

    public function addForwarder(int $accountId, int $domainId, string $local, string $destination): int
    {
        return (int) $this->table('mail_forwarders')->insert([
            'account_id' => $accountId, 'mail_domain_id' => $domainId, 'local' => $local, 'destination' => $destination, 'created_at' => Accounts::now(),
        ], 'id');
    }

    public function deleteForwarder(int $id): void
    {
        $this->table('mail_forwarders')->where('id', $id)->delete();
    }

    /** Forget a terminated account: the agent removed its mail files itself, and its mail went with its home. */
    public function deleteAccount(int $accountId): void
    {
        foreach ($this->table('mail_domains')->where('account_id', $accountId)->get() as $d) {
            $this->deleteDomain((int) $d['id']);
        }

        $this->table('mail_purges')->where('account_id', $accountId)->delete();
    }

    // -------------------------------------------------------------------------
    // Purges: mail to delete once the server no longer has it set up

    public function queuePurge(int $accountId, string $domain, string $local = ''): void
    {
        $this->table('mail_purges')->insert(['account_id' => $accountId, 'domain' => $domain, 'local' => $local, 'created_at' => Accounts::now()]);
    }

    /** @return list<array<string, mixed>> */
    public function purges(?int $accountId = null): array
    {
        $q = $this->table('mail_purges');

        return ($accountId === null ? $q : $q->where('account_id', $accountId))->orderBy('id')->get();
    }

    public function purged(int $id): void
    {
        $this->table('mail_purges')->where('id', $id)->delete();
    }

    /** A purge waiting for an address that is a mailbox again is dropped: its mail is someone's again. */
    public function cancelPurge(int $accountId, string $domain, string $local): void
    {
        $this->table('mail_purges')->where('account_id', $accountId)->where('domain', $domain)->whereIn('local', [$local, ''])->delete();
    }

    // -------------------------------------------------------------------------

    /** @return list<array<string, mixed>> every mail domain on the server, with its account and counts */
    public function all(): array
    {
        $usernames = [];

        foreach ($this->connections->connection()->table('accounts')->get() as $a) {
            $usernames[(int) $a['id']] = (string) $a['username'];
        }

        $boxes = [];

        foreach ($this->table('mail_boxes')->get() as $b) {
            $boxes[(int) $b['mail_domain_id']] = ($boxes[(int) $b['mail_domain_id']] ?? 0) + 1;
        }

        return \array_map(static fn(array $d): array => $d + ['username' => $usernames[(int) $d['account_id']] ?? '?', 'mailboxes' => $boxes[(int) $d['id']] ?? 0], $this->table('mail_domains')->orderBy('name')->get());
    }

    /** @return array<int, string> mail domain id => name */
    private function domainNames(int $accountId): array
    {
        $names = [];

        foreach ($this->domains($accountId) as $d) {
            $names[(int) $d['id']] = (string) $d['name'];
        }

        return $names;
    }

    private function table(string $name): QueryBuilder
    {
        return $this->connections->connection()->table($name);
    }
}
