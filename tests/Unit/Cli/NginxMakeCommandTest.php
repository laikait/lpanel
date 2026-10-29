<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cli;

use App\Engine\Cli\Commands\NginxMakeCommand;
use App\Engine\Cli\Output;
use App\Engine\Container\Container;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Tests\Support\TestCase;

/**
 * nginx:make, against a throwaway directory rather than the project root --
 * a test run has no business leaving an nginx.conf where a deployment would
 * pick it up.
 */
final class NginxMakeCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/nginx-make-' . \bin2hex(\random_bytes(6));
        \mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @\unlink($this->directory . '/' . NginxMakeCommand::FILE);
        @\rmdir($this->directory);

        parent::tearDown();
    }

    /** @return array{int, string} exit code and captured output */
    private function make(string $root = '', string $serverName = '_', bool $force = false): array
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $command = new NginxMakeCommand(new Application(new Container(), ExecutionContext::cli(), $this->directory));
        $status = $command(new Output($stream, $stream), $serverName, $root, force: $force);

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        return [$status, $output];
    }

    private function written(): string
    {
        $contents = \file_get_contents($this->directory . '/' . NginxMakeCommand::FILE);
        self::assertIsString($contents);

        return $contents;
    }

    public function test_it_writes_the_server_block_into_the_application_root(): void
    {
        [$status, $output] = $this->make(serverName: 'app.example.com');

        self::assertSame(0, $status);
        self::assertStringContainsString('Wrote', $output);

        $conf = $this->written();
        $root = \rtrim(\str_replace('\\', '/', $this->directory), '/');

        self::assertStringContainsString('server_name app.example.com;', $conf);
        // nginx serves public/ and nothing above it.
        self::assertStringContainsString('root ' . $root . '/public;', $conf);
        self::assertStringContainsString('alias ' . $root . '/public/assets/;', $conf);
        self::assertStringContainsString('fastcgi_pass unix:/run/php/php-fpm.sock;', $conf);

        // nginx variables survive the template rather than being interpolated away.
        self::assertStringContainsString('try_files $uri /index.php$is_args$args;', $conf);
        self::assertStringContainsString('$document_root/index.php;', $conf);
    }

    /**
     * ^~ makes nginx skip the regex locations, so the top-level dotfile rule
     * never reached /assets/core/: public/assets/.git or a stray .env there
     * would have been served. The rule is repeated inside, with nosniff,
     * since nothing served there passes through PHP.
     */
    public function test_the_direct_assets_location_keeps_dotfiles_out_and_adds_nosniff(): void
    {
        $block = NginxMakeCommand::serverBlock('example.com', '/srv/app', '80', 'unix:/run/php-fpm.sock');

        $start = \strpos($block, 'location ^~ /assets/core/ {');
        self::assertIsInt($start);
        $assets = \substr($block, $start, (int) \strpos($block, "\n    }", $start) - $start);

        self::assertStringContainsString('location ~ /\. { deny all; }', $assets);
        self::assertStringContainsString('add_header X-Content-Type-Options "nosniff" always;', $assets);
    }

    public function test_the_root_can_be_the_one_on_the_server_rather_than_this_one(): void
    {
        $this->make(root: '/srv/app/');

        self::assertStringContainsString('root /srv/app/public;', $this->written());
        self::assertStringContainsString('alias /srv/app/public/assets/;', $this->written());
    }

    /** A hand-edited file is exactly the one that must not be regenerated silently. */
    public function test_it_refuses_to_overwrite_without_force(): void
    {
        \file_put_contents($this->directory . '/' . NginxMakeCommand::FILE, 'edited by hand');

        [$status, $output] = $this->make();

        self::assertSame(1, $status);
        self::assertStringContainsString('--force', $output);
        self::assertSame('edited by hand', $this->written());

        [$status] = $this->make(force: true);

        self::assertSame(0, $status);
        self::assertStringContainsString('server {', $this->written());
    }
}
