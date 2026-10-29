<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Cron;

use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Cron\CronException;
use App\Engine\System\Cron\UserCrontab;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

/**
 * The real crontab program's protocol, against a stand-in for it.
 *
 * The stand-in is a PHP script that answers `-l` and `-` the way crontab does,
 * from a file of its own. Running the real one would edit the crontab of
 * whoever runs the suite.
 */
final class UserCrontabTest extends TestCase
{
    private string $directory = '';

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        if ($this->directory === '') {
            return;
        }

        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            \unlink($file);
        }

        \rmdir($this->directory);
    }

    private function fakeCrontab(int $failWith = 0): string
    {
        $this->directory = \sys_get_temp_dir() . '/lphp-crontab-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory);

        $store = \var_export($this->directory . '/spool', true);
        $program = $this->directory . '/crontab';

        \file_put_contents($program, '#!' . \PHP_BINARY . " -n\n<?php\n"
            . "if ({$failWith} !== 0) { fwrite(STDERR, \"crontab: permission denied for /var/spool/cron/crontabs/tester\\n\"); exit({$failWith}); }\n"
            . "if (\$argv[1] === '-l') {\n"
            . "    if (!is_file({$store})) { fwrite(STDERR, \"no crontab for tester\\n\"); exit(1); }\n"
            . "    echo file_get_contents({$store}); exit(0);\n"
            . "}\n"
            . "if (\$argv[1] === '-') { file_put_contents({$store}, stream_get_contents(STDIN)); exit(0); }\n"
            . "exit(2);\n");
        \chmod($program, 0o755);

        return $program;
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_no_crontab_reads_as_empty(): void
    {
        self::assertSame('', (new UserCrontab(new CommandExecutor(), $this->fakeCrontab()))->read());
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_what_is_written_is_what_is_read(): void
    {
        $crontab = new UserCrontab(new CommandExecutor(), $this->fakeCrontab());
        $contents = "MAILTO=\"\"\n* * * * * '/usr/bin/php' 'laika' 'schedule:run' > /dev/null 2>&1\n";

        $crontab->write($contents);

        self::assertSame($contents, $crontab->read());
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_failed_read_names_the_exit_code_and_not_the_output(): void
    {
        try {
            (new UserCrontab(new CommandExecutor(), $this->fakeCrontab(failWith: 3)))->read();
            self::fail('no exception');
        } catch (CronException $e) {
            self::assertStringContainsString('crontab -l exited with 3', $e->getMessage());
            self::assertStringNotContainsString('/var/spool', $e->getMessage());
        }
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_failed_write_is_an_exception(): void
    {
        $this->expectException(CronException::class);
        $this->expectExceptionMessage('could not be written (crontab exited with 4)');

        (new UserCrontab(new CommandExecutor(), $this->fakeCrontab(failWith: 4)))->write("* * * * * /bin/true\n");
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function test_windows_is_refused_before_anything_runs(): void
    {
        $this->expectException(CronException::class);
        $this->expectExceptionMessage('It is Linux-only');

        (new UserCrontab(new CommandExecutor()))->read();
    }
}
