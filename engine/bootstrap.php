<?php

declare(strict_types=1);

/*
 * One bootstrap, two execution contexts.
 *
 * Both index.php and laika (the console) require this file, which is the concrete form
 * of "REST and web HTTP use the same kernel, and the CLI uses the same
 * application bootstrap". The only thing that differs is the context object.
 *
 * It sits here rather than in engine/Bootstrap/ because Windows filesystems are
 * case-insensitive, so bootstrap.php beside Bootstrap.php is the same file.
 */

use App\Engine\Bootstrap\Bootstrap;
use App\Engine\Core\ExecutionContext;

$argv = $_SERVER['argv'] ?? null;

$context = \PHP_SAPI === 'cli'
    ? ExecutionContext::cli(is_array($argv) ? array_values(array_filter($argv, '\is_string')) : [], $_SERVER)
    : ExecutionContext::http($_SERVER);

return Bootstrap::create(dirname(__DIR__), $context);
