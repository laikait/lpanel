<?php

declare(strict_types=1);

namespace App\Engine\System\Command;

/**
 * A command ran and did not succeed, turned into an exception on request.
 *
 * Only CommandResult::orFail() throws this. The result is kept on the
 * exception, so stdout and stderr are there for whoever catches it -- and are
 * not in the message, which reaches logs that the output was never meant for.
 */
class CommandFailedException extends CommandException
{
    public function __construct(
        public readonly CommandResult $result,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function from(CommandResult $result): self
    {
        if ($result->timedOut()) {
            return new CommandTimeoutException($result, \sprintf(
                'The command was stopped after %.1f seconds, its timeout. What it wrote before then is on the result.',
                $result->duration(),
            ));
        }

        return new self($result, $result->exitCode() === null
            ? 'The command was ended by a signal. What it wrote is on the result.'
            : \sprintf('The command exited with %d. What it wrote is on the result.', $result->exitCode()));
    }
}
