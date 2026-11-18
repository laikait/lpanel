<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Modules\Accounts\Provisioner;
use App\Modules\Files\Ssh\SshKeyGen;
use App\Modules\Files\Ssh\SshKeys;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M7c: SSH keys for the jailed shell and SFTP, in the User panel, the API
 * and the CLI, over the agent's ssh.apply; and the shell a package gives.
 */
final class SshTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->rootPassword($this->app, 'root-password-123');
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->makeAccount('shop', $this->packageFor(null, 'Shell', 2048, ['domains', 'file_manager', 'ssh']), null);
    }

    public function test_packages_give_a_jailed_shell_sftp_alone_or_nothing(): void
    {
        self::assertSame('jail', $this->server->users['shop']['shell']);
        $this->makeAccount('files', $this->packageFor(null, 'Files', 1024, ['domains', 'sftp']), null);
        self::assertSame('sftp', $this->server->users['files']['shell']);
        $this->makeAccount('plain', $this->packageFor(null, 'Bare', 1024, ['domains']), null);
        self::assertSame('none', $this->server->users['plain']['shell']);

        // The page and its menu item: for ssh, for sftp, and not without either.
        $owner = $this->owner();
        self::assertStringContainsString('Shell and SFTP', $owner->get('/panel/ssh')->body());
        self::assertStringContainsString('href="/panel/ssh"', $owner->get('/panel')->body());
        $files = $this->signedIn('user', 'files', 'files-owner-password');
        $page = $files->get('/panel/ssh')->body();
        self::assertStringContainsString('SFTP only', $page);
        self::assertStringNotContainsString('ssh files@', $page, 'no shell command is offered');
        self::assertStringContainsString('href="/panel/ssh"', $files->get('/panel')->body());
        $plain = $this->signedIn('user', 'plain', 'plain-owner-password');
        self::assertSame(403, $plain->get('/panel/ssh')->status());
        self::assertStringNotContainsString('href="/panel/ssh"', $plain->get('/panel')->body());
    }

    public function test_the_owner_adds_a_public_key(): void
    {
        $public = SshKeyGen::ed25519('x')['public'];
        $owner = $this->owner();
        self::assertStringContainsString('No keys yet', $owner->get('/panel/ssh')->body());

        $added = $owner->post('/panel/ssh/keys', ['key' => "ssh-ed25519 {$public} me@laptop\n", 'name' => '']);
        self::assertSame(303, $added->status());
        $sent = $this->server->sshKeys['shop'];
        self::assertSame([['type' => 'ssh-ed25519', 'key' => $public, 'comment' => 'me@laptop']], $sent, 'the key\'s comment names it');
        $fingerprint = SshKeys::fingerprint((string) \base64_decode($public, true));
        self::assertStringContainsString($fingerprint, $owner->get('/panel/ssh')->body());
        $audit = \array_values(\array_filter($this->auditRows(), static fn(array $r): bool => $r['action'] === 'ssh.key_add'));
        self::assertSame('ok', $audit[0]['outcome']);
        self::assertStringContainsString($fingerprint, (string) $audit[0]['details']);

        foreach ([
            "ssh-ed25519 {$public} again",                          // already added
            'ssh-dss AAAAB3NzaC1kc3M= old',                        // DSA
            "-----BEGIN OPENSSH PRIVATE KEY-----\nb3Blbg==\n-----END OPENSSH PRIVATE KEY-----",
            'not a key at all',
            "ssh-rsa {$public} mismatched type",
            "ssh-ed25519 {$public} a\nssh-ed25519 {$public} b",   // two lines
        ] as $bad) {
            self::assertSame(422, $owner->post('/panel/ssh/keys', ['key' => $bad, 'name' => ''])->status(), $bad);
        }

        self::assertSame(422, $owner->post('/panel/ssh/keys', ['key' => 'ssh-ed25519 ' . SshKeyGen::ed25519('y')['public'], 'name' => 'me@laptop'])->status(), 'a name is used once');
        self::assertCount(1, $this->server->sshKeys['shop']);
    }

    public function test_a_key_made_by_the_panel_is_shown_once_and_never_kept(): void
    {
        $owner = $this->owner();
        $page = $owner->post('/panel/ssh/generate', ['name' => 'work laptop']);
        self::assertSame(200, $page->status());
        self::assertStringContainsString('-----BEGIN OPENSSH PRIVATE KEY-----', $page->body());
        self::assertStringContainsString('no-store', (string) $page->header('Cache-Control'));
        self::assertMatchesRegularExpression('/-----BEGIN OPENSSH PRIVATE KEY-----\n([A-Za-z0-9+\/=\n]+)-----END/', $page->body());

        self::assertCount(1, $this->server->sshKeys['shop']);
        self::assertSame('work laptop', $this->server->sshKeys['shop'][0]['comment']);
        $row = $this->db()->table('ssh_keys')->where('name', 'work laptop')->first();
        self::assertNotNull($row);
        self::assertStringNotContainsString('PRIVATE', \json_encode($row, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('PRIVATE', \json_encode($this->auditRows(), \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('PRIVATE KEY', $owner->get('/panel/ssh')->body(), 'gone once the page is left');

        // The private key matches the public key that was added.
        \preg_match('/-----BEGIN OPENSSH PRIVATE KEY-----\n([A-Za-z0-9+\/=\n]+)-----END/', $page->body(), $m);
        $file = (string) \base64_decode(\str_replace("\n", '', $m[1] ?? ''), true);
        self::assertStringStartsWith("openssh-key-v1\0", $file);
        self::assertStringContainsString((string) \base64_decode((string) $row['public_key'], true), $file);

        self::assertSame(422, $owner->post('/panel/ssh/generate', ['name' => ''])->status());
    }

    public function test_removal_and_a_refused_change(): void
    {
        $owner = $this->owner();
        $owner->post('/panel/ssh/keys', ['key' => 'ssh-ed25519 ' . SshKeyGen::ed25519('a')['public'], 'name' => 'desk']);
        $id = $this->keyId('desk');

        $this->server->sshRefuse = true;
        $owner->post('/panel/ssh/keys', ['key' => 'ssh-ed25519 ' . SshKeyGen::ed25519('b')['public'], 'name' => 'phone']);
        self::assertNull($this->db()->table('ssh_keys')->where('name', 'phone')->first(), 'a refused key is not kept');
        $failed = \array_values(\array_filter($this->auditRows(), static fn(array $r): bool => $r['action'] === 'ssh.key_add' && $r['outcome'] === 'failed'));
        self::assertCount(1, $failed);
        $this->server->sshRefuse = false;

        self::assertSame(303, $owner->post('/panel/ssh/keys/' . $id . '/delete')->status());
        self::assertArrayNotHasKey('shop', $this->server->sshKeys, 'the last key gone, the file goes');
        self::assertSame(404, $owner->post('/panel/ssh/keys/' . $id . '/delete')->status());
    }

    public function test_another_accounts_key_cannot_be_touched(): void
    {
        $this->makeAccount('blog', $this->packageFor(null, 'Shell2', 1024, ['ssh']), null);
        $blog = $this->signedIn('user', 'blog', 'blog-owner-password');
        $blog->post('/panel/ssh/keys', ['key' => 'ssh-ed25519 ' . SshKeyGen::ed25519('a')['public'], 'name' => 'blog key']);
        $id = $this->keyId('blog key');

        self::assertSame(404, $this->owner()->post('/panel/ssh/keys/' . $id . '/delete')->status());
        self::assertCount(1, $this->server->sshKeys['blog']);
    }

    public function test_terminate_forgets_the_keys(): void
    {
        $this->owner()->post('/panel/ssh/keys', ['key' => 'ssh-ed25519 ' . SshKeyGen::ed25519('a')['public'], 'name' => 'desk']);
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $provisioner = $this->app->container()->get(Provisioner::class);
        $provisioner->terminate($root, null, (int) $this->account('shop')['id']);
        $provisioner->settleAll();
        self::assertArrayNotHasKey('shop', $this->server->sshKeys);
        self::assertSame([], $this->db()->table('ssh_keys')->get());
        self::assertSame([], $this->db()->table('ssh_accounts')->get());
    }

    public function test_the_api_does_the_same(): void
    {
        $token = $this->rootToken();
        $base = '/admin/api/v1/accounts/shop/ssh-keys';
        $public = SshKeyGen::ed25519('a')['public'];
        $made = $this->api('POST', $base, $token, ['key' => 'ssh-ed25519 ' . $public, 'name' => 'ci']);
        self::assertSame(201, $made->status());
        $data = $this->json($made)['data'];
        self::assertSame('jail', $data['access']);
        self::assertSame('ci', $data['keys'][0]['name']);
        $fingerprint = $data['keys'][0]['fingerprint'];

        $generated = $this->api('POST', $base, $token, ['generate' => true, 'name' => 'deploy']);
        self::assertSame(201, $generated->status());
        self::assertStringStartsWith('-----BEGIN OPENSSH PRIVATE KEY-----', $this->json($generated)['data']['private_key']);
        self::assertStringNotContainsString('PRIVATE', $this->api('GET', $base, $token)->body());

        self::assertSame(422, $this->api('POST', $base, $token, ['key' => 'nonsense'])->status());
        self::assertSame(204, $this->api('DELETE', $base . '/' . \strtr($fingerprint, '+/', '-_'), $token)->status(), 'by fingerprint, URL-safe');
        self::assertSame(204, $this->api('DELETE', $base . '/deploy', $token)->status());
        self::assertSame(404, $this->api('DELETE', $base . '/deploy', $token)->status());

        $this->makeAccount('plain', $this->packageFor(null, 'Bare', 1024, ['domains']), null);
        self::assertSame(409, $this->api('POST', '/admin/api/v1/accounts/plain/ssh-keys', $token, ['key' => 'ssh-ed25519 ' . $public])->status());
    }


    private function keyId(string $name): int
    {
        return (int) ($this->db()->table('ssh_keys')->where('name', $name)->first() ?? throw new \LogicException('no key ' . $name))['id'];
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
