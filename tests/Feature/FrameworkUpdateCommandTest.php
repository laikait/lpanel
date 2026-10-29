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
use App\Engine\Update\ArchiveReleaseSource;
use App\Engine\Update\ReleaseSource;
use App\Engine\Update\Updater;
use App\Tests\Unit\Update\UpdateTestCase;

/**
 * framework:update and framework:rollback through the real console kernel,
 * against an application directory and a release in a temporary directory.
 * Nothing is downloaded and the project's own files are never touched.
 */
final class FrameworkUpdateCommandTest extends UpdateTestCase
{
    private Application $app;

    private string $application;

    protected function setUp(): void
    {
        parent::setUp();

        $old = $this->release('old', '3.0.0', ['engine/A.php' => 'a v1', 'composer.json' => '{"require": {"php": "^8.2"}}']);
        $this->release('new', '3.1.0', ['engine/A.php' => 'a v2', 'engine/B.php' => 'b', 'composer.json' => '{"require": {"php": "^8.2"}}']);

        $this->application = $this->tree('application', [
            'engine/A.php' => 'a v1',
            'composer.json' => '{"require": {"php": "^8.2"}}',
            'framework.json' => $old->manifest->toJson(),
            'tests/Unit/MyTest.php' => 'mine',
        ]);

        $this->app = $this->shippedApplication()->boot();
        $this->app->container()->instance(Updater::class, new Updater($this->application));
        $this->app->container()->instance(ReleaseSource::class, new ArchiveReleaseSource($this->root . '/new'));
    }

    /** @return array{int, string} */
    private function console(string ...$arguments): array
    {
        $container = $this->app->container();
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

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

    public function test_check_compares_without_changing_anything(): void
    {
        [$status, $output] = $this->console('framework:update', '--check');

        self::assertSame(0, $status);
        self::assertStringContainsString('3.0.0', $output);
        self::assertStringContainsString('3.1.0', $output);
        self::assertStringContainsString('php laika framework:update', $output);
        self::assertSame('a v1', \file_get_contents($this->application . '/engine/A.php'));
    }

    public function test_a_dry_run_prints_the_plan_and_changes_nothing(): void
    {
        [$status, $output] = $this->console('framework:update', '--dry-run');

        self::assertSame(0, $status, $output);
        self::assertStringContainsString("Replace (1):\n  engine/A.php", $output);
        self::assertStringContainsString("Add (1):\n  engine/B.php", $output);
        self::assertStringContainsString('Dry run', $output);
        self::assertFileDoesNotExist($this->application . '/engine/B.php');
    }

    public function test_an_edited_framework_file_stops_the_update(): void
    {
        \file_put_contents($this->application . '/engine/A.php', 'a v1, patched');

        [$status, $output] = $this->console('framework:update');

        self::assertSame(1, $status);
        self::assertStringContainsString('engine/A.php  (edited here)', $output);
        self::assertStringContainsString('nothing was updated', $output);
        self::assertFileDoesNotExist($this->application . '/engine/B.php');
    }

    public function test_update_then_rollback(): void
    {
        [$status, $output] = $this->console('framework:update');

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Updated the framework from 3.0.0 to 3.1.0', $output);
        self::assertSame('a v2', \file_get_contents($this->application . '/engine/A.php'));
        self::assertSame('mine', \file_get_contents($this->application . '/tests/Unit/MyTest.php'));
        self::assertDirectoryDoesNotExist($this->application . '/system/Runtime/update/3.1.0', 'the work directory is cleared');

        [$status, $output] = $this->console('framework:update');
        self::assertStringContainsString('already at 3.1.0', $output);

        [$status, $output] = $this->console('framework:rollback');

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('from 3.1.0 to 3.0.0', $output);
        self::assertSame('a v1', \file_get_contents($this->application . '/engine/A.php'));
        self::assertFileDoesNotExist($this->application . '/engine/B.php');
    }

    public function test_an_older_release_needs_force(): void
    {
        [$status, $output] = $this->console('framework:update', '--to=2.9.0');

        self::assertSame(1, $status);
        self::assertStringContainsString('older than the installed 3.0.0', $output);
    }

    public function test_a_major_upgrade_needs_major(): void
    {
        [$status, $output] = $this->console('framework:update', '--to=4.0.0');

        self::assertSame(1, $status);
        self::assertStringContainsString('--major', $output);
    }
}
