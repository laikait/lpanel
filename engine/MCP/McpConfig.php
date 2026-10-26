<?php

declare(strict_types=1);

namespace App\Engine\MCP;

use App\Engine\Config\Config;
use App\Engine\Config\ConfigurationException;

/**
 * The `mcp` configuration block, read once and checked.
 *
 *     // config/mcp.php
 *     return [
 *         'server' => ['name' => 'Acme ERP', 'version' => '4.2.0'],
 *         'transports' => ['stdio' => true, 'http' => true],
 *         'http' => ['path' => '/mcp'],
 *     ];
 *
 * **Nothing is exposed by default.** MCP is enabled and STDIO is available,
 * which opens nothing: a STDIO server exists only while somebody who can run
 * the console runs `mcp:stdio`. HTTP is off until it is switched on, and
 * guests are refused until they are allowed.
 *
 * `enabled` is the switch for the whole of MCP. Off, `mcp:stdio` refuses to
 * start and the HTTP path is an ordinary 404; modules may still declare
 * capabilities, and nothing serves them.
 *
 * **Every value is checked when this is built** -- once per process, when the
 * first thing that needs MCP asks for it -- and the exception names the key.
 */
final class McpConfig
{
    public const DEFAULT_PATH = '/mcp';

    public const DEFAULT_NAME = 'lphp';

    private function __construct(
        public readonly bool $enabled,
        public readonly bool $stdio,
        public readonly bool $http,
        public readonly string $path,
        public readonly bool $allowGuests,
        public readonly string $serverName,
        public readonly string $serverVersion,
    ) {}

    /**
     * @param string $version the server version when none is configured: the framework's
     *
     * @throws ConfigurationException naming the key that is wrong
     */
    public static function fromConfig(Config $config, string $version): self
    {
        return new self(
            $config->bool('mcp.enabled', true),
            $config->bool('mcp.transports.stdio', true),
            $config->bool('mcp.transports.http', false),
            self::path($config->string('mcp.http.path', self::DEFAULT_PATH) ?? self::DEFAULT_PATH),
            $config->bool('mcp.allow_guests', false),
            self::label('mcp.server.name', $config->string('mcp.server.name', self::DEFAULT_NAME) ?? self::DEFAULT_NAME),
            self::label('mcp.server.version', $config->string('mcp.server.version', $version) ?? $version),
        );
    }

    public function stdioEnabled(): bool
    {
        return $this->enabled && $this->stdio;
    }

    public function httpEnabled(): bool
    {
        return $this->enabled && $this->http;
    }

    /**
     * One path, compared exactly with the request's: "/mcp", or deeper, such as
     * "/api/mcp". Surrounding slashes are forgiven; anything a URL would
     * interpret -- a query, a fragment, "..", a percent-escape -- is refused
     * rather than guessed at, and so is "/", which would take the home page.
     */
    private static function path(string $path): string
    {
        $normalized = '/' . \trim($path, '/');

        if (
            $normalized === '/'
            || \preg_match('#^(/[A-Za-z0-9._~-]+)+$#D', $normalized) !== 1
            || \preg_match('#/\.{1,2}(/|$)#', $normalized) === 1
        ) {
            throw ConfigurationException::unusableValue('mcp.http.path', 'a path such as "/mcp": letters, digits, "-", "_", "." and "~" between slashes, and not "/"');
        }

        return $normalized;
    }

    /** What a client displays: one line, not empty, not a paragraph. */
    private static function label(string $key, string $value): string
    {
        if (\trim($value) === '' || \mb_strlen($value) > 100 || \preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw ConfigurationException::unusableValue($key, 'one line of 1 to 100 characters');
        }

        return $value;
    }
}
