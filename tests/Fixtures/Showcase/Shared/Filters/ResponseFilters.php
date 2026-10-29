<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Shared\Filters;

use App\Engine\Http\Response;

/**
 * Filters the shared module contributes to every response.
 *
 * These take their dependencies by constructor injection rather than reaching
 * for a global helper, which is the pattern module classes should follow. The
 * global helpers exist for module.php files and templates, where there is no
 * constructor to inject into.
 */
final class ResponseFilters
{
    /**
     * Runs at a late priority so it sees the response every other module has
     * already had a chance to change.
     */
    public static function stampEngine(Response $response): Response
    {
        return $response->withHeader('X-Engine', 'app-framework');
    }
}
