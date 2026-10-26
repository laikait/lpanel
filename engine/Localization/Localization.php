<?php

declare(strict_types=1);

namespace App\Engine\Localization;

use App\Engine\Http\Request;
use App\Engine\Logging\Logger;

/**
 * Translations, in the locale of whatever is running now.
 *
 *     $localization->get('updated');                                  // Updated
 *     $localization->get('user_update_success', ['user' => 'Some User']);
 *     $localization->get('Billing.invoice_created');
 *
 * The locale is decided the first time something asks for it -- from the
 * request when there is one (see LocaleResolver), en when there is not -- and
 * then kept. A page that never translates anything never decides one.
 *
 * A key is looked up in the active locale, then in each less specific form of
 * it (bn-BD, then bn), then in en, and when all of those miss it comes back
 * unchanged. A missing translation is therefore visible on the page, never an
 * empty string, and one rule covers the application and every module alike.
 *
 * One instance serves one request, one command or one job: the framework
 * hands it each request as it arrives and resets it before each job, so a
 * locale chosen for one never leaks into the next.
 */
final class Localization
{
    private ?string $locale = null;

    private ?string $directory = null;

    private bool $resolvedFromRequest = false;

    /** @var list<string>|null */
    private ?array $chain = null;

    /** @var array<string, true> keys already reported missing */
    private array $reported = [];

    public function __construct(
        private readonly TranslationCatalog $catalog,
        private readonly LocaleResolver $resolver,
        private readonly TranslationLoader $loader = new TranslationLoader(),
        private ?Request $request = null,
        /** Missing keys are reported here, once each; null in production. */
        private readonly ?Logger $log = null,
    ) {}

    /** A new request: forget the last one's locale and resolve this one's when asked. */
    public function onRequest(Request $request): void
    {
        $this->reset();
        $this->request = $request;
    }

    /** Forget the locale and everything loaded, as before a job. */
    public function reset(): void
    {
        $this->request = null;
        $this->locale = null;
        $this->directory = null;
        $this->resolvedFromRequest = false;
        $this->chain = null;
        $this->reported = [];
        $this->loader->flush();
    }

    public function locale(): string
    {
        if ($this->locale === null) {
            $this->locale = $this->resolver->resolve($this->request);
            $this->resolvedFromRequest = $this->request !== null;
        }

        return $this->locale;
    }

    /**
     * Use $locale from now on, whatever the request asked for.
     *
     * For a command, a job, an email in the recipient's language, a test. It
     * writes no cookie: remembering a visitor's choice is the handler's job,
     * with a `language` cookie on its response.
     *
     * $locale may name a sub-directory of lang/: 'admin/bn' reads
     * lang/admin/bn.php first, then lang/admin/en.php, then the files of lang/
     * itself. locale() is still 'bn'. Only a caller can choose a directory --
     * a cookie or a header never can.
     *
     * @throws LocalizationException when there is no lang/ file for $locale
     */
    public function setLocale(string $locale): void
    {
        $directory = '';
        $slash = \strrpos($locale, '/');

        if ($slash !== false) {
            $directory = \substr($locale, 0, $slash);
            $locale = \substr($locale, $slash + 1);

            if (!$this->catalog->hasDirectory($directory)) {
                throw LocalizationException::unavailable($directory . '/' . $locale, $this->available());
            }
        }

        $matched = null;

        if ($directory !== '') {
            foreach (Locale::candidates($locale) as $candidate) {
                if ($this->catalog->hasLocale($candidate, TranslationCatalog::ROOT, $directory)) {
                    $matched = $candidate;
                    break;
                }
            }
        }

        $matched ??= $this->resolver->match($locale);

        if ($matched === null) {
            throw LocalizationException::unavailable($directory === '' ? $locale : $directory . '/' . $locale, $this->available());
        }

        $this->locale = $matched;
        $this->directory = $directory === '' ? null : $directory;
        $this->resolvedFromRequest = false;
        $this->chain = null;
    }

    /** The sub-directory of lang/ chosen with setLocale('<directory>/<locale>'), or null. */
    public function directory(): ?string
    {
        return $this->directory;
    }

    /** @return list<string> the application's locales: one per lang/<locale>.php */
    public function available(): array
    {
        return $this->catalog->locales();
    }

    /** Whether the locale was decided from request data, so a response must Vary on it. */
    public function resolvedFromRequest(): bool
    {
        return $this->resolvedFromRequest;
    }

    public function has(string $key): bool
    {
        return $this->lookup($key) !== null;
    }

    /**
     * The translation of $key, with each :name replaced by $parameters['name'].
     *
     * A placeholder with no parameter stays as it is, so the gap is visible.
     * The result is plain text: a template escapes it like any other value.
     *
     * **Plurals.** When the message is a set of plural forms, the `count`
     * parameter picks one by the rules of the language the message was found
     * in -- English has one and other, Bengali treats 0 as one, Arabic has six
     * -- with an exact "=N" form first, and "other" when the right form is
     * missing or there is no count.
     *
     * @param array<array-key, mixed> $parameters
     */
    public function get(string $key, array $parameters = []): string
    {
        $found = $this->lookup($key);

        if ($found === null) {
            $this->report($key);
            $message = $key;
        } else {
            [$message, $locale] = $found;

            if (\is_array($message)) {
                $message = self::plural($message, $parameters['count'] ?? null, $locale);
            }
        }

        return $parameters === [] ? $message : self::replace($message, $parameters);
    }

    /**
     * The CLDR category of $count in $locale: one, few, many... ICU knows the
     * rules but PHP exposes no class for them, so a plural pattern whose every
     * branch prints its own name is formatted, and its output is the answer.
     */
    public static function pluralCategory(int|float $count, string $locale): string
    {
        static $formatters = [];

        $formatter = $formatters[$locale] ??= \MessageFormatter::create(
            $locale,
            '{0,plural,zero{zero} one{one} two{two} few{few} many{many} other{other}}',
        );

        $category = $formatter instanceof \MessageFormatter ? $formatter->format([$count]) : false;

        return \is_string($category) && $category !== '' ? $category : 'other';
    }

    /** @param array<string, string> $forms */
    private static function plural(array $forms, mixed $count, string $locale): string
    {
        if (!\is_int($count) && !\is_float($count) && !(\is_string($count) && \is_numeric($count))) {
            return $forms['other'];
        }

        $number = \is_string($count) ? $count + 0 : $count;

        if (\is_int($number) && isset($forms['=' . $number])) {
            return $forms['=' . $number];
        }

        return $forms[self::pluralCategory($number, $locale)] ?? $forms['other'];
    }

    /** @return array{string|array<string, string>, string}|null the message and the locale it was found in */
    private function lookup(string $key): ?array
    {
        [$namespace, $name] = $this->catalog->split($key);

        // The chosen sub-directory first, then lang/ itself.
        $directories = $this->directory === null ? [''] : [$this->directory, ''];

        foreach ($directories as $directory) {
            foreach ($this->chain() as $locale) {
                $file = $this->catalog->file($namespace, $locale, $directory);

                if ($file === null) {
                    continue;
                }

                $message = $this->loader->load($file)[$name] ?? null;

                if ($message !== null) {
                    return [$message, $locale];
                }
            }
        }

        return null;
    }

    /** @return list<string> the locales a lookup tries, in order */
    private function chain(): array
    {
        return $this->chain ??= \array_values(\array_unique([
            ...Locale::candidates($this->locale()),
            LocaleResolver::FALLBACK,
        ]));
    }

    private function report(string $key): void
    {
        if ($this->log === null || isset($this->reported[$key])) {
            return;
        }

        $this->reported[$key] = true;
        $this->log->debug('Missing translation', ['key' => $key, 'locale' => $this->locale()]);
    }

    /** @param array<array-key, mixed> $parameters */
    private static function replace(string $message, array $parameters): string
    {
        $pairs = [];

        foreach ($parameters as $name => $value) {
            if (\is_scalar($value) || $value instanceof \Stringable) {
                $pairs[':' . $name] = (string) $value;
            }
        }

        // strtr() tries the longest placeholder first, so :username is never
        // read as :user followed by "name".
        return \strtr($message, $pairs);
    }
}
