<?php

declare(strict_types=1);

namespace App\Engine\Storage;

use App\Engine\Http\Client\Client;
use App\Engine\Storage\S3\S3Disk;
use App\Engine\Support\Path;

/**
 * The application's disks, by name.
 *
 *     public function __construct(private readonly Storage $storage) {}
 *
 *     $this->storage->disk('uploads')->put($path, $contents);
 *     $this->storage->disk()->get('reports/2026.csv');   // the default disk
 *
 * Disks are configured under storage.disks and built on first use, so a
 * request that never touches S3 never reads its credentials.
 *
 * In a test, replace the whole thing with memory:
 *
 *     $container->instance(Storage::class, Storage::fake(['local', 'uploads']));
 */
final class Storage
{
    /** The named route a local disk's temporary URL points at; the Shared module declares it. */
    public const ROUTE = 'storage.file';

    /**
     * What a stored file may be shown as rather than downloaded.
     *
     * Never HTML, SVG, XML or JavaScript: opened in a browser they run script,
     * on whichever origin serves them -- this application's for a signed link
     * through FileDownload, the bucket's for an S3 URL. Anything else is
     * served as application/octet-stream and downloads.
     */
    public const INLINE_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        'application/pdf', 'text/plain', 'audio/mpeg', 'video/mp4',
    ];

    /** @var array<string, Disk> */
    private array $built = [];

    /**
     * @param array<string, \Closure(): Disk> $disks
     */
    public function __construct(
        private readonly array $disks,
        private readonly string $default,
    ) {}

    /**
     * Disks from configuration.
     *
     * @param array<array-key, mixed>                          $disks         storage.disks
     * @param string                                           $basePath      a local root that is relative is relative to this
     * @param ?\Closure(): Client                               $http          for S3 disks, asked for when one is first used
     * @param ?\Closure(string, string, int): string            $temporaryUrls disk, path, expiry => a signed URL, for local disks
     */
    public static function fromConfig(
        array $disks,
        string $default,
        string $basePath,
        ?\Closure $http = null,
        ?\Closure $temporaryUrls = null,
    ): self {
        $factories = [];

        foreach ($disks as $name => $settings) {
            $name = (string) $name;

            if (!\is_array($settings)) {
                throw StorageException::misconfigured($name, 'its settings are not an array.');
            }

            $factories[$name] = static fn(): Disk => self::build($name, $settings, $basePath, $http, $temporaryUrls);
        }

        return new self($factories, $default);
    }

    /**
     * Memory disks under these names; the first is the default.
     *
     * @param list<string> $names
     */
    public static function fake(array $names = ['local']): self
    {
        $disks = [];

        foreach ($names as $name) {
            $disk = new MemoryDisk($name);
            $disks[$name] = static fn(): Disk => $disk;
        }

        return new self($disks, $names[0] ?? 'local');
    }

    /** @throws StorageException for a disk that is not configured, or is misconfigured */
    public function disk(?string $name = null): Disk
    {
        $name ??= $this->default;

        if (isset($this->built[$name])) {
            return $this->built[$name];
        }

        $factory = $this->disks[$name] ?? throw StorageException::unknownDisk($name, $this->names());

        return $this->built[$name] = $factory();
    }

    public function defaultName(): string
    {
        return $this->default;
    }

    /** @return list<string> */
    public function names(): array
    {
        return \array_map(\strval(...), \array_keys($this->disks));
    }

    /**
     * @param array<array-key, mixed>                $settings
     * @param ?\Closure(): Client                     $http
     * @param ?\Closure(string, string, int): string  $temporaryUrls
     */
    private static function build(string $name, array $settings, string $basePath, ?\Closure $http, ?\Closure $temporaryUrls): Disk
    {
        $driver = self::string($settings, 'driver') ?? 'local';
        $url = self::string($settings, 'url');

        switch ($driver) {
            case 'local':
                $root = self::string($settings, 'root') ?? 'system/Storage';

                return new LocalDisk(
                    Path::isAbsolute($root) ? $root : Path::join($basePath, $root),
                    $name,
                    $url,
                    $temporaryUrls === null ? null : static fn(string $path, int $expiresAt): string => $temporaryUrls($name, $path, $expiresAt),
                );

            case 's3':
                if ($http === null) {
                    throw StorageException::misconfigured($name, 'no HTTP client was given to reach S3 with.');
                }

                return new S3Disk(
                    $http(),
                    self::string($settings, 'bucket') ?? '',
                    self::string($settings, 'region') ?? 'us-east-1',
                    self::string($settings, 'key') ?? '',
                    self::string($settings, 'secret') ?? '',
                    self::string($settings, 'endpoint'),
                    \filter_var($settings['path_style'] ?? false, \FILTER_VALIDATE_BOOLEAN),
                    $url,
                    self::string($settings, 'root') ?? '',
                    $name,
                );

            case 'memory':
                return new MemoryDisk($name, $url);

            default:
                throw StorageException::unknownDriver($name, $driver);
        }
    }

    /** @param array<array-key, mixed> $settings */
    private static function string(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
