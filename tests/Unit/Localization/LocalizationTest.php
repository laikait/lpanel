<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Engine\Http\Request;
use App\Engine\Localization\LocaleResolver;
use App\Engine\Localization\Localization;
use App\Engine\Localization\LocalizationException;
use App\Engine\Localization\TranslationCatalog;
use App\Engine\Localization\TranslationLoader;
use App\Engine\Logging\Logger;
use App\Engine\Logging\LogManager;
use App\Engine\Logging\LogRecord;
use App\Engine\Logging\LogWriter;

final class LocalizationTest extends LocalizationTestCase
{
    private TranslationCatalog $catalog;

    private TranslationLoader $loader;

    protected function setUp(): void
    {
        $this->catalog = new TranslationCatalog($this->directory([
            'en.php' => [
                'updated' => 'Updated',
                'save' => 'Save',
                'user_update_success' => ':user updated successfully',
                'greeting' => 'Hello :name, you are :username',
                'moved' => ':user moved :count items to :place',
            ],
            'bn.php' => [
                'updated' => 'আপডেট করা হয়েছে',
                'user_update_success' => ':user সফলভাবে আপডেট হয়েছে',
            ],
            'bn-BD.php' => [
                'save' => 'সেভ',
            ],
            'admin/en.php' => ['dashboard' => 'Dashboard', 'save' => 'Save changes'],
            'admin/bn.php' => ['dashboard' => 'ড্যাশবোর্ড'],
        ]));

        $this->catalog->add('Billing', $this->directory([
            'en.php' => ['invoice_created' => 'Invoice created', 'only_english' => 'Only English'],
            'bn.php' => ['invoice_created' => 'ইনভয়েস তৈরি হয়েছে'],
        ]));

        $this->loader = new TranslationLoader();
    }

    private function localization(?Request $request = null, ?Logger $log = null): Localization
    {
        return new Localization(
            $this->catalog,
            new LocaleResolver($this->catalog),
            $this->loader,
            $request,
            $log,
        );
    }

    // ---- lookup -----------------------------------------------------------

    public function test_a_root_key_is_translated(): void
    {
        $localization = $this->localization();

        self::assertSame('Updated', $localization->get('updated'));

        $localization->setLocale('bn');

        self::assertSame('আপডেট করা হয়েছে', $localization->get('updated'));
    }

    public function test_a_missing_key_comes_back_as_the_key(): void
    {
        $localization = $this->localization();

        self::assertSame('no_such_key', $localization->get('no_such_key'));
        self::assertFalse($localization->has('no_such_key'));
        self::assertTrue($localization->has('updated'));
    }

    public function test_a_module_key_is_translated_from_the_module(): void
    {
        $localization = $this->localization();

        self::assertSame('Invoice created', $localization->get('Billing.invoice_created'));

        $localization->setLocale('bn');

        self::assertSame('ইনভয়েস তৈরি হয়েছে', $localization->get('Billing.invoice_created'));
    }

    public function test_a_missing_module_key_comes_back_as_the_key(): void
    {
        self::assertSame('Billing.invoice_missing', $this->localization()->get('Billing.invoice_missing'));
    }

    public function test_a_module_key_is_not_found_in_the_root_or_another_module(): void
    {
        $localization = $this->localization();

        self::assertSame('Billing.updated', $localization->get('Billing.updated'));
        self::assertSame('Removed.invoice_created', $localization->get('Removed.invoice_created'));
    }

    public function test_a_locale_falls_back_through_its_less_specific_forms_then_english(): void
    {
        $localization = $this->localization();
        $localization->setLocale('bn-BD');

        self::assertSame('bn-BD', $localization->locale());
        self::assertSame('সেভ', $localization->get('save'), 'bn-BD itself');
        self::assertSame('আপডেট করা হয়েছে', $localization->get('updated'), 'from bn');
        self::assertSame('Hello :name, you are :username', $localization->get('greeting'), 'from en');
        self::assertSame('Only English', $localization->get('Billing.only_english'), 'modules fall back the same way');
    }

    public function test_only_the_active_locale_and_its_fallbacks_are_loaded(): void
    {
        $localization = $this->localization();
        $localization->setLocale('bn');
        $localization->get('updated');

        $root = (string) $this->catalog->directory();

        self::assertTrue($this->loader->isLoaded($root . '/bn.php'));
        self::assertFalse($this->loader->isLoaded($root . '/en.php'), 'found in bn, so en was never needed');
        self::assertFalse($this->loader->isLoaded($root . '/bn-BD.php'));
    }

    // ---- parameters -------------------------------------------------------

    public function test_a_parameter_is_replaced(): void
    {
        self::assertSame('Some User updated successfully', $this->localization()->get('user_update_success', ['user' => 'Some User']));
    }

    public function test_several_parameters_are_replaced(): void
    {
        self::assertSame(
            'Ann moved 3 items to Archive',
            $this->localization()->get('moved', ['user' => 'Ann', 'count' => 3, 'place' => 'Archive']),
        );
    }

    public function test_a_longer_placeholder_is_not_read_as_a_shorter_one(): void
    {
        self::assertSame('Hello Ann, you are ann42', $this->localization()->get('greeting', ['name' => 'Ann', 'username' => 'ann42']));
    }

    public function test_a_missing_parameter_leaves_the_placeholder_visible(): void
    {
        self::assertSame(':user updated successfully', $this->localization()->get('user_update_success'));
        self::assertSame(':user updated successfully', $this->localization()->get('user_update_success', ['other' => 'x']));
    }

    public function test_empty_numeric_and_utf8_values_are_replaced(): void
    {
        $localization = $this->localization();

        self::assertSame(' updated successfully', $localization->get('user_update_success', ['user' => '']));
        self::assertSame('1.5 updated successfully', $localization->get('user_update_success', ['user' => 1.5]));

        $localization->setLocale('bn');

        self::assertSame('রিয়াদ সফলভাবে আপডেট হয়েছে', $localization->get('user_update_success', ['user' => 'রিয়াদ']));
    }

    public function test_a_value_that_is_not_text_is_ignored(): void
    {
        self::assertSame(':user updated successfully', $this->localization()->get('user_update_success', ['user' => ['x']]));
    }

    public function test_parameters_apply_to_a_missing_key_too(): void
    {
        self::assertSame('missing Ann', $this->localization()->get('missing :user', ['user' => 'Ann']));
    }

    // ---- the locale -------------------------------------------------------

    public function test_the_locale_comes_from_the_request(): void
    {
        $localization = $this->localization(Request::create('GET', '/', ['cookies' => ['language' => 'bn']]));

        self::assertSame('bn', $localization->locale());
        self::assertTrue($localization->resolvedFromRequest());
        self::assertSame('আপডেট করা হয়েছে', $localization->get('updated'));
    }

    public function test_without_a_request_the_locale_is_english(): void
    {
        $localization = $this->localization();

        self::assertSame('en', $localization->locale());
        self::assertFalse($localization->resolvedFromRequest());
    }

    public function test_set_locale_overrides_the_request(): void
    {
        $localization = $this->localization(Request::create('GET', '/', ['cookies' => ['language' => 'bn']]));
        $localization->setLocale('en');

        self::assertSame('en', $localization->locale());
        self::assertFalse($localization->resolvedFromRequest());
        self::assertSame('Updated', $localization->get('updated'));
    }

    public function test_set_locale_refuses_an_unavailable_locale(): void
    {
        $this->expectException(LocalizationException::class);
        $this->expectExceptionMessage('"de" is not available. Available: bn, bn-BD, en.');

        $this->localization()->setLocale('de');
    }

    public function test_set_locale_can_name_a_sub_directory(): void
    {
        $localization = $this->localization();
        $localization->setLocale('admin/bn');

        self::assertSame('bn', $localization->locale());
        self::assertSame('admin', $localization->directory());
        self::assertSame('ড্যাশবোর্ড', $localization->get('dashboard'));
        // Missing from admin/bn.php: admin/en.php wins over the root's bn.php.
        self::assertSame('Save changes', $localization->get('save'));
        // Only in the root files.
        self::assertSame('আপডেট করা হয়েছে', $localization->get('updated'));
    }

    public function test_a_sub_directory_is_forgotten_by_reset_and_set_locale(): void
    {
        $localization = $this->localization();
        $localization->setLocale('admin/en');
        $localization->setLocale('en');

        self::assertNull($localization->directory());
        self::assertSame('dashboard', $localization->get('dashboard'));
    }

    public function test_set_locale_refuses_a_missing_sub_directory(): void
    {
        $this->expectException(LocalizationException::class);

        $this->localization()->setLocale('shop/bn');
    }

    public function test_set_locale_refuses_a_path(): void
    {
        $this->expectException(LocalizationException::class);

        $this->localization()->setLocale('../../config');
    }

    public function test_each_request_resolves_its_own_locale(): void
    {
        $localization = $this->localization();

        $localization->onRequest(Request::create('GET', '/', ['cookies' => ['language' => 'bn']]));
        self::assertSame('bn', $localization->locale());

        $localization->onRequest(Request::create('GET', '/', ['headers' => ['Accept-Language' => 'en']]));
        self::assertSame('en', $localization->locale());
    }

    public function test_reset_forgets_the_locale(): void
    {
        $localization = $this->localization();
        $localization->setLocale('bn');
        $localization->get('updated');

        $localization->reset();

        self::assertSame('en', $localization->locale());
        self::assertFalse($this->loader->isLoaded((string) $this->catalog->directory() . '/bn.php'));
    }

    public function test_the_available_locales_are_the_root_files(): void
    {
        self::assertSame(['bn', 'bn-BD', 'en'], $this->localization()->available());
    }

    // ---- diagnostics ------------------------------------------------------

    public function test_a_missing_key_is_reported_once_when_a_log_is_given(): void
    {
        $writer = new class implements LogWriter {
            /** @var list<LogRecord> */
            public array $records = [];

            public function describe(): string
            {
                return 'memory';
            }

            public function accepts(LogRecord $record): bool
            {
                return true;
            }

            public function write(LogRecord $record): void
            {
                $this->records[] = $record;
            }
        };

        $logs = new LogManager();
        $logs->add($writer);

        $localization = $this->localization(log: $logs->channel());
        $localization->get('no_such_key');
        $localization->get('no_such_key');
        $localization->get('updated');

        self::assertCount(1, $writer->records);
        self::assertSame('Missing translation', $writer->records[0]->message);
        self::assertSame('no_such_key', $writer->records[0]->context['key']);
    }
}
