<?php

declare(strict_types=1);

namespace App\Engine\System\Cron;

use App\Engine\System\Audit\AuditOutcome;
use App\Engine\System\Audit\SystemAudit;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\Invocation;
use App\Engine\System\SystemException;

/**
 * The jobs this application owns in a crontab, and nothing else in it.
 *
 *     $cron = new CronManager(new UserCrontab($executor), 'shop');
 *
 *     $cron->install(new CronJob('app.schedule', '* * * * *', $command));   // CronChange
 *     $cron->jobs();                                                       // id => CronJob
 *     $cron->remove('app.schedule');
 *     $cron->uninstall();
 *
 * **Ownership is a marked block.** Everything this manager writes sits between
 * two comment lines naming the owner:
 *
 *     # BEGIN LPHP CRON shop -- managed by the application; edit it and it will refuse to change it
 *     # lphp-job {"id":"app.schedule","schedule":"* * * * *","argv":["/usr/bin/php","laika","schedule:run"],...}
 *     * * * * * cd '/srv/app' && '/usr/bin/php' 'laika' 'schedule:run' > /dev/null 2>&1
 *     # END LPHP CRON shop
 *
 * Every line outside that block -- a person's own jobs, their MAILTO, another
 * application's block with a different owner -- is written back byte for byte.
 * The owner is what lets two applications share one user's crontab.
 *
 * **Each job is described twice, on purpose.** The comment holds the job as
 * data, and the line is regenerated from it and compared. Reading jobs back
 * never parses shell quoting, and a line somebody edited by hand is noticed
 * rather than silently replaced: every operation refuses until it is resolved.
 * So does a block that is not exactly what this code writes -- a BEGIN with no
 * END, two blocks, a stray line. A crontab is a person's file first.
 *
 * **Nothing is written that did not change.** Installing an identical job is
 * CronChange::Unchanged and does not touch the crontab, so doing it on every
 * deployment is free.
 *
 * Two processes changing the same crontab at the same moment can lose one
 * change: crontab has no lock to take. Changes are deployment-time operations,
 * made one at a time.
 */
final class CronManager
{
    private const OWNER = '/^[a-z0-9][a-z0-9._-]{0,63}$/D';

    private const BEGIN = '# BEGIN LPHP CRON ';

    private const END = '# END LPHP CRON ';

    private const JOB = '# lphp-job ';

    /** @param ?SystemAudit $audit when given, system.cron.created, .updated and .deleted are recorded */
    public function __construct(
        private readonly CronTable $table,
        private readonly string $owner,
        private readonly ?SystemAudit $audit = null,
    ) {
        if (\preg_match(self::OWNER, $owner) !== 1) {
            throw CronException::invalidOwner($owner);
        }
    }

    /** @return array<string, CronJob> in the order they were installed */
    public function jobs(): array
    {
        return $this->parse($this->table->read())['jobs'];
    }

    public function job(string $id): ?CronJob
    {
        return $this->jobs()[$id] ?? null;
    }

    public function install(CronJob $job): CronChange
    {
        $text = $this->table->read();
        $parsed = $this->parse($text);
        $existing = $parsed['jobs'][$job->id()] ?? null;

        if ($existing !== null && $this->describe($existing) === $this->describe($job)) {
            return CronChange::Unchanged;
        }

        $jobs = $parsed['jobs'];
        $jobs[$job->id()] = $job;
        $this->table->write($this->render($text, $parsed, $jobs));

        $change = $existing === null ? CronChange::Created : CronChange::Updated;
        $this->audit?->record('system.cron.' . $change->value, AuditOutcome::Succeeded, $job->id(), [
            'owner' => $this->owner,
            'schedule' => $job->schedule(),
            ...Invocation::describe($job->command()),
        ]);

        return $change;
    }

    /** False when there was no such job. */
    public function remove(string $id): bool
    {
        $text = $this->table->read();
        $parsed = $this->parse($text);

        if (!isset($parsed['jobs'][$id])) {
            return false;
        }

        $jobs = $parsed['jobs'];
        unset($jobs[$id]);
        $this->table->write($this->render($text, $parsed, $jobs));
        $this->audit?->record('system.cron.deleted', AuditOutcome::Succeeded, $id, ['owner' => $this->owner]);

        return true;
    }

    /** Remove every job this owner has, and the block with them. Returns how many there were. */
    public function uninstall(): int
    {
        $text = $this->table->read();
        $parsed = $this->parse($text);

        if ($parsed['begin'] === null) {
            return 0;
        }

        $this->table->write($this->render($text, $parsed, []));

        foreach (\array_keys($parsed['jobs']) as $id) {
            $this->audit?->record('system.cron.deleted', AuditOutcome::Succeeded, $id, ['owner' => $this->owner]);
        }

        return \count($parsed['jobs']);
    }

    /**
     * @return array{jobs: array<string, CronJob>, begin: ?int, end: ?int}
     */
    private function parse(string $text): array
    {
        $lines = \explode("\n", $text);
        $begin = null;
        $end = null;

        foreach ($lines as $number => $line) {
            $line = \rtrim($line, "\r");

            if ($line === \rtrim(self::BEGIN . $this->owner) || \str_starts_with($line, self::BEGIN . $this->owner . ' ')) {
                if ($begin !== null) {
                    throw CronException::corruptBlock($this->owner, 'it appears more than once');
                }

                $begin = $number;
            } elseif ($line === self::END . $this->owner) {
                if ($end !== null || $begin === null) {
                    throw CronException::corruptBlock($this->owner, $end !== null ? 'it ends more than once' : 'it ends before it begins');
                }

                $end = $number;
            }
        }

        if ($begin === null) {
            return ['jobs' => [], 'begin' => null, 'end' => null];
        }

        if ($end === null) {
            throw CronException::corruptBlock($this->owner, 'it begins and never ends');
        }

        $body = \array_slice($lines, $begin + 1, $end - $begin - 1);

        if (\count($body) % 2 !== 0) {
            throw CronException::corruptBlock($this->owner, 'a job is missing its line or its description');
        }

        $jobs = [];

        foreach (\array_chunk($body, 2) as [$description, $line]) {
            $job = $this->read(\rtrim($description, "\r"));

            if (isset($jobs[$job->id()])) {
                throw CronException::corruptBlock($this->owner, \sprintf('job "%s" is listed twice', $job->id()));
            }

            if (\rtrim($line, "\r") !== $job->line()) {
                throw CronException::editedByHand($this->owner, $job->id());
            }

            $jobs[$job->id()] = $job;
        }

        return ['jobs' => $jobs, 'begin' => $begin, 'end' => $end];
    }

    private function read(string $description): CronJob
    {
        if (!\str_starts_with($description, self::JOB)) {
            throw CronException::corruptBlock($this->owner, 'it holds a line this framework did not write');
        }

        $data = \json_decode(\substr($description, \strlen(self::JOB)), true);

        if (!\is_array($data)
            || !\is_string($data['id'] ?? null)
            || !\is_string($data['schedule'] ?? null)
            || !\is_array($data['argv'] ?? null) || $data['argv'] === [] || !\array_is_list($data['argv'])
            || !(\is_string($data['cwd'] ?? null) || ($data['cwd'] ?? null) === null)
            || !(\is_string($data['log'] ?? null) || ($data['log'] ?? null) === null)) {
            throw CronException::corruptBlock($this->owner, 'a job description is not readable');
        }

        $argv = [];

        foreach ($data['argv'] as $part) {
            if (!\is_string($part)) {
                throw CronException::corruptBlock($this->owner, 'a job description is not readable');
            }

            $argv[] = $part;
        }

        try {
            return new CronJob(
                $data['id'],
                $data['schedule'],
                new Command($argv[0], \array_slice($argv, 1), $data['cwd']),
                $data['log'],
            );
        } catch (SystemException $e) {
            throw CronException::corruptBlock($this->owner, 'a job description does not describe a job that could be installed');
        }
    }

    private function describe(CronJob $job): string
    {
        $command = $job->command();
        $argv = [$command->executable()];

        foreach ($command->arguments() as $argument) {
            // CronJob refuses a Secret, so every argument is a string.
            $argv[] = \is_string($argument) ? $argument : '';
        }

        return self::JOB . \json_encode(
            [
                'id' => $job->id(),
                'schedule' => $job->schedule(),
                'argv' => $argv,
                'cwd' => $command->workingDirectory(),
                'log' => $job->log(),
            ],
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param array{jobs: array<string, CronJob>, begin: ?int, end: ?int} $parsed
     * @param array<string, CronJob> $jobs
     */
    private function render(string $text, array $parsed, array $jobs): string
    {
        $block = [];

        if ($jobs !== []) {
            $block[] = self::BEGIN . $this->owner . ' -- managed by the application; edit it and it will refuse to change it';

            foreach ($jobs as $job) {
                $block[] = $this->describe($job);
                $block[] = $job->line();
            }

            $block[] = self::END . $this->owner;
        }

        if ($parsed['begin'] !== null && $parsed['end'] !== null) {
            $lines = \explode("\n", $text);
            \array_splice($lines, $parsed['begin'], $parsed['end'] - $parsed['begin'] + 1, $block);

            return \implode("\n", $lines);
        }

        if ($text !== '' && !\str_ends_with($text, "\n")) {
            $text .= "\n";
        }

        return $text . \implode("\n", $block) . "\n";
    }
}
