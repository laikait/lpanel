<?php

declare(strict_types=1);

namespace App\Engine\Localization;

use App\Engine\Error\FrameworkException;

final class LocalizationException extends FrameworkException
{
    public static function invalidFile(string $file, string $reason): self
    {
        return new self(\sprintf(
            'The translation file %s %s. A translation file returns a flat array of strings: '
            . "return ['updated' => 'Updated'];",
            $file,
            $reason,
        ));
    }

    public static function invalidCountryMap(string $file, string $reason): self
    {
        return new self(\sprintf(
            'The country map %s %s. It returns country codes mapped to locales: '
            . "return ['BD' => 'bn'];",
            $file,
            $reason,
        ));
    }

    public static function duplicateNamespace(string $namespace): self
    {
        return new self(\sprintf(
            'The translation namespace "%s" is registered twice. Each module owns exactly one.',
            $namespace,
        ));
    }

    /** @param list<string> $available */
    public static function unavailable(string $locale, array $available): self
    {
        return new self(\sprintf(
            'The locale "%s" is not available. Available: %s. A locale is available when lang/<locale>.php exists.',
            $locale,
            $available === [] ? '(none)' : \implode(', ', $available),
        ));
    }

    public static function maxMindUnavailable(): self
    {
        return new self(
            'localization.maxmind_database is set, but the MaxMind reader is not installed. '
            . 'Run: composer require maxmind-db/reader',
        );
    }

    public static function maxMindDatabase(string $file, string $reason): self
    {
        return new self(\sprintf('The MaxMind database %s %s.', $file, $reason));
    }
}
