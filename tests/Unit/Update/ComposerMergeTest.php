<?php

declare(strict_types=1);

namespace App\Tests\Unit\Update;

use App\Engine\Update\ComposerMerge;
use App\Tests\Support\TestCase;

final class ComposerMergeTest extends TestCase
{
    /** @var array<string, mixed> */
    private const OLD = [
        'name' => 'laikait/lphp',
        'require' => ['php' => '^8.2', 'psr/container' => '^2.0', 'twig/twig' => '^3.8'],
        'require-dev' => ['phpunit/phpunit' => '^11.5'],
        'scripts' => ['test' => 'phpunit'],
    ];

    public function test_the_application_s_additions_stay_and_the_release_s_changes_arrive(): void
    {
        $local = self::OLD;
        $local['require']['guzzlehttp/guzzle'] = '^7.9';
        $local['scripts']['deploy'] = 'php laika cache:warm';

        $new = self::OLD;
        $new['require']['psr/container'] = '^2.1';
        $new['require']['psr/log'] = '^3.0';
        unset($new['require']['twig/twig']);

        [$merged, $notes] = ComposerMerge::merge($local, self::OLD, $new);

        self::assertSame(
            ['php' => '^8.2', 'psr/container' => '^2.1', 'guzzlehttp/guzzle' => '^7.9', 'psr/log' => '^3.0'],
            $merged['require'],
        );
        self::assertSame(['test' => 'phpunit', 'deploy' => 'php laika cache:warm'], $merged['scripts']);
        self::assertSame([], $notes);
    }

    /** The framework's code needs its constraint, so in require the release wins -- and says so. */
    public function test_a_constraint_both_changed_takes_the_release_s_and_is_noted(): void
    {
        $local = self::OLD;
        $local['require']['twig/twig'] = '^3.9';
        $new = self::OLD;
        $new['require']['twig/twig'] = '^3.10';

        [$merged, $notes] = ComposerMerge::merge($local, self::OLD, $new);

        self::assertSame('^3.10', $merged['require']['twig/twig']);
        self::assertCount(1, $notes);
        self::assertStringContainsString('require.twig/twig', $notes[0]);
    }

    public function test_a_dropped_package_the_application_pinned_itself_stays(): void
    {
        $local = self::OLD;
        $local['require']['twig/twig'] = '^3.12';
        $new = self::OLD;
        unset($new['require']['twig/twig']);

        [$merged, $notes] = ComposerMerge::merge($local, self::OLD, $new);

        self::assertSame('^3.12', $merged['require']['twig/twig'], 'the application may use it itself');
        self::assertStringContainsString('kept this application', $notes[0]);
    }

    public function test_outside_require_the_application_s_choice_stays(): void
    {
        $local = self::OLD;
        $local['scripts']['test'] = 'phpunit --testdox';
        $new = self::OLD;
        $new['scripts']['test'] = 'phpunit --colors';

        [$merged, $notes] = ComposerMerge::merge($local, self::OLD, $new);

        self::assertSame('phpunit --testdox', $merged['scripts']['test']);
        self::assertStringContainsString('kept this application', $notes[0]);
    }

    public function test_a_key_the_application_removed_stays_removed(): void
    {
        $local = self::OLD;
        unset($local['scripts']);
        $new = self::OLD;
        $new['scripts']['test'] = 'phpunit --colors';

        [$merged] = ComposerMerge::merge($local, self::OLD, $new);

        self::assertArrayNotHasKey('scripts', $merged);
    }

    public function test_it_is_written_as_composer_writes_it(): void
    {
        self::assertSame("{\n    \"require\": {\n        \"php\": \"^8.2\"\n    }\n}\n", ComposerMerge::encode(['require' => ['php' => '^8.2']]));
    }
}
