<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Update\Updater;

/**
 * Undo the last framework:update from the backup it made.
 *
 * Restores every file the update replaced or deleted, removes the ones it
 * created, and puts framework.json and composer.json back. The backup is kept,
 * marked as rolled back, so running this twice undoes the update before.
 */
final class FrameworkRollbackCommand
{
    public function __construct(private readonly Updater $updater) {}

    public function __invoke(Output $output): int
    {
        $result = $this->updater->rollback();

        $output->success(\sprintf('Rolled the framework back from %s to %s.', $result['from'], $result['to']));
        $output->line('Restored from ' . $result['backup'] . '.');
        $output->line('Run composer install if composer.json changed, then php laika cache:clear.');

        return 0;
    }
}
