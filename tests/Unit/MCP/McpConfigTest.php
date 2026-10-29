<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP;

use App\Engine\Bootstrap\Bootstrap;
use App\Engine\Config\Config;
use App\Engine\Config\ConfigurationException;
use App\Engine\MCP\McpConfig;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class McpConfigTest extends TestCase
{
    /** @param array<string, mixed> $mcp what config/mcp.php would return */
    private static function config(array $mcp = []): McpConfig
    {
        $config = new Config(Bootstrap::defaults());
        $config->merge('mcp', $mcp);

        return McpConfig::fromConfig($config, '1.2.3');
    }

    public function test_the_defaults_expose_nothing(): void
    {
        $config = self::config();

        self::assertTrue($config->enabled);
        self::assertTrue($config->stdioEnabled(), 'STDIO opens nothing until somebody runs mcp:stdio');
        self::assertFalse($config->httpEnabled());
        self::assertFalse($config->allowGuests);
        self::assertSame('/mcp', $config->path);
        self::assertSame(McpConfig::DEFAULT_NAME, $config->serverName);
        self::assertSame('1.2.3', $config->serverVersion, 'the framework version when none is configured');
    }

    public function test_an_application_configures_it(): void
    {
        $config = self::config([
            'server' => ['name' => 'Acme ERP', 'version' => '4.2.0'],
            'transports' => ['http' => true],
            'http' => ['path' => '/api/mcp/'],
            'allow_guests' => true,
        ]);

        self::assertTrue($config->httpEnabled());
        self::assertTrue($config->stdioEnabled(), 'a key left out keeps its default');
        self::assertTrue($config->allowGuests);
        self::assertSame('/api/mcp', $config->path);
        self::assertSame('Acme ERP', $config->serverName);
        self::assertSame('4.2.0', $config->serverVersion);
    }

    public function test_disabling_mcp_disables_every_transport(): void
    {
        $config = self::config(['enabled' => false, 'transports' => ['stdio' => true, 'http' => true]]);

        self::assertFalse($config->stdioEnabled());
        self::assertFalse($config->httpEnabled());
    }

    public function test_each_transport_switches_off_alone(): void
    {
        $config = self::config(['transports' => ['stdio' => false, 'http' => true]]);

        self::assertFalse($config->stdioEnabled());
        self::assertTrue($config->httpEnabled());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'enabled is not a bool' => [['enabled' => 'yes'], 'mcp.enabled'];
        yield 'a transport switch is not a bool' => [['transports' => ['http' => 1]], 'mcp.transports.http'];
        yield 'guests is not a bool' => [['allow_guests' => 'false'], 'mcp.allow_guests'];
        yield 'the path is not a string' => [['http' => ['path' => 42]], 'mcp.http.path'];
        yield 'the path is the home page' => [['http' => ['path' => '/']], 'mcp.http.path'];
        yield 'the path is empty' => [['http' => ['path' => '']], 'mcp.http.path'];
        yield 'the path climbs' => [['http' => ['path' => '/api/../mcp']], 'mcp.http.path'];
        yield 'the path has a dot segment' => [['http' => ['path' => '/./mcp']], 'mcp.http.path'];
        yield 'the path has a query' => [['http' => ['path' => '/mcp?token=1']], 'mcp.http.path'];
        yield 'the path has a fragment' => [['http' => ['path' => '/mcp#x']], 'mcp.http.path'];
        yield 'the path is escaped' => [['http' => ['path' => '/m%63p']], 'mcp.http.path'];
        yield 'the path has a space' => [['http' => ['path' => '/my mcp']], 'mcp.http.path'];
        yield 'the path has an empty segment' => [['http' => ['path' => '/api//mcp']], 'mcp.http.path'];
        yield 'the name is empty' => [['server' => ['name' => ' ']], 'mcp.server.name'];
        yield 'the name is two lines' => [['server' => ['name' => "Acme\nERP"]], 'mcp.server.name'];
        yield 'the name is a paragraph' => [['server' => ['name' => \str_repeat('a', 101)]], 'mcp.server.name'];
        yield 'the version is empty' => [['server' => ['version' => '']], 'mcp.server.version'];
    }

    /** @param array<string, mixed> $mcp */
    #[DataProvider('invalid')]
    public function test_a_wrong_value_is_refused_naming_its_key(array $mcp, string $key): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($key);

        self::config($mcp);
    }

    /** @return iterable<string, array{string, string}> */
    public static function paths(): iterable
    {
        yield 'as written' => ['/mcp', '/mcp'];
        yield 'no leading slash' => ['mcp', '/mcp'];
        yield 'a trailing slash' => ['/mcp/', '/mcp'];
        yield 'deeper' => ['/api/v1/mcp', '/api/v1/mcp'];
        yield 'with a dot inside a segment' => ['/mcp.json', '/mcp.json'];
    }

    #[DataProvider('paths')]
    public function test_a_path_is_normalized_to_one_exact_form(string $path, string $expected): void
    {
        self::assertSame($expected, self::config(['http' => ['path' => $path]])->path);
    }
}
