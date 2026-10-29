<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\SystemInfo;

use App\Engine\System\SystemInfo\Disk;
use App\Engine\System\SystemInfo\Memory;
use App\Engine\System\SystemInfo\SystemInfo;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

final class SystemInfoTest extends TestCase
{
    private function fixture(): SystemInfo
    {
        return new SystemInfo(\dirname(__DIR__, 3) . '/Fixtures/System/machine');
    }

    // ---- parsing the kernel's files, on any platform ---------------------------------

    public function test_the_distribution_name_comes_from_os_release(): void
    {
        self::assertSame('Ubuntu 26.04 LTS', $this->fixture()->os());
    }

    public function test_online_cpus_are_counted_from_their_ranges(): void
    {
        // 0-5 is six, 8 is one, 10-11 is two.
        self::assertSame(9, $this->fixture()->cpuCount());
    }

    public function test_memory_is_total_and_available_in_bytes(): void
    {
        $memory = $this->fixture()->memory();

        self::assertInstanceOf(Memory::class, $memory);
        self::assertSame(16318876 * 1024, $memory->total);
        self::assertSame(9174220 * 1024, $memory->available);
        self::assertSame((16318876 - 9174220) * 1024, $memory->used());
        self::assertEqualsWithDelta(0.4378, $memory->usedRatio(), 0.0001);
    }

    public function test_load_average_is_the_three_figures(): void
    {
        self::assertSame([0.52, 0.61, 0.7], $this->fixture()->loadAverage());
    }

    public function test_uptime_is_seconds_since_boot(): void
    {
        self::assertSame(93784.52, $this->fixture()->uptime());
    }

    /** A machine that publishes none of these files says so with null, not zero. */
    public function test_a_machine_without_the_files_answers_null(): void
    {
        $info = new SystemInfo(\sys_get_temp_dir() . '/lphp-no-machine-' . \bin2hex(\random_bytes(4)));

        self::assertNull($info->cpuCount());
        self::assertNull($info->memory());
        self::assertNull($info->loadAverage());
        self::assertNull($info->uptime());
        self::assertSame(\php_uname('s'), $info->os());
    }

    // ---- PHP's own answers --------------------------------------------------------------

    public function test_php_answers_the_rest(): void
    {
        $info = new SystemInfo();

        self::assertSame(\PHP_OS_FAMILY, $info->osFamily());
        self::assertSame(\php_uname('r'), $info->kernel());
        self::assertSame(\php_uname('m'), $info->architecture());
        self::assertSame(\PHP_VERSION, $info->phpVersion());
        self::assertNotSame('', $info->hostname());
    }

    public function test_the_disk_a_path_is_on(): void
    {
        $disk = (new SystemInfo())->disk(\sys_get_temp_dir());

        self::assertInstanceOf(Disk::class, $disk);
        self::assertGreaterThan(0, $disk->total);
        self::assertLessThanOrEqual($disk->total, $disk->free);
        self::assertSame($disk->total - $disk->free, $disk->used());
    }

    public function test_a_path_that_does_not_exist_has_no_disk(): void
    {
        self::assertNull((new SystemInfo())->disk(\sys_get_temp_dir() . '/lphp-missing-' . \bin2hex(\random_bytes(4))));
    }

    public function test_a_binary_is_found_where_a_command_would_find_it(): void
    {
        $info = new SystemInfo();

        self::assertNull($info->binary('lphp-no-such-program'));
        self::assertNull($info->binary('rm -rf /'), 'a name no Command accepts is never looked up');
        self::assertNull($info->binary('../bin/php'));
    }

    // ---- this machine ------------------------------------------------------------------

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_real_linux_machine_answers_everything(): void
    {
        $info = new SystemInfo();

        self::assertGreaterThanOrEqual(1, $info->cpuCount());
        self::assertGreaterThan(0, $info->memory()?->total);
        self::assertGreaterThan(0.0, $info->uptime());
        self::assertCount(3, $info->loadAverage() ?? []);
        self::assertNotSame('Linux', $info->os(), 'os-release was not read');
        self::assertNotNull($info->binary('env'), 'env is on every Linux PATH');
    }
}
