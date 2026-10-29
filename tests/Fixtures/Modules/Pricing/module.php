<?php

declare(strict_types=1);

use App\Engine\Http\Response;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Template\TemplateHelperCollector;
use App\Engine\Template\TemplateManager;
use App\Tests\Fixtures\Modules\Pricing\Money;

/** Offers template helpers, for TemplateHelperSliceTest. */
return static function (ModuleContext $module): void {
    $module->name('Pricing')->version('0.1.0');

    $module->config(['symbol' => '৳']);

    $module->templates(static function (TemplateHelperCollector $templates): void {
        $templates
            ->filter('money', [Money::class, 'format'])
            ->filter('badge', [Money::class, 'badge'], safe: true)
            ->function('shout', static fn(string $text): string => strtoupper($text) . '!');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/receipt', static fn(TemplateManager $templates): Response => (new Response(
            $templates->render('@Pricing/receipt', ['total' => 123456, 'label' => 'paid & <done>']),
        ))->withContentType('text/html'))->name('pricing.receipt');
    });
};
