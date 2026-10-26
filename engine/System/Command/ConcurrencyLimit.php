<?php

declare(strict_types=1);

namespace App\Engine\System\Command;

/**
 * At most N commands running at once, across every PHP process of the application.
 *
 *     $limit = new ConcurrencyLimit('/srv/app/system/System/slots', 16);
 *     $executor = new CommandExecutor(concurrency: $limit);
 *
 * **A slot is a locked file.** N files, and a command runs while it holds an
 * exclusive lock on one of them. Asking for a slot tries each without waiting;
 * when every one is held, the command is refused, not queued: work that has to
 * wait its turn belongs on the queue, and a web request that waited for a slot
 * would be a request that times out instead.
 *
 * Locks rather than a counter for one reason: **a process that dies releases its
 * locks**, because the operating system drops them with the file handle. A
 * counter decremented in a finally block would leak a slot every time a worker
 * was killed mid-command, and a machine that ran long enough would end up with
 * none. flock() is per open file on Linux and per handle on Windows, so two
 * attempts in the same process exclude each other as well.
 *
 * The limit is per directory, so two applications on one machine do not share
 * it unless they are pointed at the same one.
 */
final class ConcurrencyLimit
{
    public function __construct(
        private readonly string $directory,
        private readonly int $slots,
    ) {
        if ($slots < 1) {
            throw CommandException::invalidConcurrency($slots);
        }
    }

    public function slots(): int
    {
        return $this->slots;
    }

    /**
     * A held slot, or null when all of them are in use.
     *
     * @phpstan-impure the answer depends on what every other process holds
     *
     * @throws CommandException when the slot directory cannot be created or used
     */
    public function acquire(): ?CommandSlot
    {
        if (!\is_dir($this->directory) && !@\mkdir($this->directory, 0o775, true) && !\is_dir($this->directory)) {
            throw CommandException::couldNotStart('the directory holding concurrency slots could not be created');
        }

        for ($slot = 0; $slot < $this->slots; ++$slot) {
            $handle = @\fopen($this->directory . '/slot-' . $slot . '.lock', 'c');

            if ($handle === false) {
                throw CommandException::couldNotStart('a concurrency slot could not be opened');
            }

            if (\flock($handle, \LOCK_EX | \LOCK_NB)) {
                return new CommandSlot($handle);
            }

            \fclose($handle);
        }

        return null;
    }
}
