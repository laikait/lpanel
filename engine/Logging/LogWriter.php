<?php

declare(strict_types=1);

namespace App\Engine\Logging;

/**
 * Somewhere a record can be written.
 *
 * Three methods, and that is the whole extension point. A writer is not
 * configured through this framework, does not know about channels and does not
 * decide what gets logged -- the manager filters by level before a writer is
 * ever called, so a writer's only job is to put a record somewhere.
 *
 * Named without the Interface suffix, like TemplateEngine, because the suffix
 * describes the language feature rather than the role.
 *
 * A writer may throw. The manager catches it, stops using that writer, and
 * records why -- logging must never be the reason a working request fails, and
 * the alternative is every writer defensively swallowing its own errors and
 * nobody being able to find out that the disk filled up.
 */
interface LogWriter
{
    /** A name for diagnostics: what "log:status" prints. */
    public function describe(): string;

    /**
     * Whether this writer wants this record at all.
     *
     * Asked before write(), so a writer that only cares about failures does not
     * have to open a file to decide it has nothing to do.
     */
    public function accepts(LogRecord $record): bool;

    public function write(LogRecord $record): void;
}
