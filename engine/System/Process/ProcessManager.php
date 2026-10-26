<?php

declare(strict_types=1);

namespace App\Engine\System\Process;

use App\Engine\Filter\FilterEngine;
use App\Engine\System\Audit\AuditOutcome;
use App\Engine\System\Audit\SystemAudit;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandException;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\CommandPolicy;
use App\Engine\System\Command\CommandPolicyException;
use App\Engine\System\Command\ConcurrencyLimit;
use App\Engine\System\Command\Invocation;
use App\Engine\System\Command\ShellCommand;

/**
 * Starts a command without waiting for it.
 *
 *     $conversion = $processes->start(new Command('ffmpeg', [...], timeout: 300.0));
 *
 *     // ... other work ...
 *
 *     $result = $conversion->wait();
 *
 * The same Command, the same preparation and the same rules as
 * CommandExecutor::run() -- found before it starts, no shell, a small default
 * environment, a timeout that always exists -- with the waiting moved to when
 * the caller asks. See Process for what a started process can do.
 *
 * **It is not a supervisor.** Nothing here restarts a process, keeps one alive
 * after the PHP process that started it, or tracks processes it did not start.
 * Work that must outlive a request belongs on the queue, where a worker owns it.
 */
final class ProcessManager
{
    /**
     * @param float          $defaultTimeout   seconds, for a Command that names none
     * @param int            $defaultMaxOutput bytes per stream, for a Command that names none
     * @param ?CommandPolicy $policy           when given, only what it allows starts
     * @param ?SystemAudit   $audit            when given, starts, ends and refusals are recorded
     * @param bool           $shell            whether a ShellCommand may start; off unless asked for
     * @param string         $shellBinary      the bash that runs one: "bash" on PATH, or an absolute path
     * @param ?ConcurrencyLimit $concurrency   when given, a process starts only with a slot, and holds it until it ends
     * @param ?FilterEngine  $filters          when given, system.command.timeout and .max_output may narrow the limits
     */
    public function __construct(
        private readonly float $defaultTimeout = CommandExecutor::DEFAULT_TIMEOUT,
        private readonly int $defaultMaxOutput = CommandExecutor::DEFAULT_MAX_OUTPUT,
        private readonly ?CommandPolicy $policy = null,
        private readonly ?SystemAudit $audit = null,
        private readonly bool $shell = false,
        private readonly string $shellBinary = ShellCommand::BASH,
        private readonly ?ConcurrencyLimit $concurrency = null,
        private readonly ?FilterEngine $filters = null,
    ) {
        Invocation::checkDefaults($defaultTimeout, $defaultMaxOutput);
        Invocation::checkShellBinary($shellBinary);
    }

    /**
     * Audited as system.process.started, or .refused; how it ended is recorded
     * by the Process.
     *
     * @throws CommandException|CommandPolicyException when nothing could be started
     */
    public function start(Command|ShellCommand $command): Process
    {
        $described = $this->audit === null ? [] : Invocation::describe($command);

        try {
            $invocation = Invocation::prepare($command, $this->defaultTimeout, $this->defaultMaxOutput, $this->policy, $this->shell, $this->shellBinary, $this->filters);
            $slot = $this->concurrency?->acquire();

            if ($this->concurrency !== null && $slot === null) {
                throw CommandPolicyException::tooManyRunning($this->concurrency->slots());
            }
        } catch (CommandPolicyException $e) {
            $this->audit?->record('system.process.refused', AuditOutcome::Refused, $described['program'] ?? null, $described);

            throw $e;
        }

        $argv = $invocation->argv;
        $files = [];

        try {
            // Files, not pipes, on every platform: nobody reads a pipe while the
            // caller is busy, and a child that fills one stops until somebody does.
            $files = [0 => Invocation::temporaryFile(), 1 => Invocation::temporaryFile(), 2 => Invocation::temporaryFile()];

            if (\file_put_contents($files[0], $invocation->stdin) === false) {
                throw CommandException::couldNotStart('its input could not be written to a temporary file');
            }

            $started = \hrtime(true);
            $process = @\proc_open(
                $argv,
                [0 => ['file', $files[0], 'r'], 1 => ['file', $files[1], 'w'], 2 => ['file', $files[2], 'w']],
                $pipes,
                $invocation->directory,
                $invocation->environment,
            );

            if (!\is_resource($process)) {
                throw CommandException::couldNotStart('the operating system refused to create the process');
            }
        } catch (\Throwable $e) {
            $slot?->release();

            foreach ($files as $file) {
                @\unlink($file);
            }

            throw $e;
        }

        $process = new Process($process, $started, $invocation->timeout, $invocation->limit, $files, $this->audit, $described, $slot);

        $this->audit?->record('system.process.started', AuditOutcome::Started, $described['program'] ?? null, [...$described, 'pid' => $process->pid()]);

        return $process;
    }
}
