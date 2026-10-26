<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Asset\AssetRegistry;
use App\Engine\Auth\AuthManager;
use App\Engine\Cache\Cache;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Config\ConfigCache;
use App\Engine\Core\Application;
use App\Engine\Database\ConnectionManager;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Logging\LogManager;
use App\Engine\Module\ModuleManager;
use App\Engine\Queue\Queue;
use App\Engine\Routing\Router;
use App\Engine\Scheduler\ScheduleLock;
use App\Engine\Scheduler\ScheduleRegistry;
use App\Engine\Session\SessionManager;

/**
 * What this application is, in one screen.
 *
 * Note what this class is not: it extends nothing, implements nothing, and its
 * dependencies arrive through its constructor like any other object's. The
 * framework's own commands go through exactly the path a module's command goes
 * through -- there is no privileged registration, no separate list of built-ins
 * the kernel knows about by name. If commands only worked for the framework,
 * this is where that would show.
 */
final class AboutCommand
{
    public function __construct(
        private readonly Application $application,
        private readonly Config $config,
        private readonly ModuleManager $modules,
        private readonly Router $router,
        private readonly CommandRegistry $commands,
        private readonly HookEngine $hooks,
        private readonly FilterEngine $filters,
        private readonly AssetRegistry $assets,
        private readonly Cache $cache,
        private readonly Queue $queue,
        private readonly ScheduleRegistry $schedules,
        private readonly ScheduleLock $locks,
        private readonly SessionManager $sessions,
        private readonly AuthManager $auth,
        private readonly LogManager $logs,
        private readonly ConnectionManager $connections,
    ) {}

    public function __invoke(Output $output): int
    {
        $output->heading('App Framework');
        $output->pairs([
            'Version' => Application::VERSION,
            'PHP' => \PHP_VERSION,
            'Environment' => (string) $this->config->get('app.env', 'production'),
            'Debug' => $this->config->get('app.debug', false) === true ? 'on' : 'off',
            'Base path' => $this->application->basePath(),
            'Boot path' => $this->describeBootPath(),
            'Modules' => (string) $this->modules->registry()->count(),
            'Routes' => (string) $this->router->count(),
            'Commands' => (string) $this->commands->count(),
            'Hooks' => (string) \count($this->hooks->names()),
            'Filters' => (string) \count($this->filters->names()),
            'Assets' => $this->assets->count() . ' published',
            'Cache' => $this->cache->store()->describe(),
            'Queue' => $this->describeQueue(),
            'Schedule' => $this->describeSchedule(),
            'Logging' => $this->describeLogging(),
            'Observability' => $this->describeObservability(),
            'Sessions' => $this->sessions->describe(),
            'Users' => $this->auth->provider()->describe(),
            'Auth' => $this->auth->describe(),
            // Names only. Reading this line must never be a way to learn a
            // password, and a DSN is one typo away from carrying one.
            'Connections' => $this->connections->isConfigured()
                ? \implode(', ', $this->connections->names())
                : 'none configured',
        ]);

        return 0;
    }

    /**
     * One line, and it has to be able to say "uncached" without sounding broken.
     *
     * Uncached is right in development and a missed deployment step in
     * production, and this is the one screen somebody checks when a production
     * boot is slower than it should be. cache:warm is what changes the answer.
     */
    private function describeBootPath(): string
    {
        $config = ConfigCache::read(ConfigCache::file($this->application->basePath())) !== null
            ? 'config cached'
            : 'config read from config/';

        $modules = match (true) {
            $this->modules->discoveredFromCache() => 'modules cached',
            $this->config->get('app.debug', false) === true => 'modules scanned (debug never reads the cache)',
            default => 'modules scanned (cache:warm caches both)',
        };

        return $config . ', ' . $modules;
    }

    /**
     * One line: what is being measured, since the answer is usually "nothing"
     * and the question usually comes up while something is slow.
     *
     * Read from configuration rather than from the profiler, because the
     * profiler is deliberately not something a command depends on.
     */
    private function describeObservability(): string
    {
        $slow = $this->config->int('observability.slow_query_ms', 0) ?? 0;

        return \sprintf(
            'request ids on, profiling %s, %s',
            $this->config->bool('observability.profile', false) ? 'ON' : 'off',
            $slow > 0 ? \sprintf('queries over %d ms logged', $slow) : 'slow queries not logged',
        );
    }

    /**
     * One line, and it has to be able to say how much is waiting.
     *
     * A queue nobody is draining looks exactly like an empty one from inside
     * the application, so the number is worth more here than the store name.
     */
    private function describeQueue(): string
    {
        $waiting = 0;

        foreach ($this->queue->queues() as $queue) {
            $waiting += $this->queue->pending($queue);
        }

        $failed = \count($this->queue->failed());

        return $this->queue->store()->describe()
            . ($waiting === 0 ? '' : \sprintf(', %d waiting', $waiting))
            . ($failed === 0 ? '' : \sprintf(', %d failed', $failed));
    }

    /**
     * One line, and it has to be able to say "nothing is scheduled".
     *
     * The count is what matters, not the clock: every schedule in this
     * application depends on one cron line that this process cannot see, so the
     * useful thing to report here is how much work is relying on it.
     */
    private function describeSchedule(): string
    {
        $count = $this->schedules->count();

        if ($count === 0) {
            return 'nothing scheduled';
        }

        $running = \count($this->locks->held());

        return \sprintf('%d task%s', $count, $count === 1 ? '' : 's')
            . ($running === 0 ? '' : \sprintf(', %d running', $running));
    }

    /**
     * One line, and it has to be able to say "nowhere".
     *
     * An application that believes it is logging and is not is a common and
     * expensive misunderstanding, so the summary says how many writers are
     * attached rather than just the level. log:status has the detail.
     */
    private function describeLogging(): string
    {
        $writers = \count($this->logs->writers());

        $summary = $writers === 0
            ? 'nothing attached'
            : \sprintf('%d writer%s at %s', $writers, $writers === 1 ? '' : 's', $this->logs->minimum()->label());

        return $this->logs->isHealthy() ? $summary : $summary . ' (see log:status)';
    }
}
