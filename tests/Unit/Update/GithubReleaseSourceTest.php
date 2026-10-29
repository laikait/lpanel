<?php

declare(strict_types=1);

namespace App\Tests\Unit\Update;

use App\Engine\Http\Client\Client;
use App\Engine\Http\Client\ClientRequest;
use App\Engine\Http\Client\ClientResponse;
use App\Engine\Http\Client\FakeTransport;
use App\Engine\Update\GithubReleaseSource;
use App\Engine\Update\Manifest;
use App\Engine\Update\UpdateException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Against a stand-in for GitHub: a test run never touches the network.
 */
final class GithubReleaseSourceTest extends UpdateTestCase
{
    /** @var list<string> */
    private array $asked = [];

    /** @param array<string, string> $responses URL => body */
    private function source(array $responses): GithubReleaseSource
    {
        $fake = new FakeTransport();

        for ($i = 0; $i < 10; ++$i) {
            $fake->pushAnswer(function (ClientRequest $request) use ($responses): ClientResponse {
                $this->asked[] = $request->url;

                return isset($responses[$request->url]) ? new ClientResponse(200, [], $responses[$request->url]) : new ClientResponse(404);
            });
        }

        return new GithubReleaseSource(new Client($fake), 'laikait/lphp');
    }

    /** @return array<string, string> */
    private function githubRelease(string $version, string $zip = '', string $checksum = '', string $manifest = ''): array
    {
        $base = 'https://github.com/laikait/lphp/releases/download/v' . $version . '/';

        return [
            'https://api.github.com/repos/laikait/lphp/releases/tags/v' . $version => (string) \json_encode([
                'tag_name' => 'v' . $version,
                'assets' => [
                    ['name' => 'lphp-v' . $version . '.zip', 'browser_download_url' => $base . 'lphp-v' . $version . '.zip'],
                    ['name' => 'lphp-v' . $version . '.zip.sha256', 'browser_download_url' => $base . 'lphp-v' . $version . '.zip.sha256'],
                    ['name' => 'framework.json', 'browser_download_url' => $base . 'framework.json'],
                ],
            ]),
            $base . 'lphp-v' . $version . '.zip' => $zip,
            $base . 'lphp-v' . $version . '.zip.sha256' => $checksum,
            $base . 'framework.json' => $manifest,
        ];
    }

    public function test_the_latest_version_comes_from_the_latest_release(): void
    {
        $source = $this->source([
            'https://api.github.com/repos/laikait/lphp/releases/latest' => '{"tag_name": "v3.1.0", "assets": []}',
        ]);

        self::assertSame('3.1.0', $source->latest());
        self::assertSame(['https://api.github.com/repos/laikait/lphp/releases/latest'], $this->asked);
    }

    public function test_the_manifest_of_a_version_is_fetched_on_its_own(): void
    {
        $manifest = new Manifest('3.0.0', ['engine/A.php' => ['sha256' => \str_repeat('a', 64), 'role' => 'owned']]);
        $source = $this->source($this->githubRelease('3.0.0', manifest: $manifest->toJson()));

        self::assertSame($manifest->files, $source->manifest('3.0.0')?->files);
        self::assertNull($source->manifest('9.9.9'), 'no such release');
    }

    #[RequiresPhpExtension('zip')]
    public function test_a_release_is_downloaded_verified_and_unpacked(): void
    {
        $zip = $this->zip('3.1.0');
        $source = $this->source($this->githubRelease('3.1.0', $zip, \hash('sha256', $zip) . "  lphp-v3.1.0.zip\n"));

        $release = $source->fetch('3.1.0', $this->root . '/work');

        self::assertSame('3.1.0', $release->version());
        self::assertSame('<?php // a', \file_get_contents($release->path('engine/A.php')));
    }

    #[RequiresPhpExtension('zip')]
    public function test_a_download_that_does_not_match_its_checksum_is_refused(): void
    {
        $zip = $this->zip('3.1.0');
        $source = $this->source($this->githubRelease('3.1.0', $zip, \str_repeat('0', 64) . "  lphp-v3.1.0.zip\n"));

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('does not match its published SHA-256 checksum');
        $source->fetch('3.1.0', $this->root . '/work');
    }

    #[RequiresPhpExtension('zip')]
    public function test_an_archive_with_an_entry_outside_itself_is_refused(): void
    {
        $path = $this->root . '/evil.zip';
        $archive = new \ZipArchive();
        $archive->open($path, \ZipArchive::CREATE);
        $archive->addFromString('lphp/framework.json', '{}');
        $archive->addFromString('../escaped.php', '<?php');
        $archive->close();
        $zip = (string) \file_get_contents($path);
        $source = $this->source($this->githubRelease('3.1.0', $zip, \hash('sha256', $zip)));

        try {
            $source->fetch('3.1.0', $this->root . '/work');
            self::fail('an escaping entry was unpacked');
        } catch (UpdateException $e) {
            self::assertStringContainsString('../escaped.php', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->root . '/escaped.php');
    }

    /** A zip as the release workflow builds it: one lphp/ directory with framework.json in it. */
    private function zip(string $version): string
    {
        $tree = $this->tree('build/lphp', ['engine/A.php' => '<?php // a', 'composer.json' => '{}']);
        Manifest::build($tree, $version)->write($tree . '/framework.json');

        $path = $this->root . '/release.zip';
        $archive = new \ZipArchive();
        $archive->open($path, \ZipArchive::CREATE);

        foreach (['engine/A.php', 'composer.json', 'framework.json'] as $file) {
            $archive->addFile($tree . '/' . $file, 'lphp/' . $file);
        }

        $archive->close();

        return (string) \file_get_contents($path);
    }
}
