<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Filters;

/**
 * This module's own value transformations.
 *
 * A filter must return the value it was given, transformed or not. In debug
 * mode, forgetting to return is an error that names the callback.
 */
final class CustomerFilters
{
    /**
     * Titlecase names for presentation, leaving stored data alone.
     *
     * The distinction matters: this is why "customer.name" is a filter and
     * "customer.created" is a hook.
     */
    public static function normaliseName(string $name): string
    {
        return \ucwords(\strtolower(\trim($name)));
    }
}
