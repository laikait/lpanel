<?php

declare(strict_types=1);

namespace App\Engine\Cli;

use App\Engine\Core\ExecutionContext;
use App\Engine\Error\ErrorHandler;
use App\Engine\Hook\HookEngine;

/**
 * The console, as the mirror image of HttpKernel.
 *
 * Read the two side by side and the symmetry is the point: a name is looked up
 * in a registry, input is parsed against what was declared, a handler is called
 * through the container, and a failure becomes a status the caller understands.
 * One returns a Response and the other an exit code, and that is the whole
 * difference.
 *
 * This kernel knows nothing about any particular command. Everything it can run
 * arrived through CommandRegistry -- the framework's own commands through
 * CoreCommands, a module's through module.php -- so there is no list of
 * built-ins here to keep in step, and no command that works only because the
 * kernel special-cases it.
 *
 * Exit codes mean what a shell expects them to mean:
 *
 *   0    the command succeeded
 *   1    it ran and failed, or something threw
 *   2    the command line was wrong: a missing argument, an unknown option
 *   127  no such command
 *
 * The 2/127 split is worth having. A deployment script that mistypes a command
 * name and one that forgets an argument are different bugs, and a wrapper that
 * retries on one should not retry on the other.
 */
final class ConsoleKernel
{
    public const SUCCESS = 0;

    public const FAILURE = 1;

    public const USAGE = 2;

    public const UNKNOWN = 127;

    public function __construct(
        private readonly CommandRegistry $commands,
        private readonly CommandDispatcher $dispatcher,
        private readonly HookEngine $hooks,
        private readonly ErrorHandler $errors,
        private readonly Output $output = new Output(),
    ) {}

    public function handle(ExecutionContext $context): int
    {
        $name = $context->command();
        $tokens = $context->arguments();

        // `laika`, `laika --help` and `laika help` are the same request.
        if ($name === null || $name === '--help' || $name === '-h') {
            $name = 'help';
            $tokens = [];
        }

        try {
            $command = $this->commands->get($name)
                ?? throw ConsoleException::unknownCommand($name, $this->commands->suggest($name));

            // `laika customer:sync --help` explains rather than runs. Only
            // the first token counts, so a command is still free to take
            // "--help" as a value after a -- separator.
            if (($tokens[0] ?? null) === '--help' || ($tokens[0] ?? null) === '-h') {
                return $this->explain($command);
            }

            return $this->dispatcher->dispatch($command, Input::parse($command, $tokens), $this->output);
        } catch (ConsoleException $e) {
            return $this->refuse($e);
        } catch (\Throwable $e) {
            $this->hooks->do('command.failed', $e, $name);
            $this->output->error($this->errors->renderCli($e));

            return self::FAILURE;
        }
    }

    /**
     * Hand a refusal back to whoever typed it.
     *
     * A usage error gets the command's synopsis, because the useful next thing
     * is the shape of the line they were trying to write. A declaration error
     * does not: printing a synopsis at somebody whose module registered two
     * commands with the same name would be answering a question they did not
     * ask.
     */
    private function refuse(ConsoleException $e): int
    {
        $this->output->error($e->getMessage());

        if ($e->unknown) {
            $this->output->errorLine('Run "php laika help" to see what is available.');

            return self::UNKNOWN;
        }

        if (!$e->usage) {
            return self::FAILURE;
        }

        $command = $e->command === null ? null : $this->commands->get($e->command);

        if ($command !== null) {
            $this->output->errorLine();
            $this->output->errorLine('Usage: php laika ' . $command->synopsis());
            $this->output->errorLine(\sprintf('       php laika help %s', $command->name));
        }

        return self::USAGE;
    }

    /** Run the help command against another command, so there is one renderer. */
    private function explain(Command $command): int
    {
        $help = $this->commands->get('help');

        if ($help === null) {
            $this->output->line('Usage: php laika ' . $command->synopsis());

            return self::SUCCESS;
        }

        return $this->dispatcher->dispatch($help, Input::parse($help, [$command->name]), $this->output);
    }
}
