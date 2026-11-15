<?php

declare(strict_types=1);

namespace App\Modules\Dns;

/**
 * The rules a DNS record must follow, and zone templates.
 *
 * The agent checks every record again and never trusts these checks; they
 * are here so a mistake is explained beside the field that holds it.
 *
 * Records are kept the way people write them: names relative to the zone
 * ("@", "www", "_dmarc"), host names in full without the final dot, TXT as
 * plain text, and priorities at the start ("10 mail.example.com").
 */
final class DnsRecords
{
    /** Types the zone editor offers. PTR is the administrator's alone. */
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS', 'PTR'];

    public const MIN_TTL = 60;
    public const MAX_TTL = 604800;
    public const DEFAULT_TTL = 3600;
    public const MAX_TXT = 4096;

    /** Records in one zone. */
    public const MAX_RECORDS = 2000;

    /**
     * The template new zones get unless the administrator or the reseller
     * wrote another: one record a line, as "name ttl type content".
     * {domain} is the zone's name, {ip} and {ip6} the server's addresses;
     * a line whose address is not set is left out.
     */
    public const DEFAULT_TEMPLATE = <<<'TEXT'
        @     3600  A      {ip}
        @     3600  AAAA   {ip6}
        www   3600  A      {ip}
        www   3600  AAAA   {ip6}
        mail  3600  A      {ip}
        mail  3600  AAAA   {ip6}
        @     3600  MX     10 mail.{domain}
        @     3600  TXT    v=spf1 a mx ~all
        TEXT;

    private const LABEL = '/^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$/';
    private const HOST_LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * Check one record for a zone.
     *
     * @param array<string, mixed> $input    name, type, ttl, content
     * @param bool                 $allowPtr the administrator's zone editor
     *
     * @return array{0: array{name: string, type: string, ttl: int, content: string}, 1: array<string, string>} [clean, errors by field]
     */
    public static function validate(string $zone, array $input, bool $allowPtr = false): array
    {
        $errors = [];
        $type = \strtoupper(\trim((string) ($input['type'] ?? '')));

        if (!\in_array($type, self::TYPES, true) || ($type === 'PTR' && !$allowPtr)) {
            $errors['type'] = 'Dns.error_type';
        }

        $name = self::relative((string) ($input['name'] ?? ''), $zone);

        if ($name === null) {
            $errors['name'] = 'Dns.error_name';
            $name = '';
        } elseif ($name === '@' && $type === 'CNAME') {
            $errors['name'] = 'Dns.error_cname_apex';
        } elseif ($name === '@' && $type === 'NS') {
            $errors['name'] = 'Dns.error_ns_apex';
        } elseif ($type === 'NS' && \str_starts_with($name, '*')) {
            $errors['name'] = 'Dns.error_name';
        }

        $ttl = $input['ttl'] ?? '';
        $ttl = $ttl === '' ? self::DEFAULT_TTL : (\is_numeric($ttl) ? (int) $ttl : -1);

        if ($ttl < self::MIN_TTL || $ttl > self::MAX_TTL) {
            $errors['ttl'] = 'Dns.error_ttl';
        }

        $content = \trim((string) ($input['content'] ?? ''));

        if (!isset($errors['type'])) {
            [$content, $why] = self::content($type, $content, $zone);

            if ($why !== null) {
                $errors['content'] = $why;
            }
        }

        return [['name' => $name, 'type' => $type, 'ttl' => $ttl, 'content' => $content], $errors];
    }

    /**
     * A record's name relative to its zone: "@" for the zone itself. Accepts
     * "", "@", "www", "www.example.com" and "www.example.com.".
     */
    public static function relative(string $name, string $zone): ?string
    {
        $name = \strtolower(\rtrim(\trim($name), '.'));

        if ($name === '' || $name === '@' || $name === $zone) {
            return '@';
        }

        if (\str_ends_with($name, '.' . $zone)) {
            $name = \substr($name, 0, -\strlen($zone) - 1);
        }

        if (\strlen($name) + \strlen($zone) + 1 > 253) {
            return null;
        }

        foreach (\explode('.', $name) as $i => $label) {
            if (!($label === '*' && $i === 0) && \preg_match(self::LABEL, $label) !== 1) {
                return null;
            }
        }

        return $name;
    }

    /** A relative name in full: "www" in example.com is www.example.com. */
    public static function full(string $name, string $zone): string
    {
        return $name === '@' ? $zone : $name . '.' . $zone;
    }

    /**
     * Check and tidy a record's content.
     *
     * @return array{0: string, 1: ?string} [content, error key]
     */
    public static function content(string $type, string $content, string $zone): array
    {
        $fields = \preg_split('/\s+/', $content, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        switch ($type) {
            case 'A':
                return \filter_var($content, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false
                    ? [$content, 'Dns.error_ipv4']
                    : [$content, null];

            case 'AAAA':
                $packed = \filter_var($content, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) === false ? false : @\inet_pton($content);

                return $packed === false ? [$content, 'Dns.error_ipv6'] : [(string) \inet_ntop($packed), null];

            case 'CNAME':
            case 'NS':
            case 'PTR':
                $host = \count($fields) === 1 ? self::host($fields[0], $zone) : null;

                return $host === null ? [$content, 'Dns.error_host'] : [$host, null];

            case 'MX':
                $host = \count($fields) === 2 ? ($fields[1] === '.' ? '.' : self::host($fields[1], $zone)) : null;

                return $host === null || !self::number($fields[0], 65535)
                    ? [$content, 'Dns.error_mx']
                    : [(int) $fields[0] . ' ' . $host, null];

            case 'SRV':
                $host = \count($fields) === 4 ? ($fields[3] === '.' ? '.' : self::host($fields[3], $zone)) : null;

                return $host === null || !self::number($fields[0], 65535) || !self::number($fields[1], 65535) || !self::number($fields[2], 65535)
                    ? [$content, 'Dns.error_srv']
                    : [(int) $fields[0] . ' ' . (int) $fields[1] . ' ' . (int) $fields[2] . ' ' . $host, null];

            case 'CAA':
                if (\preg_match('/^(\d{1,3})\s+([a-z0-9]{1,15})\s+(.+)$/i', $content, $m) !== 1 || (int) $m[1] > 255) {
                    return [$content, 'Dns.error_caa'];
                }

                $value = \trim($m[3]);
                $value = \strlen($value) >= 2 && $value[0] === '"' && \str_ends_with($value, '"') ? \substr($value, 1, -1) : $value;

                return $value === '' || \strlen($value) > 255 || \preg_match('/^[\x20-\x7e]+$/', $value) !== 1 || \strpbrk($value, '"\\') !== false
                    ? [$content, 'Dns.error_caa']
                    : [(int) $m[1] . ' ' . \strtolower($m[2]) . ' "' . $value . '"', null];

            case 'TXT':
                // People paste TXT values with the quotes around them.
                if (\strlen($content) >= 2 && $content[0] === '"' && \str_ends_with($content, '"') && \substr_count($content, '"') === 2) {
                    $content = \substr($content, 1, -1);
                }

                return $content === '' || \strlen($content) > self::MAX_TXT ? [$content, 'Dns.error_txt'] : [$content, null];
        }

        return [$content, 'Dns.error_type'];
    }

    /** A host name a record points at: in full, lowercase, no final dot; "@" is the zone. */
    public static function host(string $host, string $zone = ''): ?string
    {
        $host = \strtolower(\rtrim(\trim($host), '.'));

        if ($host === '@' && $zone !== '') {
            return $zone;
        }

        if ($host === '' || \strlen($host) > 253 || !\str_contains($host, '.')) {
            return null;
        }

        foreach (\explode('.', $host) as $label) {
            if (\preg_match(self::LABEL, $label) !== 1) {
                return null;
            }
        }

        return $host;
    }

    /** A zone or nameserver name: host-name labels only, two or more. */
    public static function domain(string $name): ?string
    {
        $name = \strtolower(\rtrim(\trim($name), '.'));

        if ($name === '' || \strlen($name) > 253 || !\str_contains($name, '.')) {
            return null;
        }

        foreach (\explode('.', $name) as $label) {
            if (\preg_match(self::HOST_LABEL, $label) !== 1) {
                return null;
            }
        }

        return $name;
    }

    /**
     * Why a record cannot join the others of its zone, or null.
     *
     * @param list<array<string, mixed>>                                       $existing the zone's records
     * @param array{name: string, type: string, ttl: int, content: string} $record
     */
    public static function conflict(array $existing, array $record, ?int $exceptId = null): ?string
    {
        $count = 0;

        foreach ($existing as $row) {
            if ($exceptId !== null && (int) $row['id'] === $exceptId) {
                continue;
            }

            $count++;

            if ($row['name'] !== $record['name']) {
                continue;
            }

            if ($row['type'] === $record['type'] && $row['content'] === $record['content']) {
                return 'Dns.error_duplicate';
            }

            if (($row['type'] === 'CNAME') !== ($record['type'] === 'CNAME') || ($row['type'] === 'CNAME' && $record['type'] === 'CNAME')) {
                return 'Dns.error_cname_alone';
            }
        }

        return $count >= self::MAX_RECORDS ? 'Dns.error_too_many' : null;
    }

    /**
     * Read a template: one record a line, "name ttl type content"; empty
     * lines and lines starting with # or ; are skipped.
     *
     * @return array{0: list<array{name: string, type: string, ttl: int, content: string}>, 1: ?string} [rows, "line N: why"]
     */
    public static function parseTemplate(string $text): array
    {
        $rows = [];

        foreach (\preg_split('/\r\n|\r|\n/', $text) ?: [] as $i => $line) {
            $line = \trim($line);

            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            $parts = \preg_split('/\s+/', $line, 4) ?: [];

            if (\count($parts) !== 4 || !\ctype_digit($parts[1])) {
                return [[], 'Dns.error_template_line|' . ($i + 1)];
            }

            // Checked with stand-ins for the placeholders, so a template that
            // could never make a valid record is refused now.
            $probe = \strtr($parts[3], ['{domain}' => 'example.com', '{ip}' => '192.0.2.1', '{ip6}' => '2001:db8::1']);
            [$clean, $errors] = self::validate('example.com', ['name' => $parts[0], 'ttl' => $parts[1], 'type' => $parts[2], 'content' => $probe]);

            if ($errors !== [] || \preg_match('/\{(?!domain\}|ip\}|ip6\})/', $parts[3]) === 1) {
                return [[], 'Dns.error_template_line|' . ($i + 1)];
            }

            $rows[] = ['name' => $clean['name'], 'type' => $clean['type'], 'ttl' => $clean['ttl'], 'content' => $parts[3]];
        }

        if (\count($rows) > 100) {
            return [[], 'Dns.error_template_long'];
        }

        return [$rows, null];
    }

    /**
     * A template's records for one zone.
     *
     * @return list<array{name: string, type: string, ttl: int, content: string}>
     */
    public static function expand(string $template, string $zone, string $ip4, string $ip6): array
    {
        [$rows] = self::parseTemplate($template);
        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            if ((\str_contains($row['content'], '{ip}') && $ip4 === '') || (\str_contains($row['content'], '{ip6}') && $ip6 === '')) {
                continue;
            }

            $content = \strtr($row['content'], ['{domain}' => $zone, '{ip}' => $ip4, '{ip6}' => $ip6]);
            [$clean, $errors] = self::validate($zone, ['content' => $content] + $row);
            $key = $clean['name'] . '/' . $clean['type'] . '/' . $clean['content'];

            if ($errors === [] && !isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $clean;
            }
        }

        return $out;
    }

    private static function number(string $s, int $max): bool
    {
        return \ctype_digit($s) && \strlen($s) <= 5 && (int) $s <= $max;
    }
}
