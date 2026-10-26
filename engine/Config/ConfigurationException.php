<?php

declare(strict_types=1);

namespace App\Engine\Config;

use App\Engine\Error\FrameworkException;

/**
 * Configuration could not be read, or was read and did not make sense.
 *
 * Every one of these is thrown during bootstrap, before a single line of
 * application code runs, and that is the point. A configuration mistake found
 * at boot is found by whoever just made it; the same mistake absorbed by a
 * defensive default is found six weeks later by somebody wondering why the
 * retention window never applied.
 *
 * The messages name the key, the file or the environment variable, because the
 * person reading one has a text editor open and needs to know where to look.
 */
final class ConfigurationException extends FrameworkException
{
    public static function wrongType(string $key, string $expected, mixed $actual): self
    {
        return new self(\sprintf(
            'Configuration key "%s" must be of type %s, %s given. Values are never coerced: '
            . 'the environment is the only place a setting arrives as text, and Env::%s() parses it there.',
            $key,
            $expected,
            \get_debug_type($actual),
            $expected,
        ));
    }

    /** The right type, and still not a usable value; $previous says what is wrong with it. */
    public static function unusableValue(string $key, string $expected, ?\Throwable $previous = null): self
    {
        return new self(\sprintf(
            'Configuration key "%s" is not usable. It must be %s.',
            $key,
            $expected,
        ), 0, $previous);
    }

    public static function notAListOfStrings(string $key): self
    {
        return new self(\sprintf(
            'Configuration key "%s" must be a list of strings.',
            $key,
        ));
    }

    public static function unreadableEnvironment(string $name, string $expected, string $value): self
    {
        return new self(\sprintf(
            'Environment variable %s must be %s. It is currently "%s".',
            $name,
            $expected,
            $value,
        ));
    }

    public static function fileReturnedNoArray(string $file, string $returned): self
    {
        return new self(\sprintf(
            'The configuration file %s must return an array; it returned %s.',
            $file,
            $returned,
        ));
    }

    public static function malformedEnvLine(string $file, int $line): self
    {
        return new self(\sprintf(
            'Line %d of %s is not KEY=value, and was not ignored quietly: '
            . 'a setting that silently fails to apply is worse than a boot that stops.',
            $line,
            $file,
        ));
    }

    public static function notPlainData(string $key, string $type): self
    {
        return new self(\sprintf(
            'Configuration under "%s" holds %s, which cannot be cached. '
            . 'A cacheable configuration is plain data: scalars, null and arrays of them.',
            $key,
            $type,
        ));
    }

    public static function unknownTimezone(string $timezone): self
    {
        return new self(\sprintf(
            'app.timezone is set to "%s", which PHP does not recognise. '
            . 'Use an identifier from timezone_identifiers_list(), such as "UTC" or "Europe/London".',
            $timezone,
        ));
    }

    public static function invalidTimeLimit(int $seconds, string $reason): self
    {
        return new self(\sprintf(
            'app.max_execution_time (MAX_EXECUTION_TIME) is %d, which %s.',
            $seconds,
            $reason,
        ));
    }

    public static function invalidMemoryLimit(string $limit, string $reason): self
    {
        return new self(\sprintf(
            'app.memory_limit (MEMORY_LIMIT) is "%s", which %s.',
            $limit,
            $reason,
        ));
    }
}
