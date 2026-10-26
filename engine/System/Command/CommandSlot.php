<?php

declare(strict_types=1);

namespace App\Engine\System\Command;

/**
 * One held place under a ConcurrencyLimit.
 *
 * Released explicitly when the command ends, and again -- harmlessly -- when
 * the object goes away, so that an exception between acquiring and releasing
 * cannot keep a slot. If the whole process dies, the operating system releases
 * the lock with the file handle.
 */
final class CommandSlot
{
    /** @param resource $handle */
    public function __construct(private $handle) {}

    public function __destruct()
    {
        $this->release();
    }

    public function release(): void
    {
        if (\is_resource($this->handle)) {
            \flock($this->handle, \LOCK_UN);
            \fclose($this->handle);
        }
    }
}
