<?php

declare(strict_types=1);

namespace App\Engine\System\SystemInfo;

use App\Engine\System\Command\Invocation;

/**
 * Read-only facts about the machine, without starting a process.
 *
 *     $info->os();            // "Ubuntu 26.04 LTS"
 *     $info->kernel();        // "6.6.87.2-microsoft-standard-WSL2"
 *     $info->architecture();  // "x86_64"
 *     $info->cpuCount();      // 8, or null
 *     $info->memory();        // Memory, or null
 *     $info->disk('/srv');    // Disk, or null
 *
 * **Nothing here spawns anything.** Every answer comes from PHP itself or from
 * the files the kernel publishes -- /proc/meminfo, /proc/uptime,
 * /sys/devices/system/cpu/online, /etc/os-release -- which is what `free`,
 * `uptime` and `nproc` read too. Running those programs would cost a process
 * each and parse their human-readable output, whose format is not a contract.
 *
 * **Null means "this machine does not say".** Those files are Linux's; on
 * Windows, or in a container that hides them, the answer is null rather than a
 * guess, and a zero that looked like a measurement.
 *
 * **The root is a parameter** so that the parsing is tested against fixture
 * files on any platform. In an application it is always "/".
 *
 * Only the distribution name is kept once read: it cannot change while the
 * process runs. Memory, load, uptime and even the online CPU count (hotplug)
 * can, so they are read each time; each is one small file. Nothing here is
 * secret, but all of it is
 * reconnaissance -- a kernel version is an attack's first question -- so who may
 * read it is for authorization to decide, not for this class.
 */
final class SystemInfo
{
    private readonly string $root;

    private ?string $os = null;

    public function __construct(string $root = '/')
    {
        $this->root = \rtrim(\str_replace('\\', '/', $root), '/');
    }

    /** The distribution's name where it says one, otherwise the kernel's name ("Linux", "Windows NT"). */
    public function os(): string
    {
        if ($this->os !== null) {
            return $this->os;
        }

        $release = $this->read('/etc/os-release');

        if ($release !== null && \preg_match('/^PRETTY_NAME=(["\']?)(.*)\1\s*$/m', $release, $match) === 1 && $match[2] !== '') {
            return $this->os = $match[2];
        }

        return $this->os = \php_uname('s');
    }

    /** "Linux", "Windows", "Darwin", "BSD": PHP_OS_FAMILY. */
    public function osFamily(): string
    {
        return \PHP_OS_FAMILY;
    }

    public function kernel(): string
    {
        return \php_uname('r');
    }

    public function architecture(): string
    {
        return \php_uname('m');
    }

    public function hostname(): string
    {
        $name = \gethostname();

        return $name === false ? \php_uname('n') : $name;
    }

    public function phpVersion(): string
    {
        return \PHP_VERSION;
    }

    /**
     * CPUs the kernel has online, from /sys/devices/system/cpu/online
     * ("0-3,6"). Not the CPUs this process may use: a cgroup or taskset can
     * allow fewer.
     */
    public function cpuCount(): ?int
    {
        $online = $this->read('/sys/devices/system/cpu/online');

        if ($online === null || \preg_match('/^\d+(-\d+)?(,\d+(-\d+)?)*$/D', \trim($online)) !== 1) {
            return null;
        }

        $count = 0;

        foreach (\explode(',', \trim($online)) as $range) {
            $bounds = \array_map('intval', \explode('-', $range));
            $count += \count($bounds) === 2 ? \max(0, $bounds[1] - $bounds[0] + 1) : 1;
        }

        return $count;
    }

    public function memory(): ?Memory
    {
        $meminfo = $this->read('/proc/meminfo');

        if ($meminfo === null
            || \preg_match('/^MemTotal:\s+(\d+) kB$/m', $meminfo, $total) !== 1
            || \preg_match('/^MemAvailable:\s+(\d+) kB$/m', $meminfo, $available) !== 1) {
            return null;
        }

        return new Memory((int) $total[1] * 1024, (int) $available[1] * 1024);
    }

    /** @return array{0: float, 1: float, 2: float}|null the 1, 5 and 15 minute averages */
    public function loadAverage(): ?array
    {
        $loadavg = $this->read('/proc/loadavg');

        if ($loadavg !== null && \preg_match('/^(\d+\.\d+) (\d+\.\d+) (\d+\.\d+) /', $loadavg, $match) === 1) {
            return [(float) $match[1], (float) $match[2], (float) $match[3]];
        }

        return null;
    }

    /** Seconds since boot. */
    public function uptime(): ?float
    {
        $uptime = $this->read('/proc/uptime');

        if ($uptime === null || \preg_match('/^(\d+(?:\.\d+)?) /', $uptime, $match) !== 1) {
            return null;
        }

        return (float) $match[1];
    }

    /** The filesystem holding $path, which must exist. */
    public function disk(string $path): ?Disk
    {
        $total = @\disk_total_space($path);
        $free = @\disk_free_space($path);

        if ($total === false || $free === false) {
            return null;
        }

        return new Disk($path, (int) $total, (int) $free);
    }

    /**
     * Where a program would be found by a Command with the default
     * environment, or null. "Is rsync installed?" asked the way running it
     * would answer, without running it.
     */
    public function binary(string $name): ?string
    {
        return Invocation::locate($name);
    }

    private function read(string $path): ?string
    {
        $file = $this->root . $path;

        if (!\is_file($file) || !\is_readable($file)) {
            return null;
        }

        $contents = @\file_get_contents($file);

        return $contents === false ? null : $contents;
    }
}
