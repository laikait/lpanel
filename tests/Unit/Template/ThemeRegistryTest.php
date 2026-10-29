<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Template\TemplateException;
use App\Engine\Template\ThemeRegistry;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which themes exist, and which one a request renders with.
 *
 * The rule everything here protects is that a theme exists because something
 * registered it: a directory under templates/ publishes nothing on its own.
 */
final class ThemeRegistryTest extends TestCase
{
    private AssetRegistry $assets;

    protected function setUp(): void
    {
        $this->assets = new AssetRegistry();
    }

    /** @param (\Closure(ThemeRegistry): void)|null $loader */
    private function themes(?\Closure $loader = null): ThemeRegistry
    {
        return new ThemeRegistry($this->assets, '/site/templates', $loader);
    }

    // ---- registration -----------------------------------------------------

    public function test_registering_publishes_the_themes_assets(): void
    {
        $themes = $this->themes();
        $themes->register('dark');
        $themes->register('admin/dark');

        self::assertSame('/site/templates/dark/assets', $this->assets->source(AssetKind::Template, 'dark')->root);
        self::assertSame(
            '/site/templates/admin/dark/assets',
            $this->assets->source(AssetKind::Template, 'admin/dark')->root,
        );
        self::assertSame('/site/templates/admin/dark', $themes->root('admin/dark'));
        self::assertSame(['admin/dark', 'dark'], $themes->all());
    }

    public function test_nothing_is_published_without_registration(): void
    {
        $this->themes()->load();

        self::assertFalse($this->assets->has(AssetKind::Template, 'dark'));
    }

    public function test_registering_twice_is_harmless(): void
    {
        $themes = $this->themes();
        $themes->register('dark');
        $themes->register('dark');

        self::assertSame(['dark'], $themes->all());
    }

    /** @return iterable<string, array{string}> */
    public static function unusableNames(): iterable
    {
        yield 'three parts' => ['a/b/c'];
        yield 'empty' => [''];
        yield 'traversal' => ['../dark'];
        yield 'dot' => ['admin/.dark'];
        yield 'space' => ['da rk'];
        yield 'the assets folder' => ['assets'];
        yield 'under the assets folder' => ['Assets/dark'];
        yield 'the emails folder' => ['emails'];
        yield 'the errors folder' => ['errors'];
        yield 'under the errors folder' => ['Errors/dark'];
    }

    #[DataProvider('unusableNames')]
    public function test_a_name_that_is_not_a_theme_directory_is_refused(string $name): void
    {
        $this->expectException(TemplateException::class);

        $this->themes()->register($name);
    }

    /** templates/<Module>/ is already that module's override directory. */
    public function test_a_theme_cannot_take_a_module_id(): void
    {
        $themes = $this->themes();
        $themes->reserve(['Billing']);

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('override directory');

        $themes->register('billing/dark');
    }

    public function test_reserving_after_registration_still_refuses_the_clash(): void
    {
        $themes = $this->themes();
        $themes->register('Billing');

        $this->expectException(TemplateException::class);

        $themes->reserve(['Billing']);
    }

    /** A name written like a path is the same theme: only the ends are trimmed. */
    public function test_slashes_at_either_end_are_trimmed(): void
    {
        $themes = $this->themes();
        $themes->register('/app/default/');
        $themes->register('dark/');

        self::assertSame(['app/default', 'dark'], $themes->all());
        self::assertTrue($themes->has('/app/default/'));
        self::assertTrue($themes->has('app/default/'));
        self::assertTrue($this->assets->has(AssetKind::Template, 'app/default'));
        self::assertSame('/site/templates/app/default', $themes->root('/app/default'));

        $themes->use('/app/default/');
        self::assertSame('app/default', $themes->active());

        $themes->mapErrors('/app', '/app/default/');
        self::assertSame('app/default', $themes->forPath('/app/x'));
    }

    public function test_an_empty_segment_in_the_middle_is_still_refused(): void
    {
        $this->expectException(TemplateException::class);

        $this->themes()->register('/app//default/');
    }

    public function test_only_slashes_is_not_a_theme(): void
    {
        $this->expectException(TemplateException::class);

        $this->themes()->register('/');
    }

    // ---- loading ----------------------------------------------------------

    public function test_the_loader_runs_once_and_only_when_asked(): void
    {
        $runs = 0;
        $themes = $this->themes(static function (ThemeRegistry $themes) use (&$runs): void {
            ++$runs;
            $themes->register('dark');
        });

        self::assertSame(0, $runs, 'construction loads nothing');

        $themes->load();
        $themes->load();

        self::assertSame(1, $runs);
        self::assertTrue($themes->has('dark'));
    }

    public function test_reading_the_registry_loads_it(): void
    {
        $themes = $this->themes(static fn(ThemeRegistry $themes) => $themes->register('dark'));

        self::assertTrue($themes->has('dark'));
    }

    // ---- the active theme -------------------------------------------------

    public function test_no_theme_is_active_until_one_is_used(): void
    {
        $themes = $this->themes();
        $themes->register('admin/dark');

        self::assertNull($themes->active());
        self::assertNull($themes->activeRoot());

        $themes->use('admin/dark');

        self::assertSame('admin/dark', $themes->active());
        self::assertSame('/site/templates/admin/dark', $themes->activeRoot());

        $themes->use(null);

        self::assertNull($themes->active());
    }

    public function test_an_unregistered_theme_cannot_be_used(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('never discovered');

        $this->themes()->use('dark');
    }

    // ---- error themes -----------------------------------------------------

    public function test_the_longest_prefix_wins(): void
    {
        $themes = $this->themes();
        $themes->register('admin/dark');
        $themes->register('admin/light');
        $themes->mapErrors('/admin', 'admin/dark');
        $themes->mapErrors('/admin/reports/', 'admin/light');

        self::assertSame('admin/dark', $themes->forPath('/admin'));
        self::assertSame('admin/dark', $themes->forPath('/admin/missing'));
        self::assertSame('admin/light', $themes->forPath('/admin/reports'));
        self::assertSame('admin/light', $themes->forPath('/admin/reports/2026'));
        self::assertNull($themes->forPath('/customers'));
    }

    public function test_a_prefix_matches_whole_segments_only(): void
    {
        $themes = $this->themes();
        $themes->register('admin/dark');
        $themes->mapErrors('/admin', 'admin/dark');

        self::assertNull($themes->forPath('/administrator'));
    }

    public function test_the_root_prefix_matches_everything(): void
    {
        $themes = $this->themes();
        $themes->register('dark');
        $themes->register('admin/dark');
        $themes->mapErrors('/', 'dark');
        $themes->mapErrors('/admin', 'admin/dark');

        self::assertSame('dark', $themes->forPath('/anything/at/all'));
        self::assertSame('admin/dark', $themes->forPath('/admin/x'));
    }

    public function test_an_error_theme_must_be_registered(): void
    {
        $this->expectException(TemplateException::class);

        $this->themes()->mapErrors('/admin', 'admin/dark');
    }

    public function test_an_error_prefix_must_be_a_path(): void
    {
        $themes = $this->themes();
        $themes->register('dark');

        $this->expectException(TemplateException::class);

        $themes->mapErrors('admin', 'dark');
    }

    public function test_a_later_mapping_replaces_an_earlier_one(): void
    {
        $themes = $this->themes();
        $themes->register('admin/dark');
        $themes->register('admin/light');
        $themes->mapErrors('/admin', 'admin/dark');
        $themes->mapErrors('/admin', 'admin/light');

        self::assertSame('admin/light', $themes->forPath('/admin/x'));
    }
}
