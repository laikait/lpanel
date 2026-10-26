<?php

declare(strict_types=1);

namespace App\Engine\Logging;

/**
 * Where records go, and the guarantee that they never take the request with
 * them.
 *
 * The specification's hardest requirement in this area is one sentence:
 * *logging must not cause application failure when the primary operation
 * succeeds*. Everything unusual in this class follows from it.
 *
 * A writer that throws is caught, **retired** -- taken out of the rotation for
 * the rest of the process -- and the reason is kept. Retiring matters as much
 * as catching: a full disk does not un-fill itself, so a writer that failed
 * once will fail on every record, and a request that logs forty times would
 * otherwise spend forty exceptions finding that out. The reason is kept because
 * the failure mode of "catch everything" is an application that has silently
 * not been logging for three weeks, which is worse than the exception was.
 *
 * Reentrancy is refused for the same reason. A writer that logs -- or a
 * hook listener that logs while handling a log-driven error -- would recurse
 * until the stack ran out, and a stack overflow caused by the logger is exactly
 * the failure the rule forbids.
 *
 * Filtering happens here rather than in the writers, so a record below the
 * threshold costs one integer comparison and never has its context normalised.
 * For an application that logs at debug in development and info in production,
 * that is most of the cost of logging at all.
 */
final class LogManager
{
    public const DEFAULT_CHANNEL = 'app';

    public const CHANNEL_PATTERN = '/^[a-z][a-z0-9_-]*$/';

    /** @var list<LogWriter> */
    private array $writers = [];

    /** @var array<string, Logger> */
    private array $channels = [];

    /** @var list<string> */
    private array $failures = [];

    private bool $writing = false;

    private int $written = 0;

    private int $dropped = 0;

    /** @var (\Closure(): array<string, mixed>)|null */
    private ?\Closure $enricher = null;

    public function __construct(
        private readonly Level $minimum = Level::Debug,
        private readonly Context $context = new Context(),
    ) {}

    // ---- configuration ------------------------------------------------------

    public function add(LogWriter $writer): self
    {
        $this->writers[] = $writer;

        return $this;
    }

    /** @return list<LogWriter> */
    public function writers(): array
    {
        return $this->writers;
    }

    public function minimum(): Level
    {
        return $this->minimum;
    }

    // ---- channels -------------------------------------------------------------

    /**
     * A logger for a named channel.
     *
     * Channels separate what is being logged, not where it goes: "billing" and
     * "app" both reach every writer, and a writer or a search decides what to do
     * with the name. That keeps a module from having to know the deployment's
     * log layout in order to say which of its own concerns a line belongs to.
     */
    public function channel(string $name = self::DEFAULT_CHANNEL): Logger
    {
        if (\preg_match(self::CHANNEL_PATTERN, $name) !== 1) {
            throw LoggingException::unusableChannel($name);
        }

        return $this->channels[$name] ??= new Logger($this, $name);
    }

    /** @return list<string> the channels something has actually asked for */
    public function channels(): array
    {
        $names = \array_keys($this->channels);
        \sort($names);

        return $names;
    }

    // ---- writing ---------------------------------------------------------------

    /**
     * Put a record where it goes, whatever happens.
     *
     * This method does not throw. That is not a claim about the code being
     * careful; it is the point of the method.
     */
    public function record(LogRecord $record): void
    {
        if (!$record->level->isAtLeast($this->minimum)) {
            return;
        }

        if ($this->writing) {
            // A writer logged. Dropping the record is the only option that
            // terminates; counting it means log:status can say so.
            ++$this->dropped;

            return;
        }

        if ($this->writers === []) {
            ++$this->dropped;

            return;
        }

        $this->writing = true;

        try {
            $context = $record->context;

            if ($this->enricher !== null) {
                // Underneath, not on top: a caller that says which request a
                // record belongs to -- a worker logging about a job it is not
                // running -- is not overruled by whatever happens to be current.
                $context = [...($this->enricher)(), ...$context];
            }

            // A new record rather than with(), which merges over the old context
            // and would put the ids after the caller's fields. First is where
            // somebody scanning a log line looks for them.
            $this->deliver(new LogRecord(
                $record->level,
                $record->message,
                $this->context->normalise($context),
                $record->channel,
                $record->time,
            ));
        } catch (\Throwable $e) {
            // An enricher that throws is a failure of logging, and logging does
            // not fail the request. Recorded, like a writer that stopped.
            $this->failures[] = 'the log enricher failed: ' . $e->getMessage();
            $this->enricher = null;
            ++$this->dropped;
        } finally {
            $this->writing = false;
        }
    }

    /**
     * Add fields to every record: which request, which job, which correlation.
     *
     * Called only for records that pass the level check, so a debug line in
     * production costs nothing extra. An enricher that throws is detached and
     * the failure kept, the same bargain a writer gets.
     *
     * @param \Closure(): array<string, mixed> $enricher
     */
    public function enrich(\Closure $enricher): void
    {
        $this->enricher = $enricher;
    }

    private function deliver(LogRecord $record): void
    {
        $survivors = [];
        $delivered = false;

        foreach ($this->writers as $writer) {
            try {
                if ($writer->accepts($record)) {
                    $writer->write($record);
                    $delivered = true;
                }

                $survivors[] = $writer;
            } catch (\Throwable $e) {
                // Retired, not retried. See the class docblock.
                $this->failures[] = \sprintf('%s stopped: %s', $writer->describe(), $e->getMessage());
            }
        }

        $this->writers = $survivors;

        $delivered ? ++$this->written : ++$this->dropped;
    }

    // ---- diagnostics -------------------------------------------------------------

    /**
     * Why a writer is no longer being used.
     *
     * The answer to "logging stopped working and nothing said so", which is the
     * failure that swallowing exceptions buys and this pays back.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public function isHealthy(): bool
    {
        return $this->failures === [];
    }

    /** Records that reached at least one writer. */
    public function written(): int
    {
        return $this->written;
    }

    /** Records that passed the level check and still went nowhere. */
    public function dropped(): int
    {
        return $this->dropped;
    }
}
