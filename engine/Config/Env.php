<?php

declare(strict_types=1);

namespace App\Engine\Config;

/**
 * The one place the environment is read.
 *
 * Everything in the environment is text. A configuration value is not: a
 * retention window is an integer, debug is a boolean, and "false" is a string
 * that is true. Parsing that text is a real job with real edge cases, and doing
 * it in eighteen places is how two of them end up disagreeing about what "off"
 * means. So it happens here, once, with the conversions written down.
 *
 * That concentration buys something else. Cached configuration is a well-known
 * trap: a deployment builds the cache, the resolved values freeze into a file,
 * and a later change to an environment variable does nothing at all, silently.
 * Because every read goes through here, this class can keep a record of what it
 * was asked and what it answered -- which is exactly the fingerprint the cache
 * needs to notice that the environment has moved underneath it. See
 * ConfigCache.
 *
 * Reads consult $_ENV, then $_SERVER, then getenv(). All three exist because
 * PHP's variables_order decides which of the first two are populated, a .env
 * file populates $_ENV directly, and a real environment variable may only be
 * visible to getenv(). Nothing here writes: putenv() is documented as not
 * thread-safe, this is a ZTS build, and an architecture test forbids it.
 *
 * An empty string counts as absent. "APP_ENV=" in a deployment's environment is
 * a variable somebody meant to fill in, not a request for an empty environment
 * name.
 */
final class Env
{
    /**
     * What was asked for, and what was there at the time.
     *
     * @var array<string, string|null>
     */
    private static array $reads = [];

    /**
     * The raw text of a variable, or null when it is not set.
     *
     * Every other method here goes through this one, which is what makes the
     * read log complete.
     */
    public static function raw(string $name): ?string
    {
        $value = self::lookUp($name);

        self::$reads[$name] = $value;

        return $value;
    }

    public static function has(string $name): bool
    {
        return self::raw($name) !== null;
    }

    public static function string(string $name, ?string $default = null): ?string
    {
        return self::raw($name) ?? $default;
    }

    /**
     * A boolean, spelled the way people spell booleans.
     *
     * Anything outside the two lists is an error rather than a guess. "APP_DEBUG=maybe"
     * has no defensible reading, and choosing one for it means a production
     * deployment finds out which one was chosen by watching its own error pages.
     */
    public static function bool(string $name, ?bool $default = null): ?bool
    {
        $value = self::raw($name);

        if ($value === null) {
            return $default;
        }

        return match (\strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw ConfigurationException::unreadableEnvironment(
                $name,
                'true or false (also 1/0, yes/no, on/off)',
                $value,
            ),
        };
    }

    public static function int(string $name, ?int $default = null): ?int
    {
        $value = self::raw($name);

        if ($value === null) {
            return $default;
        }

        if (\preg_match('/^[+-]?\d+$/', $value) !== 1) {
            throw ConfigurationException::unreadableEnvironment($name, 'a whole number', $value);
        }

        return (int) $value;
    }

    /**
     * A comma-separated list, which is how a flat string map expresses several
     * of something.
     *
     * @param list<string> $default
     *
     * @return list<string>
     */
    public static function list(string $name, array $default = []): array
    {
        $value = self::raw($name);

        if ($value === null) {
            return $default;
        }

        return \array_values(\array_filter(
            \array_map('\trim', \explode(',', $value)),
            static fn(string $item): bool => $item !== '',
        ));
    }

    /**
     * Every variable read so far, in the order it was first asked for.
     *
     * @return array<string, string|null>
     */
    public static function reads(): array
    {
        return self::$reads;
    }

    /**
     * The names of the variables in $_ENV, without their values.
     *
     * For the debug error page, which masks every one of them. Names only, and
     * not logged as reads: nothing here decides configuration.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return \array_values(\array_map(\strval(...), \array_keys($_ENV)));
    }

    /**
     * Forget the read log.
     *
     * For tests and for the cache-building command, which wants a fingerprint
     * of the variables this configuration actually depends on rather than of
     * everything the process has ever looked at.
     */
    public static function forget(): void
    {
        self::$reads = [];
    }

    private static function lookUp(string $name): ?string
    {
        foreach ([$_ENV, $_SERVER] as $source) {
            if (\array_key_exists($name, $source) && \is_scalar($source[$name])) {
                $value = (string) $source[$name];

                return $value === '' ? null : $value;
            }
        }

        $value = \getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
