<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Cron;

use App\Engine\System\Command\Command;
use App\Engine\System\Cron\CronChange;
use App\Engine\System\Cron\CronException;
use App\Engine\System\Cron\CronJob;
use App\Engine\System\Cron\CronManager;
use App\Tests\Fixtures\System\MemoryCronTable;
use App\Tests\Support\TestCase;

final class CronManagerTest extends TestCase
{
    /** A crontab somebody keeps by hand: every byte of it must survive. */
    private const PERSONAL = "MAILTO=ops@example.test\n"
        . "# nightly report, do not touch\n"
        . "30 2 * * * /usr/local/bin/report --all  >> /var/log/report.log 2>&1\n"
        . "\n"
        . "@reboot /home/me/start-tunnel.sh\n";

    private function job(string $id = 'app.schedule', string $schedule = '* * * * *', string $command = 'schedule:run'): CronJob
    {
        return new CronJob($id, $schedule, new Command('/usr/bin/php', ['console', $command], workingDirectory: '/srv/app'));
    }

    // ---- install -----------------------------------------------------------------------

    public function test_installing_into_an_empty_crontab_writes_one_marked_block(): void
    {
        $table = new MemoryCronTable();
        $cron = new CronManager($table, 'shop');

        self::assertSame(CronChange::Created, $cron->install($this->job()));

        $lines = \explode("\n", $table->contents);

        self::assertStringStartsWith('# BEGIN LPHP CRON shop ', $lines[0]);
        self::assertStringStartsWith('# lphp-job {"id":"app.schedule"', $lines[1]);
        self::assertSame($this->job()->line(), $lines[2]);
        self::assertSame('# END LPHP CRON shop', $lines[3]);
        self::assertSame('', $lines[4], 'a crontab must end with a newline');
        self::assertCount(5, $lines);
    }

    public function test_installing_the_same_job_again_changes_and_writes_nothing(): void
    {
        $table = new MemoryCronTable();
        $cron = new CronManager($table, 'shop');
        $cron->install($this->job());
        $written = $table->contents;

        self::assertSame(CronChange::Unchanged, $cron->install($this->job()));
        self::assertSame(1, $table->writes);
        self::assertSame($written, $table->contents);
    }

    public function test_installing_a_job_with_a_known_id_updates_it_in_place(): void
    {
        $table = new MemoryCronTable();
        $cron = new CronManager($table, 'shop');
        $cron->install($this->job('a.first'));
        $cron->install($this->job('b.second'));

        self::assertSame(CronChange::Updated, $cron->install($this->job('a.first', '*/10 * * * *')));

        self::assertSame(['a.first', 'b.second'], \array_keys($cron->jobs()));
        self::assertSame('*/10 * * * *', $cron->job('a.first')?->schedule());
        self::assertSame(1, \substr_count($table->contents, '"id":"a.first"'));
    }

    public function test_jobs_read_back_as_they_were_installed(): void
    {
        $cron = new CronManager(new MemoryCronTable(), 'shop');
        $job = new CronJob(
            'report.odd',
            '0 3 * * SUN',
            new Command('/opt/tools/report', ["it's 100%", '$(id)', ''], workingDirectory: '/srv/my app'),
            '/var/log/app/report.log',
        );

        $cron->install($job);
        $read = $cron->job('report.odd');

        self::assertNotNull($read);
        self::assertSame($job->line(), $read->line());
        self::assertSame(["it's 100%", '$(id)', ''], $read->command()->arguments());
        self::assertSame('/var/log/app/report.log', $read->log());
        self::assertNull($cron->job('not.there'));
    }

    // ---- unrelated entries ------------------------------------------------------------

    public function test_everything_outside_the_block_survives_byte_for_byte(): void
    {
        $table = new MemoryCronTable(self::PERSONAL);
        $cron = new CronManager($table, 'shop');

        $cron->install($this->job('a.first'));
        self::assertStringStartsWith(self::PERSONAL, $table->contents);

        $cron->install($this->job('b.second'));
        $cron->remove('a.first');
        $cron->uninstall();

        self::assertSame(self::PERSONAL, $table->contents);
    }

    public function test_lines_after_the_block_stay_after_it(): void
    {
        $table = new MemoryCronTable(self::PERSONAL);
        $cron = new CronManager($table, 'shop');
        $cron->install($this->job());
        $table->contents .= "15 4 * * * /usr/local/bin/added-later\n";

        $cron->install($this->job('app.schedule', '*/2 * * * *'));

        self::assertStringStartsWith(self::PERSONAL, $table->contents);
        self::assertStringEndsWith("# END LPHP CRON shop\n15 4 * * * /usr/local/bin/added-later\n", $table->contents);
    }

    public function test_a_crontab_without_a_final_newline_gets_one_before_the_block(): void
    {
        $table = new MemoryCronTable('0 1 * * * /bin/backup');
        (new CronManager($table, 'shop'))->install($this->job());

        self::assertStringStartsWith("0 1 * * * /bin/backup\n# BEGIN LPHP CRON shop ", $table->contents);
    }

    public function test_windows_line_endings_outside_the_block_are_kept(): void
    {
        $original = "0 1 * * * /bin/backup\r\n";
        $table = new MemoryCronTable($original);
        $cron = new CronManager($table, 'shop');

        $cron->install($this->job());
        $cron->uninstall();

        self::assertSame($original, $table->contents);
    }

    public function test_two_owners_share_a_crontab_without_touching_each_other(): void
    {
        $table = new MemoryCronTable(self::PERSONAL);
        $shop = new CronManager($table, 'shop');
        $blog = new CronManager($table, 'blog');

        $shop->install($this->job('app.schedule'));
        $blog->install($this->job('app.schedule', '*/15 * * * *'));
        $blogBlock = \substr($table->contents, (int) \strpos($table->contents, '# BEGIN LPHP CRON blog'));

        $shop->uninstall();

        self::assertSame([], $shop->jobs());
        self::assertSame(['app.schedule'], \array_keys($blog->jobs()));
        self::assertSame(self::PERSONAL . $blogBlock, $table->contents);
    }

    public function test_an_owner_is_not_mistaken_for_one_it_is_a_prefix_of(): void
    {
        $table = new MemoryCronTable();
        (new CronManager($table, 'shop-staging'))->install($this->job());

        self::assertSame([], (new CronManager($table, 'shop'))->jobs());
    }

    // ---- remove and uninstall --------------------------------------------------------------

    public function test_removing_the_last_job_removes_the_block(): void
    {
        $table = new MemoryCronTable();
        $cron = new CronManager($table, 'shop');
        $cron->install($this->job());

        self::assertTrue($cron->remove('app.schedule'));
        self::assertSame('', $table->contents);
    }

    public function test_removing_an_unknown_job_writes_nothing(): void
    {
        $table = new MemoryCronTable(self::PERSONAL);

        self::assertFalse((new CronManager($table, 'shop'))->remove('not.there'));
        self::assertSame(0, $table->writes);
    }

    public function test_uninstall_reports_how_many_jobs_it_removed(): void
    {
        $table = new MemoryCronTable();
        $cron = new CronManager($table, 'shop');
        $cron->install($this->job('a.first'));
        $cron->install($this->job('b.second'));

        self::assertSame(2, $cron->uninstall());
        self::assertSame(0, $cron->uninstall());
        self::assertSame(3, $table->writes);
    }

    // ---- refusing to guess --------------------------------------------------------------

    public function test_a_line_edited_by_hand_is_refused_and_nothing_is_written(): void
    {
        $table = new MemoryCronTable();
        $cron = new CronManager($table, 'shop');
        $cron->install($this->job());
        $table->contents = \str_replace("'schedule:run'", "'schedule:run' --verbose", $table->contents);
        $edited = $table->contents;

        foreach ([
            static fn() => $cron->jobs(),
            fn() => $cron->install($this->job('other.job')),
            static fn() => $cron->remove('app.schedule'),
            static fn() => $cron->uninstall(),
        ] as $operation) {
            try {
                $operation();
                self::fail('an edited block was acted on');
            } catch (CronException $e) {
                self::assertStringContainsString('has been edited by hand', $e->getMessage());
            }
        }

        self::assertSame($edited, $table->contents);
        self::assertSame(1, $table->writes);
    }

    /** @return iterable<string, array{string, string}> */
    public static function corruptBlocks(): iterable
    {
        $job = "# lphp-job {\"id\":\"a.b\",\"schedule\":\"* * * * *\",\"argv\":[\"/bin/true\"],\"cwd\":null,\"log\":null}\n"
            . "* * * * * '/bin/true' > /dev/null 2>&1\n";

        yield 'begins and never ends' => ["# BEGIN LPHP CRON shop\n" . $job, 'never ends'];
        yield 'ends before it begins' => ["# END LPHP CRON shop\n# BEGIN LPHP CRON shop\n", 'ends before it begins'];
        yield 'two blocks' => ["# BEGIN LPHP CRON shop\n# END LPHP CRON shop\n# BEGIN LPHP CRON shop\n# END LPHP CRON shop\n", 'more than once'];
        yield 'a person\'s line inside' => ["# BEGIN LPHP CRON shop\n0 1 * * * /bin/mine\n# END LPHP CRON shop\n", 'missing its line'];
        yield 'a stray pair inside' => ["# BEGIN LPHP CRON shop\n# my note\n0 1 * * * /bin/mine\n# END LPHP CRON shop\n", 'did not write'];
        yield 'unreadable description' => ["# BEGIN LPHP CRON shop\n# lphp-job {nope\n* * * * * x\n# END LPHP CRON shop\n", 'not readable'];
        yield 'a description of something that is not a job' => ["# BEGIN LPHP CRON shop\n# lphp-job {\"id\":\"a.b\",\"schedule\":\"* * * * *\",\"argv\":[\"sh\"],\"cwd\":null,\"log\":null}\nx\n# END LPHP CRON shop\n", 'could be installed'];
        yield 'the same job twice' => ["# BEGIN LPHP CRON shop\n" . $job . $job . "# END LPHP CRON shop\n", 'listed twice'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('corruptBlocks')]
    public function test_a_block_this_code_did_not_write_is_refused(string $contents, string $why): void
    {
        $table = new MemoryCronTable($contents);

        try {
            (new CronManager($table, 'shop'))->install($this->job());
            self::fail('a corrupt block was acted on');
        } catch (CronException $e) {
            self::assertStringContainsString($why, $e->getMessage());
        }

        self::assertSame($contents, $table->contents);
        self::assertSame(0, $table->writes);
    }

    public function test_an_invalid_owner_is_refused(): void
    {
        $this->expectException(CronException::class);
        $this->expectExceptionMessage('Cron owner "My Shop" is invalid');

        new CronManager(new MemoryCronTable(), 'My Shop');
    }
}
