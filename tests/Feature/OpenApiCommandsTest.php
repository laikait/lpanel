<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\OpenApi\OpenApiState;
use App\Engine\Security\Signer;
use App\Tests\Support\TestCase;

/**
 * `openapi:docs` and `openapi:auth`: switches written to a state file, with the
 * result printed, a capability typo refused, and production warned about.
 * The state lives in a temporary directory, never this project's system/.
 */
final class OpenApiCommandsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/lphp-openapi-cmd-' . \bin2hex(\random_bytes(4));
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

    /** @param array<string, mixed> $config */
    private function app(array $config = []): Application
    {
        $config['security']['key'] ??= Signer::generate();
        $app = $this->application($config)->boot();
        $app->container()->instance(OpenApiState::class, new OpenApiState($this->directory));

        // On after boot: the commands' report honours the switches, yet this
        // project's own system/Runtime/openapi.json was never read.
        $app->config()->set('openapi.runtime', true);

        return $app;
    }

    private function state(): OpenApiState
    {
        return new OpenApiState($this->directory);
    }

    public function test_docs_enable_turns_on_the_page_and_the_description(): void
    {
        [$status, $output] = $this->console($this->app(), 'openapi:docs', '--enable');

        self::assertSame(0, $status);
        self::assertSame(['docs' => ['on' => true, 'path' => null, 'spec' => null]], $this->state()->read());
        self::assertStringContainsString('The API docs are on.', $output);
        self::assertMatchesRegularExpression('/Docs page\s+\/docs/', $output);
        self::assertMatchesRegularExpression('/Description\s+\/openapi\.json/', $output);
        self::assertMatchesRegularExpression('/Docs set by\s+php laika openapi:docs/', $output);
    }

    public function test_docs_takes_its_paths(): void
    {
        [, $output] = $this->console($this->app(), 'openapi:docs', '--enable', '--path=api-docs/', '--spec=/api.json');

        self::assertSame(['on' => true, 'path' => '/api-docs', 'spec' => '/api.json'], $this->state()->read()['docs'] ?? null);
        self::assertMatchesRegularExpression('/Docs page\s+\/api-docs/', $output);
    }

    public function test_docs_disable_turns_both_off(): void
    {
        $app = $this->app(['openapi' => ['route' => '/openapi.json', 'docs' => '/docs']]);
        [$status, $output] = $this->console($app, 'openapi:docs', '--disable');

        self::assertSame(0, $status);
        self::assertMatchesRegularExpression('/Docs page\s+off/', $output);
        self::assertMatchesRegularExpression('/Description\s+off/', $output);
    }

    public function test_no_flag_only_shows_the_state(): void
    {
        [$status, $output] = $this->console($this->app(), 'openapi:docs');

        self::assertSame(0, $status);
        self::assertSame([], $this->state()->read());
        self::assertMatchesRegularExpression('/Docs page\s+off/', $output);
        self::assertMatchesRegularExpression('/Docs set by\s+config/', $output);
    }

    public function test_both_flags_are_a_usage_error(): void
    {
        [$status, , $errors] = $this->console($this->app(), 'openapi:docs', '--enable', '--disable');

        self::assertSame(2, $status);
        self::assertStringContainsString('choose one', $errors);
        self::assertSame([], $this->state()->read());
    }

    public function test_the_page_and_description_cannot_share_a_path(): void
    {
        [$status, , $errors] = $this->console($this->app(), 'openapi:docs', '--enable', '--path=/x', '--spec=/x');

        self::assertSame(1, $status);
        self::assertStringContainsString('both need /x', $errors);
    }

    public function test_auth_enable_requires_a_login_or_a_capability(): void
    {
        $app = $this->app();

        [$status, $output] = $this->console($app, 'openapi:auth', '--enable');
        self::assertSame(0, $status);
        self::assertSame(['on' => true, 'can' => null], $this->state()->read()['auth'] ?? null);
        self::assertMatchesRegularExpression('/Access\s+a login/', $output);

        [, $output] = $this->console($app, 'openapi:auth', '--enable', '--can=user.list');
        self::assertSame(['on' => true, 'can' => 'user.list'], $this->state()->read()['auth'] ?? null);
        self::assertMatchesRegularExpression('/Access\s+the capability user\.list/', $output);

        [, $output] = $this->console($app, 'openapi:auth', '--disable');
        self::assertMatchesRegularExpression('/Access\s+anyone/', $output);
    }

    public function test_auth_refuses_a_capability_nothing_declares(): void
    {
        [$status, , $errors] = $this->console($this->app(), 'openapi:auth', '--enable', '--can=api.docz');

        self::assertSame(1, $status);
        self::assertStringContainsString('"api.docz"', $errors);
        self::assertSame([], $this->state()->read());
    }

    public function test_can_without_enable_is_a_usage_error(): void
    {
        self::assertSame(2, $this->console($this->app(), 'openapi:auth', '--can=user.list')[0]);
    }

    public function test_open_docs_in_production_are_allowed_and_warned_about(): void
    {
        [$status, , $errors] = $this->console($this->app(['app' => ['env' => 'production']]), 'openapi:docs', '--enable');

        self::assertSame(0, $status);
        self::assertStringContainsString('Anyone can read the API description in production', $errors);
        self::assertStringContainsString('php laika openapi:auth --enable', $errors);
    }

    public function test_guarded_docs_in_production_are_not_warned_about(): void
    {
        $app = $this->app(['app' => ['env' => 'production']]);
        $this->console($app, 'openapi:auth', '--enable');

        [, , $errors] = $this->console($app, 'openapi:docs', '--enable');

        self::assertStringNotContainsString('Anyone can read', $errors);
    }

    public function test_an_unreadable_state_file_is_reported(): void
    {
        $state = $this->state();
        $state->docs(true);
        \file_put_contents($state->path(), 'nope');

        [$status, , $errors] = $this->console($this->app(), 'openapi:docs');

        self::assertSame(0, $status);
        self::assertStringContainsString('cannot be read, so it is ignored', $errors);
    }

    /** @return array{int, string, string} */
    private function console(Application $app, string ...$arguments): array
    {
        $stream = \fopen('php://memory', 'r+');
        $errors = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        self::assertIsResource($errors);

        $status = (new ConsoleKernel(
            $app->container()->get(CommandRegistry::class),
            $app->container()->get(CommandDispatcher::class),
            $app->container()->get(HookEngine::class),
            $app->container()->get(ErrorHandler::class),
            new Output($stream, $errors),
        ))->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

        \rewind($stream);
        \rewind($errors);
        $read = [(string) \stream_get_contents($stream), (string) \stream_get_contents($errors)];
        \fclose($stream);
        \fclose($errors);

        return [$status, ...$read];
    }
}
