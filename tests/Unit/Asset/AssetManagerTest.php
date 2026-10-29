<?php

declare(strict_types=1);

namespace App\Tests\Unit\Asset;

use App\Engine\Asset\AssetException;
use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetManager;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetResolver;
use App\Engine\Asset\AssetSource;
use App\Engine\Asset\AssetVersioning;
use App\Engine\Cache\Cache;
use App\Engine\Cache\Stores\ArrayStore;
use App\Tests\Support\TestCase;

final class AssetManagerTest extends TestCase
{
    private string $root;

    private AssetRegistry $registry;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/framework-assets-' . \bin2hex(\random_bytes(6));

        foreach (['core', 'template', 'template-admin', 'plugin', 'gateway'] as $directory) {
            \mkdir($this->root . '/' . $directory . '/css', 0o777, true);
        }

        \file_put_contents($this->root . '/core/css/app.css', 'body{}');
        \file_put_contents($this->root . '/template/css/app.css', 'main{}');
        \file_put_contents($this->root . '/template-admin/css/admin.css', 'nav{}');
        \file_put_contents($this->root . '/plugin/css/example.css', 'p{}');
        \file_put_contents($this->root . '/gateway/css/gateway.css', 'a{}');

        $this->registry = new AssetRegistry();
        $this->registry->publish(AssetKind::Core, null, $this->root . '/core');
        $this->registry->publish(AssetKind::Template, null, $this->root . '/template');
        $this->registry->publish(AssetKind::Template, 'admin', $this->root . '/template-admin');
        $this->registry->publish(AssetKind::Module, 'Example', $this->root . '/plugin');
        $this->registry->publish(AssetKind::Module, 'ExampleGateway', $this->root . '/gateway');
    }

    protected function tearDown(): void
    {
        foreach (['core', 'template', 'template-admin', 'plugin', 'gateway'] as $directory) {
            \array_map('unlink', \glob($this->root . '/' . $directory . '/css/*') ?: []);
            @\rmdir($this->root . '/' . $directory . '/css');
            @\rmdir($this->root . '/' . $directory);
        }

        @\unlink($this->root . '/core/manifest.json');
        @\rmdir($this->root);

        parent::tearDown();
    }

    private function manager(
        string $baseUrl = '',
        AssetVersioning $versioning = AssetVersioning::Content,
        bool $manifests = true,
        bool $strict = false,
    ): AssetManager {
        return new AssetManager($this->registry, new AssetResolver(), $baseUrl, $versioning, $manifests, $strict);
    }

    // ---- the five namespaces ----------------------------------------------

    public function test_each_namespace_produces_the_documented_url_shape(): void
    {
        $assets = $this->manager(versioning: AssetVersioning::None);

        self::assertSame('/assets/core/css/app.css', $assets->core('css/app.css'));
        self::assertSame('/assets/template/css/app.css', $assets->template('css/app.css'));
        self::assertSame('/assets/template/admin/css/admin.css', $assets->template('admin', 'css/admin.css'));
        self::assertSame('/assets/module/Example/css/example.css', $assets->module('Example', 'css/example.css'));
        self::assertSame('/assets/module/ExampleGateway/css/gateway.css', $assets->module('ExampleGateway', 'css/gateway.css'));
    }

    /**
     * The URL says which namespace, never which directory. Publishing the same
     * plugin from somewhere else changes nothing a caller writes or reads.
     */
    public function test_a_url_never_reveals_where_the_file_lives(): void
    {
        $url = $this->manager()->module('Example', 'css/example.css');

        self::assertStringNotContainsString('modules', $url);
        self::assertStringNotContainsString($this->root, $url);
        self::assertStringNotContainsString('assets/assets', $url);
    }

    public function test_two_modules_do_not_collide(): void
    {
        $assets = $this->manager(versioning: AssetVersioning::None);

        self::assertNotSame(
            $assets->module('Example', 'css/example.css'),
            $assets->module('ExampleGateway', 'css/gateway.css'),
        );
    }

    // ---- the base path ----------------------------------------------------

    public function test_a_subdirectory_install_prefixes_every_url(): void
    {
        $assets = $this->manager('/framework', AssetVersioning::None);

        self::assertSame('/framework/assets/core/css/app.css', $assets->core('css/app.css'));
    }

    public function test_a_cdn_origin_is_just_a_longer_prefix(): void
    {
        $assets = $this->manager('https://cdn.example.test/', AssetVersioning::None);

        self::assertSame('https://cdn.example.test/assets/core/css/app.css', $assets->core('css/app.css'));
    }

    // ---- versioning -------------------------------------------------------

    public function test_content_versioning_puts_a_hash_on_the_url(): void
    {
        $url = $this->manager()->core('css/app.css');

        self::assertMatchesRegularExpression('#^/assets/core/css/app\.css\?v=[0-9a-f]{8}$#', $url);
    }

    public function test_the_token_changes_only_when_the_content_does(): void
    {
        $first = $this->manager()->core('css/app.css');

        self::assertSame($first, $this->manager()->core('css/app.css'));

        \file_put_contents($this->root . '/core/css/app.css', 'body{color:red}');

        self::assertNotSame($first, $this->manager()->core('css/app.css'));
    }

    /**
     * A token kept between requests must not outlive the file it describes.
     *
     * The cache is what production uses, and a deploy that forgets cache:clear
     * is the case this is about: the old token would keep being sent, and a
     * ?v= URL is cached by browsers as immutable for a year.
     */
    public function test_a_cached_token_follows_the_file_without_a_cache_clear(): void
    {
        $cache = new Cache(new ArrayStore());
        $manager = fn(): AssetManager => new AssetManager(
            $this->registry,
            new AssetResolver(),
            cache: $cache,
        );

        $first = $manager()->core('css/app.css');

        self::assertSame($first, $manager()->core('css/app.css'));

        $file = $this->root . '/core/css/app.css';
        \file_put_contents($file, 'body{color:red}');
        \touch($file, \time() + 60);
        \clearstatcache();

        self::assertNotSame($first, $manager()->core('css/app.css'));
    }

    public function test_an_area_theme_has_its_own_url(): void
    {
        \mkdir($this->root . '/template-admin-dark/css', 0o777, true);
        \file_put_contents($this->root . '/template-admin-dark/css/app.css', 'aside{}');

        try {
            $this->registry->publish(AssetKind::Template, 'admin/dark', $this->root . '/template-admin-dark');

            $url = $this->manager()->template('admin/dark', 'css/app.css');

            self::assertMatchesRegularExpression('#^/assets/template/admin/dark/css/app\.css\?v=[0-9a-f]{8}$#', $url);

            // Written like a path, it is the same theme.
            self::assertSame($url, $this->manager()->template('/admin/dark/', 'css/app.css'));
            self::assertSame($url, $this->manager()->template('admin/dark/', 'css/app.css'));

            // "/" alone names no theme: templates/assets/.
            self::assertSame($this->manager()->template('css/app.css'), $this->manager()->template('/', 'css/app.css'));
        } finally {
            @\unlink($this->root . '/template-admin-dark/css/app.css');
            @\rmdir($this->root . '/template-admin-dark/css');
            @\rmdir($this->root . '/template-admin-dark');
        }
    }

    public function test_modified_versioning_uses_the_timestamp(): void
    {
        \touch($this->root . '/core/css/app.css', 1700000000);

        self::assertSame(
            '/assets/core/css/app.css?v=' . \dechex(1700000000),
            $this->manager(versioning: AssetVersioning::Modified)->core('css/app.css'),
        );
    }

    // ---- manifests --------------------------------------------------------

    public function test_a_manifest_redirects_the_name_to_the_built_file(): void
    {
        \file_put_contents($this->root . '/core/css/app.9c81f4a2.css', 'body{}');
        \file_put_contents(
            $this->root . '/core/manifest.json',
            '{"css/app.css": "css/app.9c81f4a2.css"}',
        );

        // The caller still writes the name they always wrote.
        self::assertSame(
            '/assets/core/css/app.9c81f4a2.css',
            $this->manager()->core('css/app.css'),
        );
    }

    /** A hashed filename already carries its version; ?v= on top is noise. */
    public function test_a_manifested_url_carries_no_query_token(): void
    {
        \file_put_contents($this->root . '/core/css/app.9c81f4a2.css', 'body{}');
        \file_put_contents($this->root . '/core/manifest.json', '{"css/app.css": "css/app.9c81f4a2.css"}');

        self::assertStringNotContainsString('?v=', $this->manager()->core('css/app.css'));
    }

    public function test_manifests_can_be_switched_off(): void
    {
        \file_put_contents($this->root . '/core/manifest.json', '{"css/app.css": "css/app.9c81f4a2.css"}');

        self::assertStringContainsString(
            'css/app.css?v=',
            $this->manager(manifests: false)->core('css/app.css'),
        );
    }

    // ---- missing assets ---------------------------------------------------

    /**
     * Loud while somebody is writing the page, quiet once it is in production.
     * A stale stylesheet is a smaller problem than a page that will not render,
     * and the request for the missing file shows up as a 404 anyway.
     */
    public function test_a_missing_asset_throws_in_strict_mode(): void
    {
        $this->expectException(AssetException::class);

        $this->manager(strict: true)->core('css/nope.css');
    }

    public function test_a_missing_asset_still_produces_a_url_otherwise(): void
    {
        self::assertSame('/assets/core/css/nope.css', $this->manager()->core('css/nope.css'));
    }

    public function test_an_unpublished_namespace_throws_in_strict_mode(): void
    {
        $this->expectException(AssetException::class);
        $this->expectExceptionMessage("module 'Missing'");

        $this->manager(strict: true)->module('Missing', 'css/x.css');
    }

    // ---- locate -----------------------------------------------------------

    public function test_locate_returns_the_resolved_file(): void
    {
        $reference = $this->manager()->locate(AssetKind::Core, null, 'css/app.css');

        self::assertSame('css/app.css', $reference->path);
        self::assertSame('body{}', $reference->contents());
    }

    public function test_locate_always_throws_for_a_missing_asset(): void
    {
        // Unlike url(), which has a useful fallback, there is nothing sensible
        // to return here -- so this one does not soften in production.
        $this->expectException(AssetException::class);

        $this->manager(strict: false)->locate(AssetKind::Core, null, 'css/nope.css');
    }

    public function test_exists_answers_without_throwing(): void
    {
        $assets = $this->manager();

        self::assertTrue($assets->exists(AssetKind::Core, null, 'css/app.css'));
        self::assertFalse($assets->exists(AssetKind::Core, null, 'css/nope.css'));
        self::assertFalse($assets->exists(AssetKind::Module, 'Missing', 'css/x.css'));
    }

    // ---- encoding ---------------------------------------------------------

    public function test_a_space_in_a_filename_is_encoded_and_the_slashes_are_not(): void
    {
        \file_put_contents($this->root . '/core/css/two words.css', 'body{}');

        self::assertSame(
            '/assets/core/css/two%20words.css',
            $this->manager(versioning: AssetVersioning::None)->core('css/two words.css'),
        );
    }

    // ---- declaration ------------------------------------------------------

    public function test_a_source_name_that_could_alter_a_url_is_refused(): void
    {
        foreach (['../evil', 'a/b', '', 'has space', '.hidden', 'semi;colon'] as $name) {
            try {
                new AssetSource(AssetKind::Module, $name, $this->root);
                self::fail(\sprintf('the source name "%s" was accepted', $name));
            } catch (AssetException $e) {
                self::assertStringContainsString('name', $e->getMessage());
            }
        }
    }

    public function test_a_template_may_be_named_by_area_and_theme(): void
    {
        self::assertSame('template/dark', (new AssetSource(AssetKind::Template, 'dark', $this->root))->key());
        self::assertSame('template/admin/dark', (new AssetSource(AssetKind::Template, 'admin/dark', $this->root))->key());

        foreach (['a/b/c', '/dark', 'dark/', 'admin//dark', '../dark', 'admin/.dark', 'admin/da rk'] as $name) {
            try {
                new AssetSource(AssetKind::Template, $name, $this->root);
                self::fail(\sprintf('the template name "%s" was accepted', $name));
            } catch (AssetException $e) {
                self::assertStringContainsString('name', $e->getMessage());
            }
        }
    }

    public function test_core_cannot_be_named(): void
    {
        $caught = 'nothing was thrown';

        try {
            new AssetSource(AssetKind::Core, 'anything', $this->root);
        } catch (AssetException $e) {
            $caught = $e->getMessage();
        }

        self::assertStringContainsString('cannot be named', $caught);
    }

    public function test_a_module_source_must_be_named(): void
    {
        $caught = 'nothing was thrown';

        try {
            new AssetSource(AssetKind::Module, null, $this->root);
        } catch (AssetException $e) {
            $caught = $e->getMessage();
        }

        self::assertStringContainsString('must be named', $caught);
    }
}
