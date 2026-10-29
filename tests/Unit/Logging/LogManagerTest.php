<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Engine\Logging\Context;
use App\Engine\Logging\Level;
use App\Engine\Logging\LoggingException;
use App\Engine\Logging\LogManager;
use App\Engine\Logging\LogRecord;
use App\Engine\Logging\LogWriter;
use App\Tests\Fixtures\Logging\BrokenWriter;
use App\Tests\Fixtures\Logging\CollectingWriter;
use App\Tests\Support\TestCase;

/**
 * Where records go, and the promise that they never take the request with them.
 *
 * "Logging must not cause application failure when the primary operation
 * succeeds" is the specification's hardest requirement here, and most of this
 * file is about the ways that promise could be broken.
 */
final class LogManagerTest extends TestCase
{
    // ---- enrichment ------------------------------------------------------------

    public function test_every_record_is_enriched_underneath_its_own_context(): void
    {
        $writer = new CollectingWriter();
        $logs = (new LogManager())->add($writer);
        $logs->enrich(static fn(): array => ['request_id' => 'current-request-01', 'trace' => 'http']);

        $logs->channel()->info('Invoice sent', ['invoice' => 7]);
        $logs->channel()->info('About another job', ['request_id' => 'the-job-it-is-about']);

        self::assertSame(
            ['request_id' => 'current-request-01', 'trace' => 'http', 'invoice' => 7],
            $writer->records[0]->context,
        );
        self::assertSame('the-job-it-is-about', $writer->records[1]->context['request_id'], 'a caller who names the request wins');
    }

    /** Below the threshold a record is one comparison; the enricher is not even asked. */
    public function test_a_record_below_the_threshold_is_not_enriched(): void
    {
        $asked = 0;
        $logs = (new LogManager(Level::Warning))->add(new CollectingWriter());
        $logs->enrich(static function () use (&$asked): array {
            ++$asked;

            return [];
        });

        $logs->channel()->debug('noise');

        self::assertSame(0, $asked);
    }

    /** Logging never fails the request, and an enricher is part of logging. */
    public function test_an_enricher_that_throws_is_detached_and_remembered(): void
    {
        $writer = new CollectingWriter();
        $logs = (new LogManager())->add($writer);
        $logs->enrich(static function (): never {
            throw new \RuntimeException('no trace available');
        });

        $logs->channel()->error('first');
        $logs->channel()->error('second');

        self::assertSame(['second'], $writer->messages());
        self::assertStringContainsString('no trace available', \implode(' ', $logs->failures()));
        self::assertFalse($logs->isHealthy());
    }

    // ---- routing ---------------------------------------------------------------

    public function test_a_record_reaches_every_writer_that_accepts_it(): void
    {
        $first = new CollectingWriter();
        $second = new CollectingWriter();

        $logs = (new LogManager())->add($first)->add($second);
        $logs->channel()->error('Payment declined');

        self::assertSame(['Payment declined'], $first->messages());
        self::assertSame(['Payment declined'], $second->messages());
    }

    public function test_a_writer_that_refuses_a_record_does_not_get_it(): void
    {
        $quiet = new CollectingWriter(Level::Error);
        $everything = new CollectingWriter();

        $logs = (new LogManager())->add($quiet)->add($everything);
        $logs->channel()->info('routine');

        self::assertSame([], $quiet->messages());
        self::assertSame(['routine'], $everything->messages());
    }

    /**
     * Filtering happens once, before the context is normalised, so a debug
     * record in production costs one integer comparison.
     */
    public function test_a_record_below_the_threshold_never_reaches_a_writer(): void
    {
        $writer = new CollectingWriter();
        $logs = (new LogManager(Level::Warning))->add($writer);

        $logs->channel()->info('quiet');
        $logs->channel()->warning('loud');

        self::assertSame(['loud'], $writer->messages());
    }

    public function test_the_channel_travels_with_the_record(): void
    {
        $writer = new CollectingWriter();
        $logs = (new LogManager())->add($writer);

        $logs->channel('billing')->error('Payment declined');

        self::assertSame('billing', $writer->last()?->channel);
    }

    public function test_context_is_normalised_before_a_writer_sees_it(): void
    {
        $writer = new CollectingWriter();
        $logs = (new LogManager())->add($writer);

        $logs->channel()->error('failed', ['exception' => new \RuntimeException('boom'), 'password' => 'hunter2']);

        self::assertCount(1, $writer->records);

        $context = $writer->records[0]->context;

        self::assertIsArray($context['exception'], 'a writer must never be handed something it cannot encode');
        self::assertSame(Context::REDACTED, $context['password']);
    }

    // ---- the promise ---------------------------------------------------------

    /** The requirement, stated as a test. */
    public function test_a_writer_that_throws_does_not_reach_the_caller(): void
    {
        $logs = (new LogManager())->add(new BrokenWriter());
        $outcome = 'the exception escaped';

        try {
            $logs->channel()->error('the request still worked');
            $outcome = 'the request carried on';
        } catch (\Throwable) {
            // Deliberately empty: the assertion below is what reports this.
        }

        self::assertSame('the request carried on', $outcome);
        self::assertCount(1, $logs->failures());
    }

    /**
     * Retired, not retried.
     *
     * A full disk does not un-fill itself, so a writer that failed once will
     * fail on every record. Retrying means a request that logs forty times
     * spends forty exceptions finding that out.
     */
    public function test_a_writer_that_throws_is_not_tried_again(): void
    {
        $broken = new BrokenWriter();
        $logs = (new LogManager())->add($broken);

        $logs->channel()->error('one');
        $logs->channel()->error('two');
        $logs->channel()->error('three');

        self::assertSame(1, $broken->attempts);
        self::assertSame([], $logs->writers(), 'the writer should have been taken out of the rotation');
    }

    public function test_one_broken_writer_does_not_stop_the_others(): void
    {
        $working = new CollectingWriter();
        $logs = (new LogManager())->add(new BrokenWriter())->add($working);

        $logs->channel()->error('still written');

        self::assertSame(['still written'], $working->messages());
        self::assertCount(1, $logs->writers());
    }

    /**
     * The failure mode that swallowing exceptions buys: an application that has
     * silently not been logging for three weeks. The reason is kept so that
     * log:status can say what happened.
     */
    public function test_the_reason_a_writer_stopped_is_kept(): void
    {
        $logs = (new LogManager())->add(new BrokenWriter('the disk'));

        self::assertTrue($logs->isHealthy());

        $logs->channel()->error('boom');

        self::assertFalse($logs->isHealthy());
        self::assertStringContainsString('the disk', $logs->failures()[0]);
        self::assertStringContainsString('disk may be full', $logs->failures()[0]);
    }

    /**
     * A writer that logs would otherwise recurse until the stack ran out, and a
     * stack overflow caused by the logger is exactly the failure the rule
     * forbids.
     */
    public function test_a_writer_that_logs_does_not_recurse(): void
    {
        $writer = new class implements LogWriter {
            public ?LogManager $logs = null;

            public int $writes = 0;

            public function describe(): string
            {
                return 'reentrant';
            }

            public function accepts(LogRecord $record): bool
            {
                return true;
            }

            public function write(LogRecord $record): void
            {
                ++$this->writes;
                $this->logs?->channel()->error('written while writing');
            }
        };

        $logs = (new LogManager())->add($writer);
        $writer->logs = $logs;

        $logs->channel()->error('the first one');

        self::assertSame(1, $writer->writes);
        self::assertSame(1, $logs->dropped());
    }

    public function test_a_manager_with_no_writers_drops_rather_than_fails(): void
    {
        $logs = new LogManager();

        $logs->channel()->emergency('nobody is listening');

        self::assertSame(0, $logs->written());
        self::assertSame(1, $logs->dropped());
    }

    public function test_counts_distinguish_written_from_dropped(): void
    {
        $logs = (new LogManager(Level::Warning))->add(new CollectingWriter(Level::Error));

        $logs->channel()->error('written');
        $logs->channel()->warning('accepted by the manager, refused by the writer');
        $logs->channel()->debug('below the threshold entirely');

        self::assertSame(1, $logs->written());
        self::assertSame(1, $logs->dropped(), 'the debug record never counted at all');
    }

    // ---- channels ---------------------------------------------------------------

    public function test_the_same_channel_is_the_same_logger(): void
    {
        $logs = new LogManager();

        self::assertSame($logs->channel('billing'), $logs->channel('billing'));
        self::assertNotSame($logs->channel('billing'), $logs->channel('app'));
    }

    public function test_channels_report_what_has_actually_been_asked_for(): void
    {
        $logs = new LogManager();

        self::assertSame([], $logs->channels());

        $logs->channel('billing');
        $logs->channel();

        self::assertSame(['app', 'billing'], $logs->channels());
    }

    public function test_a_channel_name_that_could_become_a_filename_is_refused(): void
    {
        $this->expectException(LoggingException::class);

        (new LogManager())->channel('../../etc');
    }

    public function test_a_bound_logger_adds_its_context_to_every_record(): void
    {
        $writer = new CollectingWriter();
        $logs = (new LogManager())->add($writer);

        $logs->channel('billing')->with(['tenant' => 7])->error('Payment declined', ['order' => 41]);

        self::assertSame(['tenant' => 7, 'order' => 41], $writer->last()?->context);
    }

    public function test_binding_context_does_not_change_the_original_logger(): void
    {
        $writer = new CollectingWriter();
        $logs = (new LogManager())->add($writer);

        $logger = $logs->channel();
        $logger->with(['tenant' => 7]);
        $logger->error('plain');

        self::assertSame([], $writer->last()?->context);
    }

    public function test_every_severity_has_a_method(): void
    {
        $writer = new CollectingWriter();
        $logger = (new LogManager())->add($writer)->channel();

        $logger->emergency('a');
        $logger->alert('b');
        $logger->critical('c');
        $logger->error('d');
        $logger->warning('e');
        $logger->notice('f');
        $logger->info('g');
        $logger->debug('h');

        self::assertSame(
            Level::names(),
            \array_map(static fn(LogRecord $record): string => $record->level->label(), $writer->records),
        );
    }
}
