<?php

declare(strict_types=1);

namespace App\Engine\Localization;

/**
 * Reads a translation file once and keeps the array.
 *
 * A page with forty labels on it asks for forty translations and loads one
 * file. The arrays are kept for the life of this object -- a request, a
 * command, a job -- and never written anywhere, so an edited file shows on the
 * next request without anything to clear.
 */
final class TranslationLoader
{
    /** The CLDR plural categories a plural message may name, besides "=N" for an exact count. */
    public const PLURAL_CATEGORIES = ['zero', 'one', 'two', 'few', 'many', 'other'];

    /** @var array<string, array<string, string|array<string, string>>> keyed by file */
    private array $loaded = [];

    /**
     * A message is a string, or -- when it depends on a count -- an array of
     * plural forms: ['one' => ':count item', 'other' => ':count items'], with
     * "other" required and "=0", "=1"... allowed for an exact count.
     *
     * @return array<string, string|array<string, string>>
     *
     * @throws LocalizationException when the file is missing or a message is neither
     */
    public function load(string $file): array
    {
        return $this->loaded[$file] ??= self::read($file);
    }

    public function isLoaded(string $file): bool
    {
        return isset($this->loaded[$file]);
    }

    public function flush(): void
    {
        $this->loaded = [];
    }

    /** @return array<string, string|array<string, string>> */
    private static function read(string $file): array
    {
        if (!\is_file($file)) {
            throw LocalizationException::invalidFile($file, 'does not exist');
        }

        // In a closure of its own, so the file sees no variables from here.
        $messages = (static fn(string $__file): mixed => require $__file)($file);

        if (!\is_array($messages)) {
            throw LocalizationException::invalidFile($file, \sprintf('returns %s instead of an array', \get_debug_type($messages)));
        }

        foreach ($messages as $key => $message) {
            if (!\is_string($key)) {
                throw LocalizationException::invalidFile($file, \sprintf('has the non-string key %s', \var_export($key, true)));
            }

            if (\is_array($message)) {
                self::checkPlural($file, $key, $message);

                continue;
            }

            if (!\is_string($message)) {
                throw LocalizationException::invalidFile($file, \sprintf('maps "%s" to %s instead of a string', $key, \get_debug_type($message)));
            }
        }

        /** @var array<string, string|array<string, string>> $messages */
        return $messages;
    }

    /** @param array<array-key, mixed> $forms */
    private static function checkPlural(string $file, string $key, array $forms): void
    {
        if (!isset($forms['other']) || !\is_string($forms['other'])) {
            throw LocalizationException::invalidFile($file, \sprintf('gives "%s" plural forms without "other", which every language needs', $key));
        }

        foreach ($forms as $form => $text) {
            $known = \in_array($form, self::PLURAL_CATEGORIES, true) || (\is_string($form) && \preg_match('/^=\d+$/D', $form) === 1);

            if (!$known || !\is_string($text)) {
                throw LocalizationException::invalidFile($file, \sprintf(
                    'gives "%s" the plural form "%s"; forms are %s, or "=N" for an exact count, each a string',
                    $key,
                    (string) $form,
                    \implode(', ', self::PLURAL_CATEGORIES),
                ));
            }
        }
    }
}
