<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Update\ArchiveReleaseSource;
use App\Engine\Update\Manifest;
use App\Engine\Update\ReleaseSource;
use App\Engine\Update\UpdateException;
use App\Engine\Update\UpdatePlan;
use App\Engine\Update\Updater;
use App\Engine\Update\UpgradeNotes;

/**
 * Update this application's copy of the framework to a newer release.
 *
 * An application is a copy of the framework, not a package in vendor/, so
 * composer update never brings new framework code. This does: it fetches a
 * release, works out file by file what may be replaced (see Updater), stops if
 * a framework file was edited here, backs up everything it changes, and then
 * says what to run next. It runs neither composer nor migrations -- Composer
 * may not be on the server, and a migration is the application's decision.
 */
final class FrameworkUpdateCommand
{
    /** Where releases are unpacked, under the application. */
    public const WORK = 'system/Runtime/update';

    public function __construct(
        private readonly Updater $updater,
        private readonly ReleaseSource $source,
    ) {}

    public function __invoke(
        Output $output,
        bool $check = false,
        ?string $to = null,
        ?string $from = null,
        bool $dryRun = false,
        bool $force = false,
        bool $major = false,
        ?string $baseline = null,
    ): int {
        $installed = $this->updater->installed();
        $current = $installed === null ? Application::VERSION : $installed->version;
        $source = $from === null || $from === '' ? $this->source : new ArchiveReleaseSource($from);
        $target = $to === null || $to === '' ? $source->latest() : \ltrim($to, 'v');

        if ($check) {
            return $this->check($output, $current, $target, $source);
        }

        if (\version_compare($target, $current, '==')) {
            $output->success(\sprintf('The framework is already at %s.', $current));

            return 0;
        }

        if (\version_compare($target, $current, '<') && !$force) {
            throw UpdateException::downgrade($current, $target);
        }

        if (self::major($target) > self::major($current) && !$major) {
            throw UpdateException::majorUpgrade($current, $target);
        }

        $old = $installed ?? $this->baseline($current, $source, $baseline);
        $work = $this->updater->basePath() . '/' . self::WORK . '/' . $target;
        self::clear($work);

        $output->line(\sprintf('Fetching %s from %s...', $target, $source->describe()));
        $release = $source->fetch($target, $work);
        $plan = $this->updater->plan($old, $release);

        $this->describe($output, $plan, $release->root);

        if ($dryRun) {
            $output->line();
            $output->line('Dry run: nothing was changed.');

            return 0;
        }

        if ($plan->hasConflicts() && !$force) {
            $output->line();
            $output->error(\sprintf('%d framework file(s) were changed here, so nothing was updated.', \count($plan->conflicts)));
            $output->line('Compare each with the release copy above, move your change into a module if you can, and run this again.');
            $output->line('--force replaces them with the release\'s; your copies go into the backup.');

            return 1;
        }

        $backup = $this->updater->apply($plan, $release, $force);
        $notes = \is_file($release->path('UPGRADING.md'))
            ? UpgradeNotes::between((string) \file_get_contents($release->path('UPGRADING.md')), $current, $target)
            : [];
        self::clear($work);

        $output->line();
        $output->success(\sprintf('Updated the framework from %s to %s.', $current, $target));
        $output->line('Backup: ' . $backup . ' (undo with php laika framework:rollback)');

        foreach ($notes as $section) {
            $output->line();
            $output->write($section);
        }

        $output->line();
        $output->line('Next:');

        if ($plan->composer !== null) {
            $output->line('  composer update            composer.json changed');
        }

        $output->line('  php laika migrate          if a module or the framework added migrations');
        $output->line('  php laika cache:clear');
        $output->line('  php laika security:check');
        $output->line('  git diff                   review, then commit framework.json with the rest');

        return 0;
    }

    private function check(Output $output, string $current, string $target, ReleaseSource $source): int
    {
        $output->pairs(['Installed' => $current, 'Available' => $target . ' (' . $source->describe() . ')']);

        if (\version_compare($target, $current, '>')) {
            $output->line();
            $output->line(\sprintf('Update with: php laika framework:update%s', self::major($target) > self::major($current) ? ' --major' : ''));
        } else {
            $output->success('The framework is up to date.');
        }

        return 0;
    }

    private function baseline(string $version, ReleaseSource $source, ?string $file): Manifest
    {
        if ($file !== null && $file !== '') {
            return Manifest::read($file);
        }

        return $source->manifest($version)
            ?? ($source === $this->source ? null : $this->source->manifest($version))
            ?? throw UpdateException::noBaseline($version);
    }

    private function describe(Output $output, UpdatePlan $plan, string $releaseRoot): void
    {
        $output->line();
        $output->heading(\sprintf('Framework %s to %s', $plan->from, $plan->to));

        $sections = [
            'Replace' => $plan->update,
            'Add' => $plan->add,
            'Delete' => $plan->delete,
            'New in your modules, templates, lang or config' => $plan->seedAdd,
            'Changed upstream; written beside yours as <file>.dist' => $plan->seedChanged,
        ];

        foreach ($sections as $title => $paths) {
            if ($paths === []) {
                continue;
            }

            $output->line();
            $output->line(\sprintf('%s (%d):', $title, \count($paths)));

            foreach ($paths as $path) {
                $output->line('  ' . $path);
            }
        }

        if ($plan->composer !== null) {
            $output->line();
            $output->line('composer.json: merged with the release\'s.');

            foreach ($plan->composerNotes as $note) {
                $output->line('  ' . $note);
            }
        }

        if ($plan->conflicts !== []) {
            $output->line();
            $output->warning(\sprintf('Changed here (%d):', \count($plan->conflicts)));

            foreach ($plan->conflicts as $path => $why) {
                $output->line(\sprintf('  %s  (%s)', $path, $why));
                $output->line(\sprintf('      diff -u %s %s/%s', $path, $releaseRoot, $path));
            }
        }

        if ($plan->isEmpty()) {
            $output->line();
            $output->line('No file changes: only framework.json is updated.');
        }
    }

    private static function major(string $version): int
    {
        return (int) \explode('.', $version)[0];
    }

    /** Remove a work directory left by an earlier run. */
    private static function clear(string $directory): void
    {
        if (!\is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() && !$item->isLink() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
            }
        }

        @\rmdir($directory);
    }
}
