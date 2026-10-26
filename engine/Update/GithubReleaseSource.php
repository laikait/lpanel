<?php

declare(strict_types=1);

namespace App\Engine\Update;

use App\Engine\Http\Client\Client;
use App\Engine\Http\Client\HttpClientException;

/**
 * Releases published on GitHub by the release workflow.
 *
 * Each release carries three assets: lphp-vX.Y.Z.zip, its .sha256, and the
 * framework.json inside it, published on its own so an application created
 * before manifests existed can fetch the one for its version without
 * downloading the whole release. The zip is checked against the checksum
 * before it is unpacked; a mismatch stops the update.
 *
 * Fetched through Http\Client\Client, so redirects -- GitHub sends every
 * asset download to its CDN -- timeouts and retries behave as everywhere else.
 */
final class GithubReleaseSource implements ReleaseSource
{
    public const REPOSITORY = 'laikait/lphp';

    private const API = 'https://api.github.com/repos/';

    /** @var array<string, array<string, string>> version => asset name => download URL */
    private array $assets = [];

    public function __construct(
        private readonly Client $http = new Client(),
        private readonly string $repository = self::REPOSITORY,
    ) {}

    public function latest(): string
    {
        return $this->release('latest');
    }

    public function fetch(string $version, string $workDirectory): Release
    {
        $assets = $this->assetsOf($version);
        $zipName = 'lphp-v' . $version . '.zip';
        $zipUrl = $assets[$zipName] ?? throw UpdateException::download($this->describe() . ' v' . $version, 'it has no ' . $zipName);
        $sumUrl = $assets[$zipName . '.sha256'] ?? throw UpdateException::download($this->describe() . ' v' . $version, 'it has no checksum');

        if (!\is_dir($workDirectory) && !@\mkdir($workDirectory, 0o755, true) && !\is_dir($workDirectory)) {
            throw UpdateException::unwritable($workDirectory);
        }

        $zip = $workDirectory . '/' . $zipName;

        if (@\file_put_contents($zip, $this->get($zipUrl)) === false) {
            throw UpdateException::unwritable($zip);
        }

        ArchiveReleaseSource::verify($zip, $this->get($sumUrl));
        $release = Release::open(ArchiveReleaseSource::unpack($zip, $workDirectory . '/release'));

        if ($release->version() !== $version) {
            throw UpdateException::versionMismatch($version, $release->version());
        }

        return $release;
    }

    public function manifest(string $version): ?Manifest
    {
        try {
            $url = $this->assetsOf($version)[Manifest::FILE] ?? null;

            return $url === null ? null : Manifest::fromJson($this->get($url), $this->describe() . ' v' . $version);
        } catch (UpdateException) {
            return null;
        }
    }

    public function describe(): string
    {
        return 'github.com/' . $this->repository;
    }

    /** The version of the release at "latest" or "tags/vX.Y.Z", remembering its assets. */
    private function release(string $which): string
    {
        $url = self::API . $this->repository . '/releases/' . $which;

        try {
            $data = \json_decode($this->get($url), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw UpdateException::download($url, 'the answer is not JSON');
        }

        $tag = \is_array($data) ? ($data['tag_name'] ?? null) : null;

        if (!\is_string($tag) || \preg_match('/^v?(\d+\.\d+\.\d+)$/D', $tag, $match) !== 1) {
            throw UpdateException::download($url, 'it names no version tag');
        }

        $assets = [];

        foreach (\is_array($data['assets'] ?? null) ? $data['assets'] : [] as $asset) {
            if (\is_array($asset) && \is_string($asset['name'] ?? null) && \is_string($asset['browser_download_url'] ?? null)) {
                $assets[$asset['name']] = $asset['browser_download_url'];
            }
        }

        $this->assets[$match[1]] = $assets;

        return $match[1];
    }

    /** @return array<string, string> */
    private function assetsOf(string $version): array
    {
        if (!isset($this->assets[$version])) {
            $this->release('tags/v' . $version);
        }

        return $this->assets[$version] ?? [];
    }

    private function get(string $url): string
    {
        try {
            // GitHub's API answers only a request that names itself, and a
            // release download can take a while; one retry covers a blip.
            $response = $this->http
                ->accept('application/vnd.github+json, application/octet-stream')
                ->timeout(120)
                ->retry(1, 500)
                ->get($url);
        } catch (HttpClientException $e) {
            throw UpdateException::download($url, $e->getMessage());
        }

        if ($response->status() !== 200) {
            throw UpdateException::download($url, $response->status() === 0 ? 'no answer' : 'HTTP ' . $response->status());
        }

        return $response->body();
    }
}
