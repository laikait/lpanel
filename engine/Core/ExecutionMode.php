<?php

declare(strict_types=1);

namespace App\Engine\Core;

/**
 * How the application was invoked.
 *
 * Web and REST are deliberately not separate modes: they are the same kernel
 * answering the same way, and the difference is content negotiation, not
 * execution.
 */
enum ExecutionMode: string
{
    case Http = 'http';
    case Cli = 'cli';
}
