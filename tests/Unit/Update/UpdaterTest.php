<?php

declare(strict_types=1);

namespace App\Tests\Unit\Update;

use App\Engine\Update\Manifest;
use App\Engine\Update\Release;
use App\Engine\Update\UpdateException;
use App\Engine\Update\UpdatePlan;
use App\Engine\Update\Updater;

final class UpdaterTest extends UpdateTestCase
{
    private const OLD = [
        'engine/Kept.php' => 'kept',
        'engine/Changed.php' => 'v1',
        'engine/Dropped.php' => 'dropped',
        'tests/Unit/FrameworkTest.php' => 'framework test v1',
        'lang/en.php' => 'en v1',
        'modules/Shared/module.php' => 'shared',
        'composer.json' => '{"require": {"php": "^8.2", "twig/twig": "^3.8"}}',
    ];

    private const NEW = [
        'engine/Kept.php' => 'kept',
        'engine/Changed.php' => 'v2',
        'engine/Added.php' => 'added',
        'tests/Unit/FrameworkTest.php' => 'framework test v2',
        'lang/en.php' => 'en v2',
        'modules/Shared/module.php' => 'shared',
        'templates/partials/nav.twig' => 'nav',
        'composer.json' => '{"require": {"php": "^8.2", "twig/twig": "^3.10"}}',
    ];

    private Release $old;

    private Release $new;

    private string $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->old = $this->release('old', '3.0.0', self::OLD);
        $this->new = $this->release('new', '3.1.0', self::NEW);

        // The application: the old release, its manifest, and its own files.
        $this->app = $this->tree('app', [
            ...self::OLD,
            'framework.json' => $this->old->manifest->toJson(),
            'modules/Billing/module.php' => 'mine',
            'tests/Unit/MyTest.php' => 'my test',
            '.env' => 'APP_KEY=secret',
            'composer.json' => '{"require": {"php": "^8.2", "twig/twig": "^3.8", "guzzlehttp/guzzle": "^7.9"}}',
        ]);
    }

    private function updater(): Updater
    {
        return new Updater($this->app);
    }

    private function plan(): UpdatePlan
    {
        $installed = $this->updater()->installed();
        self::assertNotNull($installed);

        return $this->updater()->plan($installed, $this->new);
    }

    public function test_the_plan_names_what_changes_file_by_file(): void
    {
        $plan = $this->plan();

        self::assertSame('3.0.0', $plan->from);
        self::assertSame('3.1.0', $plan->to);
        self::assertSame(['engine/Changed.php', 'tests/Unit/FrameworkTest.php'], $plan->update);
        self::assertSame(['engine/Added.php'], $plan->add);
        self::assertSame(['engine/Dropped.php'], $plan->delete);
        self::assertSame(['templates/partials/nav.twig'], $plan->seedAdd);
        self::assertSame(['lang/en.php'], $plan->seedChanged);
        self::assertSame([], $plan->conflicts);
        self::assertNotNull($plan->composer);
    }

    public function test_applying_it_changes_only_what_the_release_owns(): void
    {
        $this->updater()->apply($this->plan(), $this->new);
        $after = $this->contents($this->app);

        self::assertSame('v2', $after['engine/Changed.php']);
        self::assertSame('added', $after['engine/Added.php']);
        self::assertArrayNotHasKey('engine/Dropped.php', $after);
        self::assertSame('framework test v2', $after['tests/Unit/FrameworkTest.php']);
        self::assertSame('nav', $after['templates/partials/nav.twig']);

        // Seed files are never replaced; the release's copy is beside it.
        self::assertSame('en v1', $after['lang/en.php']);
        self::assertSame('en v2', $after['lang/en.php.dist']);

        // The application's own files, in no manifest, are untouched.
        self::assertSame('mine', $after['modules/Billing/module.php']);
        self::assertSame('my test', $after['tests/Unit/MyTest.php']);
        self::assertSame('APP_KEY=secret', $after['.env']);

        $composer = \json_decode($after['composer.json'], true);
        self::assertSame(['php' => '^8.2', 'twig/twig' => '^3.10', 'guzzlehttp/guzzle' => '^7.9'], $composer['require']);

        self::assertSame('3.1.0', Manifest::fromJson($after['framework.json'])->version);
    }

    public function test_a_framework_file_edited_here_is_a_conflict_and_nothing_is_written(): void
    {
        \file_put_contents($this->app . '/engine/Changed.php', 'v1 with my patch');
        $before = $this->contents($this->app);

        $plan = $this->plan();

        self::assertSame(['engine/Changed.php' => UpdatePlan::EDITED], $plan->conflicts);
        self::assertNotContains('engine/Changed.php', $plan->update);

        try {
            $this->updater()->apply($plan, $this->new);
            self::fail('a conflict was applied without --force');
        } catch (UpdateException) {
        }

        self::assertSame($before, $this->contents($this->app));
    }

    public function test_an_edited_file_the_release_did_not_change_is_left_alone(): void
    {
        \file_put_contents($this->app . '/engine/Kept.php', 'kept, patched');

        $plan = $this->plan();

        self::assertSame([], $plan->conflicts);
        $this->updater()->apply($plan, $this->new);
        self::assertSame('kept, patched', \file_get_contents($this->app . '/engine/Kept.php'));
    }

    public function test_the_other_kinds_of_conflict(): void
    {
        \unlink($this->app . '/engine/Changed.php');
        \file_put_contents($this->app . '/engine/Added.php', 'my own Added.php');
        \file_put_contents($this->app . '/engine/Dropped.php', 'dropped, patched');

        self::assertSame([
            'engine/Added.php' => UpdatePlan::IN_THE_WAY,
            'engine/Changed.php' => UpdatePlan::DELETED_HERE,
            'engine/Dropped.php' => UpdatePlan::REMOVED_BUT_EDITED,
        ], $this->plan()->conflicts);
    }

    public function test_force_takes_the_release_s_copy_and_keeps_yours_in_the_backup(): void
    {
        \file_put_contents($this->app . '/engine/Changed.php', 'v1 with my patch');

        $backup = $this->updater()->apply($this->plan(), $this->new, force: true);

        self::assertSame('v2', \file_get_contents($this->app . '/engine/Changed.php'));
        self::assertSame('v1 with my patch', \file_get_contents($this->app . '/' . $backup . '/files/engine/Changed.php'));
    }

    public function test_rollback_restores_every_file_byte_for_byte(): void
    {
        \file_put_contents($this->app . '/lang/en.php.dist', 'an older .dist');
        $before = $this->contents($this->app);

        $backup = $this->updater()->apply($this->plan(), $this->new);
        $result = $this->updater()->rollback();

        self::assertSame(['from' => '3.1.0', 'to' => '3.0.0', 'backup' => $backup], $result);

        $after = $this->contents($this->app);
        $after = \array_filter($after, static fn(string $path): bool => !\str_starts_with($path, 'system/Backups/'), \ARRAY_FILTER_USE_KEY);

        self::assertSame($before, $after);
        self::assertDirectoryDoesNotExist($this->app . '/templates/partials', 'an emptied directory goes too');
        self::assertNull($this->updater()->latestBackup(), 'a rolled-back backup is not offered again');
    }

    public function test_rollback_with_no_backup_says_so(): void
    {
        $this->expectException(UpdateException::class);
        $this->updater()->rollback();
    }

    public function test_an_application_without_a_manifest_has_none_installed(): void
    {
        \unlink($this->app . '/framework.json');

        self::assertNull($this->updater()->installed());
    }

    /** A seed file the application deleted stays deleted. */
    public function test_a_seed_file_deleted_here_is_not_brought_back(): void
    {
        \unlink($this->app . '/modules/Shared/module.php');

        $plan = $this->plan();

        self::assertNotContains('modules/Shared/module.php', $plan->seedAdd);
        self::assertNotContains('modules/Shared/module.php', $plan->seedChanged);
    }
}
