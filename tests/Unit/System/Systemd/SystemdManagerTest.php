<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Systemd;

use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Service\ServiceManager;
use App\Engine\System\Service\ServicePolicy;
use App\Engine\System\Systemd\SystemdException;
use App\Engine\System\Systemd\SystemdManager;
use App\Engine\System\Systemd\SystemdUnits;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

/**
 * Against a temporary unit directory and a stand-in systemctl that records
 * what it was asked: a test run must never touch the real systemd.
 */
#[RequiresOperatingSystemFamily('Linux')]
final class SystemdManagerTest extends TestCase
{
    private string $root = '';

    private string $units = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/lphp-systemd-' . \bin2hex(\random_bytes(4));
        $this->units = $this->root . '/units';
        \mkdir($this->units, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::delete($this->root);

        parent::tearDown();
    }

    private static function delete(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            \unlink($path);

            return;
        }

        foreach (\glob($path . '/*') ?: [] as $child) {
            self::delete($child);
        }

        if (\is_dir($path)) {
            \rmdir($path);
        }
    }

    /**
     * A systemctl that logs its arguments and, for enable/disable, makes and
     * removes the *.wants symlinks the way the real one does.
     */
    private function manager(int $exitCode = 0, bool $privileged = true): SystemdManager
    {
        $program = $this->root . '/systemctl';
        \file_put_contents($program, '#!' . \PHP_BINARY . " -n\n<?php\n"
            . '$dir = ' . \var_export($this->units, true) . ";\n"
            . '$args = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, "--no-")));' . "\n"
            . 'file_put_contents(' . \var_export($this->root . '/calls', true) . ', implode(" ", $args) . "\n", FILE_APPEND);' . "\n"
            . "if ({$exitCode} !== 0) { exit({$exitCode}); }\n"
            . '$units = in_array("--", $args, true) ? array_slice($args, array_search("--", $args, true) + 1) : [];' . "\n"
            . 'foreach ($units as $unit) {' . "\n"
            . '  $wants = $dir . "/" . (str_ends_with($unit, ".timer") ? "timers.target.wants" : "multi-user.target.wants");' . "\n"
            . '  if ($args[0] === "enable") { @mkdir($wants); @symlink("/dev/null", $wants . "/" . $unit); }' . "\n"
            . '  if ($args[0] === "disable") { @unlink($wants . "/" . $unit); }' . "\n"
            . "}\n");
        \chmod($program, 0o755);

        return new SystemdManager(new ServiceManager(new CommandExecutor(), ServicePolicy::none(), $program), $this->units, runtimeDirectory: null, privileged: $privileged);
    }

    /** @return list<string> */
    private function calls(): array
    {
        return \is_file($this->root . '/calls') ? (\file($this->root . '/calls', \FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    private function units(): SystemdUnits
    {
        return SystemdUnits::forApplication('/srv/shop', 'shop', 'www-data', null, '/usr/bin/php');
    }

    public function test_install_writes_the_units_reloads_and_starts_them(): void
    {
        $manager = $this->manager();
        $result = $manager->install($this->units(), ['default', 'billing']);

        self::assertSame(['shop-worker@.service', 'shop-scheduler.service', 'shop-scheduler.timer'], $result['written']);
        self::assertSame(['shop-scheduler.timer', 'shop-worker@default.service', 'shop-worker@billing.service'], $result['enabled']);
        self::assertFileExists($this->units . '/shop-worker@.service');
        self::assertSame([
            'daemon-reload',
            'enable --now -- shop-scheduler.timer shop-worker@default.service shop-worker@billing.service',
        ], $this->calls());
        self::assertTrue($manager->hasScheduler($this->units()));
    }

    public function test_install_again_rewrites_nothing(): void
    {
        $manager = $this->manager();
        $manager->install($this->units(), ['default']);
        $result = $manager->install($this->units(), ['default']);

        self::assertSame([], $result['written']);
        self::assertCount(3, $result['unchanged']);
    }

    public function test_install_can_leave_the_scheduler_to_cron(): void
    {
        $result = $this->manager()->install($this->units(), ['default'], scheduler: false);

        self::assertSame(['shop-worker@default.service'], $result['enabled']);
    }

    public function test_a_bad_queue_changes_nothing(): void
    {
        try {
            $this->manager()->install($this->units(), ['Not A Queue']);
            self::fail('a bad queue name was accepted');
        } catch (SystemdException) {
        }

        self::assertSame([], \glob($this->units . '/*') ?: []);
        self::assertSame([], $this->calls());
    }

    public function test_remove_disables_what_is_enabled_and_deletes_the_files(): void
    {
        $manager = $this->manager();
        $manager->install($this->units(), ['default', 'billing']);

        self::assertSame(
            ['shop-scheduler.timer', 'shop-worker@billing.service', 'shop-worker@default.service'],
            $manager->enabled($this->units()),
        );

        $result = $manager->remove($this->units());

        self::assertSame(['shop-scheduler.timer', 'shop-worker@billing.service', 'shop-worker@default.service'], $result['disabled']);
        self::assertCount(3, $result['deleted']);
        self::assertFileDoesNotExist($this->units . '/shop-scheduler.timer');
        self::assertSame('daemon-reload', $this->calls()[3]);
        self::assertFalse($manager->hasScheduler($this->units()));
    }

    public function test_another_applications_units_are_left_alone(): void
    {
        \mkdir($this->units . '/multi-user.target.wants');
        \symlink('/dev/null', $this->units . '/multi-user.target.wants/shopfront-worker@default.service');

        self::assertSame([], $this->manager()->enabled($this->units()));
    }

    public function test_remove_with_nothing_installed_does_nothing(): void
    {
        $result = $this->manager()->remove($this->units());

        self::assertSame(['disabled' => [], 'deleted' => []], $result);
        self::assertSame([], $this->calls());
    }

    public function test_without_privilege_nothing_is_written(): void
    {
        try {
            $this->manager(privileged: false)->install($this->units(), ['default']);
            self::fail('an unprivileged install was allowed');
        } catch (SystemdException $e) {
            self::assertStringContainsString('needs root', $e->getMessage());
        }

        self::assertSame([], \glob($this->units . '/*') ?: []);
    }

    public function test_a_machine_without_systemd_is_refused(): void
    {
        $manager = new SystemdManager(new ServiceManager(new CommandExecutor(), ServicePolicy::none()), $this->units, runtimeDirectory: $this->root . '/not-systemd', privileged: true);

        $this->expectException(SystemdException::class);
        $this->expectExceptionMessage('system:cron:install');
        $manager->install($this->units(), ['default']);
    }

    /** enable/disable go through ServiceManager, which takes only this application's units. */
    public function test_another_service_cannot_be_enabled_through_the_unit_methods(): void
    {
        $services = new ServiceManager(new CommandExecutor(), ServicePolicy::none(), $this->root . '/systemctl');
        $this->manager();

        foreach (['ssh.service', 'nginx.service', 'shopfront-worker@default.service', 'shop-worker@x;y.service'] as $unit) {
            try {
                $services->enableUnits($this->units(), [$unit]);
                self::fail($unit . ' was enabled');
            } catch (SystemdException $e) {
                self::assertStringContainsString('not one of this application', $e->getMessage());
            }
        }

        self::assertSame([], $this->calls());
    }

    public function test_a_failing_systemctl_is_reported(): void
    {
        $this->expectException(SystemdException::class);
        $this->expectExceptionMessage('systemctl daemon-reload failed (exit code 1)');
        $this->manager(exitCode: 1)->install($this->units(), ['default']);
    }
}
