<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Permission;

use App\Engine\System\Filesystem\FilesystemException;
use App\Engine\System\Filesystem\FilesystemPolicy;
use App\Engine\System\Permission\PermissionException;
use App\Engine\System\Permission\PermissionManager;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

final class PermissionManagerTest extends TestCase
{
    private string $base;

    private string $file;

    private PermissionManager $permissions;

    protected function setUp(): void
    {
        $this->base = \str_replace('\\', '/', (string) \realpath(\sys_get_temp_dir())) . '/lphp-perm-' . \bin2hex(\random_bytes(4));
        \mkdir($this->base . '/writable', 0o777, true);
        \mkdir($this->base . '/readable', 0o777, true);
        $this->file = $this->base . '/writable/data.txt';
        \file_put_contents($this->file, 'x');
        \file_put_contents($this->base . '/readable/data.txt', 'x');

        $this->permissions = new PermissionManager(
            FilesystemPolicy::none()->allowWrite($this->base . '/writable')->allowRead($this->base . '/readable'),
            owners: ['nobody'],
            groups: ['nogroup', 'lphp-no-such-group'],
        );
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        foreach (['/writable/data.txt', '/readable/data.txt'] as $file) {
            @\chmod($this->base . $file, 0o644);
            @\unlink($this->base . $file);
        }

        @\rmdir($this->base . '/writable');
        @\rmdir($this->base . '/readable');
        @\rmdir($this->base);
    }

    // ---- refused, on every platform, before anything changes ------------------------------

    /** @return iterable<string, array{int, string}> */
    public static function refusedModes(): iterable
    {
        yield 'setuid' => [0o4755, 'Mode 4755 is not usable'];
        yield 'setgid' => [0o2755, 'Mode 2755 is not usable'];
        yield 'sticky' => [0o1777, 'Mode 1777 is not usable'];
        yield 'negative' => [-1, 'is not usable'];
        yield 'world writable' => [0o666, 'lets every user on the machine write'];
        yield 'everything' => [0o777, 'lets every user on the machine write'];
    }

    #[DataProvider('refusedModes')]
    public function test_a_dangerous_mode_is_refused(int $mode, string $message): void
    {
        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage($message);

        $this->permissions->chmod($this->file, $mode);
    }

    public function test_ownership_goes_only_to_listed_users_and_groups(): void
    {
        foreach ([
            'user "root"' => fn() => $this->permissions->chown($this->file, 'root'),
            'user "www-data"' => fn() => $this->permissions->chown($this->file, 'www-data'),
            'group "root"' => fn() => $this->permissions->chgrp($this->file, 'root'),
            'group "sudo"' => fn() => $this->permissions->chgrp($this->file, 'sudo'),
        ] as $who => $change) {
            try {
                $change();
                self::fail($who . ' was allowed');
            } catch (PermissionException $e) {
                self::assertStringContainsString('Nothing allows giving files to ' . $who, $e->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'numeric id' => ['0'];
        yield 'option' => ['-R'];
        yield 'user and group' => ['nobody:nogroup'];
        yield 'capitals' => ['Nobody'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidNames')]
    public function test_a_name_that_is_not_a_plain_account_name_is_refused(string $name): void
    {
        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('The user name is invalid');

        $this->permissions->chown($this->file, $name);
    }

    public function test_an_invalid_name_in_the_allowed_list_is_refused_when_made(): void
    {
        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('The group name is invalid');

        new PermissionManager(FilesystemPolicy::none(), groups: ['wheel', '*']);
    }

    public function test_the_path_must_be_one_the_filesystem_policy_allows_writing(): void
    {
        foreach ([
            [$this->base . '/readable/data.txt', 'allows reading, not writing'],
            [$this->base . '/writable/../readable/data.txt', 'contains a ".." segment'],
            ['/etc/passwd', 'is not inside any root'],
        ] as [$path, $message]) {
            try {
                $this->permissions->chmod($path, 0o600);
                self::fail($path . ' was changed');
            } catch (FilesystemException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_windows_refuses_rather_than_half_applies(): void
    {
        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage('refused rather than half-applied');

        $this->permissions->chmod($this->file, 0o600);
    }

    // ---- applied, on Linux --------------------------------------------------------------------

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_chmod_applies_the_mode(): void
    {
        $this->permissions->chmod($this->file, 0o640);

        self::assertSame(0o640, \fileperms($this->file) & 0o777);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_ownership_changes_as_root_or_fails_safely_without_it(): void
    {
        if (!\function_exists('posix_geteuid') || \posix_getgrnam('nogroup') === false || \posix_getpwnam('nobody') === false) {
            self::markTestSkipped('needs posix and the nobody user and nogroup group');
        }

        $before = [\fileowner($this->file), \filegroup($this->file)];

        if (\posix_geteuid() !== 0) {
            try {
                $this->permissions->chown($this->file, 'nobody');
                self::fail('an unprivileged process changed an owner');
            } catch (PermissionException $e) {
                self::assertStringContainsString('Nothing was changed', $e->getMessage());
            }

            \clearstatcache();
            self::assertSame($before, [\fileowner($this->file), \filegroup($this->file)]);

            return;
        }

        $this->permissions->chown($this->file, 'nobody');
        $this->permissions->chgrp($this->file, 'nogroup');

        self::assertSame(\posix_getpwnam('nobody')['uid'], \fileowner($this->file));
        self::assertSame(\posix_getgrnam('nogroup')['gid'], \filegroup($this->file));
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_group_that_does_not_exist_fails_and_changes_nothing(): void
    {
        $group = \filegroup($this->file);

        try {
            $this->permissions->chgrp($this->file, 'lphp-no-such-group');
            self::fail('no exception');
        } catch (PermissionException $e) {
            self::assertStringContainsString('Could not change the group of', $e->getMessage());
        }

        \clearstatcache();
        self::assertSame($group, \filegroup($this->file));
    }
}
