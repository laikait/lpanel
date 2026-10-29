<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Bootstrap\Bootstrap;
use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Config\ConfigCache;
use App\Engine\Config\ConfigLoader;
use App\Engine\Config\ConfigurationException;
use App\Engine\Config\Env;
use App\Engine\Core\ExecutionContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Tests\Support\TestCase;

/**
 * Configuration through a real application.
 *
 * The chain being proved runs the length of the framework: a default written in
 * the engine, overridden by a file in config/, overridden by an environment
 * variable that file reads, reaching a handler that never learns where any of
 * it came from.
 */
final class ConfigurationSliceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $original = [];

    protected function setUp(): void
    {
        $this->original = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->original;

        parent::tearDown();
    }

    /** @return array{int, string} */
    private function console(string ...$arguments): array
    {
        $app = $this->application()->boot();
        $container = $app->container();

        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $status = (new ConsoleKernel(
            $container->get(CommandRegistry::class),
            $container->get(CommandDispatcher::class),
            $container->get(HookEngine::class),
            $container->get(ErrorHandler::class),
            new Output($stream),
        ))->handle(ExecutionContext::cli(\array_values(['laika', ...$arguments])));

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        return [$status, $output];
    }

    // ---- the layers, in order ---------------------------------------------

    public function test_the_defaults_are_there_with_no_files_at_all(): void
    {
        $settings = new Config(Bootstrap::defaults());

        self::assertSame('UTC', $settings->string('app.timezone'));
        self::assertSame([], $settings->strings('logging.writers'));
    }

    public function test_a_file_overrides_a_default(): void
    {
        // config/Example.php, read at bootstrap, before any module has
        // been discovered. The showcase directory stands in for an application
        // root, because the framework itself ships no config/.
        $settings = new Config(Bootstrap::settings($this->basePath(self::SHOWCASE)));

        self::assertSame(10, $settings->int('Example.page_size'));
    }

    public function test_what_the_bootstrap_is_handed_beats_a_file(): void
    {
        $settings = new Config(Bootstrap::settings(
            $this->basePath(self::SHOWCASE),
            ['Example' => ['page_size' => 3]],
        ));

        self::assertSame(3, $settings->int('Example.page_size'));
    }

    /**
     * The direction that is easy to get backwards. A module declares its own
     * defaults during registration, which happens long after config/ was read,
     * so an ordinary merge there would silently undo every override an
     * application had made.
     */
    public function test_a_module_declares_defaults_and_the_application_outranks_them(): void
    {
        $settings = $this->application()->boot()->container()->get(Config::class);

        // 10 is handed to Bootstrap by TestCase::application(), at the layer
        // config/ files arrive at; the module's own module.php says 25.
        self::assertSame(10, $settings->int('Example.page_size'), 'the application wins');
    }

    public function test_a_module_default_survives_where_no_file_names_it(): void
    {
        $settings = $this->fixtureApplication()->boot()->container()->get(Config::class);

        self::assertSame('USD', $settings->string('Shared.currency'));
    }

    /**
     * All the way through: the value reaches the handler, and the handler has
     * no idea a file was involved.
     */
    public function test_the_configured_value_reaches_the_response(): void
    {
        $response = $this->application()->boot()->handle(Request::create('GET', '/customers.json'));

        $body = \json_decode($response->body(), true);

        self::assertIsArray($body);
        self::assertIsArray($body['meta']);
        self::assertSame(10, $body['meta']['per_page'], 'the application\'s value, not the module default of 25');
    }

    // ---- the environment ---------------------------------------------------

    public function test_an_environment_variable_reaches_the_configuration(): void
    {
        $_ENV['APP_TIMEZONE'] = 'Europe/London';

        self::assertSame(
            'Europe/London',
            (new Config(Bootstrap::settings($this->basePath())))->string('app.timezone'),
        );
    }

    public function test_the_timezone_is_applied_to_php_itself(): void
    {
        $_ENV['APP_TIMEZONE'] = 'Asia/Tokyo';

        $this->application();

        try {
            self::assertSame('Asia/Tokyo', \date_default_timezone_get());
        } finally {
            $this->application(['app' => ['timezone' => 'UTC']]);
        }
    }

    /**
     * Checked rather than trusted: date_default_timezone_set() answers false
     * and warns for something it does not know, and then every date in the
     * application is quietly in whatever php.ini said.
     */
    public function test_an_unknown_timezone_stops_the_boot_and_says_which_key(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/app\.timezone/');

        $this->application(['app' => ['timezone' => 'Mars/Olympus_Mons']]);
    }

    // ---- the cache ----------------------------------------------------------

    /**
     * Every setting this framework ships can be cached. A default that is a
     * closure or an object would be discovered by whoever first ran
     * config:cache on a production machine, which is the worst possible time.
     */
    public function test_the_shipped_configuration_is_cacheable(): void
    {
        $this->expectNotToPerformAssertions();

        ConfigCache::assertPlainData(Bootstrap::settings($this->basePath()));
    }

    public function test_a_cached_configuration_boots_to_the_same_thing(): void
    {
        $directory = \sys_get_temp_dir() . '/config-slice-' . \bin2hex(\random_bytes(6));
        $file = $directory . '/config.php';

        Env::forget();
        $fresh = Bootstrap::settings($this->basePath(), cached: false);

        try {
            self::assertTrue(ConfigCache::write($file, $fresh, Env::reads()));
            self::assertSame($fresh, ConfigCache::read($file));
        } finally {
            @\unlink($file);
            @\rmdir($directory);
        }
    }

    public function test_the_cache_is_not_built_by_simply_running(): void
    {
        $this->application()->boot();

        self::assertFileDoesNotExist(
            ConfigCache::file($this->basePath()),
            'building it is a deployment step, not something a request does',
        );
    }

    // ---- the console ---------------------------------------------------------

    public function test_config_list_prints_resolved_values(): void
    {
        [$status, $output] = $this->console('config:list', '--prefix=Example');

        self::assertSame(ConsoleKernel::SUCCESS, $status);
        self::assertStringContainsString('Example.page_size', $output);
        self::assertStringContainsString('10', $output);
    }

    public function test_config_list_reports_where_configuration_came_from(): void
    {
        [, $output] = $this->console('config:list', '--sources', '--prefix=app');

        // The framework ships no config/ at all, and an application adds files
        // to it; either answer is a correct one, and what matters is that the
        // row says which. Listing the files themselves is ConfigLoader's job,
        // and ConfigLoaderTest proves it against a fixture directory.
        self::assertMatchesRegularExpression(
            '#Files\s+(none \(config/ is empty or absent\)|' . ConfigLoader::DIRECTORY . '/)#',
            $output,
        );
        self::assertStringContainsString('not built', $output);
    }

    /**
     * There is no flag to reveal these, because a flag like that gets used, and
     * where it gets used is a terminal somebody is sharing their screen from.
     */
    public function test_config_list_hides_the_values_worth_hiding(): void
    {
        $app = $this->application([
            'database' => ['connections' => ['default' => [
                'dsn' => 'mysql:host=localhost;dbname=erp',
                'username' => 'erp',
                'password' => 'hunter2',
            ]]],
        ])->boot();

        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        (new ConsoleKernel(
            $app->container()->get(CommandRegistry::class),
            $app->container()->get(CommandDispatcher::class),
            $app->container()->get(HookEngine::class),
            $app->container()->get(ErrorHandler::class),
            new Output($stream),
        ))->handle(ExecutionContext::cli(['laika', 'config:list', '-p', 'database']));

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        self::assertStringNotContainsString('hunter2', $output);
        self::assertStringContainsString('[hidden]', $output);
        self::assertStringContainsString('erp', $output, 'the username is still useful and still shown');
    }

    public function test_an_unknown_prefix_is_reported_rather_than_printing_nothing(): void
    {
        [$status, $output] = $this->console('config:list', '--prefix=nothing.like.this');

        self::assertSame(ConsoleKernel::FAILURE, $status);
        self::assertStringContainsString('No configuration key', $output);
    }
}
