<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Pricing;

use App\Engine\Config\Config;

/**
 * An instance-method helper with a dependency, which is the case the template
 * helper registry exists for: it has to be built through the container, and
 * built once however many amounts a page formats.
 */
final class Money
{
    public static int $built = 0;

    public function __construct(private readonly Config $config)
    {
        ++self::$built;
    }

    public function format(int $cents, ?string $symbol = null): string
    {
        $symbol ??= $this->config->string('Pricing.symbol') ?? '$';

        return $symbol . \number_format($cents / 100, 2);
    }

    /** Markup the helper escaped itself, declared safe. */
    public function badge(string $label): string
    {
        return '<b>' . \htmlspecialchars($label, \ENT_QUOTES) . '</b>';
    }
}
