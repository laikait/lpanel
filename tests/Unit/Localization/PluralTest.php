<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Engine\Localization\LocaleResolver;
use App\Engine\Localization\Localization;
use App\Engine\Localization\LocalizationException;
use App\Engine\Localization\TranslationCatalog;
use App\Engine\Localization\TranslationLoader;
use PHPUnit\Framework\Attributes\DataProvider;

final class PluralTest extends LocalizationTestCase
{
    private function localization(string $locale): Localization
    {
        $catalog = new TranslationCatalog($this->directory([
            'en.php' => [
                'cart' => ['=0' => 'Your cart is empty', 'one' => ':count item in your cart', 'other' => ':count items in your cart'],
                'files' => ['one' => ':count file', 'other' => ':count files'],
                'dogs' => ['one' => ':count dog', 'other' => ':count dogs'],
            ],
            'bn.php' => [
                'files' => ['one' => ':countটি ফাইল', 'other' => ':countটি ফাইলসমূহ'],
            ],
            'ar.php' => [
                'files' => ['zero' => 'zero', 'one' => 'one', 'two' => 'two', 'few' => 'few', 'many' => 'many', 'other' => 'other'],
            ],
            'ru.php' => [
                'files' => ['one' => ':count файл', 'few' => ':count файла', 'many' => ':count файлов', 'other' => ':count файла'],
            ],
        ]));

        $localization = new Localization($catalog, new LocaleResolver($catalog), new TranslationLoader());
        $localization->setLocale($locale);

        return $localization;
    }

    /** @return array<string, array{string, int|float|string, string}> */
    public static function counts(): array
    {
        return [
            'en 1' => ['en', 1, '1 file'],
            'en 0' => ['en', 0, '0 files'],
            'en 2' => ['en', 2, '2 files'],
            'en 1.5' => ['en', 1.5, '1.5 files'],
            'en "3" as a string' => ['en', '3', '3 files'],
            'bn 0 is one' => ['bn', 0, '0টি ফাইল'],
            'bn 2' => ['bn', 2, '2টি ফাইলসমূহ'],
            'ru 1' => ['ru', 1, '1 файл'],
            'ru 3' => ['ru', 3, '3 файла'],
            'ru 5' => ['ru', 5, '5 файлов'],
            'ru 21' => ['ru', 21, '21 файл'],
            'ar 0' => ['ar', 0, 'zero'],
            'ar 2' => ['ar', 2, 'two'],
            'ar 3' => ['ar', 3, 'few'],
            'ar 11' => ['ar', 11, 'many'],
            'ar 100' => ['ar', 100, 'other'],
        ];
    }

    #[DataProvider('counts')]
    public function test_the_form_follows_the_language(string $locale, int|float|string $count, string $expected): void
    {
        self::assertSame($expected, $this->localization($locale)->get('files', ['count' => $count]));
    }

    public function test_an_exact_count_comes_first(): void
    {
        $localization = $this->localization('en');

        self::assertSame('Your cart is empty', $localization->get('cart', ['count' => 0]));
        self::assertSame('1 item in your cart', $localization->get('cart', ['count' => 1]));
        self::assertSame('4 items in your cart', $localization->get('cart', ['count' => 4]));
    }

    /** A fallback message is pluralised by the rules of the language it is written in. */
    public function test_a_fallback_uses_its_own_language_rules(): void
    {
        // Bengali counts 0 as "one"; the message is English, where 0 is "other".
        self::assertSame('one', Localization::pluralCategory(0, 'bn'));
        self::assertSame('0 dogs', $this->localization('bn')->get('dogs', ['count' => 0]));
    }

    public function test_without_a_count_other_is_used(): void
    {
        self::assertSame(':count files', $this->localization('en')->get('files'));
        self::assertSame('x files', $this->localization('en')->get('files', ['count' => 'x']));
    }

    public function test_plural_forms_without_other_are_refused(): void
    {
        $catalog = new TranslationCatalog($this->directory(['en.php' => ['files' => ['one' => 'one file']]]));

        $this->expectException(LocalizationException::class);
        (new Localization($catalog, new LocaleResolver($catalog)))->get('files', ['count' => 1]);
    }

    public function test_an_unknown_form_is_refused(): void
    {
        $catalog = new TranslationCatalog($this->directory(['en.php' => ['files' => ['other' => 'files', 'several' => 'x']]]));

        $this->expectException(LocalizationException::class);
        (new Localization($catalog, new LocaleResolver($catalog)))->get('files');
    }

    public function test_the_category_helper(): void
    {
        self::assertSame('one', Localization::pluralCategory(1, 'en'));
        self::assertSame('few', Localization::pluralCategory(3, 'ar'));
    }
}
