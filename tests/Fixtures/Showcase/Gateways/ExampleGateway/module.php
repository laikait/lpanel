<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;
use App\Tests\Fixtures\Showcase\Gateways\ExampleGateway\Hooks\AuditHooks;

/**
 * A gateway module.
 *
 * Gateways are specialised modules. They load last, which lets them observe and
 * adjust what plugins have already set up. Gateway-specific models stay inside
 * the gateway; generic concepts belong to the module that owns the capability.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Example Gateway')
        ->version('0.1.0')
        ->description('Shows a gateway reacting to another module\'s events.');

    // Optional, and that is the accurate word. This gateway only listens to
    // customer.created, which Example fires; without that plugin the
    // listener is simply never called, so there is nothing to refuse. What the
    // declaration buys is the version check: the shape of that event's payload
    // is Example's contract, and a 1.0 that changed it should stop the
    // application at boot rather than hand this listener something unexpected.
    $module->optionally('Example', '^0.1');

    $module->hook('customer.created', [AuditHooks::class, 'recordCustomer'], priority: 50);
};
