<?php

declare(strict_types=1);

namespace App\Engine\Bootstrap;

use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetManager;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetResolver;
use App\Engine\Asset\AssetServer;
use App\Engine\Asset\AssetSource;
use App\Engine\Asset\AssetVersioning;
use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\Authenticators\SessionAuthenticator;
use App\Engine\Auth\Authenticators\TokenAuthenticator;
use App\Engine\Auth\AuthGuard;
use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Authorizer;
use App\Engine\Auth\EmailVerification;
use App\Engine\Auth\Identity;
use App\Engine\Auth\Password;
use App\Engine\Auth\PasswordReset;
use App\Engine\Auth\Providers\EmptyProvider;
use App\Engine\Auth\Social\FacebookProvider;
use App\Engine\Auth\Social\GitHubProvider;
use App\Engine\Auth\Social\OidcProvider;
use App\Engine\Auth\Social\Provider;
use App\Engine\Auth\Social\SocialException;
use App\Engine\Auth\Social\SocialLogin;
use App\Engine\Auth\TokenProvider;
use App\Engine\Auth\TwoFactor;
use App\Engine\Auth\UserProvider;
use App\Engine\Cache\Cache;
use App\Engine\Cache\CacheStore;
use App\Engine\Cache\CacheTableMigration;
use App\Engine\Cache\Stores\ArrayStore;
use App\Engine\Cache\Stores\DatabaseStore as CacheDatabaseStore;
use App\Engine\Cache\Stores\FileStore;
use App\Engine\Cache\Stores\NullStore;
use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\CoreCommands;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Config\ConfigCache;
use App\Engine\Config\ConfigLoader;
use App\Engine\Config\ConfigurationException;
use App\Engine\Config\DotEnv;
use App\Engine\Config\Env;
use App\Engine\Container\Container;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Core\HttpKernel;
use App\Engine\Core\Maintenance;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;
use App\Engine\Dispatch\Dispatcher;
use App\Engine\Error\ErrorContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Error\ErrorPage;
use App\Engine\Feature\Features;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Client\Client;
use App\Engine\Http\Client\CurlTransport;
use App\Engine\Http\Client\StreamTransport;
use App\Engine\Http\HttpException;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Localization\ChainCountryResolver;
use App\Engine\Localization\CountryResolver;
use App\Engine\Localization\HeaderCountryResolver;
use App\Engine\Localization\LocaleResolver;
use App\Engine\Localization\Localization;
use App\Engine\Localization\MaxMindCountryResolver;
use App\Engine\Localization\NullCountryResolver;
use App\Engine\Localization\TranslationCatalog;
use App\Engine\Logging\Context;
use App\Engine\Logging\ErrorLog;
use App\Engine\Logging\Level;
use App\Engine\Logging\Logger;
use App\Engine\Logging\LogManager;
use App\Engine\Logging\LogTableMigration;
use App\Engine\Logging\LogWriter;
use App\Engine\Logging\McpLog;
use App\Engine\Logging\ScheduleLog;
use App\Engine\Logging\SystemAuditLog;
use App\Engine\Logging\Writers\DatabaseWriter;
use App\Engine\Logging\Writers\FileWriter;
use App\Engine\Logging\Writers\StreamWriter;
use App\Engine\Logging\Writers\SyslogWriter;
use App\Engine\Mail\Address;
use App\Engine\Mail\Mailer;
use App\Engine\Mail\MailException;
use App\Engine\Mail\Transport;
use App\Engine\Mail\Transports\ArrayTransport;
use App\Engine\Mail\Transports\LogTransport;
use App\Engine\Mail\Transports\SendmailTransport;
use App\Engine\Mail\Transports\SmtpTransport;
use App\Engine\MCP\McpAuthorizer;
use App\Engine\MCP\McpConfig;
use App\Engine\MCP\McpRegistry;
use App\Engine\MCP\McpServer;
use App\Engine\MCP\Prompt\PromptProvider;
use App\Engine\MCP\Protocol\MessageParser;
use App\Engine\MCP\Resource\ResourceReader;
use App\Engine\MCP\Tool\ToolRunner;
use App\Engine\MCP\Transport\HttpTransport;
use App\Engine\MCP\Transport\StdioTransport;
use App\Engine\Migration\MigrationFile;
use App\Engine\Migration\Migrator;
use App\Engine\Migration\SeedRunner;
use App\Engine\Model\ModelManager;
use App\Engine\Model\RelationManager;
use App\Engine\Module\ModuleDefinition;
use App\Engine\Module\ModuleManager;
use App\Engine\Module\ModuleRegistry;
use App\Engine\Observability\Profiler;
use App\Engine\Observability\Report;
use App\Engine\Observability\Tracer;
use App\Engine\OpenApi\OpenApiDocs;
use App\Engine\OpenApi\OpenApiGenerator;
use App\Engine\OpenApi\OpenApiRoutes;
use App\Engine\OpenApi\OpenApiState;
use App\Engine\Queue\Backoff;
use App\Engine\Queue\JobRunner;
use App\Engine\Queue\Queue;
use App\Engine\Queue\QueueStore;
use App\Engine\Queue\QueueTableMigration;
use App\Engine\Queue\Stores\DatabaseStore as QueueDatabaseStore;
use App\Engine\Queue\Stores\FileStore as QueueFileStore;
use App\Engine\Queue\Stores\MemoryStore;
use App\Engine\Queue\Stores\SyncStore;
use App\Engine\Queue\Worker;
use App\Engine\Routing\AppUrl;
use App\Engine\Routing\Route;
use App\Engine\Routing\Router;
use App\Engine\Routing\RoutingException;
use App\Engine\Routing\UrlSigner;
use App\Engine\Scheduler\Locks\FileLock;
use App\Engine\Scheduler\Locks\MemoryLock;
use App\Engine\Scheduler\ScheduleLock;
use App\Engine\Scheduler\Scheduler;
use App\Engine\Scheduler\ScheduleRegistry;
use App\Engine\Security\Counters\FileStore as CounterFileStore;
use App\Engine\Security\Counters\MemoryStore as CounterMemoryStore;
use App\Engine\Security\CounterStore;
use App\Engine\Security\Csrf;
use App\Engine\Security\Encrypter;
use App\Engine\Security\Guard;
use App\Engine\Security\RateLimiter;
use App\Engine\Security\RequestLimits;
use App\Engine\Security\Secret;
use App\Engine\Security\SecurityHeaders;
use App\Engine\Security\SignedUrl;
use App\Engine\Security\Signer;
use App\Engine\Security\Webhooks;
use App\Engine\Session\Session;
use App\Engine\Session\SessionManager;
use App\Engine\Session\SessionStore;
use App\Engine\Session\SessionTableMigration;
use App\Engine\Session\Stores\ArrayStore as SessionArrayStore;
use App\Engine\Session\Stores\DatabaseStore as SessionDatabaseStore;
use App\Engine\Session\Stores\FileStore as SessionFileStore;
use App\Engine\Storage\Storage;
use App\Engine\Storage\StorageException;
use App\Engine\Support\Extensions;
use App\Engine\Support\Path;
use App\Engine\Support\Sealer;
use App\Engine\System\Audit\SystemAudit;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\Invocation;
use App\Engine\System\Cron\CronManager;
use App\Engine\System\Cron\UserCrontab;
use App\Engine\System\Filesystem\SystemFilesystem;
use App\Engine\System\Permission\PermissionManager;
use App\Engine\System\Process\ProcessManager;
use App\Engine\System\Security\SystemAuthorizer;
use App\Engine\System\Service\ServiceManager;
use App\Engine\System\SystemConfig;
use App\Engine\System\Systemd\SystemdManager;
use App\Engine\System\SystemDisabledException;
use App\Engine\Template\Escaper;
use App\Engine\Template\PhpTemplateEngine;
use App\Engine\Template\TemplateException;
use App\Engine\Template\TemplateHelpers;
use App\Engine\Template\TemplateManager;
use App\Engine\Template\TemplateRegistry;
use App\Engine\Template\TemplateSource;
use App\Engine\Template\ThemeProvider;
use App\Engine\Template\ThemeRegistry;
use App\Engine\Template\TwigTemplateEngine;
use App\Engine\Update\GithubReleaseSource;
use App\Engine\Update\ReleaseSource;
use App\Engine\Update\Updater;

/**
 * Builds the container and hands back an application that has not run yet.
 *
 * Pure wiring: no discovery, no routing, no I/O beyond reading the environment.
 * Everything that could fail interestingly happens in Application::boot(),
 * which makes a bootstrap failure easy to tell apart from a module failure.
 *
 * The entry script that calls this lives at engine/bootstrap.php rather than
 * inside this directory, because Windows filesystems are case-insensitive and
 * "bootstrap.php" next to "Bootstrap.php" is the same file there.
 */
final class Bootstrap
{
    /** @param array<string, mixed> $config overrides merged over the defaults */
    public static function create(string $basePath, ExecutionContext $context, array $config = []): Application
    {
        // The error handler before anything that can fail -- including reading
        // the configuration, which runs every config/*.php. Registered later,
        // a config file that threw met PHP's own handler: its message, its
        // stack trace and its paths, printed by PHP, with no debug page.
        //
        // It cannot have the configuration yet, so it starts with what the
        // environment says about debug, and a page that knows no templates.
        // configure() below hands it the real ones.
        $errors = self::earlyErrors($basePath, $config);

        $container = new Container();
        $settings = new Config(self::settings($basePath, $config));

        // Before anything formats a date. An ERP that books an invoice against
        // the wrong day because the server was installed in a different
        // country is a support call nobody enjoys, and PHP's own default is
        // whatever the ini file says.
        self::applyTimezone($settings);

        // Before anything large is built, for the same reason: php.ini differs
        // between a laptop and the server, and a limit that is only right on
        // one of them is found by the first big report in production.
        self::applyMemoryLimit($settings);
        self::applyTimeLimit($settings, $context);

        $hooks = new HookEngine();
        $filters = new FilterEngine((bool) $settings->get('app.debug', false));
        $router = new Router();

        // The cache, before anything that might want one. It is built from
        // configuration and it is never asked for statically: everything that
        // caches is handed its own namespace here, which is what makes each of
        // them testable with a store that keeps nothing.
        //
        // Note which caches are NOT this one. Module discovery and the compiled
        // configuration keep their own var_export files, built by cache:warm,
        // and for good reasons:
        // configuration is read before this object can exist, and both hold
        // plain data that changes only at deploy time, which is exactly what
        // opcache is better at than anything written here could be.
        $cache = self::cache($settings, $basePath, $container);

        // The asset layer. The registry starts with the two sources that are
        // facts about the layout rather than declarations by anybody: the
        // application's own public/assets/ and templates/assets/. Module
        // sources are published during module registration, because that is
        // when the set of modules is known.
        //
        // asset()->template('css/app.css') is templates/assets/css/app.css.
        $assets = new AssetRegistry();
        $assets->register(new AssetSource(AssetKind::Core, null, Path::join($basePath, 'public', 'assets')));
        $assets->register(new AssetSource(
            AssetKind::Template,
            null,
            Path::join($basePath, 'templates', 'assets'),
        ));

        // Themes: templates/<theme>/ and templates/<area>/<theme>/, each with
        // views and an assets/ folder. Registered, never discovered -- which
        // themes exist is the application's decision, made in
        // templates.themes or by a provider that may read the database. The
        // loader runs once Application knows the modules (their ids are
        // reserved: templates/<Module>/ is that module's override directory),
        // on asset requests as well as pages.
        $themes = new ThemeRegistry(
            $assets,
            Path::join($basePath, 'templates'),
            static fn(ThemeRegistry $themes) => self::loadThemes($themes, $settings, $container),
        );

        $manager = new AssetManager(
            $assets,
            $resolver = new AssetResolver(),
            self::assetBaseUrl($settings, $context),
            AssetVersioning::parse($settings->get('assets.versioning')),
            (bool) $settings->get('assets.manifests', true),
            // Strict in development only. A missing asset should be loud while
            // somebody is writing the page and quiet once it is in production,
            // where a broken stylesheet is a smaller problem than a blank page.
            (bool) $settings->get('app.debug', false),
            // Version tokens survive the request that computed them, which for
            // content hashing is the difference between hashing every asset on
            // every page and hashing it once per deployment. Not in debug: a
            // stylesheet being edited has to bust its own URL.
            $cache->namespace('assets'),
        );

        // The template layer. templates/ is registered at override precedence,
        // which is what lets a site replace a module's markup by dropping a
        // file into templates/<namespace>/; module directories are registered
        // during module registration, below that. Its assets/ holds static
        // files, and the manager refuses it as a view.
        $views = new TemplateRegistry();
        $views->add(null, Path::join($basePath, 'templates'), TemplateSource::OVERRIDE);

        // Template resolution is a filesystem search per name, and a page with
        // a layout and six partials does it seven times. The manager memoises
        // within a request on its own; the cache is what carries the answer
        // into the next one.
        //
        // Translation is handed to both as a closure that resolves the
        // localization service on first use, so a page that translates nothing
        // builds none of it, and an application that rebinds CountryResolver
        // in a module is still the one that is used.
        $translator = static fn(string $key, array $parameters = []): string => $container
            ->get(Localization::class)
            ->get($key, $parameters);

        // Filters and functions modules offer to templates. A callback naming
        // a class is built through the container when a template first calls
        // it, as a route handler is; see TemplateHelpers.
        $helpers = new TemplateHelpers(static fn(string $class): object => $container->get($class));

        $templates = new TemplateManager(
            $views,
            $manager,
            new Escaper(),
            $cache->namespace('templates'),
            $translator,
            $helpers,
            $themes,
        );

        // Twig first, PHP second, and the order is the precedence: where a
        // directory holds both home.twig and home.php, the Twig one renders.
        //
        // Twig is the default because it escapes everything it prints unless
        // told otherwise, which turns forgetting to escape from a hole into a
        // double-escaped string somebody notices. PHP templates stay, because
        // a module that already ships .php views should not have to be
        // rewritten, and because opcache makes them the cheapest thing to run.
        $templates->addEngine(new TwigTemplateEngine(
            $views,
            (bool) $settings->get('templates.cache', false)
                ? Path::join($basePath, 'system', 'Cache', 'templates')
                : null,
            (bool) $settings->get('app.debug', false),
            $translator,
            $helpers,
            $themes,
        ));
        $templates->addEngine(new PhpTemplateEngine());

        // The error handler is built here rather than earlier because it
        // renders through the template layer: an application that ships a
        // templates/errors/404 template gets its own page, in its own layout,
        // instead of the framework's. It is given the real hook engine, because
        // error.reported is how the logging phase will hear about failures and
        // a private engine would fire into nothing.
        //
        // In debug mode with filp/whoops installed, the diagnostic page is
        // Whoops; app.editor makes its file paths open in an editor.
        $errors->configure($settings, $hooks, new ErrorPage(
            $templates,
            $basePath,
            $settings->string('app.editor'),
            Env::names(),
            $themes,
        ));

        // The log. It listens to the error handler rather than being called by
        // it: error.reported is a hook, ErrorLog is an ordinary listener, and
        // the dependency runs one way only. Remove this line and errors stop
        // being logged without anything else changing, which is what
        // "logging must be independent from error rendering" has to mean if it
        // means anything.
        $logs = self::logging($settings, $basePath, $container);
        $hooks->add('error.reported', (new ErrorLog($logs))(...), 10, 'engine');

        // Observability. The tracer is always on: it costs an id and a clock
        // reading per unit of work, and every log record from here on carries
        // which request, command or job wrote it. The profiler is attached only
        // when asked for -- see Profiler for why "off" means "not attached"
        // rather than "attached and checking a flag".
        $tracer = new Tracer($settings->bool('observability.trust_incoming_ids', false));
        $profiler = new Profiler($settings->bool('observability.profile', false));
        $report = new Report($tracer, $profiler, $logs, (bool) $settings->get('app.debug', false));

        $logs->enrich($tracer->logContext(...));

        // The global helpers are a bridge to these four instances, and to
        // nothing else. See Support\Extensions for why that is not a facade.
        Extensions::init($hooks, $filters, $manager, $templates);

        $container->instance(Container::class, $container);
        $container->instance(Config::class, $settings);
        $container->instance(Cache::class, $cache);
        $container->instance(CacheStore::class, $cache->store());
        $container->instance(HookEngine::class, $hooks);
        $container->instance(FilterEngine::class, $filters);
        $container->instance(Router::class, $router);
        $container->instance(ErrorHandler::class, $errors);
        $container->instance(LogManager::class, $logs);
        $container->instance(Tracer::class, $tracer);
        $container->instance(Profiler::class, $profiler);
        $container->instance(Report::class, $report);
        // Injecting a Logger gets the default channel; a module that wants its
        // own asks the manager for it by name.
        $container->instance(Logger::class, $logs->channel());
        $container->instance(ExecutionContext::class, $context);
        $container->instance(AssetRegistry::class, $assets);
        $container->instance(AssetResolver::class, $resolver);
        $container->instance(AssetManager::class, $manager);
        $container->instance(TemplateRegistry::class, $views);
        $container->instance(TemplateManager::class, $templates);
        $container->instance(ThemeRegistry::class, $themes);

        // Every request starts with no theme. The active theme is state on a
        // shared object, so without this one request's theme would carry into
        // the next one served by the same process -- a worker, a test, an
        // Application::handle() loop -- and "/" would render in /admin's theme.
        $hooks->add('request.received', static fn() => $themes->use(null), 0, 'engine');

        // A route's theme, from meta(['theme' => 'admin/dark']) on it or its
        // group. Priority 1, ahead of the guards at 5 and 8, so that the 401,
        // 403 or 419 they throw renders in the route's theme too. The name is
        // trimmed: 'admin/dark', '/admin/dark' and '/admin/dark/' are one theme.
        $hooks->add('dispatch.before', static function (Route $route) use ($themes): void {
            $theme = $route->metaValue(ThemeRegistry::META);

            if (\is_string($theme) && $theme !== '') {
                $themes->use($theme);
            }
        }, 1, 'engine');
        $container->instance(TemplateHelpers::class, $helpers);
        self::features($container, $settings, $hooks, $filters, $helpers);

        // The console. The framework's own commands are registered here through
        // the same collector a module uses, so there is nothing the kernel
        // treats as a built-in. Registering them in an HTTP request too is
        // deliberate: a half-populated registry that depends on how the process
        // started is a debugging trap worth more than the seven objects it
        // would save.
        $commands = new CommandRegistry();
        CoreCommands::register($commands);

        $container->instance(CommandRegistry::class, $commands);
        $container->singleton(CommandDispatcher::class);

        // The schedules. Empty until modules register; the registry exists
        // first because the module manager fills it and the console reads it.
        $schedules = new ScheduleRegistry();
        $container->instance(ScheduleRegistry::class, $schedules);

        // Lazy on purpose: constructing this opens a stream, and an HTTP
        // request has no business holding one.
        $container->singleton(Output::class);

        $container->instance(AssetServer::class, new AssetServer(
            $assets,
            $resolver,
            $filters,
            (bool) $settings->get('app.debug', false),
            \is_int($maxAge = $settings->get('assets.max_age', AssetServer::IMMUTABLE_MAX_AGE)) ? $maxAge : null,
        ));

        // Model infrastructure. Both are stateful for the life of the request
        // -- one holds the identity map, the other the relation declarations --
        // so they are shared rather than rebuilt per injection.
        $container->singleton(ModelManager::class);
        $container->singleton(RelationManager::class);

        // The configured connections. Nothing is opened here and nothing is
        // opened when this resolves either: a Connection dials on first use, so
        // a request that never reads the database never opens a socket.
        //
        // Note what is NOT bound: DataSource. Which source an application reads
        // through is an application decision, made in a module, not something
        // the framework decides on its behalf.
        $container->singleton(ConnectionManager::class, static function () use ($settings, $report, $hooks): ConnectionManager {
            /** @var mixed $connections */
            $connections = $settings->get('database.connections', []);
            /** @var mixed $default */
            $default = $settings->get('database.default');

            $manager = ConnectionManager::fromArray(
                \is_array($connections) ? $connections : [],
                \is_string($default) ? $default : null,
            );

            // Here, inside the lazy factory, so that wanting query timing does
            // not make every request build a connection manager it never uses.
            $report->watchQueries($manager, $settings->int('observability.slow_query_ms', 0) ?? 0);

            // What happens to statements and transactions, as database.* hooks.
            // The database layer announces; only here does it meet the hook
            // engine, so it stays usable without the application around it.
            // Nothing that listens by logging can recurse. The database log
            // writer uses a connection of its own, which nothing observes --
            // except on SQLite, where it shares this one, and a record logged
            // while a record is being written is dropped by the log manager.
            //
            // Spelled out rather than 'database.' . $event, so that the hooks
            // the engine fires are a closed list the documentation is checked
            // against.
            $manager->listen(static function (string $event, mixed ...$arguments) use ($hooks): void {
                match ($event) {
                    'query.failed' => $hooks->do('database.query.failed', ...$arguments),
                    'transaction.committed' => $hooks->do('database.transaction.committed', ...$arguments),
                    'transaction.rolled_back' => $hooks->do('database.transaction.rolled_back', ...$arguments),
                    'transaction.retrying' => $hooks->do('database.transaction.retrying', ...$arguments),
                    default => throw new \LogicException(\sprintf('The database layer announced "%s", which has no hook.', $event)),
                };
            });

            return $manager;
        });

        // Lazy: only a migrate or db:seed command builds these, after every
        // module has registered, so the registry they walk is in load order.
        //
        // The framework's own tables come first, and only those of the stores
        // in use: sessions kept in files need no table.
        $container->singleton(Migrator::class, static fn(Container $container): Migrator => new Migrator(
            $container->get(ConnectionManager::class),
            $container->get(ModuleRegistry::class),
            $settings->string('database.migrations.table', 'migrations') ?? 'migrations',
            framework: self::frameworkMigrations($settings),
        ));
        $container->singleton(SeedRunner::class, static fn(Container $container): SeedRunner => new SeedRunner(
            $container->get(ConnectionManager::class),
            $container->get(ModuleRegistry::class),
        ));

        $container->singleton(Dispatcher::class);
        // The rate limiter counts wrong bypass guesses: maintenance answers
        // before routing, where no route's rate_limit can.
        $container->singleton(Maintenance::class, static fn(Container $container): Maintenance => new Maintenance(
            $basePath,
            $container->get(RateLimiter::class),
        ));
        $container->singleton(HttpKernel::class);
        $container->singleton(ConsoleKernel::class);

        // The access model, filled by modules during registration and frozen
        // afterwards. Built here so the manager can hand it to each module and
        // then check the whole of it once every module has spoken.
        $access = new AccessRegistry();
        $container->instance(AccessRegistry::class, $access);

        // Filled by modules' $module->mcp(...) during registration. Empty in an
        // application that declares nothing, and then nothing is offered.
        $mcp = new McpRegistry();
        $container->instance(McpRegistry::class, $mcp);

        // Translations: the application's lang/ now, each module's lang/ as the
        // manager registers it. See Localization for how a locale is chosen.
        $translations = new TranslationCatalog(Path::join($basePath, 'lang'));
        $container->instance(TranslationCatalog::class, $translations);

        $container->instance(ModuleManager::class, $modules = new ModuleManager(
            $container,
            $settings,
            $router,
            $hooks,
            $filters,
            $assets,
            $views,
            $commands,
            $schedules,
            $access,
            new ModuleRegistry(),
            $basePath,
            $mcp,
            $translations,
            $helpers,
        ));

        // Before anything boots, so module timing is there from the first
        // stage. A no-op with profiling off.
        $profiler->instrument($tracer, $hooks, $filters, $modules);

        // The queue. Its runner needs the container, and the sync store needs
        // the runner, so this is built here rather than resolved lazily -- and
        // the store is chosen exactly the way the cache's and the log's are,
        // from configuration, because where background work waits is a
        // deployment decision.
        // One sealer, both ends: what Queue seals the runner opens.
        $jobSealer = self::sealer($settings, 'queue');
        $runner = new JobRunner($container, $hooks, $tracer, $jobSealer);
        $queue = new Queue(
            self::queueStore($settings, $basePath, $runner, $container),
            $runner,
            $hooks,
            $settings->string('queue.queue', Queue::DEFAULT) ?? Queue::DEFAULT,
            // So a queued job carries the correlation of the work that queued it.
            $tracer,
            $jobSealer,
        );

        $container->instance(JobRunner::class, $runner);
        $container->instance(Queue::class, $queue);
        $container->instance(QueueStore::class, $queue->store());
        $container->instance(Backoff::class, self::backoff($settings));
        $container->singleton(Worker::class);

        // The scheduler. It is built after the queue because a scheduled job is
        // pushed rather than run, and after the console because a scheduled
        // command is dispatched through the same registry a person types at.
        $lock = self::scheduleLock($settings, $basePath);

        $container->instance(ScheduleLock::class, $lock);

        // Lazy, unlike the queue: a web request never schedules anything, and
        // resolving this one would pull the command dispatcher in behind it.
        $container->singleton(Scheduler::class, static fn(): Scheduler => new Scheduler(
            $schedules,
            $lock,
            $container,
            $commands,
            $container->get(CommandDispatcher::class),
            $queue,
            $hooks,
            self::schedulerTimezone($settings),
            $settings->int('scheduler.lock_ttl', Scheduler::DEFAULT_LOCK_SECONDS)
                ?? Scheduler::DEFAULT_LOCK_SECONDS,
        ));

        // The second listener on the logging side, for the same reason as the
        // first: work that runs with nobody watching has to leave a record, or
        // a schedule that stopped six weeks ago is indistinguishable from one
        // with nothing to do.
        $hooks->add('schedule.finished', (new ScheduleLog($logs))(...), 10, 'engine');

        // And the third: a system operation that changed the machine, or was
        // refused, is written down whoever triggered it. system.audit fires
        // either way -- it is the event stream any listener can follow -- and
        // this setting decides only whether the audit log is one of them.
        if ($settings->bool('system.audit.enabled', true)) {
            $hooks->add(SystemAudit::HOOK, (new SystemAuditLog($logs))(...), 10, 'engine');
        }

        // And MCP: who called which capability, and what came of it -- never
        // the arguments or the result. Ten listeners that cost nothing until
        // an MCP request fires them.
        if ($settings->bool('mcp.log.enabled', true)) {
            (new McpLog($logs))->attach($hooks);
        }

        self::system($container, $settings, $basePath);
        self::httpClient($container, $settings);
        self::storage($container, $settings, $basePath);
        self::mail($container, $settings);
        self::update($container, $basePath);
        self::mcp($container, $settings);

        // A web request that waits on a command holds a worker and a visitor
        // for as long as it runs. Inside HTTP, no command may take longer than
        // this, whatever it asked for: work that needs longer belongs on the
        // queue. Through the narrowing-only filter, so it can be made stricter
        // and never looser.
        $httpTimeout = $settings->float('system.execution.http_timeout');

        if ($context->isHttp() && $httpTimeout !== null) {
            if (!\is_finite($httpTimeout) || $httpTimeout <= 0.0) {
                throw ConfigurationException::unusableValue('system.execution.http_timeout', 'a number of seconds above zero, or null for no cap');
            }

            $filters->add(Invocation::TIMEOUT_FILTER, static fn(float $timeout): float => \min($timeout, $httpTimeout), 10, 'engine');
        }

        // Security. Built here rather than resolved lazily because its whole
        // job is to be attached to the request before anything else runs; a
        // guard that is only constructed when something asks for it is a guard
        // that never runs.
        $signer = Signer::fromEnvironment($settings->string('security.key'));
        $csrf = new Csrf(
            $signer,
            $settings->bool('security.csrf.check_origin', true),
            $settings->int('security.csrf.lifetime', 7200) ?? 7200,
            $settings->bool('security.csrf.host_prefix', true),
        );
        $limiter = new RateLimiter(self::counters($settings, $basePath));
        $limits = new RequestLimits(
            $settings->int('security.max_request_bytes', RequestLimits::DEFAULT_BYTES)
                ?? RequestLimits::DEFAULT_BYTES,
            true,
            self::stringList($settings->get('security.trusted_hosts')),
        );
        $signedUrls = new SignedUrl($signer);
        $guard = new Guard($csrf, $limiter, $limits, $settings->bool('security.csrf.enabled', true), $signedUrls);

        $container->instance(Signer::class, $signer);
        $container->singleton(Encrypter::class, static fn(): Encrypter => Encrypter::fromEnvironment(
            $settings->string('security.key'),
            $settings->string('security.previous_keys'),
        ));
        $container->instance(Csrf::class, $csrf);
        $container->instance(RateLimiter::class, $limiter);
        $container->instance(CounterStore::class, $limiter->store());
        $container->instance(RequestLimits::class, $limits);
        $container->instance(Guard::class, $guard);
        $container->instance(SignedUrl::class, $signedUrls);
        $container->singleton(Webhooks::class, static function (Container $container) use ($settings, $basePath): Webhooks {
            /** @var mixed $sources */
            $sources = $settings->get('security.webhooks');
            $sources = \is_array($sources) ? $sources : [];
            $webhooks = new Webhooks($sources, $container->get(Cache::class), requireSharedCache: true);

            if ($webhooks->remembersAcrossRequests()) {
                return $webhooks;
            }

            // The application's cache forgets when the request ends (array, the
            // default, or none), so the delivery ids get a file store of their
            // own: a payment provider's delivery must never fail on how the
            // cache happens to be configured, and a replay must still be seen.
            $ids = new Cache(new FileStore(Path::join($basePath, 'system', 'Cache', 'webhooks'), sealer: self::sealer($settings, 'webhooks')));

            return new Webhooks($sources, $ids, requireSharedCache: true);
        });
        $container->singleton(UrlSigner::class, static fn(Container $container): UrlSigner => new UrlSigner($container->get(Router::class), $signedUrls));
        $container->singleton(OpenApiGenerator::class, static fn(Container $container): OpenApiGenerator => new OpenApiGenerator(
            $container->get(Router::class),
            $settings->string('openapi.title') ?: ($settings->string('app.name') ?: 'API'),
            $settings->string('openapi.version') ?: '1.0.0',
            $settings->string('openapi.description') ?? '',
            $settings->string('app.url') ?: null,
        ));
        $container->singleton(OpenApiState::class, static fn(): OpenApiState => new OpenApiState($basePath));
        $container->singleton(OpenApiDocs::class, static fn(Container $container): OpenApiDocs => new OpenApiDocs(
            $container->get(Router::class),
            OpenApiRoutes::path($settings->string('openapi.docs')) ?? '/docs',
            OpenApiRoutes::path($settings->string('openapi.route')) ?? '/openapi.json',
            ($settings->string('openapi.title') ?: ($settings->string('app.name') ?: 'API')) . ' - API reference',
            OpenApiRoutes::isGuarded($settings->array('openapi.meta')),
        ));

        $headers = self::securityHeaders($settings);
        $container->instance(SecurityHeaders::class, $headers);

        // Sessions. Built eagerly like the guard and for the same reason -- it
        // has to be attached to the request before a handler runs -- but it
        // READS nothing until something asks for a session. A request that
        // never touches one costs a method call.
        $sessions = new SessionManager(
            self::sessionStore($settings, $basePath, $container),
            $hooks,
            $settings->string('session.cookie.name', SessionManager::DEFAULT_COOKIE)
                ?? SessionManager::DEFAULT_COOKIE,
            $settings->int('session.idle', SessionManager::DEFAULT_IDLE) ?? SessionManager::DEFAULT_IDLE,
            $settings->int('session.absolute', 0) ?? 0,
            $settings->int('session.grace', SessionManager::DEFAULT_GRACE) ?? SessionManager::DEFAULT_GRACE,
            $settings->int('session.cookie.lifetime', 0) ?? 0,
            $settings->string('session.cookie.path', '/') ?? '/',
            $settings->string('session.cookie.domain', '') ?? '',
            $settings->string('session.cookie.same_site', 'Lax') ?? 'Lax',
            $settings->get('session.cookie.secure') === null
                ? null
                : $settings->bool('session.cookie.secure', false),
        );

        $container->instance(SessionManager::class, $sessions);
        $container->instance(SessionStore::class, $sessions->store());

        // bind(), not singleton(): the manager owns the lifetime, and asking it
        // every time is what makes a handler that type-hints Session get the
        // session belonging to the request it is serving rather than the one
        // that happened to be first.
        $container->bind(Session::class, static fn(): Session => $sessions->session());

        // Authentication. The framework does not know what a user is: a module
        // binds a UserProvider, and until one does, every request is a guest
        // and every protected route answers 401 -- which is the correct
        // behaviour for an application with no user store, not a failure.
        $container->singleton(Password::class, static fn(): Password => new Password(
            self::passwordOptions($settings),
        ));

        if (!$container->has(UserProvider::class)) {
            $container->singleton(UserProvider::class, EmptyProvider::class);
        }

        // The request being served, as the auth listener below last heard it.
        // Kept here rather than read from the container, because a
        // request.instance filter may have replaced the Request the application
        // put there, and the manager must see the one the kernel is handling.
        $served = new class {
            public ?Request $request = null;

            /** The route being dispatched opted out of CSRF; see below. */
            public bool $sessionless = false;
        };

        $container->singleton(AuthManager::class, static function (Container $container) use (
            $settings,
            $sessions,
            $hooks,
            $served,
        ): AuthManager {
            $provider = $container->get(UserProvider::class);
            $authenticators = [];

            // Token first: a bearer token is a credential somebody put on the
            // request deliberately, a cookie is one the browser attached on its
            // own, and when both are present the deliberate one was meant.
            //
            // Registered only when the provider can answer for a token, so an
            // application without them does not carry a listener that can never
            // succeed.
            if ($provider instanceof TokenProvider) {
                $authenticators[] = new TokenAuthenticator($provider);
            }

            $authenticators[] = new SessionAuthenticator($sessions, $provider);

            $manager = new AuthManager(
                $provider,
                $container->get(Password::class),
                $authenticators,
                $sessions,
                $hooks,
                self::guestRoles($settings),
                // Failed password checks are counted on the same store as every
                // other limit; see AuthManager::THROTTLE for why there are three.
                $settings->bool('auth.throttle.enabled', true) ? $container->get(RateLimiter::class) : null,
                self::throttleLimits($settings),
            );

            // Built part-way through a request, the manager has missed the
            // request.received it would otherwise have heard.
            if ($served->request !== null) {
                $manager->onRequest($served->request);
            }

            if ($served->sessionless) {
                $manager->ignoreSession();
            }

            return $manager;
        });

        $container->singleton(
            Authorizer::class,
            static fn(): Authorizer => new Authorizer($access, $filters),
        );

        // Links that leave the page -- in an email, as an OAuth redirect_uri --
        // are built from APP_URL, never from the Host header the client sent.
        $container->singleton(AppUrl::class, static fn(Container $container): AppUrl => new AppUrl(
            $settings->string('app.url'),
            $container->get(Router::class),
        ));

        // Password reset and email verification. Each refuses, naming the
        // interface, until the UserProvider implements PasswordResettable or
        // EmailVerifiable; the routes and pages are the application's.
        $container->singleton(PasswordReset::class, static fn(Container $container): PasswordReset => new PasswordReset(
            $container->get(UserProvider::class),
            $container->get(Password::class),
            $container->get(Signer::class),
            $container->get(Mailer::class),
            $container->get(AppUrl::class),
            $container->get(RateLimiter::class),
            $hooks,
            $settings->int('auth.passwords.ttl', PasswordReset::TTL) ?? PasswordReset::TTL,
            $settings->string('auth.passwords.route', 'password.reset') ?? 'password.reset',
        ));
        $container->singleton(TwoFactor::class, static fn(Container $container): TwoFactor => new TwoFactor(
            $container->get(AuthManager::class),
            $container->get(UserProvider::class),
            $container->get(Password::class),
            $sessions,
            $settings->string('auth.two_factor.issuer', 'LPHP') ?? 'LPHP',
            $hooks,
            limiter: $container->get(RateLimiter::class),
        ));
        $container->singleton(SocialLogin::class, static fn(Container $container): SocialLogin => new SocialLogin(
            self::socialProviders($settings, $container),
            $container->get(Encrypter::class),
            $container->get(AppUrl::class),
            $container->get(AuthManager::class),
            $container->get(UserProvider::class),
            $filters,
            $hooks,
            (bool) $settings->bool('auth.social.register', false),
            twoFactor: $container->get(TwoFactor::class),
        ));
        $container->singleton(EmailVerification::class, static fn(Container $container): EmailVerification => new EmailVerification(
            $container->get(UserProvider::class),
            $container->get(Signer::class),
            $container->get(Mailer::class),
            $container->get(AppUrl::class),
            $hooks,
            $settings->int('auth.verification.ttl', EmailVerification::TTL) ?? EmailVerification::TTL,
            $settings->string('auth.verification.route', 'email.verify') ?? 'email.verify',
        ));

        // Resolved per call, never shared: a worker that held one request's
        // identity for the life of the process would answer the next request's
        // authorization questions with the previous user's roles.
        $container->bind(
            Identity::class,
            static fn(Container $container): Identity => $container->get(AuthManager::class)->identity(),
        );

        // Three listeners, on hooks the kernel already fires. This is what the
        // ban on middleware looks like in practice: named events rather than a
        // pipeline, and a refusal is an HttpException the kernel already knows
        // how to render.
        //
        // The request limit runs at priority 1 so that it is ahead of anything
        // an application attaches -- there is no point authenticating a body
        // that is about to be refused for being too large.
        $hooks->add('request.received', $guard->onRequest(...), 1, 'engine');
        $hooks->add('dispatch.before', $guard->onDispatch(...), 5, 'engine');
        $filters->add('response.instance', $guard->onResponse(...), 20, 'engine');

        // The session hears about the request straight after the size check --
        // it only records which request is being served, so the order matters
        // less than the fact that it is ahead of anything a module attaches.
        $hooks->add('request.received', $sessions->onRequest(...), 2, 'engine');
        $filters->add('response.instance', $sessions->onResponse(...), 15, 'engine');

        // Authentication, after the security guard rather than before it. A
        // request that failed the CSRF check is one this application's own
        // pages did not make, and telling it that it also needs a login would
        // be answering a question it was not entitled to ask.
        //
        // The metadata is read before anything is resolved, so a public route
        // does not construct an AuthManager, does not resolve the provider and
        // does not touch the session. That is what keeps "most pages are
        // public" costing nothing.
        //
        // The request.received half has to hold to that too, and until Phase 27
        // it did not: it resolved the manager to hand it the request, which
        // built the user provider -- and with it the shared module's repository
        // and models -- on every request, a stylesheet included. Measuring found
        // it. Now the request is only recorded, and handed over if a manager
        // already exists or when one is built.
        $hooks->add('request.received', static function (Request $request) use ($container, $served): void {
            $served->request = $request;
            $served->sessionless = false;

            if ($container->resolved(AuthManager::class)) {
                $container->get(AuthManager::class)->onRequest($request);
            }
        }, 3, 'engine');

        // A route that opted out of CSRF is not identified by the session
        // cookie. The cookie is the credential a browser attaches on its own,
        // which is the whole of what CSRF is; with the check off, honouring it
        // would let any site in the world act as whoever is logged in. Such a
        // route -- an API, a webhook -- answers to bearer tokens, which a
        // browser never attaches by itself. With CSRF off everywhere
        // (security.csrf.enabled) the application has said it has no browser
        // clients, and this does not apply.
        if ($settings->bool('security.csrf.enabled', true)) {
            $hooks->add('dispatch.before', static function (Route $route) use ($container, $served): void {
                if ($route->metaValue(Guard::CSRF_META) !== false) {
                    return;
                }

                $served->sessionless = true;

                if ($container->resolved(AuthManager::class)) {
                    $container->get(AuthManager::class)->ignoreSession();
                }
            }, 6, 'engine');
        }

        $hooks->add('dispatch.before', static function (Route $route, Request $request) use ($container): void {
            if (!AuthGuard::isProtected($route)) {
                return;
            }

            $container->get(AuthGuard::class)->onDispatch($route, $request);
        }, 8, 'engine');

        // Changing the session id has to change the CSRF token with it.
        // Otherwise logging in leaves the anonymous page's token valid against
        // the authenticated session, which is the same fixation attack the
        // regeneration was for, one layer up. Done through the hook rather than
        // a reference so that the security layer keeps knowing nothing about
        // sessions.
        $hooks->add('session.regenerated', $guard->onSessionRegenerated(...), 10, 'engine');
        // Last, so that it sees every header a module decided to set and does
        // not overwrite one. See SecurityHeaders.
        $filters->add('response.instance', $headers(...), 90, 'engine');

        // Which engine answered, on every response. Like the security headers,
        // a value a module already set is kept, and an empty one in
        // http.headers removes the header.
        $identity = self::engineHeaders($settings);
        $filters->add('response.instance', static function (Response $response) use ($identity): Response {
            foreach ($identity as $name => $value) {
                if (!$response->hasHeader($name)) {
                    $response = $response->withHeader($name, $value);
                }
            }

            return $response;
        }, 90, 'engine');

        // After the security headers, as the very last thing a response goes
        // through: X-Request-Id on every response, and -- when profiling -- the
        // record of what the request cost, measured as late as it can be while
        // the request's trace is still open. See Report.
        $filters->add('response.instance', $report->onResponse(...), 95, 'engine');
        $hooks->add('command.finished', $report->onCommand(...), 90, 'engine');
        $hooks->add('job.finished', $report->onJob(...), 90, 'engine');

        self::localization($container, $settings, $hooks, $filters, $served, $basePath);

        $application = new Application($container, $context, $basePath);
        $container->instance(Application::class, $application);

        // Already registered at the top unless the caller said otherwise; a
        // config file can still switch it off, and that is honoured here.
        if ((bool) $settings->get('app.handle_errors', true)) {
            $errors->register();
        } else {
            $errors->unregister();
        }

        return $application;
    }

    /**
     * The error handler as it can be before the configuration is read.
     *
     * .env is loaded here, as settings() does a moment later -- DotEnv fills
     * gaps, so the second load changes nothing -- because APP_DEBUG is what
     * decides whether a config file that throws gets Whoops or a plain 500.
     * A caller's own app.debug and app.handle_errors win, as they do in
     * settings(); that is how a test keeps the handler away from PHPUnit's.
     *
     * @param array<string, mixed> $overrides
     */
    private static function earlyErrors(string $basePath, array $overrides): ErrorHandler
    {
        /** @var mixed $app */
        $app = $overrides['app'] ?? [];
        $app = \is_array($app) ? $app : [];

        $provisional = static fn(): Config => new Config(['app' => [
            'debug' => (bool) ($app['debug'] ?? Env::bool('APP_DEBUG', false)),
        ]]);

        $errors = new ErrorHandler($provisional(), page: new ErrorPage(basePath: $basePath));

        // Registered before .env is read, since a malformed .env throws too;
        // what it says about APP_DEBUG is picked up straight after.
        if (($app['handle_errors'] ?? true) !== false) {
            $errors->register();
        }

        DotEnv::load(Path::join($basePath, DotEnv::fileFor(Env::string('APP_ENV'))));

        $errors->configure(
            $provisional(),
            new HookEngine(),
            new ErrorPage(basePath: $basePath, environment: Env::names()),
        );

        return $errors;
    }


    /**
     * The configuration, assembled from every source, in order.
     *
     * Defaults, then config/*.php, then whatever the caller passed in -- which
     * is a test forcing module roots, or an embedding application that builds
     * its own. The environment is not a fourth layer here: config files read it
     * themselves, through Env, so that the precedence is visible in the file
     * somebody is looking at rather than hidden in this merge.
     *
     * A .env file is loaded first if there is one, and fills gaps in the real
     * environment rather than replacing it -- .env.testing instead, when the
     * real environment says APP_ENV=testing. See DotEnv.
     *
     * The cache short-circuits the middle of that: if a valid one exists it is
     * the defaults and the files already merged. Caller overrides are applied
     * on top either way, so a cache built on a live server cannot change what a
     * test asked for. It is used whenever it exists rather than behind a
     * setting, because the setting would have to be read out of the
     * configuration this is building.
     *
     * @param array<string, mixed> $overrides
     * @param bool                 $cached  false forces a read from disk, which is what
     *                                      the command that builds the cache needs
     *
     * @return array<string, mixed>
     */
    public static function settings(string $basePath, array $overrides = [], bool $cached = true): array
    {
        // A test run reads .env.testing, never the developer's .env: see
        // DotEnv::TESTING_FILE. Only the real environment can say it is one.
        DotEnv::load(Path::join($basePath, DotEnv::fileFor(Env::string('APP_ENV'))));

        $items = ($cached ? ConfigCache::read(ConfigCache::file($basePath)) : null)
            ?? self::merge(self::defaults(), (new ConfigLoader(Path::join($basePath, ConfigLoader::DIRECTORY)))->load());

        // What `openapi:docs` and `openapi:auth` switched on this machine, over
        // the files and under the caller's overrides. Not while building the
        // cache ($cached false): a cache holds files, and a switch flipped on
        // one machine must not travel to another inside it. See OpenApiState.
        $runtime = $overrides['openapi']['runtime'] ?? $items['openapi']['runtime'] ?? true;

        if ($cached && $runtime !== false) {
            $items = OpenApiState::overlay($items, OpenApiState::at($basePath));
        }

        return self::merge($items, $overrides);
    }

    /**
     * The configured timezone, checked rather than trusted.
     *
     * date_default_timezone_set() answers false and raises a warning for an
     * identifier it does not know, and then every date in the application is
     * silently in whatever zone the ini file named. A boot that stops and says
     * which value is wrong costs less than that.
     */
    private static function applyTimezone(Config $settings): void
    {
        $timezone = $settings->string('app.timezone', 'UTC') ?? 'UTC';

        if (!\in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw ConfigurationException::unknownTimezone($timezone);
        }

        \date_default_timezone_set($timezone);
    }

    /**
     * PHP's memory_limit, when app.memory_limit sets one.
     *
     * null leaves php.ini alone. A value is PHP's own notation -- 256M, 1G,
     * 134217728, -1 for none -- and anything else stops the boot rather than
     * being passed to ini_set(), which would quietly keep the old limit.
     *
     * A limit below what this process already uses, plus the headroom the
     * error handler reserves, is refused too: the next allocation would be a
     * fatal error, and one with no room left to report itself.
     */
    private static function applyMemoryLimit(Config $settings): void
    {
        // A string from the environment, or an int from a config file:
        // 'memory_limit' => -1 is as natural to write as '256M'.
        $limit = $settings->get('app.memory_limit');

        if (\is_int($limit)) {
            $limit = (string) $limit;
        }

        if ($limit === null || (\is_string($limit) && \trim($limit) === '')) {
            return;
        }

        if (!\is_string($limit)) {
            throw ConfigurationException::invalidMemoryLimit(\get_debug_type($limit), 'is not PHP\'s notation, such as 256M, 1G or -1');
        }

        $given = \trim($limit);
        $limit = \strtoupper($given);

        if (\preg_match('/^(-1|[0-9]+[KMG]?)$/', $limit) !== 1) {
            throw ConfigurationException::invalidMemoryLimit($given, 'is not PHP\'s notation, such as 256M, 1G or -1');
        }

        $floor = \memory_get_usage(true) + 2 * ErrorHandler::RESERVED_MEMORY;

        if ($limit !== '-1' && RequestLimits::toBytes($limit) < $floor) {
            throw ConfigurationException::invalidMemoryLimit(
                $limit,
                \sprintf('is less than the %s this process needs already', RequestLimits::format($floor)),
            );
        }

        if (\ini_set('memory_limit', $limit) === false) {
            throw ConfigurationException::invalidMemoryLimit($limit, 'was refused by PHP');
        }
    }

    /**
     * PHP's time limit for a web request, when app.max_execution_time sets one.
     *
     * Web requests only. The console's "no limit" is PHP's own default and the
     * right one for a migration or a report, and a queue worker already limits
     * each job with its timeout. Setting a limit here would cut both off.
     *
     * A host that disables set_time_limit() refuses the value rather than
     * ignoring it: a limit the configuration asks for and PHP does not apply is
     * one nobody finds out about until a request runs forever.
     */
    private static function applyTimeLimit(Config $settings, ExecutionContext $context): void
    {
        $seconds = $settings->int('app.max_execution_time');

        if ($seconds === null || !$context->isHttp()) {
            return;
        }

        if ($seconds < 0) {
            throw ConfigurationException::invalidTimeLimit($seconds, 'is negative; use a number of seconds, or 0 for no limit');
        }

        if (!\set_time_limit($seconds)) {
            throw ConfigurationException::invalidTimeLimit($seconds, 'was refused: set_time_limit() is disabled on this host');
        }
    }

    /**
     * The cache, assembled from configuration.
     *
     * Which store is a deployment decision, exactly like which log writers, and
     * for the same reason: one application runs on one machine and wants files,
     * the next runs on four and wants something they share. Neither is a thing
     * the code should have to know.
     *
     * The default is memory, which lives for one process. It is a real cache --
     * a page that resolves the same template from six partials pays for one
     * search -- and it writes nothing to a disk nobody asked about. Crossing
     * requests is what CACHE_STORE=file opts into.
     *
     * An unknown name falls back to memory rather than failing: a typo in a
     * deployment's configuration should not stop an application from starting,
     * and cache:clear reports which store is actually in use.
     */
    /**
     * What a store that unserialize()s checks its bytes with: an HMAC keyed
     * from APP_KEY for one purpose, or nothing without a key. See Sealer.
     */
    private static function sealer(Config $settings, string $purpose): Sealer
    {
        $key = $settings->string('security.key');

        return Sealer::derive($key === null || $key === '' ? '' : Signer::readKey($key)->reveal(), $purpose);
    }

    private static function cache(Config $settings, string $basePath, Container $container): Cache
    {
        $ttl = $settings->int('cache.ttl');

        $store = match ($settings->string('cache.store', 'array')) {
            'file' => new FileStore(Path::join($basePath, 'system', 'Cache', 'data'), sealer: self::sealer($settings, 'cache')),
            // The connection is found on first use: the cache is built before
            // the connections are.
            'database' => new CacheDatabaseStore(
                static fn(): Connection => $container->get(ConnectionManager::class)->connection(
                    $settings->string('cache.connection') ?: null,
                ),
                $settings->string('cache.table', CacheDatabaseStore::DEFAULT_TABLE) ?? CacheDatabaseStore::DEFAULT_TABLE,
                self::sealer($settings, 'cache'),
            ),
            'null', 'none' => new NullStore(),
            default => new ArrayStore(),
        };

        return new Cache($store, $settings->string('cache.namespace', '') ?? '', $ttl);
    }

    /**
     * Where queued work waits.
     *
     * Sync by default, which is the absence of a queue rather than a queue:
     * jobs run where they are dispatched and exceptions reach the caller. The
     * alternatives both have a way of losing work quietly on a machine nobody
     * has set up yet -- memory drops the job at the end of the request, and a
     * file queue holds it until a worker that may not exist comes along -- and
     * an application that works before anybody has read the deployment notes is
     * worth more than a default that is technically a queue.
     */
    private static function queueStore(Config $settings, string $basePath, JobRunner $runner, Container $container): QueueStore
    {
        return match ($settings->string('queue.store', 'sync')) {
            'file' => new QueueFileStore(Path::join($basePath, 'system', 'Queue')),
            'database' => new QueueDatabaseStore(
                static fn(): Connection => $container->get(ConnectionManager::class)->connection(
                    $settings->string('queue.connection') ?: null,
                ),
                $settings->string('queue.table', QueueDatabaseStore::DEFAULT_TABLE) ?? QueueDatabaseStore::DEFAULT_TABLE,
            ),
            'memory', 'array' => new MemoryStore(),
            default => new SyncStore($runner),
        };
    }

    /**
     * Where rate-limit counts live.
     *
     * A file by default and not the cache, for the reason CounterStore gives:
     * a cache may forget, and a counter that forgets is not a limit. Memory is
     * offered for tests and is never right in production -- a web request is a
     * process that ends, so counts held in it have already been forgotten by
     * the time the next request arrives.
     *
     * An unknown name falls back to the file store rather than to memory,
     * because a typo in a deployment's configuration must not quietly turn
     * rate limiting off.
     */
    private static function counters(Config $settings, string $basePath): CounterStore
    {
        return match ($settings->string('security.counters', 'file')) {
            'memory', 'array' => new CounterMemoryStore(),
            default => new CounterFileStore(Path::join($basePath, 'system', 'Security')),
        };
    }

    /**
     * What password_hash() is asked for.
     *
     * Only int and string values are passed through: the option list goes
     * straight into a function that throws on anything else, and a config file
     * is exactly where a "12" arrives as a string or a nested array arrives by
     * accident.
     *
     * @return array<string, int|string>
     */
    private static function passwordOptions(Config $settings): array
    {
        $options = [];

        foreach ($settings->array('auth.password.options') as $key => $value) {
            if (\is_string($key) && (\is_int($value) || \is_string($value))) {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * The roles an unauthenticated request holds.
     *
     * @return list<string>
     */
    private static function guestRoles(Config $settings): array
    {
        $roles = [];

        foreach ($settings->array('auth.guest_roles') as $role) {
            if (\is_string($role) && $role !== '') {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * auth.throttle's limits, each checked as RateLimiter reads it, so a typo
     * stops the boot rather than silently leaving logins unthrottled.
     *
     * @return array{per_login_ip?: string, per_login?: string, per_ip?: string}
     */
    private static function throttleLimits(Config $settings): array
    {
        $limits = [];

        foreach (\array_keys(AuthManager::THROTTLE) as $name) {
            $limit = $settings->string('auth.throttle.' . $name);

            if ($limit !== null && $limit !== '') {
                RateLimiter::parse($limit);
                $limits[$name] = $limit;
            }
        }

        return $limits;
    }

    /**
     * The framework's own tables, for what is configured to keep things in the
     * database: sessions, the cache, the queue and the log. None while they
     * keep them elsewhere, so an application on files gets no tables it never
     * uses.
     *
     * @return list<MigrationFile>
     */
    private static function frameworkMigrations(Config $settings): array
    {
        $table = static fn(string $key, string $default): string => $settings->string($key, $default) ?? $default;
        $migrations = [];

        if ($settings->string('session.store', 'file') === 'database') {
            $migrations[] = MigrationFile::supplied(Migrator::FRAMEWORK, SessionTableMigration::NAME, new SessionTableMigration(
                $table('session.table', SessionDatabaseStore::DEFAULT_TABLE),
            ));
        }

        if ($settings->string('cache.store', 'array') === 'database') {
            $migrations[] = MigrationFile::supplied(Migrator::FRAMEWORK, CacheTableMigration::NAME, new CacheTableMigration(
                $table('cache.table', CacheDatabaseStore::DEFAULT_TABLE),
            ));
        }

        if ($settings->string('queue.store', 'sync') === 'database') {
            $migrations[] = MigrationFile::supplied(Migrator::FRAMEWORK, QueueTableMigration::NAME, new QueueTableMigration(
                $table('queue.table', QueueDatabaseStore::DEFAULT_TABLE),
            ));
        }

        if (\in_array('database', self::stringList($settings->get('logging.writers')), true)) {
            $migrations[] = MigrationFile::supplied(Migrator::FRAMEWORK, LogTableMigration::NAME, new LogTableMigration(
                $table('logging.database.table', DatabaseWriter::DEFAULT_TABLE),
            ));
        }

        return $migrations;
    }

    /**
     * Where sessions live.
     *
     * An unknown name falls back to the file store rather than to memory, for
     * the same reason the counters do: a typo in a deployment's configuration
     * must not quietly log everybody out on every request.
     *
     * The database store is resolved lazily through the container because it
     * needs a connection, and an application with no database configured must
     * still boot -- most of them have one, but the framework does not get to
     * assume it.
     */
    private static function sessionStore(Config $settings, string $basePath, Container $container): SessionStore
    {
        return match ($settings->string('session.store', 'file')) {
            'memory', 'array' => new SessionArrayStore(),
            'database' => new SessionDatabaseStore(
                $container->get(ConnectionManager::class)->connection(
                    $settings->string('session.connection') ?: null,
                ),
                $settings->string('session.table', SessionDatabaseStore::DEFAULT_TABLE)
                    ?? SessionDatabaseStore::DEFAULT_TABLE,
            ),
            default => new SessionFileStore(Path::join($basePath, 'system', 'Sessions')),
        };
    }

    /**
     * http.headers, sent on every response; none by default.
     *
     * No X-Powered-By, no X-Engine, and no version anywhere: a version number on every
     * response is a lookup key for whichever release has a known hole. PHP's
     * own X-Powered-By is removed when a response is sent; see Response. An
     * empty value drops a header.
     *
     * @return array<string, string>
     */
    private static function engineHeaders(Config $settings): array
    {
        // Nothing by default. "X-Engine: LPHP" used to be sent, which told
        // anybody scanning which framework -- and so which advisories -- to
        // try first. ['X-Engine' => 'LPHP'] in http.headers brings it back.
        $headers = [];

        foreach ($settings->array('http.headers') as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return \array_filter($headers, static fn(string $value): bool => $value !== '');
    }

    /**
     * The headers every response carries.
     *
     * The Content-Security-Policy is three-way: unset (null) sends the default
     * policy on pages while debug is off -- the debug page is inline script,
     * which the default refuses -- a string is the application's own policy,
     * sent always, and "" is an explicit "none", which security:check reports.
     */
    private static function securityHeaders(Config $settings): SecurityHeaders
    {
        $extra = [];

        foreach ($settings->array('security.headers.extra') as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $extra[$name] = $value;
            }
        }

        $csp = $settings->string('security.headers.csp');

        return new SecurityHeaders(
            $extra,
            $csp ?? '',
            $settings->int('security.headers.hsts_days', 0) ?? 0,
            $settings->bool('security.headers.hsts_subdomains', false),
            $csp === null && !$settings->bool('app.debug', false) ? SecurityHeaders::DEFAULT_CSP : null,
        );
    }

    /**
     * The system managers, built from `system.*` the first time one is asked
     * for, and not before: an application that never touches engine/System
     * reads none of that configuration and builds none of this.
     *
     * Each manager a module injects has the configured limits, policies, the
     * filters and the audit hook already applied. A module that needs a
     * stricter policy -- a CommandPolicy with argument rules, say -- builds its
     * own manager instead.
     */
    /**
     * Email: the transport mail.transport names, and a Mailer over it. Built
     * when first asked for, so an application that sends nothing never reads
     * a mail setting.
     */
    private static function mail(Container $container, Config $settings): void
    {
        $container->singleton(Transport::class, static function (Container $container) use ($settings): Transport {
            $name = $settings->string('mail.transport', 'log') ?? 'log';

            return match ($name) {
                'smtp' => new SmtpTransport(
                    $settings->string('mail.smtp.host', '127.0.0.1') ?? '127.0.0.1',
                    $settings->int('mail.smtp.port', 587) ?? 587,
                    $settings->string('mail.smtp.encryption', 'tls') ?? 'tls',
                    $settings->string('mail.smtp.username'),
                    new Secret($settings->string('mail.smtp.password') ?? ''),
                    (float) ($settings->int('mail.smtp.timeout', 30) ?? 30),
                ),
                'sendmail' => new SendmailTransport(
                    $container->get(CommandExecutor::class),
                    $settings->string('mail.sendmail.path', SendmailTransport::PATH) ?? SendmailTransport::PATH,
                ),
                'log' => new LogTransport($container->get(LogManager::class)->channel(LogTransport::CHANNEL)),
                'array' => new ArrayTransport(),
                default => throw MailException::unknownTransport($name),
            };
        });

        $container->singleton(Mailer::class, static function (Container $container) use ($settings): Mailer {
            $address = $settings->string('mail.from.address');

            return new Mailer(
                $container->get(Transport::class),
                $container->get(TemplateManager::class),
                $address === null || $address === '' ? null : new Address($address, $settings->string('mail.from.name', '') ?? ''),
                $container->get(FilterEngine::class),
                $container->get(HookEngine::class),
                $container->get(Queue::class),
                $container->get(ThemeRegistry::class),
            );
        });
    }

    /**
     * Outgoing HTTP. curl when the extension is loaded, PHP's own stream
     * wrapper otherwise; the same Client either way.
     */
    private static function httpClient(Container $container, Config $settings): void
    {
        $container->singleton(Client::class, static function (Container $container) use ($settings): Client {
            /** @var mixed $agent */
            $agent = $settings->get('http.client.user_agent');

            return new Client(
                CurlTransport::available() ? new CurlTransport() : new StreamTransport(),
                $container->get(FilterEngine::class),
                $container->get(HookEngine::class),
                (float) ($settings->get('http.client.timeout') ?? Client::DEFAULT_TIMEOUT),
                ['User-Agent' => \is_string($agent) && $agent !== '' ? $agent : 'LPHP/' . Application::VERSION],
            );
        });
    }

    /**
     * Feature flags: the service, feature() in templates, and meta(['feature'
     * => …]) on a route, which answers 404 while the flag is off -- after the
     * rate limit and before authentication, so a switched-off page looks like
     * no page at all.
     */
    private static function features(Container $container, Config $settings, HookEngine $hooks, FilterEngine $filters, TemplateHelpers $helpers): void
    {
        $container->singleton(Features::class, static function (Container $container) use ($settings, $filters): Features {
            /** @var mixed $flags */
            $flags = $settings->get('features');

            return new Features(
                \is_array($flags) ? $flags : [],
                static fn(): Identity => $container->get(AuthManager::class)->identity(),
                $filters,
            );
        });

        $helpers->addFunction('feature', static fn(string $name): bool => $container->get(Features::class)->active($name));

        $hooks->add('dispatch.before', static function (Route $route) use ($container): void {
            $flag = $route->metaValue(Features::META);

            if (\is_string($flag) && !$container->get(Features::class)->active($flag)) {
                throw HttpException::notFound();
            }
        }, 6, 'engine');
    }

    /**
     * The providers under auth.social.providers, each built on first use.
     *
     * @return array<string, \Closure(): Provider>
     */
    private static function socialProviders(Config $settings, Container $container): array
    {
        /** @var mixed $configured */
        $configured = $settings->get('auth.social.providers');
        $providers = [];

        foreach (\is_array($configured) ? $configured : [] as $name => $options) {
            $name = (string) $name;

            if (!\is_array($options)) {
                throw SocialException::misconfigured($name, 'its settings are not an array.');
            }

            $providers[$name] = static function () use ($name, $options, $container): Provider {
                $string = static fn(string $key, string $default = ''): string => \is_string($options[$key] ?? null) ? $options[$key] : $default;
                $scopes = \is_array($options['scopes'] ?? null) ? \array_values(\array_filter($options['scopes'], \is_string(...))) : null;
                $http = $container->get(Client::class);
                $cache = $container->get(Cache::class);

                return match ($string('driver', $name)) {
                    'google' => OidcProvider::google($http, $string('client_id'), $string('client_secret'), $cache, $scopes ?? ['openid', 'email', 'profile']),
                    'microsoft' => OidcProvider::microsoft($http, $string('client_id'), $string('client_secret'), $string('tenant', 'common'), $cache, $scopes ?? ['openid', 'email', 'profile']),
                    'apple' => OidcProvider::apple($http, $string('client_id'), $string('team_id'), $string('key_id'), $string('private_key'), $cache, $scopes ?? ['openid', 'email', 'name']),
                    'oidc' => new OidcProvider($http, $name, $string('issuer'), $string('client_id'), $string('client_secret'), $scopes ?? ['openid', 'email', 'profile'], [], $cache),
                    'github' => new GitHubProvider($http, $name, $string('client_id'), $string('client_secret'), $scopes ?? ['read:user', 'user:email']),
                    'facebook' => new FacebookProvider($http, $string('client_id'), $string('client_secret'), $scopes ?? ['email', 'public_profile'], ($options['trust_email'] ?? false) === true, $name),
                    default => throw SocialException::misconfigured($name, \sprintf('the driver "%s" is not google, microsoft, apple, github, facebook or oidc.', $string('driver', $name))),
                };
            };
        }

        return $providers;
    }

    /**
     * Disks. A local disk's temporary URL is a signed link to the Shared
     * module's "storage.file" route; an application that removed the route
     * gets an error saying so when it asks for one.
     */
    private static function storage(Container $container, Config $settings, string $basePath): void
    {
        $container->singleton(Storage::class, static function (Container $container) use ($settings, $basePath): Storage {
            /** @var mixed $disks */
            $disks = $settings->get('storage.disks');

            return Storage::fromConfig(
                \is_array($disks) ? $disks : [],
                $settings->string('storage.default', 'local') ?? 'local',
                $basePath,
                static fn(): Client => $container->get(Client::class),
                static function (string $disk, string $path, int $expiresAt) use ($container): string {
                    try {
                        return $container->get(UrlSigner::class)->signed(
                            Storage::ROUTE,
                            ['disk' => $disk, 'path' => $path],
                            new \DateTimeImmutable('@' . $expiresAt),
                        );
                    } catch (RoutingException $e) {
                        throw StorageException::noTemporaryUrls($disk, $e->getMessage());
                    }
                },
            );
        });
    }

    /**
     * framework:update's collaborators: the application directory it updates,
     * and where releases come from. Neither does anything until a command asks.
     */
    private static function update(Container $container, string $basePath): void
    {
        $container->singleton(Updater::class, static fn(): Updater => new Updater($basePath));
        $container->singleton(ReleaseSource::class, static fn(Container $container): ReleaseSource => new GithubReleaseSource($container->get(Client::class)));
    }

    private static function system(Container $container, Config $settings, string $basePath): void
    {
        $container->singleton(SystemConfig::class, static fn(): SystemConfig => SystemConfig::fromConfig($settings, $basePath));

        $enabled = static function (Container $container): SystemConfig {
            $system = $container->get(SystemConfig::class);
            $system->assertEnabled();

            return $system;
        };

        $container->singleton(SystemAudit::class, static fn(Container $container): SystemAudit => new SystemAudit($container->get(HookEngine::class)));

        $container->singleton(CommandExecutor::class, static function (Container $container) use ($enabled): CommandExecutor {
            $system = $enabled($container);

            return new CommandExecutor(
                $system->timeout,
                $system->maxOutput,
                $system->commandPolicy,
                $container->get(SystemAudit::class),
                $system->shell,
                $system->shellBinary,
                $system->concurrency,
                $container->get(FilterEngine::class),
            );
        });

        $container->singleton(ProcessManager::class, static function (Container $container) use ($enabled): ProcessManager {
            $system = $enabled($container);

            return new ProcessManager(
                $system->timeout,
                $system->maxOutput,
                $system->commandPolicy,
                $container->get(SystemAudit::class),
                $system->shell,
                $system->shellBinary,
                $system->concurrency,
                $container->get(FilterEngine::class),
            );
        });

        $container->singleton(ServiceManager::class, static function (Container $container) use ($enabled): ServiceManager {
            $system = $enabled($container);

            return new ServiceManager($container->get(CommandExecutor::class), $system->servicePolicy, audit: $container->get(SystemAudit::class));
        });

        $container->singleton(CronManager::class, static function (Container $container) use ($enabled): CronManager {
            $system = $enabled($container);

            if (!$system->cron) {
                throw SystemDisabledException::cron();
            }

            return new CronManager(new UserCrontab($container->get(CommandExecutor::class)), $system->cronOwner, $container->get(SystemAudit::class));
        });

        $container->singleton(SystemdManager::class, static function (Container $container) use ($enabled): SystemdManager {
            $enabled($container);

            return new SystemdManager($container->get(ServiceManager::class), audit: $container->get(SystemAudit::class));
        });

        $container->singleton(SystemFilesystem::class, static function (Container $container) use ($enabled): SystemFilesystem {
            return new SystemFilesystem($enabled($container)->filesystemPolicy, $container->get(SystemAudit::class));
        });

        $container->singleton(PermissionManager::class, static function (Container $container) use ($enabled): PermissionManager {
            $system = $enabled($container);

            return new PermissionManager($system->filesystemPolicy, $system->owners, $system->groups, $container->get(SystemAudit::class));
        });

        $container->singleton(SystemAuthorizer::class, static function (Container $container) use ($enabled): SystemAuthorizer {
            $enabled($container);

            return new SystemAuthorizer($container->get(Authorizer::class), $container->get(SystemAudit::class));
        });
    }

    /**
     * The MCP server and what it answers with, built only when a transport
     * asks for them: an application nobody connects to over MCP builds none of
     * this. Every unexpected failure is reported through the error handler, as
     * an API error, which is how it reaches the log.
     */
    private static function mcp(Container $container, Config $settings): void
    {
        $report = static fn(Container $container): \Closure => static function (\Throwable $e) use ($container): void {
            $container->get(ErrorHandler::class)->report($e, ErrorContext::Api);
        };

        $container->singleton(McpConfig::class, static fn(): McpConfig => McpConfig::fromConfig($settings, Application::VERSION));

        $container->singleton(McpAuthorizer::class, static fn(Container $container): McpAuthorizer => new McpAuthorizer(
            $container->get(Authorizer::class),
            $container->get(McpConfig::class)->allowGuests,
        ));

        $container->singleton(McpServer::class, static function (Container $container) use ($report): McpServer {
            $registry = $container->get(McpRegistry::class);
            $authorizer = $container->get(McpAuthorizer::class);
            $hooks = $container->get(HookEngine::class);
            $filters = $container->get(FilterEngine::class);
            $mcp = $container->get(McpConfig::class);

            return new McpServer(
                new MessageParser(),
                new ToolRunner($registry, $container, $authorizer, $report($container), $hooks, $filters),
                new ResourceReader($registry, $container, $authorizer, $report($container), $hooks, $filters),
                new PromptProvider($registry, $container, $authorizer, $report($container), $hooks, $filters),
                $registry,
                $mcp->serverName,
                $mcp->serverVersion,
                $report($container),
                $hooks,
            );
        });

        $container->singleton(StdioTransport::class, static fn(Container $container): StdioTransport => new StdioTransport(
            $container->get(McpServer::class),
            MessageParser::DEFAULT_MAX_BYTES,
            static function (string $diagnostic): void {
                \fwrite(\STDERR, $diagnostic . \PHP_EOL);
            },
        ));

        // Held by the kernel for every request, so everything behind it is
        // resolved only when a request is for the MCP path.
        $container->singleton(HttpTransport::class, static fn(Container $container): HttpTransport => new HttpTransport(
            $container->get(McpConfig::class)->httpEnabled(),
            $container->get(McpConfig::class)->path,
            static fn(): McpServer => $container->get(McpServer::class),
            static function () use ($container): ?TokenAuthenticator {
                $provider = $container->get(UserProvider::class);

                return $provider instanceof TokenProvider ? new TokenAuthenticator($provider) : null;
            },
            $container->get(McpConfig::class)->allowGuests,
            static function (string $diagnostic) use ($container): void {
                $container->get(Logger::class)->warning($diagnostic);
            },
            // Origin is checked against APP_URL when there is one: against the
            // Host header it proves nothing under DNS rebinding.
            $settings->string('app.url') ?: null,
        ));
    }

    /**
     * Where a schedule's lock lives.
     *
     * A file by default, and unlike the cache and the queue there is no
     * meaningful alternative on one machine. Memory is offered for tests and is
     * the one configuration that must never reach production: the scheduler's
     * process is started fresh by cron and exits, so a lock held in its memory
     * has never excluded anything. An unknown name falls back to the file store
     * rather than to memory, because a typo in a deployment's configuration
     * should cost nothing rather than silently switch overlap protection off.
     */
    /**
     * Localization: one instance per request, command or job, built on first use.
     *
     * @param object{request: ?Request} $served
     */
    private static function localization(
        Container $container,
        Config $settings,
        HookEngine $hooks,
        FilterEngine $filters,
        object $served,
        string $basePath,
    ): void {
        $header = $settings->string('localization.country_header');
        $header = $header === '' ? null : $header;

        $database = $settings->string('localization.maxmind_database');
        $database = $database === null || $database === ''
            ? null
            : (Path::isAbsolute($database) ? $database : Path::join($basePath, $database));

        // A trusted CDN header first, because it is free; a local MaxMind
        // database second, for requests that did not come through the CDN.
        // Replaceable: a module that binds its own CountryResolver is the one
        // locale resolution asks.
        $container->singleton(CountryResolver::class, static function () use ($header, $database): CountryResolver {
            $resolvers = \array_values(\array_filter([
                $header === null ? null : new HeaderCountryResolver($header),
                $database === null ? null : new MaxMindCountryResolver($database),
            ]));

            return match (\count($resolvers)) {
                0 => new NullCountryResolver(),
                1 => $resolvers[0],
                default => new ChainCountryResolver(...$resolvers),
            };
        });

        $container->singleton(Localization::class, static function (Container $container) use ($settings, $served, $basePath): Localization {
            $catalog = $container->get(TranslationCatalog::class);

            return new Localization(
                $catalog,
                new LocaleResolver(
                    $catalog,
                    $container->get(CountryResolver::class),
                    Path::join($basePath, 'lang', 'countries.php'),
                ),
                request: $served->request,
                log: (bool) $settings->get('app.debug', false) ? $container->get(Logger::class) : null,
            );
        });

        // Each request and each job starts without the last one's locale. Only
        // an instance that already exists is told; one built later reads the
        // request from $served.
        $hooks->add('request.received', static function (Request $request) use ($container): void {
            if ($container->resolved(Localization::class)) {
                $container->get(Localization::class)->onRequest($request);
            }
        }, 4, 'engine');

        $hooks->add('job.started', static function () use ($container): void {
            if ($container->resolved(Localization::class)) {
                $container->get(Localization::class)->reset();
            }
        }, 1, 'engine');

        // A page whose language came from the cookie, the browser or the
        // country is a different page for a different visitor, and a cache in
        // front of the application has to be told so.
        $vary = \implode(', ', \array_filter(['Cookie', 'Accept-Language', $header]));

        $filters->add('response.instance', static function (Response $response) use ($container, $vary): Response {
            if (!$container->resolved(Localization::class) || !$container->get(Localization::class)->resolvedFromRequest()) {
                return $response;
            }

            return $response->withAddedHeader('Vary', $vary);
        }, 85, 'engine');
    }

    private static function scheduleLock(Config $settings, string $basePath): ScheduleLock
    {
        return match ($settings->string('scheduler.lock', 'file')) {
            'memory', 'array' => new MemoryLock(),
            default => new FileLock(Path::join($basePath, 'system', 'Schedule')),
        };
    }

    /**
     * The clock a schedule is read against.
     *
     * app.timezone unless something says otherwise, so "daily at 02:00" means
     * the same thing here as every other date in the application. It is
     * separable because the two genuinely differ: an application may present
     * itself in a local timezone while its unattended work runs on UTC, which
     * is the only clock that does not repeat an hour twice a year.
     */
    private static function schedulerTimezone(Config $settings): string
    {
        foreach ([$settings->string('scheduler.timezone'), $settings->string('app.timezone')] as $candidate) {
            if ($candidate !== null && \in_array($candidate, \timezone_identifiers_list(), true)) {
                return $candidate;
            }
        }

        return 'UTC';
    }

    private static function backoff(Config $settings): Backoff
    {
        return new Backoff(
            base: $settings->int('queue.backoff.base', 5) ?? 5,
            multiplier: $settings->int('queue.backoff.multiplier', 2) ?? 2,
            cap: $settings->int('queue.backoff.cap', 600) ?? 600,
            jitter: $settings->float('queue.backoff.jitter', 0.0) ?? 0.0,
        );
    }

    /**
     * The log, assembled from configuration.
     *
     * Writers are named in configuration rather than constructed by an
     * application, because where records go is a deployment decision and a
     * deployment does not get to edit code. Three need nothing installed: a
     * file under system/Logs, an open stream for a container, and the
     * machine's own system logger. The fourth is a table in a configured
     * database.
     *
     * Nothing is opened here. FileWriter opens on its first record, so a request
     * that logs nothing touches no file, syslog connects on demand, and the
     * database writer finds its connection when it first writes -- the log is
     * built before the connections are.
     */
    private static function logging(Config $settings, string $basePath, Container $container): LogManager
    {
        $debug = (bool) $settings->get('app.debug', false);

        // Resolved once and handed to both. Working it out separately in the
        // manager and in each writer is how the two end up disagreeing about
        // what "the configured level" means.
        $minimum = Level::fromName($settings->get('logging.level'), $debug ? Level::Debug : Level::Info);

        $logs = new LogManager($minimum, new Context(self::stringList($settings->get('logging.redact'))));

        foreach (self::stringList($settings->get('logging.writers')) as $name) {
            $writer = self::writer($name, $minimum, $settings, $basePath, $container);

            if ($writer !== null) {
                $logs->add($writer);
            }
        }

        return $logs;
    }

    private static function writer(string $name, Level $minimum, Config $settings, string $basePath, Container $container): ?LogWriter
    {
        return match ($name) {
            'file' => new FileWriter(
                Path::join($basePath, 'system', 'Logs'),
                $minimum,
                \is_string($prefix = $settings->get('logging.file.prefix', 'app')) ? $prefix : 'app',
                \is_int($days = $settings->get('logging.file.retention_days', 0)) ? $days : 0,
            ),
            // php://stderr rather than STDERR: the constant only exists under
            // the CLI SAPI, and a container running php-fpm wants this too.
            'stderr' => \is_resource($stream = @\fopen('php://stderr', 'w'))
                ? new StreamWriter($stream, $minimum, name: 'stderr')
                : null,
            'syslog' => new SyslogWriter(
                \is_string($identity = $settings->get('logging.syslog.identity', 'app')) ? $identity : 'app',
                $minimum,
            ),
            'database' => new DatabaseWriter(
                static fn(): Connection => DatabaseWriter::ownConnection(
                    $container->get(ConnectionManager::class),
                    $settings->string('logging.database.connection') ?: null,
                ),
                $settings->string('logging.database.table', DatabaseWriter::DEFAULT_TABLE) ?? DatabaseWriter::DEFAULT_TABLE,
                $minimum,
                $settings->int('logging.database.retention_days', 0) ?? 0,
            ),
            // An unknown name is ignored rather than fatal. A typo in a
            // deployment's configuration must not stop the application from
            // starting, and log:status reports what is actually attached.
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return \array_values(\array_filter($value, \is_string(...)));
    }

    /**
     * The framework's own defaults: every key it reads, with a working value.
     *
     * This stays the single description of the configuration surface even now
     * that config/ exists. A file overrides what it names and nothing else, so
     * an application configures the two settings it cares about rather than
     * copying a hundred lines it does not, and a key added here reaches every
     * existing installation without anybody editing a file.
     *
     * Environment variables are read here rather than merged as a separate
     * layer, because a default that comes from the environment is still a
     * default -- APP_DEBUG is what to do when no file says otherwise.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function defaults(array $overrides = []): array
    {
        $defaults = [
            'app' => [
                'env' => Env::string('APP_ENV', 'production'),
                'debug' => Env::bool('APP_DEBUG', false),
                // Explicit, and UTC unless somebody says otherwise. PHP falls
                // back to whatever php.ini names, which differs between a
                // developer's machine and the server, and a billing period that
                // changes shape depending on where the code is running is a bug
                // found at month end.
                'timezone' => Env::string('APP_TIMEZONE', 'UTC'),
                // The public address, with a subdirectory if there is one:
                // https://example.com/shop. Links that leave the page (a reset
                // email, an OAuth callback) are built from it, never from the
                // Host header, which the client chooses.
                'url' => Env::string('APP_URL'),
                // PHP's memory_limit, in PHP's notation: 256M, 1G, -1 for none.
                // null leaves php.ini's. Set it here rather than in php.ini so
                // the laptop and the server agree.
                'memory_limit' => Env::string('MEMORY_LIMIT'),
                // How long a web request may run, in seconds; 0 means no limit.
                // null leaves php.ini's. Web requests only: the console keeps
                // PHP's own "no limit", and a queue worker limits each job.
                'max_execution_time' => Env::int('MAX_EXECUTION_TIME'),
                // The editor the debug page (Whoops) links file paths to:
                // phpstorm, vscode, sublime, atom, ... null for plain paths.
                'editor' => Env::string('APP_EDITOR'),
                // Tests assert on responses rather than on PHP's own error
                // output, so they switch this off instead of having the handler
                // fight the test runner for set_error_handler().
                'handle_errors' => true,
            ],
            'http' => [
                // null means "derive from SCRIPT_NAME", which is right for both
                // Apache in a subdirectory and the built-in server.
                'base_path' => null,
                'trusted_proxies' => [],
                // Headers every response carries; none by default, so nothing
                // names the framework. ['X-Engine' => 'LPHP'] if it should.
                // PHP's X-Powered-By is never sent.
                'headers' => [],
                // Outgoing requests, through Http\Client\Client.
                'client' => [
                    'timeout' => Client::DEFAULT_TIMEOUT,
                    // null for "LPHP/<version>".
                    'user_agent' => null,
                ],
            ],
            'mail' => [
                // log writes each message to the "mail" log channel and sends
                // nothing, so a development machine never mails a customer.
                // smtp, sendmail, or array (kept in memory, for tests).
                'transport' => Env::string('MAIL_TRANSPORT', 'log'),
                'from' => [
                    'address' => Env::string('MAIL_FROM_ADDRESS'),
                    'name' => Env::string('MAIL_FROM_NAME', ''),
                ],
                'smtp' => [
                    'host' => Env::string('MAIL_HOST', '127.0.0.1'),
                    'port' => Env::int('MAIL_PORT', 587),
                    // tls (STARTTLS, 587), ssl (465) or none (a relay on this machine).
                    'encryption' => Env::string('MAIL_ENCRYPTION', 'tls'),
                    'username' => Env::string('MAIL_USERNAME'),
                    'password' => Env::string('MAIL_PASSWORD'),
                    'timeout' => 30,
                ],
                'sendmail' => ['path' => SendmailTransport::PATH],
            ],
            'database' => [
                // Named connections, so that a reporting replica or a legacy
                // system being migrated from is a name rather than a special
                // case. The list is usually empty here and filled by the
                // application in config/database.php; DB_DSN covers the
                // single-database case with no file at all.
                'default' => Env::string('DB_CONNECTION', 'default'),
                'connections' => self::connectionsFromEnv(),
            ],
            'assets' => [
                // null means "the same prefix the application is served under",
                // which is what a subdirectory install needs. A CDN origin goes
                // here instead.
                'url' => null,
                // Content hashing by default; see AssetVersioning for why not
                // modification time.
                'versioning' => AssetVersioning::Content->value,
                'manifests' => true,
                'max_age' => AssetServer::IMMUTABLE_MAX_AGE,
            ],
            'storage' => [
                // Which disk $storage->disk() means without a name.
                'default' => Env::string('STORAGE_DISK', 'local'),
                // Named disks. "local" is a directory, relative to the
                // application unless absolute; "s3" is any S3-compatible bucket
                // (S3, R2, Spaces, B2, MinIO); "memory" is for tests. Built on
                // first use, so an unused disk costs nothing and a missing
                // bucket is an error only where it is used.
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => 'system/Storage',
                        // Where the root is served publicly, if it is. Usually
                        // not: temporaryUrl() makes a signed link instead.
                        'url' => null,
                    ],
                    's3' => [
                        'driver' => 's3',
                        'bucket' => Env::string('S3_BUCKET'),
                        'region' => Env::string('S3_REGION', 'us-east-1'),
                        'key' => Env::string('S3_KEY'),
                        'secret' => Env::string('S3_SECRET'),
                        // Empty for AWS; the service's endpoint otherwise.
                        'endpoint' => Env::string('S3_ENDPOINT'),
                        // bucket in the path rather than the host name: MinIO
                        // and most self-hosted servers want this.
                        'path_style' => Env::bool('S3_PATH_STYLE', false),
                        // A public base URL (a CDN) for url().
                        'url' => Env::string('S3_URL'),
                        'root' => '',
                    ],
                ],
            ],
            'cache' => [
                // Memory by default: real within a request, gone after it, and
                // it writes nothing anywhere. "file" crosses requests,
                // "database" crosses machines too, "null"
                // takes the cache out of the picture entirely, which is what
                // somebody chasing a stale value wants.
                'store' => Env::string('CACHE_STORE', 'array'),
                // For the database store only. Empty means the default
                // connection; `migrate` creates the table while the store is
                // "database" (see CacheTableMigration).
                'connection' => Env::string('CACHE_CONNECTION', ''),
                'table' => 'cache',
                // The lifetime an entry gets when its caller does not say. An
                // hour rather than forever, because an entry nobody can name
                // is an entry nobody will clear.
                'ttl' => Env::int('CACHE_TTL', 3600),
                // A prefix for every key, for when several installations share
                // one backend. Nothing needs it while the store is a file.
                'namespace' => '',
            ],
            'queue' => [
                // sync, file, database or memory. See queueStore() for why sync is the
                // default and what the other two cost on a machine with no
                // worker running.
                'store' => Env::string('QUEUE_STORE', 'sync'),
                // For the database store only, as for the cache: `migrate`
                // creates the table (see QueueTableMigration).
                'connection' => Env::string('QUEUE_CONNECTION', ''),
                'table' => 'jobs',
                'queue' => Env::string('QUEUE_NAME', Queue::DEFAULT),
                // What queue:work uses when nothing is said on the command line.
                'tries' => Env::int('QUEUE_TRIES', 3),
                'timeout' => Env::int('QUEUE_TIMEOUT', 60),
                // A worker stops, between jobs, once it uses this much: 128M,
                // 1G. null means 80% of memory_limit when there is one, so a
                // worker exits cleanly instead of dying mid-job.
                'max_memory' => Env::string('QUEUE_MAX_MEMORY'),
                'sleep' => 1,
                // Waiting is the right answer to most reasons a job fails: a
                // rate limit, a failover, a host restarting. See Queue\Backoff.
                'backoff' => [
                    'base' => 5,
                    'multiplier' => 2,
                    'cap' => 600,
                    // Worth raising above zero for anything that talks to a
                    // shared service, so that jobs which failed together do not
                    // retry together.
                    'jitter' => 0.0,
                ],
            ],
            'security' => [
                // The one secret the framework itself needs. Without it tokens
                // are unsigned -- still double-submit, still cross-origin
                // safe, but a sibling subdomain could plant a matching pair.
                // security:check reports which mode is running.
                'key' => Env::string('APP_KEY'),
                // Retired keys, comma separated: Encrypter still decrypts with
                // them, never encrypts. Remove each once what it sealed has
                // been re-encrypted; security:check reminds while one is set.
                'previous_keys' => Env::string('APP_PREVIOUS_KEYS'),
                'csrf' => [
                    'enabled' => Env::bool('CSRF_ENABLED', true),
                    // Checked when present, absent means "cannot tell". See
                    // Security\Csrf for why Referer is not checked too.
                    'check_origin' => true,
                    // Two hours. Long enough that an ordinary form does not go
                    // stale while somebody writes; short enough to matter.
                    'lifetime' => 7200,
                    // Over HTTPS the cookie is __Host-XSRF-TOKEN, which no
                    // subdomain and no plain-HTTP response can set. Off keeps
                    // XSRF-TOKEN everywhere, for a script that cannot be told
                    // the other name -- and gives that protection up.
                    'host_prefix' => true,
                ],
                // file or memory. Never the cache: a cache may forget, and a
                // counter that forgets is not a limit. See CounterStore.
                'counters' => 'file',
                // Refused with 413 before anything reads the body.
                'max_request_bytes' => Env::int('MAX_REQUEST_BYTES', RequestLimits::DEFAULT_BYTES),
                // Host names this application answers to: ['example.com',
                // '*.example.com']. A request with any other Host header is
                // refused with 400. Empty accepts any, which lets a client
                // choose the host in every link and email built from the request.
                'trusted_hosts' => [],
                // Webhooks received, by name: ['format' => 'stripe', 'secret' =>
                // …]. See Security\Webhooks for the formats and their options.
                'webhooks' => [],
                'headers' => [
                    // null sends SecurityHeaders::DEFAULT_CSP on HTML pages
                    // while debug is off: scripts from this origin only. A
                    // string replaces it everywhere; "" sends no policy at all.
                    'csp' => null,
                    // Off, and only ever sent over HTTPS. It is the one header
                    // here that cannot be taken back -- a browser that has
                    // seen it refuses plain HTTP for the whole max-age.
                    'hsts_days' => 0,
                    'hsts_subdomains' => false,
                    // Added to the four defaults; an empty value removes one.
                    'extra' => [],
                ],
            ],
            'auth' => [
                // Passed straight to password_hash(). Empty means the
                // algorithm's own default, which is the right answer until
                // somebody has measured this machine -- a cost tuned on a
                // laptop is usually wrong for the server and always wrong for
                // the test suite.
                'password' => [
                    'options' => [],
                ],
                // Failed password checks before AuthManager answers 429; see
                // AuthManager::THROTTLE. Counted per account and address, per
                // address, and per account across all addresses.
                'throttle' => [
                    'enabled' => true,
                    ...AuthManager::THROTTLE,
                ],
                // What an unauthenticated request is granted. Usually nothing.
                // An application with genuinely public data can declare a role
                // here instead of scattering "if guest" through its handlers.
                'guest_roles' => [],
                // "Forgot your password?": how long a link works, and the route
                // it points at, which the application declares.
                'passwords' => [
                    'ttl' => 3600,
                    'route' => 'password.reset',
                ],
                // Proving an address: the same two, for the verification link.
                'verification' => [
                    'ttl' => 86400,
                    'route' => 'email.verify',
                ],
                // Signing in with another site. Each provider is a name =>
                // settings: 'google' => ['client_id' => …, 'client_secret' => …];
                // the driver is the name unless 'driver' says otherwise
                // (google, microsoft, apple, github, facebook, oidc). register
                // creates an account for a newcomer; off, only somebody who
                // already has one gets in.
                'social' => [
                    'register' => false,
                    'providers' => [],
                ],
                // The name an authenticator app shows beside the six digits.
                'two_factor' => [
                    'issuer' => 'LPHP',
                    // The application's code form: where a social sign-in to
                    // an account with two-factor turned on is sent to finish.
                    // Without such a route that sign-in is refused with 403.
                    'route' => 'two_factor.challenge',
                ],
            ],
            'session' => [
                // file, database or memory. Memory is for tests only: a web
                // request is a process that ends, so a session kept in its
                // memory is gone before the response is. See SessionManager.
                'store' => Env::string('SESSION_STORE', 'file'),
                // For the database store only. Empty means the default
                // connection; `migrate` creates the table while the store is
                // "database" (see SessionTableMigration).
                'connection' => Env::string('SESSION_CONNECTION', ''),
                'table' => 'sessions',
                // Two hours without a request and the session is gone. This is
                // the clock users feel, so it is the one worth arguing about.
                'idle' => Env::int('SESSION_IDLE', SessionManager::DEFAULT_IDLE),
                // A hard ceiling regardless of activity, off by default. It
                // stops a session kept warm by a background poll from living
                // for ever, and it is the setting most often switched on after
                // an incident rather than before one.
                'absolute' => Env::int('SESSION_ABSOLUTE', 0),
                // How long a regenerated id keeps working, so that requests
                // already in flight when somebody logs in do not lose the
                // session they are holding. See SessionManager::regenerate().
                'grace' => Env::int('SESSION_GRACE', SessionManager::DEFAULT_GRACE),
                'cookie' => [
                    'name' => Env::string('SESSION_COOKIE', SessionManager::DEFAULT_COOKIE),
                    // Zero means the cookie goes when the browser does, which
                    // is the safer default. Outliving the browser is a "remember
                    // me", and that is a decision about identity rather than
                    // about storage.
                    'lifetime' => Env::int('SESSION_COOKIE_LIFETIME', 0),
                    'path' => '/',
                    'domain' => '',
                    // Lax, not Strict: Strict means arriving from any other
                    // site -- an email link, a search result -- looks logged
                    // out, and the usual fix is to turn it off entirely.
                    'same_site' => 'Lax',
                    // Unset means "follow the request", so development over
                    // plain HTTP works and HTTPS gets a Secure cookie without
                    // anybody remembering. Set it to true to require HTTPS.
                    'secure' => Env::bool('SESSION_COOKIE_SECURE'),
                ],
            ],
            'scheduler' => [
                // file or memory. Memory is for tests only and is never right
                // in production: schedule:run is a fresh process every minute,
                // so a lock in its memory has excluded nothing by the time it
                // exits. See Bootstrap::scheduleLock().
                'lock' => 'file',
                // How long a lock outlives the process that took it. It exists
                // because a killed process releases nothing, so the cost of a
                // crash has to be a bounded number of skipped runs rather than
                // a task that never runs again. A schedule with a longer run
                // than this says so with ->withoutOverlapping($seconds).
                'lock_ttl' => Env::int('SCHEDULER_LOCK_TTL', 3600),
                // Unset means app.timezone. Worth setting to UTC on its own:
                // a wall clock repeats an hour every autumn, and a task that
                // must run exactly once should not be read against one.
                'timezone' => Env::string('SCHEDULER_TIMEZONE'),
            ],
            'system' => [
                // Operating-system operations: see System\SystemConfig. Nothing
                // here costs anything until a module asks the container for a
                // system manager. Services, filesystem roots and the shell are
                // closed by default; commands are not, because sendmail and the
                // system:cron and system:systemd commands go through them --
                // security:check warns in production until commands.allowed
                // lists what this deployment runs.
                'enabled' => true,
                'execution' => [
                    'default_timeout' => CommandExecutor::DEFAULT_TIMEOUT,
                    'max_output' => CommandExecutor::DEFAULT_MAX_OUTPUT,
                    // Commands running at once, across every PHP process of this
                    // application, with lock files in system/Commands. Past it a
                    // command is refused rather than queued. null is no limit.
                    'max_concurrent' => 16,
                    // The longest any command may run inside a web request, in
                    // seconds. null is no cap beyond each command's own timeout.
                    'http_timeout' => 10.0,
                ],
                'shell' => [
                    // Off: a shell script is code outside every review this
                    // application has, and runs only where somebody said so.
                    'enabled' => false,
                    'binary' => 'bash',
                ],
                'commands' => [
                    // null is no allowlist; a list, even an empty one, is one.
                    'allowed' => null,
                    'scripts' => null,
                ],
                // service name => actions, e.g. ['nginx' => ['reload']]. None.
                'services' => [],
                'filesystem' => ['read' => [], 'write' => []],
                'permissions' => ['owners' => [], 'groups' => []],
                'cron' => [
                    'enabled' => true,
                    // null derives one from the application's directory.
                    'owner' => null,
                ],
                'audit' => ['enabled' => true],
            ],
            'mcp' => [
                // See MCP\McpConfig. Nothing here exposes anything: STDIO is a
                // server only while somebody runs mcp:stdio, HTTP is off until
                // an application turns it on, and guests are refused.
                'enabled' => true,
                'server' => [
                    'name' => McpConfig::DEFAULT_NAME,
                    // null means the framework's version.
                    'version' => null,
                ],
                'transports' => ['stdio' => true, 'http' => false],
                'http' => ['path' => McpConfig::DEFAULT_PATH],
                'allow_guests' => false,
                // The mcp log channel: see Logging\McpLog.
                'log' => ['enabled' => true],
            ],
            'openapi' => [
                // See OpenApi\OpenApiGenerator. Only routes with the openapi
                // meta are described; `php laika openapi:export` prints it.
                // null means app.name when the application sets one, else "API".
                'title' => null,
                'version' => '1.0.0',
                'description' => '',
                // A path such as '/openapi.json' serves the description over
                // HTTP too. Off by default: it lists the API, so decide who
                // may read it, with route meta such as ['auth' => true].
                'route' => null,
                // A path such as '/docs' adds Swagger UI over that description
                // (needs route). Same meta, so the page and the file it reads
                // share one access rule.
                'docs' => null,
                'meta' => [],
                // Read system/Runtime/openapi.json, which `php laika openapi:docs`
                // and `openapi:auth` write. false leaves config as the only source.
                'runtime' => true,
            ],
            'logging' => [
                // Nothing by default. A framework that starts writing files to
                // a directory nobody asked about is a framework that fills a
                // disk on somebody else's machine, and the first thing a
                // deployment does is decide where its logs go.
                'writers' => [],
                // null means debug in development, info in production.
                'level' => Env::string('LOG_LEVEL'),
                // Extra context keys to replace with [redacted], on top of the
                // list in Logging\Context.
                'redact' => [],
                'file' => [
                    'prefix' => 'app',
                    // 0 keeps everything. Deleting an audit trail because a
                    // default said so is worse than a large directory.
                    'retention_days' => 0,
                ],
                'syslog' => [
                    'identity' => 'app',
                ],
                // The "database" writer's table, made by migrate. Not the
                // only writer to name: a log in the database cannot record the
                // database failing.
                'database' => [
                    // '' is the default connection.
                    'connection' => Env::string('LOG_CONNECTION', ''),
                    'table' => 'logs',
                    // 0 keeps everything, as for files.
                    'retention_days' => 0,
                ],
            ],
            'observability' => [
                // The profiler: hook, filter, module and query timing, reported
                // to the "profile" log channel and, in debug, a Server-Timing
                // header. Off by default, and off means not attached at all.
                // Worth switching on in production for a while to find out
                // where a slow page goes; not worth leaving on.
                'profile' => Env::bool('APP_PROFILE', false),
                // A warning on the "database" channel for any statement slower
                // than this, profiling or not. 0 is off. The statement is
                // logged; its bound values never are.
                'slow_query_ms' => Env::int('SLOW_QUERY_MS', 0),
                // Whether X-Request-Id and X-Correlation-Id sent with a request
                // are used rather than new ids made. True behind a gateway or
                // load balancer that stamps them, so its log and this one agree;
                // false otherwise, because a client should not choose the id its
                // own requests are logged under.
                'trust_incoming_ids' => false,
            ],
            'localization' => [
                // The header a trusted CDN or proxy puts the visitor's country
                // in, e.g. CF-IPCountry. Read only from http.trusted_proxies;
                // null means no country detection.
                'country_header' => Env::string('LOCALIZATION_COUNTRY_HEADER'),
                // A local MaxMind GeoLite2/GeoIP2 Country or City database,
                // relative to the project root or absolute. Needs
                // maxmind-db/reader. null means no database lookup.
                'maxmind_database' => Env::string('LOCALIZATION_MAXMIND_DATABASE'),
            ],
            'templates' => [
                // Twig's compilation cache. Off by default, like the module
                // cache, because a cache with no invalidation story is a
                // deployment decision rather than a default. PHP templates
                // never need it -- opcache already has them.
                'cache' => false,
                // Themes to publish: "dark" is templates/dark/, "admin/dark"
                // is templates/admin/dark/. Nothing is discovered; a theme not
                // named here or by the provider does not exist.
                'themes' => [],
                // A ThemeProvider class, built through the container, for
                // themes that live in the database. null for none.
                'theme_provider' => null,
                // Path prefix => theme, for error pages raised before a route
                // chose one: ['/admin' => 'admin/dark'].
                'error_themes' => [],
            ],
            'modules' => [
                // Where modules are. Every directory directly inside one of
                // these that has a module.php is a module named after its
                // directory -- modules/Billing is "Billing" -- and
                // modules/Shared is the shared module. A path that has a
                // module.php of its own is a single module, for one kept
                // outside modules/.
                'paths' => ['modules'],
                // There is no "cache" key here any more. The discovery cache is
                // used when cache:warm has built it and app.debug is off -- the
                // file is the switch, as it is for the configuration cache. See
                // ModuleManager::readDiscoveryCache().
                //
                // Module ids installed here but switched off, e.g.
                // ['Stripe']. A disabled module's module.php never
                // runs, and anything that requires it refuses to boot rather
                // than half-working. An unknown id is refused too: a typo would
                // otherwise leave the module on. Shared cannot be disabled.
                'disabled' => [],
            ],
            // Feature flags: name => true, false, or rules (accounts, roles,
            // percent). See Feature\Features.
            'features' => [],
        ];

        return self::merge($defaults, $overrides);
    }

    /**
     * The prefix every generated asset URL starts with.
     *
     * Configured wins, because a CDN origin is not derivable from anything.
     * Otherwise it is whatever prefix the application is mounted under, taken
     * from the same derivation a Request would use -- an asset URL built in a
     * CLI job has to come out identical to one built while serving a page, and
     * a second implementation of that derivation would eventually disagree with
     * the first.
     */
    /**
     * Fill the theme registry from configuration and the provider.
     *
     * Module ids first, so a theme that would sit on a module's override
     * directory is refused by name rather than quietly merged with it. The
     * provider is built through the container, which is why this runs when
     * Application asks rather than here, while the container is still being
     * filled.
     */
    private static function loadThemes(ThemeRegistry $themes, Config $settings, Container $container): void
    {
        $themes->reserve(\array_map(
            static fn(ModuleDefinition $definition): string => $definition->id,
            $container->get(ModuleManager::class)->registry()->installed(),
        ));

        foreach ($settings->strings('templates.themes') as $theme) {
            $themes->register($theme);
        }

        $provider = null;
        $class = $settings->string('templates.theme_provider');

        if ($class !== null && $class !== '') {
            $provider = $container->get($class);

            if (!$provider instanceof ThemeProvider) {
                throw TemplateException::invalidThemeProvider($class);
            }

            foreach ($provider->themes() as $theme) {
                $themes->register($theme);
            }
        }

        /** @var mixed $theme */
        foreach ($settings->array('templates.error_themes') as $prefix => $theme) {
            if (\is_string($prefix) && \is_string($theme)) {
                $themes->mapErrors($prefix, $theme);
            }
        }

        foreach ($provider?->errorThemes() ?? [] as $prefix => $theme) {
            $themes->mapErrors($prefix, $theme);
        }
    }

    private static function assetBaseUrl(Config $settings, ExecutionContext $context): string
    {
        /** @var mixed $configured */
        $configured = $settings->get('assets.url');

        if (\is_string($configured) && $configured !== '') {
            return \rtrim($configured, '/');
        }

        /** @var mixed $configuredBase */
        $configuredBase = $settings->get('http.base_path');

        if (\is_string($configuredBase)) {
            return \rtrim($configuredBase, '/');
        }

        return Request::basePathFrom($context->server);
    }

    /**
     * A single connection from the environment.
     *
     * The one-database case, configured without a file at all, which is what a
     * container image wants. More than one, or anything with options, goes in
     * config/database.php -- where this is still reachable, because a config
     * file may call Env itself.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function connectionsFromEnv(): array
    {
        $dsn = Env::string('DB_DSN');

        if ($dsn === null) {
            return [];
        }

        return [
            Env::string('DB_CONNECTION', 'default') ?? 'default' => [
                'dsn' => $dsn,
                'username' => Env::string('DB_USERNAME'),
                'password' => Env::string('DB_PASSWORD'),
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
