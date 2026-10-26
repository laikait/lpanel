<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Data\DataSource;
use App\Engine\Http\Request;
use App\Tests\Support\ApplicationTestCase;

/** What a fresh LPanel answers before anyone signs in. */
final class ShippedApplicationTest extends ApplicationTestCase
{
    public function test_health_answers_with_its_checks_as_json(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/health'));

        self::assertContains($response->status(), [200, 503]);
        self::assertStringStartsWith('application/json', (string) $response->contentType());

        $body = \json_decode($response->body(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('checks', $body);
    }

    public function test_a_guest_is_sent_from_the_front_page_to_sign_in(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/'));

        self::assertSame(303, $response->status());
        self::assertSame('/login', $response->header('Location'));
    }

    public function test_the_login_page_carries_a_csrf_token_and_is_not_cached(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/login'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('name="_token"', $response->body());
        self::assertSame('no-store', $response->header('Cache-Control'));
    }

    public function test_repositories_have_somewhere_to_store_data(): void
    {
        self::assertInstanceOf(DataSource::class, $this->service(DataSource::class));
    }
}
