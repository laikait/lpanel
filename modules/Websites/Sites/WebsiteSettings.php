<?php

declare(strict_types=1);

namespace App\Modules\Websites\Sites;

use App\Modules\Operations\Catalogue\Catalogue;

/**
 * A website's managed settings (ADR-0014): domains, its folder under the
 * account's web/, PHP on or off, index files, redirects and headers. Nothing
 * else; never configuration text.
 *
 * These are the agent's rules, checked first so a person gets a message per
 * field. The agent checks them again and is the one that decides.
 */
final class WebsiteSettings
{
    public const MAX_DOMAINS = 10;

    public const MAX_REDIRECTS = 50;

    public const MAX_HEADERS = 20;

    public const MAX_INDEX = 8;

    public const REDIRECT_CODES = [301 => '301 Permanent', 302 => '302 Temporary', 307 => '307 Temporary, keeps method', 308 => '308 Permanent, keeps method'];

    /** Headers Nginx or LPanel own, and hop-by-hop ones. */
    private const RESERVED_HEADERS = ['connection', 'content-length', 'content-type', 'date', 'host', 'keep-alive', 'location', 'proxy-authenticate', 'server', 'set-cookie', 'status', 'te', 'trailer', 'transfer-encoding', 'upgrade', 'www-authenticate'];

    /**
     * Settings as a form or a JSON body gives them, normalised and checked.
     *
     * @param array<string, mixed> $raw
     *
     * @return array{settings: array{domains: list<string>, document_root: string, php: bool, index: list<string>, redirects: list<array{from: string, to: string, code: int}>, headers: list<array{name: string, value: string}>}, errors: array<string, string>}
     */
    public static function normalize(array $raw): array
    {
        $errors = [];

        $domains = [];
        foreach (self::words($raw['domains'] ?? []) as $d) {
            $d = \strtolower(\rtrim(\trim($d), '.'));

            if (\preg_match('#^[a-z][a-z0-9+.-]*://#', $d) === 1) {
                $errors['domains'] ??= 'Give domain names only, without http:// or https://.';

                continue;
            }

            if (\function_exists('idn_to_ascii') && \preg_match('/[^\x00-\x7f]/', $d) === 1) {
                $ascii = \idn_to_ascii($d, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
                $d = \is_string($ascii) ? $ascii : $d;
            }

            if ($d !== '' && !\in_array($d, $domains, true)) {
                $domains[] = $d;
            }
        }

        if ($domains === []) {
            $errors['domains'] ??= 'A website needs at least one domain name, such as example.com.';
        } elseif (\count($domains) > self::MAX_DOMAINS) {
            $errors['domains'] = \sprintf('A website answers to at most %d domain names.', self::MAX_DOMAINS);
        } else {
            foreach ($domains as $d) {
                if (\strlen($d) > 253 || \preg_match(Catalogue::DOMAIN, $d) !== 1) {
                    $errors['domains'] = \sprintf('"%s" is not a domain name LPanel accepts: letters, digits and hyphens, with a dot, like example.com.', self::clip($d));

                    break;
                }
            }
        }

        // A trailing slash is forgiven; a leading one is an absolute path, refused below.
        $root = \rtrim(\trim(self::text($raw['document_root'] ?? '')), '/');
        if ($root === '' && $domains !== []) {
            $root = $domains[0];
        }
        if (\preg_match(Catalogue::DOCUMENT_ROOT, $root) !== 1) {
            $errors['document_root'] = 'Name one to four folders under web/, like example.com or example.com/public: letters, digits and . _ -, not starting with a dot.';
        }

        $php = self::bool($raw['php'] ?? false);

        $index = \array_values(\array_unique(self::words($raw['index'] ?? [])));
        if (\count($index) > self::MAX_INDEX) {
            $errors['index'] = \sprintf('At most %d index files.', self::MAX_INDEX);
        }
        foreach ($index as $i) {
            if (\preg_match(Catalogue::INDEX_FILE, $i) !== 1) {
                $errors['index'] = \sprintf('"%s" is not a file name: letters, digits and . _ -, no folders.', self::clip($i));

                break;
            }
        }

        $redirects = [];
        $seen = [];
        foreach (self::rows($raw['redirects'] ?? []) as $r) {
            $from = \trim(self::text($r['from'] ?? ''));
            $to = \trim(self::text($r['to'] ?? ''));
            $code = (int) self::text($r['code'] ?? '301');

            if ($from === '' && $to === '') {
                continue;
            }

            // Keyed by its place among the rows kept, as the form shows them again.
            $key = 'redirects.' . \count($redirects);
            if (\strlen($from) > 255 || \preg_match(Catalogue::REDIRECT_FROM, $from) !== 1 || \str_contains($from, '//')) {
                $errors[$key] ??= 'Redirect from a path such as /old-page: letters, digits and . _ ~ + - /, no spaces or %.';
            } elseif (\array_intersect(\explode('/', $from), ['.', '..']) !== []) {
                $errors[$key] ??= 'A redirect\'s path cannot contain . or .. folders.';
            } elseif (\str_starts_with($from, '/.well-known/lpanel')) {
                $errors[$key] ??= '/.well-known/lpanel-probe is LPanel\'s own path.';
            } elseif (isset($seen[$from])) {
                $errors[$key] ??= \sprintf('There is already a redirect from %s.', $from);
            }
            $seen[$from] = true;

            if ($to === '' || \strlen($to) > 2048 || \preg_match(Catalogue::REDIRECT_TO, $to) !== 1) {
                $errors[$key] ??= 'Redirect to a path (/new-page) or an http:// or https:// address, without spaces, quotes or $.';
            }

            if (!isset(self::REDIRECT_CODES[$code])) {
                $errors[$key] ??= 'A redirect is 301, 302, 307 or 308.';
            }

            $redirects[] = ['from' => $from, 'to' => $to, 'code' => $code];
        }
        if (\count($redirects) > self::MAX_REDIRECTS) {
            $errors['redirects'] = \sprintf('At most %d redirects.', self::MAX_REDIRECTS);
        }

        $headers = [];
        $seen = [];
        foreach (self::rows($raw['headers'] ?? []) as $h) {
            $name = \trim(self::text($h['name'] ?? ''));
            $value = \trim(self::text($h['value'] ?? ''));

            if ($name === '' && $value === '') {
                continue;
            }

            $key = 'headers.' . \count($headers);
            $lower = \strtolower($name);
            if (\preg_match(Catalogue::HEADER_NAME, $name) !== 1) {
                $errors[$key] ??= 'A header name is letters, digits and hyphens, like X-Frame-Options.';
            } elseif (\in_array($lower, self::RESERVED_HEADERS, true) || \str_starts_with($lower, 'x-lpanel-') || \str_starts_with($lower, 'x-accel-')) {
                $errors[$key] ??= \sprintf('%s is set by the web server itself.', $name);
            } elseif (isset($seen[$lower])) {
                $errors[$key] ??= \sprintf('%s is listed twice.', $name);
            }
            $seen[$lower] = true;

            if (\preg_match(Catalogue::HEADER_VALUE, $value) !== 1) {
                $errors[$key] ??= 'A header value is up to 1024 printable characters, without " $ \\ { or }.';
            }

            $headers[] = ['name' => $name, 'value' => $value];
        }
        if (\count($headers) > self::MAX_HEADERS) {
            $errors['headers'] = \sprintf('At most %d headers.', self::MAX_HEADERS);
        }

        return [
            'settings' => ['domains' => $domains, 'document_root' => $root, 'php' => $php, 'index' => $index, 'redirects' => $redirects, 'headers' => $headers],
            'errors' => $errors,
        ];
    }

    /** @return list<string> words of a string (spaces, commas, new lines) or a list */
    private static function words(mixed $value): array
    {
        if (\is_string($value)) {
            return \array_values(\array_filter(\preg_split('/[\s,]+/', $value) ?: [], static fn(string $w): bool => $w !== ''));
        }

        return \is_array($value) ? \array_values(\array_filter(\array_map(self::text(...), $value), static fn(string $w): bool => \trim($w) !== '')) : [];
    }

    /** @return array<int, array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        $rows = [];

        foreach (\is_array($value) ? \array_values($value) : [] as $n => $row) {
            if ($row instanceof \stdClass) {
                $row = \get_object_vars($row);
            }

            if (\is_array($row)) {
                $rows[$n] = $row;
            }
        }

        return $rows;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : (\is_int($value) ? (string) $value : '');
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || \in_array($value, ['1', 'on', 'true', 'yes'], true);
    }

    private static function clip(string $s): string
    {
        return \mb_strlen($s) > 64 ? \mb_substr($s, 0, 64) . '…' : $s;
    }
}
