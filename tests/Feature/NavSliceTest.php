<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Identity;
use App\Engine\Core\Application;
use App\Engine\Database\ConnectionManager;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Migration\Migrator;
use App\Engine\Security\Csrf;
use App\Engine\Security\Signer;
use App\Tests\Fixtures\Modules\Nav\Data\NavRepository;
use App\Tests\Support\TestCase;

/**
 * Nav, wired the way a real application would: its own module, its own
 * migration, running against a real database -- both the read side
 * (NavRepository::tree()) and the unauthenticated admin UI over HTTP.
 */
final class NavSliceTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function app(array $config = []): Application
    {
        $config['modules']['paths'] ??= [self::SHOWCASE . '/Shared', 'tests/Fixtures/Modules/Nav'];
        $config['database']['connections']['default']['dsn'] ??= 'sqlite::memory:';
        $config['security']['key'] ??= Signer::generate();

        $app = $this->application($config)->boot();
        $app->container()->get(Migrator::class)->migrate();

        return $app;
    }

    /** @return array{Response, string, string} the response, its CSRF cookie value, and the _token field it carried */
    private function get(Application $app, string $uri): array
    {
        $response = $app->handle(Request::create('GET', $uri));
        $cookies = $response->cookies();

        self::assertNotSame([], $cookies, 'no CSRF cookie was issued');

        if (\preg_match('/name="_token" value="([^"]+)"/', $response->body(), $field) !== 1) {
            self::fail('the page carried no _token field: ' . $response->body());
        }

        return [$response, $cookies[0]->value, $field[1]];
    }

    // ---- reading, no login involved ------------------------------------

    public function test_its_migration_runs_and_its_service_resolves(): void
    {
        $nav = $this->app()->container()->get(NavRepository::class);

        // The table exists and is empty: nothing seeds it.
        self::assertSame([], $nav->tree('header', Identity::guest()));
    }

    public function test_a_row_written_through_the_real_connection_renders_as_a_tree(): void
    {
        $app = $this->app();

        $app->container()->get(ConnectionManager::class)->connection()->table('nav_items')->insert([
            'parentId' => null,
            'location' => 'header',
            'label' => 'Home',
            'url' => '/',
            'route' => null,
            'capability' => null,
            'order' => 0,
            'enabled' => true,
        ]);

        $tree = $app->container()->get(NavRepository::class)->tree('header', Identity::guest());

        self::assertCount(1, $tree);
        self::assertSame('Home', $tree[0]->item->label());
    }

    // ---- the admin UI, unauthenticated ---------------------------------

    public function test_the_index_page_is_reachable_by_a_guest(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/nav'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Navigation', $response->body());
    }

    public function test_a_guest_can_create_edit_reorder_and_delete_an_item(): void
    {
        $app = $this->app();

        // Create.
        [, $cookie, $token] = $this->get($app, '/nav/create');

        $store = $app->handle(Request::create('POST', '/nav', [
            'cookies' => [Csrf::COOKIE => $cookie],
            'body' => [
                Csrf::FIELD => $token,
                'location' => 'header',
                'label' => 'Home',
                'parentId' => '',
                'url' => '/',
                'route' => '',
                'capability' => '',
                'order' => '0',
                'enabled' => '1',
            ],
        ]));

        self::assertSame(303, $store->status());

        $nav = $app->container()->get(NavRepository::class);
        $items = $nav->all();
        self::assertCount(1, $items);
        $id = $items[0]->identity();
        self::assertNotNull($id);
        self::assertSame('Home', $items[0]->label());

        // A second, sibling item to reorder against.
        [, $cookie2, $token2] = $this->get($app, '/nav/create');
        $app->handle(Request::create('POST', '/nav', [
            'cookies' => [Csrf::COOKIE => $cookie2],
            'body' => [
                Csrf::FIELD => $token2,
                'location' => 'header',
                'label' => 'About',
                'parentId' => '',
                'url' => '/about',
                'route' => '',
                'capability' => '',
                'order' => '1',
                'enabled' => '1',
            ],
        ]));

        $about = $this->onlyByLabel($nav, 'About');

        // Edit.
        [, $editCookie, $editToken] = $this->get($app, '/nav/' . $id . '/edit');

        $update = $app->handle(Request::create('POST', '/nav/' . $id, [
            'cookies' => [Csrf::COOKIE => $editCookie],
            'body' => [
                Csrf::FIELD => $editToken,
                'location' => 'header',
                'label' => 'Home page',
                'parentId' => '',
                'url' => '/',
                'route' => '',
                'capability' => '',
                'order' => '0',
                'enabled' => '1',
            ],
        ]));

        self::assertSame(303, $update->status());
        self::assertSame('Home page', $this->onlyByLabel($nav, 'Home page')->label());

        // Move: "Home page" (order 0) moves down, past "About" (order 1).
        [, $moveCookie, $moveToken] = $this->get($app, '/nav');

        $moved = $app->handle(Request::create('POST', '/nav/' . $id . '/move', [
            'cookies' => [Csrf::COOKIE => $moveCookie],
            'body' => [Csrf::FIELD => $moveToken, 'direction' => 'down'],
        ]));

        self::assertSame(303, $moved->status());
        self::assertGreaterThan($this->onlyByLabel($nav, 'About')->order(), $nav->find($id)?->order());

        // Delete.
        [, $deleteCookie, $deleteToken] = $this->get($app, '/nav');

        $deleted = $app->handle(Request::create('POST', '/nav/' . $id . '/delete', [
            'cookies' => [Csrf::COOKIE => $deleteCookie],
            'body' => [Csrf::FIELD => $deleteToken],
        ]));

        self::assertSame(303, $deleted->status());
        self::assertNull($nav->find($id));
        self::assertCount(1, $nav->all());
    }

    public function test_invalid_input_is_rejected_with_422_and_the_form_is_shown_again(): void
    {
        $app = $this->app();
        [, $cookie, $token] = $this->get($app, '/nav/create');

        $response = $app->handle(Request::create('POST', '/nav', [
            'cookies' => [Csrf::COOKIE => $cookie],
            'body' => [
                Csrf::FIELD => $token,
                'location' => '', // required, blank
                'label' => '',    // required, blank
                'order' => '0',
                'enabled' => '',
            ],
        ]));

        self::assertSame(422, $response->status());
        self::assertSame([], $app->container()->get(NavRepository::class)->all());
    }

    // ---- "no auth" is not "no protection at all" -----------------------

    public function test_a_write_with_no_csrf_token_is_refused_with_403(): void
    {
        $response = $this->app()->handle(Request::create('POST', '/nav', [
            'body' => ['location' => 'header', 'label' => 'Home', 'order' => '0', 'enabled' => '1'],
        ]));

        self::assertSame(403, $response->status());
    }

    public function test_reading_the_index_needs_no_identity_and_no_session(): void
    {
        // No cookies at all, not even a CSRF one yet: the very first request
        // a brand-new visitor could make.
        $response = $this->app()->handle(Request::create('GET', '/nav'));

        self::assertSame(200, $response->status());
    }

    private function onlyByLabel(NavRepository $nav, string $label): \App\Tests\Fixtures\Modules\Nav\Model\NavItem
    {
        foreach ($nav->all() as $item) {
            if ($item->label() === $label) {
                return $item;
            }
        }

        self::fail(\sprintf('no item labelled "%s" was found', $label));
    }
}
