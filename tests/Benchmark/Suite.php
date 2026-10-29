<?php

declare(strict_types=1);

namespace App\Tests\Benchmark;

use App\Engine\Asset\AssetManager;
use App\Engine\Asset\AssetServer;
use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Bootstrap\Bootstrap;
use App\Engine\Config\Config;
use App\Engine\Config\ConfigCache;
use App\Engine\Config\Env;
use App\Engine\Container\Container;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Data\ArraySource;
use App\Engine\Data\Query;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\SqlSource;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\MCP\McpAuthorizer;
use App\Engine\MCP\McpCollector;
use App\Engine\MCP\McpRegistry;
use App\Engine\MCP\McpServer;
use App\Engine\MCP\McpSession;
use App\Engine\MCP\Prompt\PromptProvider;
use App\Engine\MCP\Protocol\MessageParser;
use App\Engine\MCP\Resource\ResourceReader;
use App\Engine\MCP\Tool\ToolRunner;
use App\Engine\Model\ModelManager;
use App\Engine\Module\DependencyResolver;
use App\Engine\Module\ModuleContext;
use App\Engine\Module\ModuleDefinition;
use App\Engine\Module\ModuleDiscovery;
use App\Engine\Module\ModuleManager;
use App\Engine\Module\ModuleRegistry;
use App\Engine\Module\ModuleStage;
use App\Engine\Observability\Profiler;
use App\Engine\Observability\TraceKind;
use App\Engine\Observability\Tracer;
use App\Engine\Routing\Route;
use App\Engine\Routing\Router;
use App\Engine\Template\TemplateManager;
use App\Tests\Fixtures\MCP\GreetTool;
use App\Tests\Fixtures\Model\Customer;
use App\Tests\Fixtures\Model\CustomerListRecord;

/**
 * The benchmarks, one or more for every subject the specification names.
 *
 * Each measures the framework as it really runs wherever that is possible --
 * the real bootstrap, the real modules, the real template -- because a benchmark
 * of a simplified copy measures the copy. Where scale matters more than realism
 * (a 500-route table, a 50-module dependency graph) the input is synthetic and
 * says how big it is in its name.
 */
final class Suite
{
    /** Section 50 of the specification, verbatim. A test holds the suite to it. */
    public const SUBJECTS = [
        'Application boot',
        'Module discovery',
        'Route resolution',
        'Dependency resolution',
        'Database queries',
        'Model hydration',
        'Read-model queries',
        'Asset resolution',
        'Template rendering',
        'Hook execution',
        'Filter execution',
    ];

    /** @return list<Benchmark> */
    public static function all(string $basePath): array
    {
        return [
            ...self::boot($basePath),
            ...self::discovery($basePath),
            ...self::routing(),
            ...self::dependencies(),
            ...self::database(),
            ...self::assets($basePath),
            ...self::templates($basePath),
            ...self::extensions(),
        ];
    }

    // ---- application boot -------------------------------------------------

    /** @return list<Benchmark> */
    private static function boot(string $basePath): array
    {
        return [
            new Benchmark('Application boot', 'bootstrap and boot every module', static fn(): \Closure
                => static fn(): Application => self::application($basePath)->boot()),

            // The lazy path, and beside it the same request the way it was
            // served before Phase 27: every module booted first. If the two
            // lines ever converge, an asset request has started loading modules
            // again.
            new Benchmark('Application boot', 'bootstrap and serve /assets/core/css/app.css', static fn(): \Closure
                => static fn(): int => self::application($basePath)
                    ->handle(Request::create('GET', '/assets/core/css/app.css', ['server' => ['SCRIPT_NAME' => '/index.php']]))
                    ->status()),

            new Benchmark('Application boot', 'same asset, booting every module first', static fn(): \Closure
                => static fn(): int => self::application($basePath)
                    ->boot()
                    ->handle(Request::create('GET', '/assets/core/css/app.css', ['server' => ['SCRIPT_NAME' => '/index.php']]))
                    ->status()),

            new Benchmark('Application boot', 'bootstrap, boot and answer /customers.json', static fn(): \Closure
                => static fn(): int => self::application($basePath)
                    ->handle(Request::create('GET', '/customers.json', ['server' => ['SCRIPT_NAME' => '/index.php']]))
                    ->status()),

            // The two halves of what cache:warm changes for configuration.
            // The cache is written to a temporary file rather than to
            // system/Cache, because a benchmark that leaves a cache in the
            // working tree changes how the development server boots.
            new Benchmark('Application boot', 'read configuration from config/', static fn(): \Closure
                => static fn(): array => Bootstrap::settings($basePath, cached: false)),

            new Benchmark('Application boot', 'read the configuration cache', static function () use ($basePath): \Closure {
                $file = \sys_get_temp_dir() . '/framework-bench-config-' . \getmypid() . '.php';
                ConfigCache::write($file, Bootstrap::settings($basePath, cached: false), Env::reads());
                self::age($file);

                return static fn(): ?array => ConfigCache::read($file);
            }),

            // What an MCP manifest would have saved, and the reason there is
            // none: module.php declares capabilities on every boot, as it does
            // routes, and registering them is this cheap.
            new Benchmark('Application boot', 'register 100 MCP capabilities', static fn(): \Closure
                => static function (): McpRegistry {
                    $registry = new McpRegistry();
                    $mcp = new McpCollector($registry, 'Bench');

                    for ($i = 0; $i < 100; ++$i) {
                        $mcp->tool("bench.tool{$i}", GreetTool::class, 'A tool.', 'bench.view');
                    }

                    return $registry;
                }),

            // Beside "answer /customers.json": an MCP request should cost no
            // more than an API request does.
            new Benchmark('Application boot', 'bootstrap, boot and answer an MCP tools/call over HTTP', static fn(): \Closure
                => static fn(): int => Bootstrap::create(
                    $basePath,
                    ExecutionContext::http(['SCRIPT_NAME' => '/index.php', 'REQUEST_METHOD' => 'POST']),
                    [
                        'app' => ['handle_errors' => false],
                        'security' => ['key' => \str_repeat('k', 64)],
                        'modules' => ['paths' => ['tests/Fixtures/Modules/Shared', 'tests/Fixtures/Modules/Mcp/Plugins'], 'disabled' => ['Muted']],
                        'mcp' => ['transports' => ['http' => true]],
                    ],
                )->handle(Request::create('POST', '/mcp', [
                    'server' => ['SCRIPT_NAME' => '/index.php'],
                    'headers' => ['Authorization' => 'Bearer ada-token-do-not-use', 'Content-Type' => 'application/json'],
                    'body' => '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"zulu.greet","arguments":{"name":"Ada"}}}',
                ]))->status()),
        ];
    }

    // ---- module discovery -------------------------------------------------

    /** @return list<Benchmark> */
    private static function discovery(string $basePath): array
    {
        return [
            new Benchmark('Module discovery', 'scan the configured roots', static function () use ($basePath): \Closure {
                $discovery = ModuleDiscovery::fromConfig(self::application($basePath)->config(), $basePath);

                return static fn(): array => $discovery->scan();
            }),

            new Benchmark('Module discovery', 'read the discovery cache', static function () use ($basePath): \Closure {
                $discovery = ModuleDiscovery::fromConfig(self::application($basePath)->config(), $basePath);
                $registry = new ModuleRegistry();

                foreach ($discovery->scan() as $definition) {
                    $registry->add($definition);
                }

                $file = \sys_get_temp_dir() . '/framework-bench-modules-' . \getmypid() . '.php';
                $registry->writeCache($file, $discovery->roots());
                self::age($file);
                $roots = $discovery->roots();

                return static fn(): bool => (new ModuleRegistry())->readCache($file, $roots);
            }),
        ];
    }

    // ---- routing ----------------------------------------------------------

    /** @return list<Benchmark> */
    private static function routing(): array
    {
        $table = static function (): array {
            $routes = [];

            for ($i = 0; $i < 250; ++$i) {
                $routes[] = new Route('GET', "/area{$i}/items", 'handler');
                $routes[] = (new Route('GET', "/area{$i}/items/{id}/edit", 'handler'))->where('id', '\d+');
            }

            return $routes;
        };

        $compiled = static function () use ($table): Router {
            $router = new Router();

            foreach ($table() as $route) {
                $router->add($route);
            }

            $router->compile();

            return $router;
        };

        return [
            new Benchmark('Route resolution', 'compile a 500-route table', static function () use ($table): \Closure {
                $routes = $table();

                return static function () use ($routes): void {
                    $router = new Router();

                    foreach ($routes as $route) {
                        $router->add($route);
                    }

                    $router->compile();
                };
            }),

            new Benchmark('Route resolution', 'match a static path among 500', static function () use ($compiled): \Closure {
                $router = $compiled();

                return static fn(): mixed => $router->match('GET', '/area249/items');
            }),

            new Benchmark('Route resolution', 'match a constrained parameter among 500', static function () use ($compiled): \Closure {
                $router = $compiled();

                return static fn(): mixed => $router->match('GET', '/area249/items/42/edit');
            }),

            new Benchmark('Route resolution', 'miss among 500 (404 with a 405 probe)', static function () use ($compiled): \Closure {
                $router = $compiled();

                return static fn(): mixed => $router->match('GET', '/nowhere/at/all');
            }),

            // An MCP tool name is resolved as a route is: a registered name to
            // a handler, then authorized, validated and called.
            new Benchmark('Route resolution', 'MCP: authorize, validate and call one tool among 200', static function (): \Closure {
                [$server, $session] = self::mcpServer(200);

                return static fn(): ?string => $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"bench.tool150","arguments":{"name":"Ada"}}}', $session);
            }),

            new Benchmark('Route resolution', 'MCP: tools/list, 200 tools visible', static function (): \Closure {
                [$server, $session] = self::mcpServer(200);

                return static fn(): ?string => $server->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $session);
            }),
        ];
    }

    /** @return array{McpServer, McpSession} a server over $tools tools, and a session allowed all of them */
    private static function mcpServer(int $tools): array
    {
        $access = new AccessRegistry();
        (new AccessCollector($access, 'Bench'))->capability('bench.view')->role('agent', ['bench.view']);

        $registry = new McpRegistry();
        $mcp = new McpCollector($registry, 'Bench');

        for ($i = 0; $i < $tools; ++$i) {
            $mcp->tool("bench.tool{$i}", GreetTool::class, 'A tool.', 'bench.view');
        }

        $authorizer = new McpAuthorizer(new Authorizer($access));
        $container = new Container();
        $server = new McpServer(
            new MessageParser(),
            new ToolRunner($registry, $container, $authorizer),
            new ResourceReader($registry, $container, $authorizer),
            new PromptProvider($registry, $container, $authorizer),
            $registry,
            'bench',
            '1',
        );

        $session = new McpSession(new Identity('7', 'ada', ['agent']), 'stdio');
        $session->initialize('2025-06-18', 'bench', '1');

        return [$server, $session];
    }

    // ---- dependency resolution --------------------------------------------

    /** @return list<Benchmark> */
    private static function dependencies(): array
    {
        return [
            new Benchmark('Dependency resolution', 'resolve 50 modules, a chain of 25', static function (): \Closure {
                $contexts = [];

                for ($i = 0; $i < 50; ++$i) {
                    $context = new ModuleContext(ModuleDefinition::create("/bench/P{$i}", "P{$i}"));
                    $context->enterStage(ModuleStage::Loading);
                    $context->version('1.0.0');

                    if ($i > 0 && $i < 25) {
                        $context->requires('P' . ($i - 1), '^1.0');
                    }

                    $contexts[] = $context;
                }

                // Declared out of order, so the sort has work to do.
                $contexts = \array_reverse($contexts);
                $resolver = new DependencyResolver();

                return static fn(): array => $resolver->resolve($contexts);
            }),
        ];
    }

    // ---- the data layer ---------------------------------------------------

    /** @return list<Benchmark> */
    private static function database(): array
    {
        $rows = [];

        for ($i = 1; $i <= 1000; ++$i) {
            $rows[] = [
                'id' => $i,
                'name' => 'Customer ' . \str_pad((string) $i, 4, '0', \STR_PAD_LEFT),
                'email' => "customer{$i}@example.test",
                'ownerId' => $i % 7 === 0 ? null : $i % 50,
                'active' => $i % 3 === 0 ? 0 : 1,
                'balance' => $i * 1.25,
            ];
        }

        $benchmarks = [
            new Benchmark('Model hydration', 'hydrate 100 rows into domain models', static function () use ($rows): \Closure {
                $slice = \array_slice($rows, 0, 100);
                $models = new ModelManager();

                return static function () use ($slice, $models): int {
                    // Flushed every time, or the identity map answers from
                    // memory and this measures an array lookup.
                    $models->flush();

                    return $models->hydrateAll(Customer::class, $slice)->count();
                };
            }),

            new Benchmark('Read-model queries', 'project 100 rows into read models (in memory)', static function () use ($rows): \Closure {
                $source = new ArraySource(['customers' => $rows]);
                $models = new ModelManager();

                return static fn(): array => Query::on($source, 'customers', $models)
                    ->limit(100)
                    ->into(CustomerListRecord::class);
            }),
        ];

        if (!\in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            return $benchmarks;
        }

        $database = static function () use ($rows): SqlSource {
            $connection = new Connection(ConnectionConfig::of('bench', 'sqlite::memory:'));
            $connection->execute(
                'CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL, '
                . 'ownerId INTEGER NULL, active INTEGER NOT NULL, balance REAL NOT NULL)',
            );
            $connection->execute('CREATE INDEX customers_active ON customers (active, name)');

            $source = new SqlSource($connection);
            $source->insertMany('customers', 'id', $rows);

            return $source;
        };

        return [
            ...$benchmarks,

            new Benchmark('Database queries', 'select 50 rows by criteria, ordered (sqlite)', static function () use ($database): \Closure {
                $source = $database();
                $models = new ModelManager();

                return static fn(): array => Query::on($source, 'customers', $models)
                    ->whereIs('active', 1)
                    ->orderBy('name')
                    ->limit(50)
                    ->rows();
            }),

            new Benchmark('Database queries', 'count by criteria (sqlite)', static function () use ($database): \Closure {
                $source = $database();
                $models = new ModelManager();

                return static fn(): int => Query::on($source, 'customers', $models)->whereIs('active', 1)->count();
            }),

            // The pair that says what bulk writes are for. Both roll back, so
            // the table is the same size on every call.
            new Benchmark('Database queries', 'insert 100 rows one at a time (sqlite, rolled back)', static function () use ($database): \Closure {
                $source = $database();
                $batch = self::freshRows(100);

                return static function () use ($source, $batch): void {
                    $source->connection()->begin();

                    foreach ($batch as $row) {
                        $source->insert('customers', 'id', $row);
                    }

                    $source->connection()->rollBack();
                };
            }),

            new Benchmark('Database queries', 'insert 100 rows with insertMany (sqlite, rolled back)', static function () use ($database): \Closure {
                $source = $database();
                $batch = self::freshRows(100);

                return static function () use ($source, $batch): void {
                    $source->connection()->begin();
                    $source->insertMany('customers', 'id', $batch);
                    $source->connection()->rollBack();
                };
            }),

            new Benchmark('Model hydration', 'query 50 rows into domain models (sqlite)', static function () use ($database): \Closure {
                $source = $database();
                $models = new ModelManager();

                return static function () use ($source, $models): int {
                    $models->flush();

                    return Query::on($source, 'customers', $models, Customer::class)->limit(50)->get()->count();
                };
            }),

            new Benchmark('Read-model queries', 'query 50 rows into read models, three columns (sqlite)', static function () use ($database): \Closure {
                $source = $database();
                $models = new ModelManager();

                return static fn(): array => Query::on($source, 'customers', $models)
                    ->select('id', 'name', 'email')
                    ->limit(50)
                    ->into(CustomerListRecord::class);
            }),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function freshRows(int $count): array
    {
        $rows = [];

        for ($i = 0; $i < $count; ++$i) {
            $rows[] = ['name' => "New {$i}", 'email' => "new{$i}@example.test", 'ownerId' => null, 'active' => 1, 'balance' => 0.0];
        }

        return $rows;
    }

    // ---- assets and templates ---------------------------------------------

    /** @return list<Benchmark> */
    private static function assets(string $basePath): array
    {
        return [
            new Benchmark('Asset resolution', 'versioned URL for a core asset', static function () use ($basePath): \Closure {
                $assets = self::application($basePath)->container()->get(AssetManager::class);

                return static fn(): string => $assets->core('css/app.css');
            }),

            new Benchmark('Asset resolution', 'locate a published plugin asset from its URL', static function () use ($basePath): \Closure {
                $app = self::application($basePath)->boot();
                $server = $app->container()->get(AssetServer::class);

                return static fn(): mixed => $server->locate('/assets/module/Example/js/example.js');
            }),
        ];
    }

    /** @return list<Benchmark> */
    private static function templates(string $basePath): array
    {
        return [
            new Benchmark('Template rendering', 'render the customer list, 20 rows (PHP)', static function () use ($basePath): \Closure {
                $templates = self::application($basePath)->boot()->container()->get(TemplateManager::class);
                $customers = [];

                for ($i = 1; $i <= 20; ++$i) {
                    $customers[] = new CustomerListRecord($i, "Customer {$i}", "customer{$i}@example.test");
                }

                return static fn(): string => $templates->render('@Example/customers', ['customers' => $customers, 'total' => 20]);
            }),

            // Twig compiles each template to a PHP class once per process (or
            // once per deployment, with templates.cache on); what this measures
            // is every render after that first one, which is the steady state.
            new Benchmark('Template rendering', 'render the default home page and its layout (Twig)', static function () use ($basePath): \Closure {
                $templates = self::application($basePath)->boot()->container()->get(TemplateManager::class);
                $templates->render('home', ['home' => '/']);

                return static fn(): string => $templates->render('home', ['home' => '/']);
            }),
        ];
    }

    // ---- hooks and filters ------------------------------------------------

    /** @return list<Benchmark> */
    private static function extensions(): array
    {
        return [
            new Benchmark('Hook execution', 'fire a hook with 10 listeners', static function (): \Closure {
                $hooks = new HookEngine();

                for ($i = 0; $i < 10; ++$i) {
                    $hooks->add('bench.happened', static function (int $value): void {}, $i * 5, 'bench');
                }

                return static fn() => $hooks->do('bench.happened', 42);
            }),

            // The price of profiling, beside the line above: the same hook with
            // the profiler attached. With profiling off nothing is attached, so
            // the line above is also what an unprofiled application pays.
            new Benchmark('Hook execution', 'fire a hook with 10 listeners, profiled', static function (): \Closure {
                $hooks = new HookEngine();
                $tracer = new Tracer();
                $profiler = new Profiler(true);
                $profiler->instrument($tracer, $hooks, new FilterEngine(), new ModuleManager(
                    new Container(),
                    new Config(),
                    new Router(),
                    $hooks,
                    new FilterEngine(),
                ));
                $tracer->begin(TraceKind::Http);

                for ($i = 0; $i < 10; ++$i) {
                    $hooks->add('bench.happened', static function (int $value): void {}, $i * 5, 'bench');
                }

                return static fn() => $hooks->do('bench.happened', 42);
            }),

            new Benchmark('Hook execution', 'fire a hook nobody listens to', static function (): \Closure {
                $hooks = new HookEngine();

                return static fn() => $hooks->do('bench.unheard', 42);
            }),

            new Benchmark('Filter execution', 'apply a filter with 10 listeners', static function (): \Closure {
                $filters = new FilterEngine();

                for ($i = 0; $i < 10; ++$i) {
                    $filters->add('bench.value', static fn(int $value): int => $value + 1, $i * 5, 'bench');
                }

                return static fn(): mixed => $filters->apply('bench.value', 0);
            }),
        ];
    }

    // ---- shared -----------------------------------------------------------

    /**
     * Make a file that was just written look like one written at deploy time.
     *
     * opcache will not cache a file modified within the last couple of seconds
     * (opcache.file_update_protection), so a cache written and then immediately
     * read is recompiled on every read -- and a first version of this benchmark
     * reported the configuration cache as twice as SLOW as reading config/. A
     * real cache file was written by the deployment, long before the request.
     */
    private static function age(string $file): void
    {
        \touch($file, \time() - 60);
        \clearstatcache(true, $file);

        // Gone when the process is. The test suite prepares every benchmark on
        // every run, and a temporary directory is not somewhere to leave a new
        // pair of files each time.
        \register_shutdown_function(static function () use ($file): void {
            @\unlink($file);
        });
    }

    /**
     * The real application, as a web request would build it.
     *
     * Error handling is off because a benchmark that installs a process-wide
     * error handler a few thousand times is measuring set_error_handler().
     */
    /**
     * The showcase modules from the test fixtures, shared included, so there
     * is a plugin with routes, assets and templates to
     * measure. A bare installation has almost nothing to boot, and a number
     * taken from nothing says nothing about an application.
     */
    private static function application(string $basePath): Application
    {
        return Bootstrap::create(
            $basePath,
            ExecutionContext::http(['SCRIPT_NAME' => '/index.php', 'REQUEST_METHOD' => 'GET']),
            [
                'app' => ['handle_errors' => false],
                'modules' => ['paths' => [
                    'tests/Fixtures/Showcase/Shared',
                    'tests/Fixtures/Showcase/Plugins',
                    'tests/Fixtures/Showcase/Gateways',
                ]],
            ],
        );
    }
}
