<?php

declare(strict_types=1);

namespace App\Engine\System\Command;

/**
 * A command ran past its timeout and was stopped, turned into an exception on
 * request by CommandResult::orFail().
 *
 * A failure too, so catching CommandFailedException catches this; catch this
 * when a slow command means something different from a broken one -- a retry
 * later, rather than an alert now.
 */
final class CommandTimeoutException extends CommandFailedException {}
