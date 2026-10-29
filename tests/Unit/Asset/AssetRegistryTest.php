<?php

declare(strict_types=1);

namespace App\Tests\Unit\Asset;

use App\Engine\Asset\AssetException;
use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetSource;
use App\Engine\Asset\Manifest;
use App\Tests\Support\TestCase;

final class AssetRegistryTest extends TestCase
{
    public function test_a_source_key_is_the_url_prefix(): void
    {
        self::assertSame('core', (new AssetSource(AssetKind::Core, null, '/x'))->key());
        self::assertSame('template', (new AssetSource(AssetKind::Template, null, '/x'))->key());
        self::assertSame('template/admin', (new AssetSource(AssetKind::Template, 'admin', '/x'))->key());
        self::assertSame('module/Example', (new AssetSource(AssetKind::Module, 'Example', '/x'))->key());
        self::assertSame('module/Stripe', (new AssetSource(AssetKind::Module, 'Stripe', '/x'))->key());
    }

    public function test_an_unpublished_namespace_cannot_be_reached(): void
    {
        $registry = new AssetRegistry();

        self::assertFalse($registry->has(AssetKind::Module, 'Nothing'));
        self::assertNull($registry->find(AssetKind::Module, 'Nothing'));

        $this->expectException(AssetException::class);
        $registry->source(AssetKind::Module, 'Nothing');
    }

    public function test_publishing_the_same_directory_twice_is_harmless(): void
    {
        $registry = new AssetRegistry();
        $registry->publish(AssetKind::Module, 'Example', '/x/assets');
        $registry->publish(AssetKind::Module, 'Example', '/x/assets');

        self::assertSame(1, $registry->count());
    }

    /**
     * One namespace resolving to two directories means a URL names both, and
     * which one wins comes down to registration order -- which is exactly the
     * kind of thing nobody discovers until it matters.
     */
    public function test_publishing_two_directories_under_one_name_is_refused(): void
    {
        $registry = new AssetRegistry();
        $registry->publish(AssetKind::Module, 'Example', '/x/assets');

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('already published');

        $registry->publish(AssetKind::Module, 'Example', '/y/assets');
    }

    public function test_two_modules_are_two_namespaces(): void
    {
        $registry = new AssetRegistry();
        $registry->publish(AssetKind::Module, 'Example', '/Example/assets');
        $registry->publish(AssetKind::Module, 'ExampleGateway', '/ExampleGateway/assets');

        self::assertSame(2, $registry->count());
        self::assertSame(['Example', 'ExampleGateway'], $registry->names(AssetKind::Module));
    }

    public function test_listing_is_stable_regardless_of_registration_order(): void
    {
        $first = new AssetRegistry();
        $first->publish(AssetKind::Module, 'Zeta', '/z');
        $first->publish(AssetKind::Core, null, '/c');
        $first->publish(AssetKind::Module, 'Alpha', '/a');

        $second = new AssetRegistry();
        $second->publish(AssetKind::Module, 'Alpha', '/a');
        $second->publish(AssetKind::Module, 'Zeta', '/z');
        $second->publish(AssetKind::Core, null, '/c');

        $keys = static fn(AssetRegistry $registry): array => \array_map(
            static fn(AssetSource $source): string => $source->key(),
            $registry->all(),
        );

        self::assertSame(['core', 'module/Alpha', 'module/Zeta'], $keys($first));
        self::assertSame($keys($first), $keys($second));
    }

    /**
     * A published directory that is not there yet is a normal state: a template
     * that ships no assets, a plugin mid-install. Existence is a question for
     * resolution, not for declaration.
     */
    public function test_a_source_may_point_at_a_directory_that_does_not_exist(): void
    {
        $source = new AssetSource(AssetKind::Template, 'ghost', '/nowhere/at/all');

        self::assertFalse($source->exists());
        self::assertSame('template/ghost', $source->key());
    }

    // ---- manifests --------------------------------------------------------

    public function test_a_missing_manifest_is_not_an_error(): void
    {
        $manifest = new Manifest(\sys_get_temp_dir() . '/framework-no-such-manifest.json');

        self::assertFalse($manifest->exists());
        self::assertSame([], $manifest->entries());
        self::assertNull($manifest->lookup('js/app.js'));
    }

    public function test_both_manifest_entry_shapes_are_read(): void
    {
        $file = \sys_get_temp_dir() . '/framework-manifest-' . \bin2hex(\random_bytes(4)) . '.json';
        \file_put_contents($file, '{"a.js": "a.1234.js", "b.css": {"path": "b.5678.css", "integrity": "sha384-x"}}');

        $manifest = new Manifest($file);

        self::assertSame('a.1234.js', $manifest->lookup('a.js'));
        self::assertSame('b.5678.css', $manifest->lookup('b.css'));
        self::assertTrue($manifest->isBuilt('a.1234.js'));
        self::assertFalse($manifest->isBuilt('a.js'));

        \unlink($file);
    }

    /**
     * Ignoring a broken manifest would serve stale files with nothing to
     * explain why, which is a genuinely miserable afternoon.
     */
    public function test_a_malformed_manifest_is_an_error_rather_than_an_empty_map(): void
    {
        $file = \sys_get_temp_dir() . '/framework-manifest-' . \bin2hex(\random_bytes(4)) . '.json';
        \file_put_contents($file, '{"a.js": ');

        try {
            (new Manifest($file))->entries();
            self::fail('the malformed manifest was accepted');
        } catch (AssetException $e) {
            self::assertStringContainsString('not valid JSON', $e->getMessage());
        } finally {
            \unlink($file);
        }
    }
}
