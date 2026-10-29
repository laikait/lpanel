<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Shared\Filters;

use App\Engine\Http\Response;
use App\Engine\Routing\Route;

/**
 * Conventions every API route in this application follows.
 *
 * This is what cross-cutting behaviour looks like without middleware. Both of
 * these read Route::metadata() and decorate the response; neither is wired into
 * any route, neither knows what the others do, and adding a third is one line
 * in module.php rather than a place in a pipeline everything else has to pass
 * through.
 *
 * They run on dispatch.response, the one point where the finished Response and
 * the Route that produced it both exist. Later filters see the response too,
 * but by then the route is gone and with it any way to ask what the route
 * declared about itself.
 */
final class ApiFilters
{
    /**
     * Tell the client which version answered it.
     *
     * Cheap, and it settles arguments. A bug report that says "the API returned
     * the wrong shape" is a different conversation once the response says which
     * version produced it, and the header costs nothing to send.
     */
    public static function stampVersion(Response $response, Route $route): Response
    {
        $version = $route->metadata()['version'] ?? null;

        return \is_string($version) ? $response->withHeader('X-Api-Version', $version) : $response;
    }

    /**
     * Announce a deprecation in the response, not in a changelog.
     *
     * Deprecation (RFC 9745) says when it became deprecated; Sunset (RFC 8594)
     * says when it stops answering. Both are HTTP dates, and both reach exactly
     * the people who need them: whoever is still calling the endpoint. A note
     * in release notes reaches whoever reads release notes, which is a
     * different and much smaller group.
     */
    public static function announceDeprecation(Response $response, Route $route): Response
    {
        $metadata = $route->metadata();
        $deprecated = $metadata['deprecated'] ?? null;

        if (!\is_string($deprecated)) {
            return $response;
        }

        $response = $response->withHeader('Deprecation', self::httpDate($deprecated));
        $sunset = $metadata['sunset'] ?? null;

        return \is_string($sunset) ? $response->withHeader('Sunset', self::httpDate($sunset)) : $response;
    }

    /** A date the specifications recognise, from a date a developer can type. */
    private static function httpDate(string $date): string
    {
        $timestamp = \strtotime($date);

        return $timestamp === false ? $date : \gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
    }
}
