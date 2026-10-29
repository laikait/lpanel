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
use App\Tests\Support\TestCase;

/**
 * The console, against the fixture modules.
 *
 * Two things are being proved here. The framework's own commands report what
 * the application actually found -- that is what they are for. And a command a
 * module declared is reachable, injected and parsed exactly like one the
 * framework declared, because there is only one path.
 */
final class ConsoleTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array{int, string} exit code and captured output
     */
    private function console(array $config, string ...$arguments): array
    {
        $app = $this->fixtureApplication($config)->boot();
        $container = $app->container();

        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        // One stream, so the assertions see both what the command printed and
        // what it complained about. Output's two-stream behaviour has its own
        // test below.
        $kernel = new ConsoleKernel(
            $container->get(CommandRegistry::class),
            $container->get(CommandDispatcher::class),
            $container->get(HookEngine::class),
            $container->get(ErrorHandler::class),
            new Output($stream),
        );

        $status = $kernel->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        return [$status, $output];
    }

    /** @return array{int, string} */
    private function invoke(string ...$arguments): array
    {
        return $this->console([], ...$arguments);
    }

    // ---- the framework's own commands -------------------------------------

    public function test_about_summarises_the_application(): void
    {
        [$status, $output] = $this->invoke('about');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString(Application::VERSION, $output);
        self::assertStringContainsString(\PHP_VERSION, $output);
        self::assertStringContainsString('Modules', $output);
        self::assertStringContainsString('Commands', $output);
    }

    public function test_no_arguments_lists_the_commands(): void
    {
        [$status, $output] = $this->invoke();

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('App Framework', $output);
        self::assertStringContainsString('php laika <command>', $output);
        self::assertStringContainsString('module:list', $output);
    }

    public function test_module_list_reports_every_discovered_module(): void
    {
        [$status, $output] = $this->invoke('module:list');

        self::assertSame(0, $status);

        foreach (['Shared', 'Alpha', 'Beta', 'Zeta'] as $id) {
            self::assertStringContainsString($id, $output);
        }

        self::assertStringContainsString('2.1.0', $output, 'the module version should be shown');
    }

    public function test_module_list_counts_the_commands_a_module_declared(): void
    {
        [, $output] = $this->invoke('module:list');

        self::assertMatchesRegularExpression(
            '/Alpha\s+module\s+Alpha\s+2\.1\.0\s+\d+\s+5/',
            $output,
            'Alpha declares five commands and the listing should say so',
        );
    }

    public function test_module_list_shows_them_in_load_order(): void
    {
        [, $output] = $this->invoke('module:list');

        $positions = \array_map(
            static fn(string $id): int|false => \strpos($output, $id),
            ['Shared', 'Alpha', 'Beta', 'Zeta'],
        );

        $sorted = $positions;
        \sort($sorted);

        self::assertSame($sorted, $positions, 'modules should be listed in the order they load');
    }

    public function test_route_list_reports_routes_with_their_owning_module(): void
    {
        [$status, $output] = $this->invoke('route:list');

        self::assertSame(0, $status);
        self::assertStringContainsString('/api/v1/items/{id}', $output);
        self::assertStringContainsString('api.v1.items.show', $output);
        self::assertStringContainsString('Alpha', $output);
        self::assertStringContainsString('shared', $output);
    }

    /** An option declared by the framework's own command, bound by name. */
    public function test_route_list_can_be_narrowed_to_one_module(): void
    {
        [$status, $output] = $this->invoke('route:list', '--module=Alpha');

        self::assertSame(0, $status);
        self::assertStringContainsString('Alpha', $output);
        self::assertStringNotContainsString('Zeta', $output);
    }

    public function test_asset_list_reports_what_is_published_and_whether_it_is_there(): void
    {
        [$status, $output] = $this->invoke('asset:list');

        self::assertSame(0, $status);
        self::assertStringContainsString('/assets/core', $output);

        // templates/assets/, which is what asset()->template('...') reaches.
        self::assertStringContainsString('/assets/template', $output);
        self::assertStringContainsString('templates/assets', $output);
    }

    public function test_template_list_reports_the_search_path_in_order(): void
    {
        [$status, $output] = $this->invoke('template:list');

        self::assertSame(0, $status);
        self::assertStringContainsString('(application)', $output);
        self::assertStringContainsString('override', $output);
        self::assertStringContainsString('Renderable extensions:', $output);
        self::assertStringContainsString('php', $output);
    }

    public function test_cache_clear_reports_what_it_found(): void
    {
        [$status, $output] = $this->invoke('cache:clear');

        self::assertSame(0, $status);
        self::assertStringContainsString('modules', $output);
        self::assertStringContainsString('templates', $output);
    }

    // ---- module-owned commands --------------------------------------------

    /** A closure handler, its dependency injected, returning text to print. */
    public function test_a_module_command_runs_through_the_same_path(): void
    {
        [$status, $output] = $this->invoke('item:count');

        self::assertSame(0, $status);
        self::assertStringContainsString('2 items.', $output);
    }

    public function test_arguments_and_options_arrive_as_typed_parameters(): void
    {
        [$status, $output] = $this->invoke('item:touch', '7', '--times=3', '--force');

        self::assertSame(0, $status);
        self::assertStringContainsString('touched 7 x3 (forced)', $output);
    }

    public function test_shortcuts_bundle_the_way_a_shell_user_expects(): void
    {
        [$status, $output] = $this->invoke('item:touch', '7', '-ft2');

        self::assertSame(0, $status);
        self::assertStringContainsString('touched 7 x2 (forced)', $output);
    }

    public function test_defaults_apply_when_nothing_was_typed(): void
    {
        [, $output] = $this->invoke('item:touch', '7');

        self::assertStringContainsString('touched 7 x1', $output);
        self::assertStringNotContainsString('forced', $output);
    }

    /** The exit code is the return value, because a script reads it. */
    public function test_a_commands_exit_code_reaches_the_caller(): void
    {
        [$status] = $this->invoke('item:fail');

        self::assertSame(3, $status);
    }

    public function test_a_command_that_returns_the_wrong_sort_of_thing_fails_loudly(): void
    {
        [$status, $output] = $this->invoke('item:confused');

        self::assertSame(ConsoleKernel::FAILURE, $status);
        self::assertStringContainsString('int exit code', $output);
    }

    // ---- refusals ----------------------------------------------------------

    public function test_an_unknown_command_is_127_and_suggests_a_real_one(): void
    {
        [$status, $output] = $this->invoke('item:cont');

        self::assertSame(ConsoleKernel::UNKNOWN, $status);
        self::assertStringContainsString('Unknown command "item:cont"', $output);
        self::assertStringContainsString('item:count', $output);
    }

    public function test_a_missing_argument_is_a_usage_error_with_the_synopsis(): void
    {
        [$status, $output] = $this->invoke('item:touch');

        self::assertSame(ConsoleKernel::USAGE, $status);
        self::assertStringContainsString('needs the <id> argument', $output);
        self::assertStringContainsString('Usage: php laika item:touch <id> [options]', $output);
    }

    public function test_an_unknown_option_names_the_ones_that_exist(): void
    {
        [$status, $output] = $this->invoke('item:touch', '7', '--verbose');

        self::assertSame(ConsoleKernel::USAGE, $status);
        self::assertStringContainsString('has no option --verbose', $output);
        self::assertStringContainsString('--times', $output);
    }

    public function test_a_value_that_does_not_fit_the_parameter_is_a_usage_error(): void
    {
        [$status, $output] = $this->invoke('item:touch', '7', '--times=lots');

        self::assertSame(ConsoleKernel::USAGE, $status);
        self::assertStringContainsString('"lots" is not a whole number', $output);
    }

    public function test_a_throwing_command_fails_without_leaking_its_message(): void
    {
        [$status, $output] = $this->console(['app' => ['debug' => false]], 'item:boom');

        self::assertSame(ConsoleKernel::FAILURE, $status);
        self::assertStringNotContainsString('secret-hunter2', $output);
        self::assertStringNotContainsString(__DIR__, $output);
    }

    public function test_a_throwing_command_says_everything_in_debug_mode(): void
    {
        [$status, $output] = $this->console(['app' => ['debug' => true]], 'item:boom');

        self::assertSame(ConsoleKernel::FAILURE, $status);
        self::assertStringContainsString('secret-hunter2', $output);
        self::assertStringContainsString('RuntimeException', $output);
    }

    // ---- help --------------------------------------------------------------

    public function test_help_lists_every_command_including_a_modules(): void
    {
        [$status, $output] = $this->invoke('help');

        self::assertSame(0, $status);

        foreach (['about', 'module:list', 'route:list', 'asset:list', 'template:list', 'item:touch'] as $name) {
            self::assertStringContainsString($name, $output);
        }
    }

    public function test_help_for_one_command_is_generated_from_its_declaration(): void
    {
        [$status, $output] = $this->invoke('help', 'item:touch');

        self::assertSame(0, $status);
        self::assertStringContainsString('Touch an item.', $output);
        self::assertStringContainsString('-t, --times=<value>', $output);
        self::assertStringContainsString('(default: 1)', $output);
        self::assertStringContainsString('Declared by module Alpha.', $output);
    }

    public function test_help_after_a_command_explains_it_rather_than_running_it(): void
    {
        [$status, $output] = $this->invoke('item:touch', '--help');

        self::assertSame(0, $status);
        self::assertStringContainsString('Touch an item.', $output);
        self::assertStringNotContainsString('touched', $output, 'it must not have run');
    }

    // ---- output ------------------------------------------------------------

    /** Output written to a pipe carries no escape codes. */
    public function test_output_is_not_coloured_when_nothing_is_watching(): void
    {
        [, $output] = $this->invoke('about');

        self::assertStringNotContainsString("\033[", $output);
    }

    /**
     * A result goes to standard output; a complaint about the run does not.
     *
     * This is what makes `laika route:list | grep customers` usable: a
     * warning must not end up in what the pipe carries.
     */
    public function test_messages_about_the_run_go_to_the_error_stream(): void
    {
        $app = $this->fixtureApplication()->boot();

        $out = \fopen('php://memory', 'r+');
        $err = \fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $kernel = new ConsoleKernel(
            $app->container()->get(CommandRegistry::class),
            $app->container()->get(CommandDispatcher::class),
            $app->container()->get(HookEngine::class),
            $app->container()->get(ErrorHandler::class),
            new Output($out, $err),
        );

        $status = $kernel->handle(ExecutionContext::cli(['laika', 'item:nope']));

        \rewind($out);
        \rewind($err);
        $standard = (string) \stream_get_contents($out);
        $errors = (string) \stream_get_contents($err);
        \fclose($out);
        \fclose($err);

        self::assertSame(ConsoleKernel::UNKNOWN, $status);
        self::assertSame('', $standard, 'nothing about a failure belongs on standard output');
        self::assertStringContainsString('Unknown command', $errors);
    }

    /**
     * The console shares the application bootstrap; it does not have one of its
     * own. Modules, routes and services are all present in a CLI run.
     */
    public function test_the_console_sees_the_same_booted_application_as_http(): void
    {
        [, $output] = $this->invoke('about');

        self::assertMatchesRegularExpression('/Modules\s+4/', $output);
        self::assertMatchesRegularExpression('/Routes\s+[1-9]/', $output);
        // Ten or more, rather than an exact figure: this is checking that the
        // console booted the same application, not counting the framework's
        // commands, and a test that had to be edited every time one was added
        // would stop meaning anything.
        self::assertMatchesRegularExpression('/Commands\s+[1-9][0-9]/', $output);
    }
}
