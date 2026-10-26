<?php

declare(strict_types=1);

namespace App\Engine\System;

use App\Engine\Config\Config;
use App\Engine\Config\ConfigurationException;
use App\Engine\Support\Path;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\CommandPolicy;
use App\Engine\System\Command\ConcurrencyLimit;
use App\Engine\System\Command\Invocation;
use App\Engine\System\Filesystem\FilesystemPolicy;
use App\Engine\System\Service\ServiceAction;
use App\Engine\System\Service\ServicePolicy;

/**
 * The `system` configuration block, read once and checked.
 *
 *     // config/system.php
 *     return [
 *         'execution' => ['default_timeout' => 120, 'max_output' => 4 * 1024 * 1024],
 *         'shell' => ['enabled' => true],
 *         'commands' => [
 *             'allowed' => ['/usr/bin/rsync', '/usr/bin/systemctl'],
 *             'scripts' => ['/srv/app/scripts/backup.sh'],
 *         ],
 *         'services' => ['nginx' => ['reload', 'restart']],
 *         'filesystem' => ['read' => ['/var/log/nginx'], 'write' => ['/srv/backups']],
 *     ];
 *
 * **Every default is the cautious one.** Shell execution is off; no service
 * change, filesystem root, owner or group is allowed; the audit log is on (and
 * read by Bootstrap, which attaches it). What an
 * application needs, it names.
 *
 * **Every value is checked when this is built, not when it is first used** --
 * a wrong type, a timeout of zero, a service action that does not exist, a
 * filesystem root of "/" -- and the exception names the key. Building it is
 * lazy (the container does it the first time a system manager is asked for),
 * so an application that never touches engine/System pays nothing.
 *
 * The allowlist here is the simple form: exact executables and scripts. A rule
 * with argument patterns, working directories or environment names is made in
 * code with CommandPolicy, which a module can bind in place of the default.
 * When neither list is set, there is no allowlist at all.
 */
final class SystemConfig
{
    /**
     * @param list<string> $owners
     * @param list<string> $groups
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly float $timeout,
        public readonly int $maxOutput,
        public readonly bool $shell,
        public readonly string $shellBinary,
        public readonly ?CommandPolicy $commandPolicy,
        public readonly ServicePolicy $servicePolicy,
        public readonly FilesystemPolicy $filesystemPolicy,
        public readonly array $owners,
        public readonly array $groups,
        public readonly bool $cron,
        public readonly string $cronOwner,
        public readonly ?ConcurrencyLimit $concurrency,
    ) {}

    /** @throws ConfigurationException naming the key that is wrong */
    public static function fromConfig(Config $config, string $basePath): self
    {
        $timeout = $config->float('system.execution.default_timeout', CommandExecutor::DEFAULT_TIMEOUT) ?? CommandExecutor::DEFAULT_TIMEOUT;
        $maxOutput = $config->int('system.execution.max_output', CommandExecutor::DEFAULT_MAX_OUTPUT) ?? CommandExecutor::DEFAULT_MAX_OUTPUT;
        $shellBinary = $config->string('system.shell.binary', 'bash') ?? 'bash';

        self::attempt('system.execution', 'a timeout above zero seconds and an output limit of at least one byte', static fn() => Invocation::checkDefaults($timeout, $maxOutput));
        self::attempt('system.shell.binary', '"bash" or an absolute path to bash', static fn() => Invocation::checkShellBinary($shellBinary));

        $owner = $config->string('system.cron.owner');
        $slots = $config->int('system.execution.max_concurrent');
        $concurrency = $slots === null ? null : self::attempt(
            'system.execution.max_concurrent',
            'at least 1, or null for no limit',
            static fn() => new ConcurrencyLimit(Path::join($basePath, 'system', 'Commands'), $slots),
        );

        return new self(
            $config->bool('system.enabled', true),
            $timeout,
            $maxOutput,
            $config->bool('system.shell.enabled', false),
            $shellBinary,
            self::commandPolicy($config),
            self::servicePolicy($config),
            self::filesystemPolicy($config),
            $config->strings('system.permissions.owners'),
            $config->strings('system.permissions.groups'),
            $config->bool('system.cron.enabled', true),
            $owner ?? self::ownerFor($basePath),
            $concurrency,
        );
    }

    /** @throws SystemDisabledException */
    public function assertEnabled(): void
    {
        if (!$this->enabled) {
            throw SystemDisabledException::system();
        }
    }

    /**
     * The crontab owner when none is configured: the application directory's
     * name, made safe, and a short hash of its full path -- stable across
     * deployments to the same place, and different for two checkouts of the
     * same application on one machine.
     */
    public static function ownerFor(string $basePath): string
    {
        $real = \realpath($basePath);
        $path = \str_replace('\\', '/', $real === false ? $basePath : $real);
        $name = \trim((string) \preg_replace('/[^a-z0-9._-]+/', '-', \strtolower(\basename($path))), '-._');

        return \substr($name === '' ? 'app' : $name, 0, 40) . '-' . \substr(\sha1($path), 0, 8);
    }

    private static function commandPolicy(Config $config): ?CommandPolicy
    {
        // null, not empty: an empty list is an allowlist that allows nothing.
        if ($config->get('system.commands.allowed') === null && $config->get('system.commands.scripts') === null) {
            return null;
        }

        $policy = CommandPolicy::allowlist();

        foreach ($config->strings('system.commands.allowed') as $path) {
            $policy = self::attempt('system.commands.allowed', 'a list of absolute paths', static fn() => $policy->allowExecutable($path));
        }

        foreach ($config->strings('system.commands.scripts') as $path) {
            $policy = self::attempt('system.commands.scripts', 'a list of absolute paths', static fn() => $policy->allowScript($path));
        }

        return $policy;
    }

    private static function servicePolicy(Config $config): ServicePolicy
    {
        $policy = ServicePolicy::none();

        foreach ($config->array('system.services') as $service => $actions) {
            $key = 'system.services.' . $service;

            if (!\is_string($service) || !\is_array($actions)) {
                throw ConfigurationException::unusableValue('system.services', 'a map of service name to a list of actions');
            }

            $allowed = [];

            foreach ($actions as $action) {
                $allowed[] = (\is_string($action) ? ServiceAction::tryFrom($action) : null)
                    ?? throw ConfigurationException::unusableValue($key, 'a list of start, stop, restart, reload, enable, disable');
            }

            $policy = self::attempt($key, 'a service name', static fn() => $policy->allow($service, ...$allowed));
        }

        return $policy;
    }

    private static function filesystemPolicy(Config $config): FilesystemPolicy
    {
        $policy = FilesystemPolicy::none();

        foreach ($config->strings('system.filesystem.read') as $root) {
            $policy = self::attempt('system.filesystem.read', 'a list of absolute directories, none of them a filesystem root', static fn() => $policy->allowRead($root));
        }

        foreach ($config->strings('system.filesystem.write') as $root) {
            $policy = self::attempt('system.filesystem.write', 'a list of absolute directories, none of them a filesystem root', static fn() => $policy->allowWrite($root));
        }

        return $policy;
    }

    /**
     * Run a check, and turn the layer's refusal into a configuration error that
     * names the key. The original is kept as the previous exception.
     *
     * @template T
     *
     * @param \Closure(): T $check
     *
     * @return T
     */
    private static function attempt(string $key, string $expected, \Closure $check): mixed
    {
        try {
            return $check();
        } catch (SystemException $e) {
            throw ConfigurationException::unusableValue($key, $expected, $e);
        }
    }
}
