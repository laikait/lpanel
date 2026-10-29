<?php

declare(strict_types=1);

namespace App\Tests\Unit\OpenApi;

use App\Engine\Bootstrap\Bootstrap;
use App\Engine\OpenApi\OpenApiState;
use App\Tests\Support\TestCase;

/**
 * The switches `openapi:docs` and `openapi:auth` write, and how they reach the
 * configuration: over the files, under the caller, never into the cache.
 */
final class OpenApiStateTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/lphp-openapi-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @\unlink($this->directory . '/' . OpenApiState::FILE);
        @\rmdir(\dirname($this->directory . '/' . OpenApiState::FILE));
        @\rmdir($this->directory . '/system');
        @\rmdir($this->directory);

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $openapi
     *
     * @return array<string, mixed>
     */
    private static function overlay(array $openapi, OpenApiState $state): array
    {
        $result = OpenApiState::overlay(['openapi' => $openapi], $state->read())['openapi'];
        self::assertIsArray($result);

        return $result;
    }

    public function test_no_file_is_no_state(): void
    {
        self::assertSame([], (new OpenApiState($this->directory))->read());
    }

    public function test_each_switch_is_kept_beside_the_other(): void
    {
        $state = new OpenApiState($this->directory);
        $state->docs(true, '/api-docs', null);
        $state->auth(true, 'api.docs');

        self::assertSame([
            'docs' => ['on' => true, 'path' => '/api-docs', 'spec' => null],
            'auth' => ['on' => true, 'can' => 'api.docs'],
        ], $state->read());
    }

    public function test_docs_on_uses_the_given_paths_then_config_then_the_defaults(): void
    {
        $state = new OpenApiState($this->directory);
        $state->docs(true);

        self::assertSame(['route' => '/openapi.json', 'docs' => '/docs'], self::overlay([], $state));
        self::assertSame(['route' => '/spec', 'docs' => '/reference'], self::overlay(['route' => 'spec/', 'docs' => 'reference'], $state));

        $state->docs(true, '/api-docs', '/api.json');
        self::assertSame(['route' => '/api.json', 'docs' => '/api-docs'], self::overlay(['route' => '/spec'], $state));
    }

    public function test_docs_off_wins_over_config(): void
    {
        $state = new OpenApiState($this->directory);
        $state->docs(false);

        self::assertSame(['route' => null, 'docs' => null], self::overlay(['route' => '/openapi.json', 'docs' => '/docs'], $state));
    }

    public function test_auth_changes_only_its_own_part_of_the_meta(): void
    {
        $state = new OpenApiState($this->directory);
        $configured = ['meta' => ['can' => 'old', 'rate_limit' => '10/1m']];

        $state->auth(true);
        self::assertSame(['rate_limit' => '10/1m', 'auth' => true], self::overlay($configured, $state)['meta']);

        $state->auth(true, 'api.docs');
        self::assertSame(['rate_limit' => '10/1m', 'can' => 'api.docs'], self::overlay($configured, $state)['meta']);

        $state->auth(false);
        self::assertSame(['rate_limit' => '10/1m'], self::overlay($configured, $state)['meta']);
    }

    public function test_an_unreadable_file_is_ignored_and_reported(): void
    {
        $state = new OpenApiState($this->directory);
        $state->docs(true);
        \file_put_contents($state->path(), '{not json');

        self::assertSame([], $state->read());
        self::assertFalse($state->isReadable());
    }

    public function test_the_file_is_replaced_whole(): void
    {
        $state = new OpenApiState($this->directory);
        $state->docs(true);
        $state->docs(false);

        self::assertSame([$state->path()], \glob(\dirname($state->path()) . '/openapi.json*'));
    }

    // ---- through Bootstrap::settings() ----------------------------------------

    public function test_settings_apply_the_switches_over_the_files(): void
    {
        (new OpenApiState($this->directory))->docs(true);

        $settings = Bootstrap::settings($this->directory);

        self::assertSame('/openapi.json', $settings['openapi']['route']);
        self::assertSame('/docs', $settings['openapi']['docs']);
    }

    public function test_the_caller_and_openapi_runtime_win_over_the_switches(): void
    {
        (new OpenApiState($this->directory))->docs(true);

        self::assertSame('/mine.json', Bootstrap::settings($this->directory, ['openapi' => ['route' => '/mine.json']])['openapi']['route']);
        self::assertNull(Bootstrap::settings($this->directory, ['openapi' => ['runtime' => false]])['openapi']['route']);
    }

    public function test_the_config_cache_never_carries_a_switch(): void
    {
        (new OpenApiState($this->directory))->docs(true);

        self::assertNull(Bootstrap::settings($this->directory, cached: false)['openapi']['route']);
    }
}
