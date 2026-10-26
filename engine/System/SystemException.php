<?php

declare(strict_types=1);

namespace App\Engine\System;

use App\Engine\Error\FrameworkException;

/**
 * The system layer refused something.
 *
 * Abstract because it is a type to catch, never one to throw. Each part of the
 * layer -- commands now, processes, cron and services as their phases land --
 * throws its own final subclass, so a caller that only wants to know "the
 * operating system side said no" catches this, and one that cares which part
 * catches that part.
 *
 * Whatever reaches a message here was written by this framework. A command's
 * output is not: it can hold a path, a password prompt or a database URL, so no
 * factory in this layer quotes stdout or stderr, and one that ever has to must
 * call withheld().
 */
abstract class SystemException extends FrameworkException {}
