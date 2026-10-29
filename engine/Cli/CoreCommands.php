<?php

declare(strict_types=1);

namespace App\Engine\Cli;

use App\Engine\Cli\Commands\AboutCommand;
use App\Engine\Cli\Commands\AssetListCommand;
use App\Engine\Cli\Commands\AuthAccessCommand;
use App\Engine\Cli\Commands\AuthHashCommand;
use App\Engine\Cli\Commands\CacheClearCommand;
use App\Engine\Cli\Commands\CacheWarmCommand;
use App\Engine\Cli\Commands\ConfigCacheCommand;
use App\Engine\Cli\Commands\ConfigListCommand;
use App\Engine\Cli\Commands\DbSeedCommand;
use App\Engine\Cli\Commands\DownCommand;
use App\Engine\Cli\Commands\FrameworkManifestCommand;
use App\Engine\Cli\Commands\FrameworkRollbackCommand;
use App\Engine\Cli\Commands\FrameworkUpdateCommand;
use App\Engine\Cli\Commands\HelpCommand;
use App\Engine\Cli\Commands\LogStatusCommand;
use App\Engine\Cli\Commands\McpListCommand;
use App\Engine\Cli\Commands\McpStdioCommand;
use App\Engine\Cli\Commands\MigrateCommand;
use App\Engine\Cli\Commands\MigrateRollbackCommand;
use App\Engine\Cli\Commands\MigrateStatusCommand;
use App\Engine\Cli\Commands\ModuleListCommand;
use App\Engine\Cli\Commands\ModuleMakeCommand;
use App\Engine\Cli\Commands\NginxMakeCommand;
use App\Engine\Cli\Commands\OpenApiAuthCommand;
use App\Engine\Cli\Commands\OpenApiDocsCommand;
use App\Engine\Cli\Commands\OpenApiExportCommand;
use App\Engine\Cli\Commands\QueueFailedCommand;
use App\Engine\Cli\Commands\QueueStatusCommand;
use App\Engine\Cli\Commands\QueueWorkCommand;
use App\Engine\Cli\Commands\RouteListCommand;
use App\Engine\Cli\Commands\ScheduleListCommand;
use App\Engine\Cli\Commands\ScheduleRunCommand;
use App\Engine\Cli\Commands\ScheduleUnlockCommand;
use App\Engine\Cli\Commands\SecurityCheckCommand;
use App\Engine\Cli\Commands\SecurityKeyCommand;
use App\Engine\Cli\Commands\SessionGcCommand;
use App\Engine\Cli\Commands\SystemCronInstallCommand;
use App\Engine\Cli\Commands\SystemCronListCommand;
use App\Engine\Cli\Commands\SystemCronRemoveCommand;
use App\Engine\Cli\Commands\SystemInfoCommand;
use App\Engine\Cli\Commands\SystemServiceRestartCommand;
use App\Engine\Cli\Commands\SystemServiceStatusCommand;
use App\Engine\Cli\Commands\SystemSystemdGenerateCommand;
use App\Engine\Cli\Commands\SystemSystemdInstallCommand;
use App\Engine\Cli\Commands\SystemSystemdRemoveCommand;
use App\Engine\Cli\Commands\TemplateListCommand;
use App\Engine\Cli\Commands\UpCommand;
use App\Engine\System\Cron\ScheduleRunJob;

/**
 * The commands the framework itself provides.
 *
 * Registered through the same collector a module uses, with the module name
 * "engine" so that `module:list` and a duplicate-name error both tell the truth
 * about where a command came from. The kernel has no idea these are special,
 * because they are not: if this file were deleted the console would still work
 * and would simply have fewer commands.
 *
 * Almost every one of them answers a question that is otherwise expensive to
 * answer -- what loaded, what routes exist, where templates are searched.
 * nginx:make writes web server configuration, which nothing in the framework
 * reads. module:make is the one generator, and it writes the one file a module
 * requires, exactly as modules.md documents it, plus the starter files --with asks for. A make:something command writes
 * a file whose shape the framework then quietly depends on, and scaffolding is
 * how a framework stops being a library you call and starts being a thing you
 * live inside; so there is no make:controller, and module:make adds no folder unless asked.
 */
final class CoreCommands
{
    public const MODULE = 'engine';

    public static function register(CommandRegistry $registry): void
    {
        $commands = new CommandCollector($registry, self::MODULE);

        $commands->add('about', AboutCommand::class)
            ->describe('Summarise this application: version, modules, routes, connections.');

        $commands->add('help', HelpCommand::class)
            ->describe('List the available commands, or explain one of them.')
            ->argument('command', 'The command to explain.', required: false);

        $commands->add('mcp:list', McpListCommand::class)
            ->describe('List every MCP tool, resource and prompt, its module and what a caller needs.')
            ->option('module', 'Only capabilities belonging to this module, e.g. Crm.', shortcut: 'm');

        $commands->add('mcp:stdio', McpStdioCommand::class)
            ->describe('Serve MCP over stdin and stdout, for a local client that starts this command.')
            ->option('user', 'Act as this user, by login or id. Without one, the client is a guest.')
            ->note('stdout carries the protocol only. Point the MCP client at: php laika mcp:stdio --user=<login>');

        $commands->add('module:list', ModuleListCommand::class)
            ->describe('List discovered modules, in the order they load.')
            ->note('The order is load order, which is what decides whose hook runs first at equal priority.');

        $commands->add('module:make', ModuleMakeCommand::class)
            ->describe('Create modules/<name>/module.php, the one file a module needs.')
            ->argument('name', 'Billing, or Plugins/Billing to put it in a group folder. The module id is the last part.')
            ->option('with', 'Also create: http, model, database, seeder, templates, assets, lang, or all (comma-separated).')
            ->note('Writes module.php only and never overwrites. A grouped module loads once modules/<Group> is in modules.paths: it creates config/modules.php for that, or tells you what to add to an existing one.');

        $commands->add('route:list', RouteListCommand::class)
            ->describe('List every registered route and its owning module.')
            ->option('module', 'Only routes belonging to this module, e.g. Billing.', shortcut: 'm')
            ->flag('names', 'Only routes that have a name.', shortcut: 'n');

        $commands->add('openapi:export', OpenApiExportCommand::class)
            ->describe('Print the OpenAPI description of the routes marked with openapi meta.')
            ->option('file', 'Write it to this file instead of stdout.', shortcut: 'f')
            ->flag('compact', 'One line, no indentation.', shortcut: 'c')
            ->note('Only routes with the openapi meta appear. A meta mistake exits 1 with the reason on stderr.');

        $commands->add('openapi:docs', OpenApiDocsCommand::class)
            ->describe('Switch the API docs page (/docs) and its description (/openapi.json) on or off.')
            ->flag('enable', 'Turn both on.')
            ->flag('disable', 'Turn both off.')
            ->option('path', 'Where the page is, e.g. /api-docs. Default /docs.')
            ->option('spec', 'Where the description is. Default /openapi.json.')
            ->note('With neither flag, shows what is on. One machine at a time; it wins over config/openapi.php.');

        $commands->add('openapi:auth', OpenApiAuthCommand::class)
            ->describe('Require a login, or a capability, for the API docs and description.')
            ->flag('enable', 'Require a login.')
            ->flag('disable', 'Let anyone read them.')
            ->option('can', 'With --enable: require this capability instead of any login.')
            ->note('With neither flag, shows what is set. One machine at a time; it wins over config/openapi.php.');

        $commands->add('asset:list', AssetListCommand::class)
            ->describe('List every published asset directory and the URL prefix it answers on.');

        $commands->add('template:list', TemplateListCommand::class)
            ->describe('List the template search path, highest precedence first.');

        $commands->add('log:status', LogStatusCommand::class)
            ->describe('Show where log records go, and whether they are getting there.')
            ->flag('write', 'Send a real record through the real writers.', shortcut: 'w')
            ->option('channel', 'The channel to write the test record to.', default: 'app')
            ->note('Logging fails quietly on purpose, so this is where it admits to having failed.');

        $commands->add('config:list', ConfigListCommand::class)
            ->describe('Show the configuration this process actually resolved to.')
            ->option('prefix', 'Only keys starting with this, e.g. logging or Billing.', shortcut: 'p')
            ->flag('sources', 'Also report which files, .env and cache were involved.', shortcut: 's')
            ->note('A few key names print as [hidden]. There is deliberately no flag to reveal them.');

        $commands->add('config:cache', ConfigCacheCommand::class)
            ->describe('Compile config/ and the defaults into one cached file.')
            ->flag('clear', 'Delete the cached file instead of building it.')
            ->note('The cache ignores itself when an environment variable it read has changed.');

        $commands->add('queue:work', QueueWorkCommand::class)
            ->describe('Run queued jobs until told to stop.')
            ->option('queue', 'Which queue to drain.', shortcut: 'q')
            ->flag('once', 'Take one job and stop.')
            ->flag('drain', 'Work until the queue is empty, then stop.')
            ->option('max-jobs', 'Stop after this many jobs.', default: '0')
            ->option('max-time', 'Stop after this many seconds.', default: '0')
            ->option('memory', 'Stop, between jobs, once memory use reaches this: 128M, 1G. Default 80% of memory_limit.')
            ->option('tries', 'Attempts before a job is recorded as failed.')
            ->option('timeout', 'How long a job may hold its reservation, in seconds.')
            ->option('sleep', 'Seconds to wait when the queue is empty.')
            ->note('Bounded runs are the point: let it exit and have the supervisor start a fresh one.');

        $commands->add('queue:status', QueueStatusCommand::class)
            ->describe('Show what is waiting on each queue, and what has failed.')
            ->note('A queue nobody is draining looks exactly like an empty one from inside the application.');

        $commands->add('queue:failed', QueueFailedCommand::class)
            ->describe('List the jobs that gave up, and retry or discard them.')
            ->option('retry', 'Queue this job again, with its attempts reset.')
            ->option('forget', 'Discard this failed job.')
            ->flag('retry-all', 'Queue every failed job again.');

        $commands->add('schedule:list', ScheduleListCommand::class)
            ->describe('List every scheduled task, when it next runs, and whether it is running now.')
            ->option('module', 'Only schedules belonging to this module, e.g. Billing.', shortcut: 'm')
            ->flag('due', 'Only what is due this minute.')
            ->note('One cron line drives all of these. If it is missing, every "Next" below is fiction.');

        $commands->add('schedule:run', ScheduleRunCommand::class)
            ->describe('Run whatever is due this minute. This is what cron calls.')
            ->option('id', 'Run just this schedule.')
            ->flag('force', 'Run it even though it is not due. Still takes the lock.')
            ->flag('dry-run', 'Print what would run and run nothing.', shortcut: 'd')
            ->note('Exits 1 if anything failed, so the cron line itself can be monitored.');

        $commands->add('schedule:unlock', ScheduleUnlockCommand::class)
            ->describe('Show held schedule locks, and release them after a machine died mid-run.')
            ->option('id', 'Release this schedule\'s lock.')
            ->flag('all', 'Release every held lock.')
            ->note('Locks expire on their own. Releasing one whose task is still running starts a second copy.');

        $commands->add('system:info', SystemInfoCommand::class)
            ->describe('Show the operating system, kernel, memory, disk and load of this machine.')
            ->note('Read from PHP and /proc; nothing is run to find out.');

        $commands->add('system:service:status', SystemServiceStatusCommand::class)
            ->describe('Show whether a systemd service is running.')
            ->argument('service', 'The service, e.g. nginx or php8.3-fpm.')
            ->note('Exits 0 when the service is active, 1 otherwise.');

        $commands->add('system:service:restart', SystemServiceRestartCommand::class)
            ->describe('Restart a systemd service that system.services allows restarting.')
            ->argument('service', 'The service, e.g. nginx.')
            ->note('Refused unless config/system.php lists the service with "restart". Audited.');

        $commands->add('system:cron:list', SystemCronListCommand::class)
            ->describe('List the jobs this application owns in the crontab.');

        $commands->add('system:cron:install', SystemCronInstallCommand::class)
            ->describe('Install the crontab line that runs schedule:run every minute.')
            ->option('php', 'Absolute path of the PHP CLI; this one by default.')
            ->option('log', 'Append the line\'s output to this absolute path instead of discarding it.')
            ->note('Safe on every deployment: an unchanged line is left alone. Install it on one host only.');

        $commands->add('system:cron:remove', SystemCronRemoveCommand::class)
            ->describe('Remove one of this application\'s crontab jobs, or all of them.')
            ->argument('id', 'The job to remove.', required: false, default: ScheduleRunJob::ID)
            ->flag('all', 'Remove every job this application owns.');

        $commands->add('system:systemd:generate', SystemSystemdGenerateCommand::class)
            ->describe('Print the systemd units for the queue worker and the scheduler.')
            ->option('user', 'Run them as this user; the owner of the application directory by default.')
            ->option('group', 'Run them as this group; the user\'s own by default.')
            ->option('php', 'Absolute path of the PHP CLI; this one by default.')
            ->option('queue', 'Queues to run a worker on, comma separated.', default: 'default')
            ->option('write', 'Write the files into this directory instead of printing them.')
            ->note('Changes nothing. The timer replaces the schedule:run cron line; keep one of the two.');

        $commands->add('system:systemd:install', SystemSystemdInstallCommand::class)
            ->describe('Install the worker and scheduler units, reload systemd and start them. Root only.')
            ->option('user', 'Run them as this user; the owner of the application directory by default.')
            ->option('group', 'Run them as this group; the user\'s own by default.')
            ->option('php', 'Absolute path of the PHP CLI; this one by default.')
            ->option('queue', 'Queues to run a worker on, comma separated.', default: 'default')
            ->flag('replace-cron', 'Remove the schedule:run cron line, which the timer replaces.')
            ->flag('no-scheduler', 'Install and start the workers only; leave scheduling to cron.')
            ->note('Safe on every deployment. Refuses while the schedule:run cron line is installed. Audited.');

        $commands->add('system:systemd:remove', SystemSystemdRemoveCommand::class)
            ->describe('Stop, disable and delete this application\'s systemd units. Root only.');

        $commands->add('down', DownCommand::class)
            ->describe('Put the web side into maintenance mode: every request gets a 503 page.')
            ->option('retry', 'Seconds clients are told to wait (Retry-After).', default: '60')
            ->option('message', 'Shown on the 503 page.')
            ->option('allow', 'Addresses or CIDR blocks that still get in, comma separated.')
            ->option('secret', 'Enables /?lphp_bypass=<secret>, which lets one browser in.')
            ->note('The console, queue workers and the scheduler keep running. One machine at a time: run it on each web server.');

        $commands->add('up', UpCommand::class)
            ->describe('Leave maintenance mode.');

        $commands->add('framework:update', FrameworkUpdateCommand::class)
            ->describe('Update this application\'s copy of the framework to a newer release.')
            ->flag('check', 'Show the installed and the latest version; change nothing.')
            ->option('to', 'The release to update to, e.g. 3.1.0; the latest by default.')
            ->option('from', 'A release zip, or its unpacked directory, instead of downloading from GitHub.')
            ->flag('dry-run', 'Show what would change; change nothing.')
            ->flag('force', 'Replace framework files edited here (they are backed up), or go to an older release.')
            ->flag('major', 'Allow a major-version upgrade, after reading UPGRADING.md.')
            ->option('baseline', 'The framework.json of the installed release, when this application has none.')
            ->note('Stops, changing nothing, if a framework file was edited here. Backs up what it changes. Runs neither composer nor migrations.');

        $commands->add('framework:rollback', FrameworkRollbackCommand::class)
            ->describe('Undo the last framework:update from its backup.');

        $commands->add('framework:manifest', FrameworkManifestCommand::class)
            ->describe('Build framework.json: every file a release ships, its hash and whose it is.')
            ->option('from', 'The release tree, as git archive produced it; this directory by default.')
            ->option('write', 'Write it to this path instead of printing it.')
            ->option('release', 'The version to record; Application::VERSION by default.')
            ->note('Run by the release workflow. An application never needs it.');

        $commands->add('security:check', SecurityCheckCommand::class)
            ->describe('Audit what this deployment actually has switched on.')
            ->flag('verbose', 'Also show the checks that passed.', shortcut: 'v')
            ->note('Exits 1 on a problem, so it can be a deployment step rather than a thing to remember.');

        $commands->add('security:key', SecurityKeyCommand::class)
            ->describe('Print a new APP_KEY.')
            ->flag('bare', 'The key alone, for APP_KEY=$(...) in a script.')
            ->note('It prints and does not write. Replacing a live key invalidates every token signed with it.');

        $commands->add('auth:access', AuthAccessCommand::class)
            ->describe('Every capability, every role, and which routes check them.')
            ->flag('verbose', 'Also list the routes that require nobody.', shortcut: 'v')
            ->note('Roles are shown flattened, because that is what a check actually sees.');

        $commands->add('auth:hash', AuthHashCommand::class)
            ->describe('Hash a password, for seeding the first account.')
            ->argument('password', 'The password to hash.')
            ->flag('bare', 'The hash alone, to paste into a seed.')
            ->note('It prints and does not write: the framework does not know where your users live.');

        $commands->add('session:gc', SessionGcCommand::class)
            ->describe('Delete sessions past their lifetime.')
            ->flag('quiet', 'Say nothing, for a schedule that only needs a failure to be noisy.', shortcut: 'q')
            ->note('Nothing expires because it was swept -- expiry is decided on read. This reclaims the space.');

        $commands->add('migrate', MigrateCommand::class)
            ->describe('Run every module\'s pending migrations, in module order, as one batch.')
            ->option('connection', 'Which connection. Defaults to the default one.')
            ->flag('pretend', 'Print the SQL each pending migration would send, and run nothing.')
            ->note('A deploy step. Give --connection an account with the right to create tables; the application\'s own should not have it.');

        $commands->add('migrate:status', MigrateStatusCommand::class)
            ->describe('Every migration, whether it ran and in which batch.')
            ->option('connection', 'Which connection. Defaults to the default one.');

        $commands->add('migrate:rollback', MigrateRollbackCommand::class)
            ->describe('Undo the last batch of migrations, newest first.')
            ->option('connection', 'Which connection. Defaults to the default one.')
            ->option('batches', 'How many batches to undo.', default: '1')
            ->flag('force', 'Roll back in production.')
            ->note('Refused before anything runs if a migration in the range has no down(), or its file is gone.');

        $commands->add('db:seed', DbSeedCommand::class)
            ->describe('Run every module\'s seeders, in module order, or one module\'s.')
            ->option('connection', 'Which connection. Defaults to the default one.')
            ->option('module', 'Only this module\'s seeders, by id: Billing.')
            ->flag('force', 'Seed in production.')
            ->note('Nothing records that a seeder ran; each runs every time, so each looks before it inserts.');

        $commands->add('cache:clear', CacheClearCommand::class)
            ->describe('Delete the configuration, module, template and application caches.')
            ->flag('expired', 'Only remove entries that have expired, leaving the rest warm.')
            ->note('None of them invalidates itself on a file edit, which is why clearing them is a deployment step.');

        $commands->add('nginx:make', NginxMakeCommand::class)
            ->describe('Write the nginx server block for this application to nginx.conf.')
            ->option('server-name', 'The host names nginx answers for. "_" answers any.', default: '_')
            ->option('root', 'The application directory on the web server. Defaults to this one.')
            ->option('listen', 'The port, or address:port, to listen on.', default: '80')
            ->option('php', 'Where PHP-FPM listens: unix:/path/to.sock or host:port.', default: 'unix:/run/php/php-fpm.sock')
            ->flag('force', 'Replace an existing nginx.conf.')
            ->note('It writes configuration, not code, and refuses to overwrite a file somebody may have edited.');

        $commands->add('cache:warm', CacheWarmCommand::class)
            ->describe('Build the production boot path: the configuration and module discovery caches.')
            ->note('Refuses with APP_DEBUG on, because a debug process never reads the module cache.');
    }
}
