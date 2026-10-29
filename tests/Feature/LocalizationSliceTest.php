<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Core\HttpKernel;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Cookie;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Localization\ChainCountryResolver;
use App\Engine\Localization\CountryResolver;
use App\Engine\Localization\HeaderCountryResolver;
use App\Engine\Localization\Localization;
use App\Engine\Localization\MaxMindCountryResolver;
use App\Engine\Localization\NullCountryResolver;
use App\Engine\Localization\TranslationCatalog;
use App\Engine\Template\TemplateManager;
use App\Tests\Support\TestCase;

/**
 * Localization through the real application: the shipped lang/, a module with
 * its own lang/, real Twig and PHP templates, real requests.
 */
final class LocalizationSliceTest extends TestCase
{
    private const PLUGINS = 'tests/Fixtures/Modules/Localization/Plugins';

    /** @param array<string, mixed> $config */
    private function booted(array $config = []): Application
    {
        $config['modules']['paths'] ??= ['modules/Shared', self::PLUGINS];

        return $this->shippedApplication($config)->boot();
    }

    /** @param array<string, mixed> $options */
    private function get(string $uri, array $options = [], ?Application $application = null): Response
    {
        $kernel = ($application ?? $this->booted())->container()->get(HttpKernel::class);
        self::assertInstanceOf(HttpKernel::class, $kernel);

        return $kernel->handle(Request::create('GET', $uri, $options));
    }

    // ---- registration -----------------------------------------------------

    public function test_a_module_with_a_lang_directory_is_registered_by_having_it(): void
    {
        $catalog = $this->booted()->container()->get(TranslationCatalog::class);

        self::assertSame(['Billing'], $catalog->namespaces());
        self::assertSame(['bn', 'en'], $catalog->locales('Billing'));
        self::assertSame(['bn', 'en'], $catalog->locales(), 'the shipped lang/');
    }

    public function test_a_disabled_module_takes_its_translations_with_it(): void
    {
        $application = $this->booted(['modules' => ['disabled' => ['Billing']]]);

        self::assertSame([], $application->container()->get(TranslationCatalog::class)->namespaces());
        self::assertSame(
            'Billing.invoice_created',
            $application->container()->get(Localization::class)->get('Billing.invoice_created'),
        );
    }

    // ---- templates --------------------------------------------------------

    public function test_the_local_filter_translates_through_the_template_manager(): void
    {
        $html = $this->booted()->container()->get(TemplateManager::class)->render('@Billing/invoice', ['user' => 'Some User']);

        self::assertStringContainsString('<h1>Invoice created</h1>', $html);
        self::assertStringContainsString('<p>Invoice for Some User</p>', $html);
        self::assertStringContainsString('<p>Some User updated successfully</p>', $html);
        self::assertStringContainsString('<p>Updated</p>', $html);
        self::assertStringContainsString('<p>Billing.no_such_key</p>', $html, 'a missing key shows as itself');
    }

    public function test_the_local_filter_output_is_escaped(): void
    {
        $html = $this->booted()->container()->get(TemplateManager::class)->render('@Billing/invoice', ['user' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; updated successfully', $html);
    }

    public function test_the_local_filter_works_from_the_compilation_cache(): void
    {
        $first = $this->booted(['templates' => ['cache' => true]])->container()->get(TemplateManager::class);
        $second = $this->booted(['templates' => ['cache' => true]])->container()->get(TemplateManager::class);

        self::assertStringContainsString('<h1>Invoice created</h1>', $first->render('@Billing/invoice', ['user' => 'A']));
        self::assertStringContainsString('<h1>Invoice created</h1>', $second->render('@Billing/invoice', ['user' => 'A']));
    }

    public function test_a_php_template_translates_through_the_view(): void
    {
        $html = $this->get('/receipt', ['cookies' => ['language' => 'bn']])->body();

        self::assertStringContainsString('<h1>ইনভয়েস তৈরি হয়েছে</h1>', $html);
        self::assertStringContainsString('&lt;b&gt;Ann&lt;/b&gt; সফলভাবে আপডেট হয়েছে', $html);
    }

    // ---- requests ---------------------------------------------------------

    public function test_the_language_cookie_chooses_the_language(): void
    {
        $html = $this->get('/invoice', ['cookies' => ['language' => 'bn']])->body();

        self::assertStringContainsString('<h1>ইনভয়েস তৈরি হয়েছে</h1>', $html);
        self::assertStringContainsString('<p>Some User সফলভাবে আপডেট হয়েছে</p>', $html);
    }

    public function test_the_browser_language_is_used_without_a_cookie(): void
    {
        $html = $this->get('/invoice', ['headers' => ['Accept-Language' => 'bn-BD,bn;q=0.9,en;q=0.8']])->body();

        self::assertStringContainsString('<h1>ইনভয়েস তৈরি হয়েছে</h1>', $html);
    }

    public function test_an_unavailable_browser_language_is_english(): void
    {
        $html = $this->get('/invoice', ['headers' => ['Accept-Language' => 'fr-FR']])->body();

        self::assertStringContainsString('<h1>Invoice created</h1>', $html);
    }

    public function test_a_trusted_country_header_beats_the_browser(): void
    {
        $options = [
            'headers' => ['Accept-Language' => 'en-US,en;q=0.9', 'CF-IPCountry' => 'BD'],
            'server' => ['REMOTE_ADDR' => '10.0.0.1'],
            'trustedProxies' => ['10.0.0.1'],
        ];

        $application = $this->booted(['localization' => ['country_header' => 'CF-IPCountry']]);

        self::assertInstanceOf(HeaderCountryResolver::class, $application->container()->get(CountryResolver::class));
        self::assertStringContainsString('<h1>ইনভয়েস তৈরি হয়েছে</h1>', $this->get('/invoice', $options, $application)->body());
    }

    public function test_an_untrusted_country_header_is_ignored(): void
    {
        $application = $this->booted(['localization' => ['country_header' => 'CF-IPCountry']]);
        $response = $this->get('/invoice', [
            'headers' => ['Accept-Language' => 'en', 'CF-IPCountry' => 'BD'],
            'server' => ['REMOTE_ADDR' => '203.0.113.9'],
        ], $application);

        self::assertStringContainsString('<h1>Invoice created</h1>', $response->body());
    }

    public function test_a_maxmind_database_chooses_by_the_visitors_address(): void
    {
        $application = $this->booted(['localization' => ['maxmind_database' => 'tests/Fixtures/MaxMind/GeoLite2-City-Test.mmdb']]);

        self::assertInstanceOf(MaxMindCountryResolver::class, $application->container()->get(CountryResolver::class));

        // 81.2.69.160 is GB in MaxMind's test data, and lang/countries.php
        // maps GB to en -- which outranks a browser asking for Bengali.
        $fromGb = ['headers' => ['Accept-Language' => 'bn'], 'server' => ['REMOTE_ADDR' => '81.2.69.160']];
        $unknown = ['headers' => ['Accept-Language' => 'bn'], 'server' => ['REMOTE_ADDR' => '127.0.0.1']];

        self::assertStringContainsString('<h1>Invoice created</h1>', $this->get('/invoice', $fromGb, $application)->body());
        self::assertStringContainsString('<h1>ইনভয়েস তৈরি হয়েছে</h1>', $this->get('/invoice', $unknown, $application)->body(), 'no country, so the browser decides');
    }

    public function test_the_header_and_the_database_are_chained_header_first(): void
    {
        $resolver = $this->booted(['localization' => [
            'country_header' => 'CF-IPCountry',
            'maxmind_database' => 'tests/Fixtures/MaxMind/GeoLite2-Country-Test.mmdb',
        ]])->container()->get(CountryResolver::class);

        self::assertInstanceOf(ChainCountryResolver::class, $resolver);
        self::assertInstanceOf(HeaderCountryResolver::class, $resolver->resolvers()[0]);
        self::assertInstanceOf(MaxMindCountryResolver::class, $resolver->resolvers()[1]);
        self::assertSame($this->basePath('tests/Fixtures/MaxMind/GeoLite2-Country-Test.mmdb'), $resolver->resolvers()[1]->database());
    }

    public function test_country_detection_is_off_by_default(): void
    {
        self::assertInstanceOf(NullCountryResolver::class, $this->booted()->container()->get(CountryResolver::class));
    }

    public function test_each_request_is_resolved_on_its_own(): void
    {
        $application = $this->booted();

        self::assertStringContainsString('ইনভয়েস', $this->get('/invoice', ['cookies' => ['language' => 'bn']], $application)->body());
        self::assertStringContainsString('Invoice created', $this->get('/invoice', ['cookies' => ['language' => 'en']], $application)->body());
    }

    public function test_the_language_switch_sets_the_cookie_the_next_request_reads(): void
    {
        $application = $this->booted();
        $response = $this->get('/language/bn', [], $application);
        $cookies = \array_values(\array_filter($response->cookies(), static fn(Cookie $cookie): bool => $cookie->name === 'language'));

        self::assertSame(302, $response->status());
        self::assertCount(1, $cookies);
        self::assertSame('bn', $cookies[0]->value);
        self::assertTrue($cookies[0]->httpOnly);
        self::assertSame('Lax', $cookies[0]->sameSite);

        $next = $this->get('/invoice', ['cookies' => ['language' => $cookies[0]->value]], $application);

        self::assertStringContainsString('<h1>ইনভয়েস তৈরি হয়েছে</h1>', $next->body());
        self::assertSame(404, $this->get('/language/xx', [], $application)->status());
    }

    // ---- caching ----------------------------------------------------------

    public function test_a_localized_response_varies_on_what_chose_its_language(): void
    {
        self::assertSame('Cookie, Accept-Language', $this->get('/invoice')->header('Vary'));

        $application = $this->booted(['localization' => ['country_header' => 'CF-IPCountry']]);

        self::assertSame('Cookie, Accept-Language, CF-IPCountry', $this->get('/invoice', [], $application)->header('Vary'));
    }

    public function test_a_response_that_translated_nothing_does_not_vary(): void
    {
        self::assertNull($this->get('/untranslated')->header('Vary'));
    }

    // ---- outside HTTP -----------------------------------------------------

    public function test_without_a_request_the_language_is_english(): void
    {
        $localization = $this->booted()->container()->get(Localization::class);

        self::assertSame('en', $localization->locale());
        self::assertSame('Updated', $localization->get('updated'));

        $localization->setLocale('bn');

        self::assertSame('আপডেট করা হয়েছে', $localization->get('updated'));
    }

    public function test_each_job_starts_in_english(): void
    {
        $application = $this->booted();
        $localization = $application->container()->get(Localization::class);
        $localization->setLocale('bn');

        $application->container()->get(HookEngine::class)->do('job.started', null, null);

        self::assertSame('en', $localization->locale());
    }
}
