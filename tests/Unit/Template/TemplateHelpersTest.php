<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Engine\Asset\AssetManager;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetResolver;
use App\Engine\Asset\AssetVersioning;
use App\Engine\Template\PhpTemplateEngine;
use App\Engine\Template\TemplateException;
use App\Engine\Template\TemplateHelperCollector;
use App\Engine\Template\TemplateHelpers;
use App\Engine\Template\TemplateManager;
use App\Engine\Template\TemplateRegistry;
use App\Engine\Template\TemplateSource;
use App\Engine\Template\TwigTemplateEngine;
use App\Tests\Support\TestCase;

/**
 * Filters and functions modules offer to templates.
 */
final class TemplateHelpersTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/framework-helpers-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->root . '/*') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->root);

        parent::tearDown();
    }

    private function manager(TemplateHelpers $helpers): TemplateManager
    {
        $views = new TemplateRegistry();
        $views->add(null, $this->root, TemplateSource::OVERRIDE);

        $templates = new TemplateManager(
            $views,
            new AssetManager(new AssetRegistry(), new AssetResolver(), '', AssetVersioning::None),
            helpers: $helpers,
        );

        $templates->addEngine(new TwigTemplateEngine($views, helpers: $helpers));
        $templates->addEngine(new PhpTemplateEngine());

        return $templates;
    }

    private function write(string $name, string $contents): void
    {
        \file_put_contents($this->root . '/' . $name, $contents);
    }

    // ---- declaring --------------------------------------------------------

    public function test_the_collector_stamps_the_module_on_each_helper(): void
    {
        $helpers = new TemplateHelpers();

        (new TemplateHelperCollector($helpers, 'Billing'))
            ->filter('money', static fn(int $cents): string => (string) $cents)
            ->function('now', static fn(): string => 'now', safe: true);

        self::assertSame('Billing', $helpers->filters()['money']->module);
        self::assertFalse($helpers->filters()['money']->safe);
        self::assertSame('Billing', $helpers->functions()['now']->module);
        self::assertTrue($helpers->functions()['now']->safe);
    }

    /** @return iterable<string, array{string}> */
    public static function unspellableNames(): iterable
    {
        yield 'upper case' => ['Money'];
        yield 'a dot' => ['money.format'];
        yield 'a dash' => ['to-money'];
        yield 'a leading digit' => ['2fa'];
        yield 'empty' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unspellableNames')]
    public function test_a_name_both_engines_cannot_spell_is_refused(string $name): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('Module "Billing"');

        (new TemplateHelpers())->addFilter($name, static fn(): string => '', module: 'Billing');
    }

    public function test_the_engines_own_local_cannot_be_taken(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('already provides to every template');

        (new TemplateHelpers())->addFilter('local', static fn(): string => '', module: 'Billing');
    }

    /** Which one a template got would depend on module order, so the second is refused naming both. */
    public function test_two_modules_offering_one_name_are_both_named(): void
    {
        $helpers = new TemplateHelpers();
        $helpers->addFilter('money', static fn(): string => '', module: 'Billing');

        try {
            $helpers->addFilter('money', static fn(): string => '', module: 'Payments');
            self::fail('a duplicate was accepted');
        } catch (TemplateException $e) {
            self::assertStringContainsString('"Billing"', $e->getMessage());
            self::assertStringContainsString('"Payments"', $e->getMessage());
        }
    }

    /** A filter and a function are different spellings, so they may share a name. */
    public function test_a_filter_and_a_function_may_share_a_name(): void
    {
        $helpers = new TemplateHelpers();
        $helpers->addFilter('money', static fn(): string => 'f');
        $helpers->addFunction('money', static fn(): string => 'fn');

        self::assertSame('f', $helpers->applyFilter('money', 1));
        self::assertSame('fn', $helpers->callFunction('money'));
    }

    public function test_a_callback_naming_a_missing_method_is_refused_at_declaration(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('the class or method does not exist');

        (new TemplateHelpers())->addFilter('money', [HelperTarget::class, 'nope'], module: 'Billing');
    }

    public function test_a_callback_naming_a_missing_class_is_refused_at_declaration(): void
    {
        $this->expectException(TemplateException::class);

        // @phpstan-ignore argument.type (the point of the test is a class that does not exist)
        (new TemplateHelpers())->addFilter('money', ['App\\Nowhere\\Money', 'format'], module: 'Billing');
    }

    // ---- calling ----------------------------------------------------------

    /** An instance method is built by the resolver on first call, and only once. */
    public function test_an_instance_method_is_resolved_once_on_first_call(): void
    {
        $built = 0;
        $helpers = new TemplateHelpers(static function (string $class) use (&$built): object {
            ++$built;

            return new $class('৳');
        });

        $helpers->addFilter('money', [HelperTarget::class, 'money']);

        self::assertSame(0, $built, 'declaring built nothing');
        self::assertSame('৳12.50', $helpers->applyFilter('money', 1250));
        self::assertSame('৳0.99', $helpers->applyFilter('money', 99));
        self::assertSame(1, $built);
    }

    public function test_an_instance_method_without_a_resolver_says_so(): void
    {
        $helpers = new TemplateHelpers();
        $helpers->addFilter('money', [HelperTarget::class, 'money']);

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('nothing can build its object');

        $helpers->applyFilter('money', 1);
    }

    public function test_calling_an_undeclared_helper_says_where_to_declare_it(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('$module->templates(');

        (new TemplateHelpers())->callFunction('route');
    }

    // ---- Twig -------------------------------------------------------------

    public function test_twig_gets_filters_with_arguments_and_functions(): void
    {
        $helpers = new TemplateHelpers(static fn(string $class): object => new $class('৳'));
        $helpers->addFilter('money', [HelperTarget::class, 'money']);
        $helpers->addFunction('shout', static fn(string $text): string => \strtoupper($text));

        $this->write('page.twig', '{{ 1250|money }} {{ 1250|money("$") }} {{ shout("hi") }}');

        self::assertSame('৳12.50 $12.50 HI', $this->manager($helpers)->render('page'));
    }

    /** Text is escaped like every other value; only a helper declared safe prints markup. */
    public function test_twig_escapes_a_helper_unless_it_is_declared_safe(): void
    {
        $helpers = new TemplateHelpers();
        $helpers->addFilter('bold', static fn(string $text): string => '<b>' . \htmlspecialchars($text) . '</b>', safe: true);
        $helpers->addFilter('raw_bold', static fn(string $text): string => '<b>' . $text . '</b>');

        $this->write('page.twig', '{{ "a&b"|bold }}|{{ "x"|raw_bold }}');

        self::assertSame('<b>a&amp;b</b>|&lt;b&gt;x&lt;/b&gt;', $this->manager($helpers)->render('page'));
    }

    /** Twig would let `date` be replaced silently, changing every template that uses it. */
    public function test_a_helper_may_not_replace_a_twig_built_in(): void
    {
        $helpers = new TemplateHelpers();
        $helpers->addFilter('date', static fn(): string => 'mine', module: 'Billing');

        $this->write('page.twig', '{{ "now"|date("Y") }}');

        try {
            $this->manager($helpers)->render('page');
            self::fail('a built-in was replaced');
        } catch (TemplateException $e) {
            self::assertStringContainsString('"date"', $e->getMessage());
            self::assertStringContainsString('Twig already provides', $e->getMessage());
        }
    }

    public function test_a_twig_template_without_helpers_is_unaffected(): void
    {
        $this->write('page.twig', '{{ "x"|upper }}');

        self::assertSame('X', $this->manager(new TemplateHelpers())->render('page'));
    }

    // ---- PHP --------------------------------------------------------------

    public function test_a_php_template_calls_the_same_helpers_through_view(): void
    {
        $helpers = new TemplateHelpers(static fn(string $class): object => new $class('৳'));
        $helpers->addFilter('money', [HelperTarget::class, 'money']);
        $helpers->addFunction('shout', static fn(string $text): string => \strtoupper($text));

        $this->write('page.php', '<?= $e($view->filter(\'money\', 1250, \'<$>\')) ?>|<?= $e($view->call(\'shout\', \'hi\')) ?>');

        self::assertSame('&lt;$&gt;12.50|HI', $this->manager($helpers)->render('page'));
    }
}

/** A helper with a constructor argument, as a real one has dependencies. */
final class HelperTarget
{
    public function __construct(private readonly string $symbol) {}

    public function money(int $cents, ?string $symbol = null): string
    {
        return ($symbol ?? $this->symbol) . \number_format($cents / 100, 2);
    }
}
