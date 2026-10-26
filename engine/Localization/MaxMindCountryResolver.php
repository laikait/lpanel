<?php

declare(strict_types=1);

namespace App\Engine\Localization;

use App\Engine\Http\Request;
use MaxMind\Db\Reader;

/**
 * The visitor's country from a local MaxMind database: GeoLite2 or GeoIP2,
 * Country or City. Any of them answers the only question asked here.
 *
 * The file is read on this machine, so a lookup is a few microseconds and no
 * network. It is opened on the first lookup, not at boot, so a request with a
 * language cookie never touches it.
 *
 * The address is Request::ip(), which honours X-Forwarded-For only from
 * http.trusted_proxies. Behind a proxy that is not listed, every visitor
 * looks like the proxy.
 *
 * MaxMind knows where an address is, not what its user reads: which language
 * a country gets is still lang/countries.php.
 *
 * Uses maxmind-db/reader, a regular dependency; still, nothing loads this
 * class unless localization.maxmind_database is set.
 */
final class MaxMindCountryResolver implements CountryResolver
{
    /** Database types with a country in every record. ASN, ISP and the like have none. */
    private const COUNTRY_TYPES = '/Country|City|Enterprise/';

    private ?Reader $reader = null;

    public function __construct(private readonly string $database) {}

    public function database(): string
    {
        return $this->database;
    }

    public function country(Request $request): ?string
    {
        // Parsed, so ::ffff:a.b.c.d from a dual-stack socket is looked up as
        // the IPv4 address it is; and a private or reserved address is in no
        // country's allocation, so the database is not opened for one.
        $ip = $request->ipAddress();

        if ($ip === null || !$ip->isPublic()) {
            return null;
        }

        try {
            $record = $this->reader()->get($ip->toString());
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (!\is_array($record)) {
            return null;
        }

        // Where the visitor is, and only when MaxMind does not know that,
        // where the address block is registered.
        return Locale::country(self::isoCode($record, 'country') ?? self::isoCode($record, 'registered_country'));
    }

    /** @throws LocalizationException when the reader is not installed or the file is not a country database */
    private function reader(): Reader
    {
        if ($this->reader !== null) {
            return $this->reader;
        }

        if (!\class_exists(Reader::class)) {
            throw LocalizationException::maxMindUnavailable();
        }

        if (!\is_file($this->database) || !\is_readable($this->database)) {
            throw LocalizationException::maxMindDatabase($this->database, 'does not exist or cannot be read');
        }

        try {
            $reader = new Reader($this->database);
        } catch (\Throwable $e) {
            throw LocalizationException::maxMindDatabase($this->database, 'is not a MaxMind database: ' . $e->getMessage());
        }

        $type = $reader->metadata()->databaseType;

        if (\preg_match(self::COUNTRY_TYPES, $type) !== 1) {
            $reader->close();

            throw LocalizationException::maxMindDatabase($this->database, \sprintf('is a %s database, which has no countries; use GeoLite2-Country or GeoLite2-City', $type));
        }

        return $this->reader = $reader;
    }

    /** @param array<mixed> $record */
    private static function isoCode(array $record, string $field): ?string
    {
        $section = $record[$field] ?? null;
        $code = \is_array($section) ? ($section['iso_code'] ?? null) : null;

        return \is_string($code) ? $code : null;
    }
}
