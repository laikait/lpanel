<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Config\Config;
use App\Engine\Core\Application;
use App\Modules\Accounts\Provisioner;
use App\Modules\Files\FileManager;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M7a: the file manager and the disk usage explorer, in the User panel, the
 * API and the CLI, over the agent's files.* operations.
 */
final class FilesTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    private string $staging;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->rootPassword($this->app, 'root-password-123');
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->staging = \sys_get_temp_dir() . '/lpanel-files-' . \bin2hex(\random_bytes(4));
        \mkdir($this->staging);
        $this->server->staging = $this->staging;
        $this->app->container()->get(Config::class)->set('Files.staging', $this->staging);
        $this->makeAccount('shop', $this->packageFor(null, 'Starter', 2048, ['domains', 'file_manager']), null);
        $this->server->homes['shop'] = [
            'public_html' => null, 'mail' => null, 'tmp' => null, 'logs' => null,
            'public_html/index.php' => "<?php echo 'hello';\n",
            'public_html/wp' => null,
            'public_html/wp/wp-config.php' => 'define();',
            '.bashrc' => 'alias ll="ls -l"',
        ];
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->staging . '/*') ?: [] as $f) {
            \unlink($f);
        }

        @\rmdir($this->staging);
        parent::tearDown();
    }

    public function test_the_owner_browses_their_home(): void
    {
        $owner = $this->owner();
        $home = $owner->get('/panel/files');
        self::assertSame(200, $home->status());
        $body = $home->body();
        self::assertStringContainsString('public_html', $body);
        self::assertStringContainsString('.bashrc', $body);
        self::assertStringContainsString('Kept by the server', $body, 'the server\'s own folders are marked and cannot be selected');
        self::assertStringNotContainsString('value="public_html"', $body);

        $hidden = $owner->get('/panel/files?hidden=0')->body();
        self::assertStringNotContainsString('.bashrc', $hidden);

        $site = $owner->get('/panel/files?path=public_html')->body();
        self::assertStringContainsString('index.php', $site);
        self::assertStringContainsString('value="public_html/wp"', $site);

        self::assertSame(404, $owner->get('/panel/files?path=../etc')->status(), 'no path climbs out of the home');
        $gone = $owner->get('/panel/files?path=public_html/nowhere');
        self::assertSame(303, $gone->status(), 'a folder that is not there goes back up');
    }

    public function test_the_editor_saves_through_staging_and_refuses_a_stale_save(): void
    {
        $owner = $this->owner();
        $page = $owner->get('/panel/files/edit?path=public_html/index.php')->body();
        self::assertStringContainsString('echo &#039;hello&#039;', $page);
        $hash = \hash('sha256', "<?php echo 'hello';\n");
        self::assertStringContainsString($hash, $page);

        $r = $owner->post('/panel/files/save', ['path' => 'public_html/index.php', 'content' => "<?php echo 'bye';\r\n", 'expect' => $hash]);
        self::assertSame(303, $r->status());
        self::assertSame("<?php echo 'bye';\n", $this->server->homes['shop']['public_html/index.php'], 'line ends as the file had them');
        self::assertSame([], \glob($this->staging . '/*') ?: [], 'nothing is left in the staging folder');

        // Someone changed it meanwhile: the old hash no longer matches.
        $stale = $owner->post('/panel/files/save', ['path' => 'public_html/index.php', 'content' => 'mine', 'expect' => $hash]);
        self::assertSame(422, $stale->status());
        self::assertStringContainsString('changed after you opened it', $stale->body());
        self::assertStringContainsString('mine', $stale->body(), 'what was typed is kept');
        self::assertSame("<?php echo 'bye';\n", $this->server->homes['shop']['public_html/index.php']);

        $this->server->homes['shop']['public_html/logo.png'] = "\x89PNG\0\0";
        self::assertStringContainsString('This is not a text file', $owner->get('/panel/files/edit?path=public_html/logo.png')->body());

        $audit = $this->db()->table('audit_log')->where('action', 'files.write')->get();
        self::assertCount(2, $audit);
        self::assertStringNotContainsString('bye', (string) $audit[0]['details'], 'contents are never audited');
    }

    public function test_making_moving_copying_and_deleting(): void
    {
        $owner = $this->owner();
        $owner->post('/panel/files/folder', ['dir' => 'public_html', 'name' => 'blog']);
        self::assertArrayHasKey('public_html/blog', $this->server->homes['shop']);
        $made = $owner->post('/panel/files/file', ['dir' => 'public_html/blog', 'name' => 'index.html']);
        self::assertStringContainsString('/panel/files/edit', (string) $made->header('Location'), 'a new file opens in the editor');

        $owner->post('/panel/files/copy', ['dir' => 'public_html', 'paths' => ['public_html/index.php'], 'copy_to' => 'blog']);
        self::assertArrayHasKey('public_html/blog/index.php', $this->server->homes['shop']);
        $owner->post('/panel/files/move', ['dir' => 'public_html', 'paths' => ['public_html/wp'], 'move_to' => '/tmp']);
        self::assertArrayHasKey('tmp/wp/wp-config.php', $this->server->homes['shop']);
        self::assertArrayNotHasKey('public_html/wp', $this->server->homes['shop']);
        $owner->post('/panel/files/rename', ['dir' => 'tmp', 'paths' => ['tmp/wp'], 'new_name' => 'wordpress']);
        self::assertArrayHasKey('tmp/wordpress/wp-config.php', $this->server->homes['shop']);
        $owner->post('/panel/files/chmod', ['dir' => '', 'paths' => ['.bashrc'], 'mode' => '600']);
        $owner->post('/panel/files/compress', ['dir' => 'tmp', 'paths' => ['tmp/wordpress'], 'archive' => 'wp.zip']);
        self::assertArrayHasKey('tmp/wp.zip', $this->server->homes['shop']);
        $owner->post('/panel/files/extract', ['dir' => 'tmp', 'path' => 'tmp/wp.zip', 'to' => '/tmp/restored']);
        self::assertArrayHasKey('tmp/restored/extracted.txt', $this->server->homes['shop']);
        $owner->post('/panel/files/delete', ['dir' => 'tmp', 'paths' => ['tmp/wordpress', 'tmp/wp.zip']]);
        self::assertArrayNotHasKey('tmp/wordpress/wp-config.php', $this->server->homes['shop']);

        // The server's folders, a destination outside the home, and nothing chosen.
        $owner->post('/panel/files/delete', ['dir' => '', 'paths' => ['public_html']]);
        self::assertStringContainsString('kept by the server', $owner->get('/panel/files')->body());
        self::assertArrayHasKey('public_html/index.php', $this->server->homes['shop']);
        $owner->post('/panel/files/move', ['dir' => '', 'paths' => ['.bashrc'], 'move_to' => '../../etc']);
        self::assertStringContainsString('not one within your home', $owner->get('/panel/files')->body());
        $owner->post('/panel/files/delete', ['dir' => '']);
        self::assertStringContainsString('Select at least one', $owner->get('/panel/files')->body());

        $actions = \array_column($this->db()->table('audit_log')->where('action', 'like', 'files.%')->get(), 'action');
        foreach (['files.mkdir', 'files.create', 'files.copy', 'files.move', 'files.rename', 'files.chmod', 'files.compress', 'files.extract', 'files.delete'] as $action) {
            self::assertContains($action, $actions);
        }
    }

    public function test_uploads_and_downloads_go_through_the_staging_folder(): void
    {
        $files = $this->app->container()->get(FileManager::class);
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $local = \tempnam(\sys_get_temp_dir(), 'up');
        \file_put_contents($local, 'uploaded bytes');

        $outcome = $files->upload($root, null, $this->account('shop'), 'public_html', 'notes.txt', $local, uploaded: false);
        self::assertTrue($outcome->ok(), $outcome->message);
        self::assertSame('uploaded bytes', $this->server->homes['shop']['public_html/notes.txt']);
        self::assertSame([], \glob($this->staging . '/*') ?: []);
        self::assertSame('files_exists', $files->upload($root, null, $this->account('shop'), 'public_html', 'notes.txt', $local, uploaded: false)->code);
        \unlink($local);

        $download = $this->owner()->get('/panel/files/download?path=public_html/notes.txt');
        self::assertSame(200, $download->status());
        self::assertStringContainsString('filename="notes.txt"', (string) $download->header('Content-Disposition'));
        self::assertSame('nosniff', $download->header('X-Content-Type-Options'));
        $staged = \glob($this->staging . '/*') ?: [];
        self::assertCount(1, $staged, 'the download waits in staging until it is sent');
        self::assertSame('uploaded bytes', \file_get_contents($staged[0]));
        \touch($staged[0], \time() - 2 * FileManager::STAGED_SECONDS);
        self::assertSame(1, $files->sweep(), 'files:sweep removes what nobody collected');
    }

    public function test_disk_usage_shows_what_takes_the_space(): void
    {
        $this->server->homes['shop']['public_html/video.mp4'] = \str_repeat('v', 5000);
        $page = $this->owner()->get('/panel/files/usage')->body();
        self::assertMatchesRegularExpression('/public_html.*\.bashrc/s', $page, 'largest first');
        self::assertStringContainsString('/panel/files/usage?path=public_html', $page);
    }

    public function test_a_suspended_accounts_files_are_for_administrators(): void
    {
        $owner = $this->owner();
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $this->app->container()->get(Provisioner::class)->suspend($root, null, (int) $this->account('shop')['id'], 'unpaid');
        self::assertSame(302, $owner->get('/panel/files')->status(), 'the owner is signed out');
        self::assertNotSame(201, $owner->post('/panel/files/folder', ['dir' => '', 'name' => 'x'])->status());
        self::assertArrayNotHasKey('x', $this->server->homes['shop']);

        // An administrator can still look, and clean up.
        $token = $this->rootToken();
        self::assertSame(200, $this->api('GET', '/admin/api/v1/accounts/shop/files', $token)->status());
        self::assertSame(200, $this->api('POST', '/admin/api/v1/accounts/shop/files/delete', $token, ['paths' => ['.bashrc']])->status());
        self::assertArrayNotHasKey('.bashrc', $this->server->homes['shop']);
    }

    public function test_without_the_feature_there_is_no_file_manager(): void
    {
        $this->makeAccount('plain', $this->packageFor(null, 'Bare', 1024, ['domains']), null);
        $plain = $this->signedIn('user', 'plain', 'plain-owner-password');
        self::assertContains($plain->get('/panel/files')->status(), [403, 404]);
    }

    public function test_the_api_does_the_same(): void
    {
        $token = $this->rootToken();
        $base = '/admin/api/v1/accounts/shop/files';
        $list = $this->json($this->api('GET', $base . '?path=public_html', $token))['data'];
        self::assertSame(['wp', 'index.php'], \array_column($list['entries'], 'name'));

        $file = $this->json($this->api('GET', $base . '/content?path=public_html/index.php', $token))['data'];
        $saved = $this->api('PUT', $base . '/content', $token, ['path' => 'public_html/index.php', 'content' => 'new', 'expect' => $file['sha256']]);
        self::assertSame(200, $saved->status());
        self::assertSame(409, $this->api('PUT', $base . '/content', $token, ['path' => 'public_html/index.php', 'content' => 'x', 'expect' => $file['sha256']])->status());
        self::assertSame(201, $this->api('POST', $base . '/mkdir', $token, ['path' => 'public_html/api'])->status());
        self::assertSame(409, $this->api('POST', $base . '/mkdir', $token, ['path' => 'public_html/api'])->status());
        self::assertSame(403, $this->api('POST', $base . '/delete', $token, ['paths' => ['mail']])->status());
        self::assertSame(400, $this->api('POST', $base . '/delete', $token, ['paths' => ['../x']])->status());
        self::assertSame(404, $this->api('GET', $base . '/content?path=nowhere.txt', $token)->status());
        self::assertSame(200, $this->api('POST', $base . '/delete', $token, ['paths' => ['public_html/api']])->status());
        self::assertSame(422, $this->api('POST', $base . '/chmod', $token, ['paths' => ['.bashrc'], 'mode' => '4755'])->status());
        self::assertSame(404, $this->api('GET', '/admin/api/v1/accounts/nobody/files', $token)->status());

        $usage = $this->json($this->api('GET', $base . '/usage', $token))['data'];
        $bytes = \array_column($usage['entries'], 'bytes');
        $sorted = $bytes;
        \rsort($sorted);
        self::assertSame($sorted, $bytes, 'largest first');
        self::assertContains('public_html', \array_column($usage['entries'], 'name'));
    }

    private function owner(): Browser
    {
        return $this->signedIn('user', 'shop', 'shop-owner-password');
    }

    private function rootToken(): string
    {
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;

        return $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'ops')['token'];
    }
}
