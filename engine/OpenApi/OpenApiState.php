<?php

declare(strict_types=1);

namespace App\Engine\OpenApi;

/**
 * What `openapi:docs` and `openapi:auth` switched on this machine.
 *
 *     php laika openapi:docs --enable        // /docs and /openapi.json
 *     php laika openapi:auth --enable        // both need a login
 *
 * The state is one file, system/Runtime/openapi.json, the way maintenance
 * mode is: a command cannot write config/openapi.php -- commands never write
 * code -- and the config cache would hide the change if it did. Bootstrap
 * overlays the file on the configuration it read (see overlay()), so every
 * reader -- the routes, the page, security:check -- sees one answer.
 *
 * **It wins over config/openapi.php** for the keys it holds, because running
 * the command is the more recent decision; keys it never touched still come
 * from the files. Caller overrides win over both, and openapi.runtime = false
 * ignores it entirely. Per machine, like `down`: several web servers each
 * need the command.
 */
final class OpenApiState
{
    public const FILE = 'system/Runtime/openapi.json';

    public function __construct(private readonly string $basePath) {}

    /**
     * What the commands set: 'docs' => [on, path, spec], 'auth' => [on, can].
     * A file that cannot be read is no state at all: it must never be the
     * reason a request fails, and openapi:docs reports it.
     *
     * @return array{docs?: array{on: bool, path: ?string, spec: ?string}, auth?: array{on: bool, can: ?string}}
     */
    public function read(): array
    {
        return self::load($this->path());
    }

    public function isReadable(): bool
    {
        return !\is_file($this->path()) || \is_array(\json_decode((string) @\file_get_contents($this->path()), true));
    }

    /** @throws \RuntimeException when the file cannot be written */
    public function docs(bool $on, ?string $path = null, ?string $spec = null): void
    {
        $this->write(['docs' => ['on' => $on, 'path' => $path, 'spec' => $spec]]);
    }

    /** @throws \RuntimeException when the file cannot be written */
    public function auth(bool $on, ?string $can = null): void
    {
        $this->write(['auth' => ['on' => $on, 'can' => $can]]);
    }

    public function path(): string
    {
        return \rtrim($this->basePath, '/\\') . '/' . self::FILE;
    }

    /**
     * The state in a file as configuration: openapi.route, openapi.docs and
     * the auth/can part of openapi.meta. Everything else is left as it was.
     *
     * @param array<string, mixed>                                                                                      $settings
     * @param array{docs?: array{on: bool, path: ?string, spec: ?string}, auth?: array{on: bool, can: ?string}} $state
     *
     * @return array<string, mixed>
     */
    public static function overlay(array $settings, array $state): array
    {
        $openapi = \is_array($settings['openapi'] ?? null) ? $settings['openapi'] : [];

        if (isset($state['docs'])) {
            $docs = $state['docs'];

            if ($docs['on']) {
                $openapi['route'] = $docs['spec'] ?? OpenApiRoutes::path(self::stringOrNull($openapi['route'] ?? null)) ?? '/openapi.json';
                $openapi['docs'] = $docs['path'] ?? OpenApiRoutes::path(self::stringOrNull($openapi['docs'] ?? null)) ?? '/docs';
            } else {
                $openapi['route'] = null;
                $openapi['docs'] = null;
            }
        }

        if (isset($state['auth'])) {
            $meta = \is_array($openapi['meta'] ?? null) ? $openapi['meta'] : [];
            unset($meta['auth'], $meta['can']);

            if ($state['auth']['on']) {
                $meta = [...$meta, ...($state['auth']['can'] !== null ? ['can' => $state['auth']['can']] : ['auth' => true])];
            }

            $openapi['meta'] = $meta;
        }

        $settings['openapi'] = $openapi;

        return $settings;
    }

    /**
     * The file under a base path, without an instance -- Bootstrap reads it
     * while it is still assembling the configuration.
     *
     * @return array{docs?: array{on: bool, path: ?string, spec: ?string}, auth?: array{on: bool, can: ?string}}
     */
    public static function at(string $basePath): array
    {
        return self::load(\rtrim($basePath, '/\\') . '/' . self::FILE);
    }

    /** @return array{docs?: array{on: bool, path: ?string, spec: ?string}, auth?: array{on: bool, can: ?string}} */
    private static function load(string $file): array
    {
        if (!\is_file($file)) {
            return [];
        }

        $data = \json_decode((string) @\file_get_contents($file), true);

        if (!\is_array($data)) {
            return [];
        }

        $state = [];

        if (\is_array($data['docs'] ?? null) && \is_bool($data['docs']['on'] ?? null)) {
            $state['docs'] = [
                'on' => $data['docs']['on'],
                'path' => OpenApiRoutes::path(self::stringOrNull($data['docs']['path'] ?? null)),
                'spec' => OpenApiRoutes::path(self::stringOrNull($data['docs']['spec'] ?? null)),
            ];
        }

        if (\is_array($data['auth'] ?? null) && \is_bool($data['auth']['on'] ?? null)) {
            $can = self::stringOrNull($data['auth']['can'] ?? null);
            $state['auth'] = ['on' => $data['auth']['on'], 'can' => $can === '' ? null : $can];
        }

        return $state;
    }

    /** @param array<string, array<string, mixed>> $change */
    private function write(array $change): void
    {
        $path = $this->path();
        $directory = \dirname($path);

        if (!\is_dir($directory) && !@\mkdir($directory, 0o755, true) && !\is_dir($directory)) {
            throw new \RuntimeException(\sprintf('%s could not be created.', $directory));
        }

        $state = [...$this->read(), ...$change];

        // Written whole, then renamed into place: a request never reads half a file.
        $temporary = $path . '.' . \bin2hex(\random_bytes(4));

        if (@\file_put_contents($temporary, \json_encode($state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n") === false || !@\rename($temporary, $path)) {
            @\unlink($temporary);

            throw new \RuntimeException(\sprintf('%s could not be written.', $path));
        }
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
