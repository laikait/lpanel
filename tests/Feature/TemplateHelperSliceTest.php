<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Core\HttpKernel;
use App\Engine\Http\Request;
use App\Engine\Template\TemplateException;
use App\Engine\Template\TemplateHelpers;
use App\Engine\Template\TemplateManager;
use App\Tests\Fixtures\Modules\Pricing\Money;
use App\Tests\Support\TestCase;

/**
 * Template helpers through the real application: declared in module.php,
 * replayed at Register, built through the container on first call.
 */
final class TemplateHelperSliceTest extends TestCase
{
    /** @param list<string> $modules */
    private function booted(array $modules = ['tests/Fixtures/Modules/Pricing']): Application
    {
        return $this->application([
            'modules' => ['paths' => [self::SHOWCASE . '/Shared', ...$modules]],
        ])->boot();
    }

    public function test_a_modules_helpers_are_registered_at_boot_and_built_on_first_use(): void
    {
        Money::$built = 0;

        $application = $this->booted();
        $helpers = $application->container()->get(TemplateHelpers::class);
        self::assertInstanceOf(TemplateHelpers::class, $helpers);

        self::assertSame(['money', 'badge'], \array_keys($helpers->filters()));
        // feature() is the engine's own; the rest came from the module.
        self::assertSame(['feature', 'shout'], \array_keys($helpers->functions()));
        self::assertSame('Pricing', $helpers->filters()['money']->module);
        self::assertSame(0, Money::$built, 'booting built nothing');

        $kernel = $application->container()->get(HttpKernel::class);
        self::assertInstanceOf(HttpKernel::class, $kernel);

        $html = (string) $kernel->handle(Request::create('GET', '/receipt'))->body();

        // The module's config default reached the helper: it was built by the
        // container with the real Config, once for three calls.
        self::assertStringContainsString('<p class="total">৳1,234.56</p>', $html);
        self::assertStringContainsString('<p class="usd">US$1,234.56</p>', $html);
        self::assertStringContainsString('<p class="badge"><b>paid &amp; &lt;done&gt;</b></p>', $html);
        self::assertStringContainsString('<p class="shout">PAID &amp; &lt;DONE&gt;!</p>', $html);
        self::assertSame(1, Money::$built);
    }

    public function test_a_php_template_reaches_the_same_helpers(): void
    {
        $templates = $this->booted()->container()->get(TemplateManager::class);
        self::assertInstanceOf(TemplateManager::class, $templates);

        self::assertSame(
            "<p class=\"total\">৳0.50</p>\n<p class=\"shout\">&lt;HI&gt;!</p>\n",
            $templates->render('@Pricing/plain', ['total' => 50, 'label' => '<hi>']),
        );
    }

    public function test_two_modules_offering_one_name_stop_the_boot_naming_both(): void
    {
        try {
            $this->booted(['tests/Fixtures/Modules/Pricing', 'tests/Fixtures/Modules/PricingClash']);
            self::fail('the boot accepted two helpers named "money"');
        } catch (TemplateException $e) {
            self::assertStringContainsString('"Pricing"', $e->getMessage());
            self::assertStringContainsString('"PricingClash"', $e->getMessage());
        }
    }
}
