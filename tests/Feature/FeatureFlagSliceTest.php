<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Feature\Features;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Route;
use App\Engine\Routing\Router;
use App\Engine\Template\TemplateHelpers;
use App\Tests\Support\TestCase;

final class FeatureFlagSliceTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = $this->shippedApplication(['features' => ['new-checkout' => false, 'dark-mode' => true]])->boot();
        $this->app->container()->get(Router::class)->add(
            (new Route('GET', '/checkout/v2', static fn(): Response => new Response('v2')))->meta(['feature' => 'new-checkout']),
        );
    }

    private function get(string $uri): Response
    {
        return $this->app->handle(Request::create('GET', $uri, ['server' => ['REMOTE_ADDR' => '203.0.113.9']]));
    }

    public function test_a_route_behind_a_flag_is_not_there_while_it_is_off(): void
    {
        self::assertSame(404, $this->get('/checkout/v2')->status());

        $this->app->container()->get(Features::class)->define('new-checkout', true);

        $response = $this->get('/checkout/v2');
        self::assertSame(200, $response->status());
        self::assertSame('v2', $response->body());
    }

    public function test_templates_ask_with_feature(): void
    {
        $helpers = $this->app->container()->get(TemplateHelpers::class);
        $feature = $helpers->callable($helpers->functions()['feature']);

        self::assertTrue($feature('dark-mode'));
        self::assertFalse($feature('new-checkout'));
    }

    public function test_bad_configuration_fails_when_first_used(): void
    {
        $app = $this->shippedApplication(['features' => ['typo' => ['percentage' => 5]]])->boot();

        $this->expectExceptionMessage('"percentage" is not a rule');

        $app->container()->get(Features::class);
    }
}
