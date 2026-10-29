<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Engine\Localization\LocalizationException;
use App\Engine\Localization\TranslationLoader;

final class TranslationLoaderTest extends LocalizationTestCase
{
    public function test_a_file_returning_an_array_of_strings_is_loaded(): void
    {
        $directory = $this->directory(['en.php' => ['updated' => 'Updated']]);

        self::assertSame(['updated' => 'Updated'], (new TranslationLoader())->load($directory . '/en.php'));
    }

    public function test_an_empty_file_is_an_empty_array(): void
    {
        $directory = $this->directory(['en.php' => []]);

        self::assertSame([], (new TranslationLoader())->load($directory . '/en.php'));
    }

    public function test_utf8_is_kept_as_it_is(): void
    {
        $directory = $this->directory(['bn.php' => ['updated' => 'আপডেট করা হয়েছে']]);

        self::assertSame('আপডেট করা হয়েছে', (new TranslationLoader())->load($directory . '/bn.php')['updated']);
    }

    public function test_a_file_is_read_once(): void
    {
        $directory = $this->directory(['en.php' => ['updated' => 'Updated']]);
        $loader = new TranslationLoader();

        $loader->load($directory . '/en.php');
        \file_put_contents($directory . '/en.php', "<?php return ['updated' => 'Changed'];");

        self::assertTrue($loader->isLoaded($directory . '/en.php'));
        self::assertSame('Updated', $loader->load($directory . '/en.php')['updated']);

        $loader->flush();

        self::assertSame('Changed', $loader->load($directory . '/en.php')['updated']);
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->expectException(LocalizationException::class);
        $this->expectExceptionMessage('does not exist');

        (new TranslationLoader())->load(\sys_get_temp_dir() . '/no-such-lang/en.php');
    }

    public function test_a_file_returning_something_else_is_refused(): void
    {
        $directory = $this->directory(['en.php' => "<?php return 'Updated';"]);

        $this->expectException(LocalizationException::class);
        $this->expectExceptionMessage('returns string instead of an array');

        (new TranslationLoader())->load($directory . '/en.php');
    }

    public function test_a_file_returning_nothing_is_refused(): void
    {
        $directory = $this->directory(['en.php' => '<?php // nothing']);

        $this->expectException(LocalizationException::class);
        $this->expectExceptionMessage('returns int instead of an array');

        (new TranslationLoader())->load($directory . '/en.php');
    }

    public function test_a_list_is_refused(): void
    {
        $directory = $this->directory(['en.php' => ['Updated']]);

        $this->expectException(LocalizationException::class);
        $this->expectExceptionMessage('non-string key 0');

        (new TranslationLoader())->load($directory . '/en.php');
    }

    public function test_a_nested_array_is_refused(): void
    {
        $directory = $this->directory(['en.php' => ['messages' => ['updated' => 'Updated']]]);

        $this->expectException(LocalizationException::class);
        $this->expectExceptionMessage('gives "messages" plural forms without "other"');

        (new TranslationLoader())->load($directory . '/en.php');
    }
}
