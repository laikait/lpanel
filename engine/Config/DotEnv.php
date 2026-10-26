<?php

declare(strict_types=1);

namespace App\Engine\Config;

/**
 * A .env file, for the machine that has no real environment to speak of.
 *
 * This exists because a developer's laptop and a CI container do not have
 * systemd units or an orchestrator to hand variables to the process. It is not
 * how production should be configured, and the specification is explicit that
 * .env must not be the only source: config/*.php files are the main one, this
 * fills in the variables those files read, and a real environment beats both.
 *
 * Two rules follow from that, and both matter:
 *
 * The real environment always wins. A value already present is never replaced,
 * so a .env file left behind on a server cannot quietly override what the
 * deployment set. That is the opposite of the usual "last loader wins" and it
 * is the reason this is safe to load unconditionally.
 *
 * Values are written to $_ENV only. putenv() is documented as not thread-safe,
 * this is a ZTS build of PHP, and a superglobal write costs nothing -- Env
 * reads $_ENV first precisely so this works.
 *
 * There is no variable interpolation. "${OTHER}" stays the six characters it
 * looks like. Interpolation turns a flat list of settings into a small
 * programming language with an evaluation order, and the first question it
 * raises -- does it see the real environment, the file, or both -- has no good
 * answer.
 */
final class DotEnv
{
    public const FILE = '.env';

    /**
     * What a test run reads instead of FILE.
     *
     * A developer's .env names their database and turns debug on, and a test
     * suite that picked those up would pass or fail depending on whose laptop
     * it ran on. When the real environment says APP_ENV=testing -- phpunit.xml
     * does -- this file is read instead, and a missing one means nothing is.
     */
    public const TESTING_FILE = '.env.testing';

    /** The file a process should read: FILE, or TESTING_FILE under test. */
    public static function fileFor(?string $environment): string
    {
        return $environment === 'testing' ? self::TESTING_FILE : self::FILE;
    }

    /**
     * Load a file if it is there, and report which variables it supplied.
     *
     * A missing file is not an error: most deployments should not have one.
     *
     * @return list<string> the names that were actually applied
     */
    public static function load(string $file): array
    {
        if (!\is_file($file) || !\is_readable($file)) {
            return [];
        }

        $contents = \file_get_contents($file);

        if ($contents === false) {
            return [];
        }

        $applied = [];

        foreach (self::parse($contents, $file) as $name => $value) {
            // Present already, from a real environment variable or an earlier
            // load. The environment wins; this file is a fallback.
            if (Env::has($name)) {
                continue;
            }

            $_ENV[$name] = $value;
            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $contents, string $file = self::FILE): array
    {
        $values = [];
        $number = 0;

        foreach (\preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            ++$number;
            $line = \trim($line);

            if ($line === '' || \str_starts_with($line, '#')) {
                continue;
            }

            // "export FOO=bar" is what people paste out of a shell session.
            if (\str_starts_with($line, 'export ')) {
                $line = \ltrim(\substr($line, 7));
            }

            $split = \strpos($line, '=');

            if ($split === false) {
                throw ConfigurationException::malformedEnvLine($file, $number);
            }

            $name = \rtrim(\substr($line, 0, $split));

            if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                throw ConfigurationException::malformedEnvLine($file, $number);
            }

            $values[$name] = self::value(\ltrim(\substr($line, $split + 1)));
        }

        return $values;
    }

    /**
     * Unquote one value.
     *
     * Single quotes are literal. Double quotes understand the four escapes a
     * line-oriented format needs, and no more -- \n exists so a multi-line
     * value can be written at all.
     */
    private static function value(string $raw): string
    {
        if (\strlen($raw) >= 2) {
            $quote = $raw[0];

            if (($quote === '"' || $quote === "'") && \str_ends_with($raw, $quote)) {
                $inner = \substr($raw, 1, -1);

                return $quote === "'" ? $inner : \strtr($inner, [
                    '\\n' => "\n",
                    '\\t' => "\t",
                    '\\"' => '"',
                    '\\\\' => '\\',
                ]);
            }
        }

        // Unquoted: a trailing comment needs whitespace in front of the #, so
        // that a value which is genuinely a fragment or a colour still works.
        $cut = \preg_replace('/\s+#.*$/', '', $raw);

        return \rtrim($cut ?? $raw);
    }
}
