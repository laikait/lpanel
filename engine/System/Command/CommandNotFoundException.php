<?php

declare(strict_types=1);

namespace App\Engine\System\Command;

/**
 * The program, or the script, is not there -- so nothing ran.
 *
 * Worth catching on its own: "rsync is not installed on this host" is a
 * deployment fact an application can report or work around, where most other
 * command exceptions are mistakes in code.
 */
final class CommandNotFoundException extends CommandException {}
