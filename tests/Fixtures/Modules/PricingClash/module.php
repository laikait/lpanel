<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;
use App\Engine\Template\TemplateHelperCollector;

/** Offers a helper name Pricing already took, for TemplateHelperSliceTest. */
return static function (ModuleContext $module): void {
    $module->name('PricingClash')->version('0.1.0');

    $module->templates(static function (TemplateHelperCollector $templates): void {
        $templates->filter('money', static fn(int $cents): string => (string) $cents);
    });
};
