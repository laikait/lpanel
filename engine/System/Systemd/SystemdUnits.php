<?php

declare(strict_types=1);

namespace App\Engine\System\Systemd;

use App\Engine\Queue\Queue;
use App\Engine\Support\Path;

/**
 * The systemd units an application needs: a queue worker and the scheduler.
 *
 *     $units = SystemdUnits::forApplication('/srv/shop', 'shop-1a2b3c4d', user: 'www-data');
 *
 *     shop-1a2b3c4d-worker@.service     one worker per queue: worker@default, worker@billing
 *     shop-1a2b3c4d-scheduler.service   runs schedule:run once
 *     shop-1a2b3c4d-scheduler.timer     ...every minute
 *
 * **The worker is a template.** The instance is the queue name, so one file
 * serves every queue and enabling another is `systemctl enable --now
 * <prefix>-worker@billing`, with nothing regenerated. queue:work exits on
 * purpose -- after --max-jobs or --max-time, or near its memory limit -- and
 * Restart=always starts it again, which is how new code reaches a worker
 * after a deployment.
 *
 * **Stopping a worker mid-job costs one retry.** The worker has no signal
 * handling (see Worker for why), so systemctl stop ends the process; the job's
 * reservation expires and it runs again. Nothing is lost.
 *
 * **The timer replaces the cron line, never joins it.** Both run schedule:run
 * every minute; with both installed every task runs twice. The same one-host
 * rule applies as for cron: the schedule locks are files on this machine.
 *
 * **The prefix is the cron owner** (system.cron.owner, or one derived from the
 * application directory), so two checkouts on one machine get separate units,
 * as they get separate crontab blocks.
 */
final class SystemdUnits
{
    /** A unit name prefix: what systemd allows in a name, without "@", which means an instance. */
    private const PREFIX = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/D';

    /** User and group names systemd accepts in User= and Group= (the portable POSIX set). */
    private const ACCOUNT = '/^[a-z_][a-z0-9_-]{0,31}$/D';

    public const MAX_JOBS = 1000;

    public const MAX_TIME = 3600;

    private function __construct(
        private readonly string $basePath,
        private readonly string $prefix,
        private readonly string $php,
        private readonly string $user,
        private readonly ?string $group,
    ) {}

    /**
     * @param string  $basePath the application root, where the laika console script is
     * @param string  $prefix   the start of every unit name
     * @param string  $user     who the units run as; never root unless named explicitly
     * @param ?string $group    null for the user's own group
     * @param ?string $php      absolute path to the PHP CLI; null for this one, from the console only
     *
     * @throws SystemdException
     */
    public static function forApplication(
        string $basePath,
        string $prefix,
        string $user,
        ?string $group = null,
        ?string $php = null,
    ): self {
        if (!Path::isAbsolute($basePath)) {
            throw SystemdException::relativePath($basePath);
        }

        if (\preg_match(self::PREFIX, $prefix) !== 1) {
            throw SystemdException::invalidPrefix($prefix);
        }

        if (\preg_match(self::ACCOUNT, $user) !== 1) {
            throw SystemdException::invalidAccount('user', $user);
        }

        if ($group !== null && \preg_match(self::ACCOUNT, $group) !== 1) {
            throw SystemdException::invalidAccount('group', $group);
        }

        if ($php === null) {
            if (\PHP_SAPI !== 'cli') {
                throw SystemdException::noPhpBinary(\PHP_SAPI);
            }

            $php = \PHP_BINARY;
        }

        if (!Path::isAbsolute($php)) {
            throw SystemdException::relativePhp($php);
        }

        return new self(\rtrim($basePath, '/\\') ?: '/', $prefix, $php, $user, $group);
    }

    /**
     * forApplication() with the user left out: the owner of the application
     * directory, which is who deployed it and whose files the worker writes.
     * An implicit root is refused -- a worker running as root is a decision
     * somebody has to type.
     *
     * @throws SystemdException
     */
    public static function forDeployment(
        string $basePath,
        string $prefix,
        ?string $user = null,
        ?string $group = null,
        ?string $php = null,
    ): self {
        if ($user === null || $user === '') {
            $user = self::ownerOf($basePath) ?? throw SystemdException::noUser();

            if ($user === 'root') {
                throw SystemdException::runsAsRoot();
            }
        }

        return self::forApplication($basePath, $prefix, $user, $group === '' ? null : $group, $php === '' ? null : $php);
    }

    private static function ownerOf(string $path): ?string
    {
        $uid = @\fileowner($path);

        if ($uid === false || !\function_exists('posix_getpwuid')) {
            return null;
        }

        $account = \posix_getpwuid($uid);

        return \is_array($account) ? $account['name'] : null;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** The template's file name: <prefix>-worker@.service. */
    public function workerTemplate(): string
    {
        return $this->prefix . '-worker@.service';
    }

    /**
     * The unit that runs a worker on $queue: <prefix>-worker@<queue>.service.
     *
     * @throws SystemdException when $queue is not a queue name
     */
    public function worker(string $queue): string
    {
        if (\preg_match(Queue::NAME_PATTERN, $queue) !== 1) {
            throw SystemdException::invalidQueue($queue);
        }

        return $this->prefix . '-worker@' . $queue . '.service';
    }

    /** Whether $unit is one this application generates: its timer, its scheduler service, or a worker instance. */
    public function owns(string $unit): bool
    {
        if ($unit === $this->schedulerTimer() || $unit === $this->schedulerService()) {
            return true;
        }

        $worker = $this->prefix . '-worker@';

        return \str_starts_with($unit, $worker)
            && \str_ends_with($unit, '.service')
            && \preg_match(Queue::NAME_PATTERN, \substr($unit, \strlen($worker), -\strlen('.service'))) === 1;
    }

    public function schedulerService(): string
    {
        return $this->prefix . '-scheduler.service';
    }

    public function schedulerTimer(): string
    {
        return $this->prefix . '-scheduler.timer';
    }

    /** @return list<UnitFile> the template, the scheduler service and its timer */
    public function files(): array
    {
        return [
            new UnitFile($this->workerTemplate(), $this->workerContents()),
            new UnitFile($this->schedulerService(), $this->schedulerContents()),
            new UnitFile($this->schedulerTimer(), $this->timerContents()),
        ];
    }

    private function workerContents(): string
    {
        return $this->render([
            'Unit' => [
                'Description' => $this->prefix . ' queue worker (%i)',
                'After' => 'network-online.target',
                'Wants' => 'network-online.target',
            ],
            'Service' => [
                ...$this->account(),
                'WorkingDirectory' => self::literal($this->basePath),
                'ExecStart' => $this->exec(['queue:work', '--queue=%i', '--max-jobs=' . self::MAX_JOBS, '--max-time=' . self::MAX_TIME]),
                'Restart' => 'always',
                'RestartSec' => '1',
                ...$this->hardening(),
            ],
            'Install' => ['WantedBy' => 'multi-user.target'],
        ]);
    }

    private function schedulerContents(): string
    {
        return $this->render([
            'Unit' => [
                'Description' => $this->prefix . ' scheduler (schedule:run)',
                'After' => 'network-online.target',
                'Wants' => 'network-online.target',
            ],
            'Service' => [
                'Type' => 'oneshot',
                ...$this->account(),
                'WorkingDirectory' => self::literal($this->basePath),
                'ExecStart' => $this->exec(['schedule:run']),
                ...$this->hardening(),
            ],
        ]);
    }

    private function timerContents(): string
    {
        return $this->render([
            'Unit' => ['Description' => $this->prefix . ' scheduler, every minute'],
            'Timer' => [
                'OnCalendar' => '*-*-* *:*:00',
                'AccuracySec' => '1s',
                'Persistent' => 'false',
                'Unit' => $this->schedulerService(),
            ],
            'Install' => ['WantedBy' => 'timers.target'],
        ]);
    }

    /** @return array<string, string> */
    private function account(): array
    {
        return $this->group === null ? ['User' => $this->user] : ['User' => $this->user, 'Group' => $this->group];
    }

    /**
     * What every unit can give up without the framework noticing. Nothing that
     * restricts the filesystem: a module may legitimately write anywhere its
     * configuration says, and a unit that fails for that is worse than none.
     *
     * @return array<string, string>
     */
    private function hardening(): array
    {
        return ['NoNewPrivileges' => 'yes', 'PrivateTmp' => 'yes'];
    }

    /** @param list<string> $arguments */
    private function exec(array $arguments): string
    {
        $words = [self::literal($this->php), self::literal(Path::join($this->basePath, 'laika')), ...$arguments];

        return \implode(' ', \array_map(self::quote(...), $words));
    }

    /** A path as systemd must read it: "%" starts a specifier, so a literal one is "%%". */
    private static function literal(string $path): string
    {
        return \str_replace('%', '%%', $path);
    }

    /**
     * systemd's own quoting: a word with a space or a quote is double-quoted,
     * with backslash, quote and "$" escaped. "%" is left alone here: literal()
     * has already doubled the ones in paths, and %i is meant.
     */
    private static function quote(string $word): string
    {
        // A line break ends the setting inside quotes too, and whatever follows
        // is read as the next directive -- an ExecStartPre of somebody's
        // choosing in a unit that runs as a service. No quoting fixes that.
        if (\preg_match('/[\r\n\0]/', $word) === 1) {
            throw SystemdException::lineBreak();
        }

        if (\preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $word) === 1) {
            return $word;
        }

        return '"' . \addcslashes($word, '\\"$') . '"';
    }

    /** @param array<string, array<string, string>> $sections */
    private function render(array $sections): string
    {
        $lines = ['# Generated by php laika system:systemd:generate. Regenerate rather than edit.'];

        foreach ($sections as $section => $settings) {
            $lines[] = '';
            $lines[] = '[' . $section . ']';

            foreach ($settings as $key => $value) {
                $lines[] = $key . '=' . $value;
            }
        }

        return \implode("\n", $lines) . "\n";
    }
}
