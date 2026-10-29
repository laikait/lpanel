<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Engine\Localization\LocalizationException;
use App\Engine\Localization\TranslationCatalog;

final class TranslationCatalogTest extends LocalizationTestCase
{
    public function test_the_files_are_the_list_of_locales(): void
    {
        $catalog = new TranslationCatalog($this->directory([
            'en.php' => [],
            'bn.php' => [],
            'pt-BR.php' => [],
            'countries.php' => ['BD' => 'bn'],
            'pt_PT.php' => [],
            'notes.txt' => 'not a locale',
            'en.php.bak' => [],
        ]));

        self::assertSame(['bn', 'en', 'pt-BR'], $catalog->locales());
        self::assertTrue($catalog->hasLocale('bn'));
        self::assertFalse($catalog->hasLocale('countries'));
    }

    public function test_a_missing_directory_has_no_locales(): void
    {
        self::assertSame([], (new TranslationCatalog(\sys_get_temp_dir() . '/no-such-lang'))->locales());
        self::assertSame([], (new TranslationCatalog())->locales());
    }

    public function test_a_file_is_only_ever_one_found_on_disk(): void
    {
        $directory = $this->directory(['en.php' => []]);
        $catalog = new TranslationCatalog($directory);

        self::assertSame($directory . '/en.php', $catalog->file(TranslationCatalog::ROOT, 'en'));
        self::assertNull($catalog->file(TranslationCatalog::ROOT, 'bn'));
        self::assertNull($catalog->file(TranslationCatalog::ROOT, '../en'));
        self::assertNull($catalog->file(TranslationCatalog::ROOT, 'en/../../config'));
        self::assertNull($catalog->file('Missing', 'en'));
    }

    public function test_keys_split_on_registered_namespaces_only(): void
    {
        $catalog = new TranslationCatalog($this->directory([]));
        $catalog->add('shared', $this->directory([]));
        $catalog->add('Billing', $this->directory([]));

        self::assertSame(['Billing', 'invoice_created'], $catalog->split('Billing.invoice_created'));
        self::assertSame(['Billing', 'invoice.created'], $catalog->split('Billing.invoice.created'));
        self::assertSame(['shared', 'welcome'], $catalog->split('shared.welcome'));
        self::assertSame(['', 'updated'], $catalog->split('updated'));
        self::assertSame(['', 'Removed.key'], $catalog->split('Removed.key'));
        self::assertSame(['', 'errors.not_found'], $catalog->split('errors.not_found'));
        self::assertSame(['', '.updated'], $catalog->split('.updated'));
        self::assertSame(['Billing', 'shared'], $catalog->namespaces());
    }

    public function test_a_namespace_is_registered_once(): void
    {
        $catalog = new TranslationCatalog();
        $catalog->add('Billing', $this->directory([]));

        $this->expectException(LocalizationException::class);
        $this->expectExceptionMessage('"Billing" is registered twice');

        $catalog->add('Billing', $this->directory([]));
    }

    public function test_the_root_cannot_be_registered_as_a_module(): void
    {
        $this->expectException(LocalizationException::class);

        (new TranslationCatalog())->add(TranslationCatalog::ROOT, $this->directory([]));
    }
}
