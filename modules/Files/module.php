<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Cli\CommandCollector;
use App\Engine\Config\Env;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Modules\Files\Commands\FileCommands;
use App\Modules\Files\Commands\FtpCommands;
use App\Modules\Files\Commands\SshCommands;
use App\Modules\Files\FileManager;
use App\Modules\Files\Ftp\FtpActions;
use App\Modules\Files\Ftp\FtpListeners;
use App\Modules\Files\Ftp\FtpSettings;
use App\Modules\Files\Ftp\FtpSync;
use App\Modules\Files\Ftp\FtpUsers;
use App\Modules\Files\Http\FileApi;
use App\Modules\Files\Http\FilePages;
use App\Modules\Files\Http\FtpApi;
use App\Modules\Files\Http\FtpPages;
use App\Modules\Files\Http\SshApi;
use App\Modules\Files\Http\SshPages;
use App\Modules\Files\Ssh\SshActions;
use App\Modules\Files\Ssh\SshKeys;
use App\Modules\Files\Ssh\SshListeners;
use App\Modules\Files\Ssh\SshSync;

/**
 * Files: the file manager for each account's home (browse, edit, upload,
 * download, move, copy, delete, permissions, archives), the disk usage
 * explorer, FTP logins on Pure-FTPd, each shut in a folder of the home, and
 * SSH keys for the jailed shell and SFTP. The agent does every file
 * operation as the account's own user, and FTP and SSH logins work as it too.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Files')
        ->version('0.1.0')
        ->description('The file manager, disk usage explorer, FTP logins and SSH keys for each account.');

    $module->requires('Panel', '^0.1')
        ->requires('Audit', '^0.1')
        ->requires('Identity', '^0.1')
        ->requires('Agent', '^0.1')
        ->requires('Packages', '^0.1')
        ->requires('Accounts', '^0.1')
        ->requires('Subusers', '^0.1')
        ->requires('Web', '^0.1');

    $module->config([
        // Uploads, saved files and downloads on their way to and from the
        // agent; only the panel and root can open it.
        'staging' => Env::string('LPANEL_FILES_STAGING', '/var/lib/lpanel/files'),
    ]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(FileManager::class);
        $services->bind(FilePages::class);
        $services->bind(FileApi::class);
        $services->bind(FileCommands::class);
        $services->singleton(FtpUsers::class);
        $services->singleton(FtpSync::class);
        $services->singleton(FtpSettings::class);
        $services->singleton(FtpActions::class);
        $services->singleton(FtpListeners::class);
        $services->bind(FtpPages::class);
        $services->bind(FtpApi::class);
        $services->bind(FtpCommands::class);
        $services->singleton(SshKeys::class);
        $services->singleton(SshSync::class);
        $services->singleton(SshActions::class);
        $services->singleton(SshListeners::class);
        $services->bind(SshPages::class);
        $services->bind(SshApi::class);
        $services->bind(SshCommands::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('server.ftp', 'See Pure-FTPd\'s state and every FTP session, end sessions, and change the server\'s FTP settings.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->group('/panel/files', static function (RouteCollector $routes): void {
            $routes->get('', [FilePages::class, 'index'])->name('');
            $routes->get('/edit', [FilePages::class, 'edit'])->name('.edit');
            $routes->get('/usage', [FilePages::class, 'usage'])->name('.usage');
            $routes->get('/download', [FilePages::class, 'download'])->name('.download');
            $routes->post('/save', [FilePages::class, 'save'])->name('.save');
            $routes->post('/folder', [FilePages::class, 'folder'])->name('.folder');
            $routes->post('/file', [FilePages::class, 'file'])->name('.file');
            $routes->post('/upload', [FilePages::class, 'upload'])->name('.upload');
            $routes->post('/rename', [FilePages::class, 'rename'])->name('.rename');
            $routes->post('/move', [FilePages::class, 'move'])->name('.move');
            $routes->post('/copy', [FilePages::class, 'copy'])->name('.copy');
            $routes->post('/delete', [FilePages::class, 'delete'])->name('.delete');
            $routes->post('/chmod', [FilePages::class, 'chmod'])->name('.chmod');
            $routes->post('/compress', [FilePages::class, 'compress'])->name('.compress');
            $routes->post('/extract', [FilePages::class, 'extract'])->name('.extract');
        }, name: 'user.files', meta: ['can' => 'feature.file_manager']);

        // Changing an account's files is changing the account.
        $routes->group('/admin/api/v1/accounts/{username}/files', static function (RouteCollector $routes): void {
            $user = '[a-z][a-z0-9]{1,15}';
            $modify = ['can' => 'accounts.modify'];
            $routes->get('', [FileApi::class, 'index'])->name('')->where('username', $user);
            $routes->get('/usage', [FileApi::class, 'usage'])->name('.usage')->where('username', $user);
            $routes->get('/content', [FileApi::class, 'content'])->name('.content')->where('username', $user);
            $routes->get('/download', [FileApi::class, 'download'])->name('.download')->where('username', $user);
            $routes->put('/content', [FileApi::class, 'save'])->name('.save')->where('username', $user)->meta($modify);
            $routes->post('/upload', [FileApi::class, 'upload'])->name('.upload')->where('username', $user)->meta($modify);
            foreach (['mkdir', 'create', 'rename', 'move', 'copy', 'delete', 'chmod', 'compress', 'extract'] as $action) {
                $routes->post('/' . $action, [FileApi::class, $action])->name('.' . $action)->where('username', $user)->meta($modify);
            }
        }, name: 'api.account_files', meta: ['can' => 'accounts.manage', 'csrf' => false, 'rate_limit' => '240/1m']);

        $id = '\d+';

        $routes->group('/panel/ftp', static function (RouteCollector $routes) use ($id): void {
            $routes->get('', [FtpPages::class, 'index'])->name('');
            $routes->post('', [FtpPages::class, 'store'])->name('.store');
            $routes->post('/{id}/password', [FtpPages::class, 'password'])->name('.password')->where('id', $id);
            $routes->post('/{id}/folder', [FtpPages::class, 'folder'])->name('.folder')->where('id', $id);
            $routes->post('/{id}/delete', [FtpPages::class, 'delete'])->name('.delete')->where('id', $id);
            $routes->post('/sessions/{pid}/kill', [FtpPages::class, 'kill'])->name('.kill')->where('pid', $id);
        }, name: 'user.ftp', meta: ['can' => 'feature.ftp']);

        $routes->group('/admin/ftp', static function (RouteCollector $routes) use ($id): void {
            $routes->get('', [FtpPages::class, 'admin'])->name('');
            $routes->post('/settings', [FtpPages::class, 'saveSettings'])->name('.settings');
            $routes->post('/sessions/{pid}/kill', [FtpPages::class, 'adminKill'])->name('.kill')->where('pid', $id);
        }, name: 'admin.ftp', meta: ['can' => 'server.ftp']);

        $routes->group('/admin/api/v1/ftp', static function (RouteCollector $routes) use ($id): void {
            $routes->get('', [FtpApi::class, 'server'])->name('');
            $routes->put('/settings', [FtpApi::class, 'saveSettings'])->name('.settings');
            $routes->delete('/sessions/{pid}', [FtpApi::class, 'serverKill'])->name('.kill')->where('pid', $id);
        }, name: 'api.ftp', meta: ['can' => 'server.ftp', 'csrf' => false, 'rate_limit' => '60/1m']);

        // Changing an account's FTP logins is changing the account.
        $routes->group('/admin/api/v1/accounts/{username}/ftp', static function (RouteCollector $routes) use ($id): void {
            $user = '[a-z][a-z0-9]{1,15}';
            $login = '[a-z0-9._%@-]{3,120}';
            $modify = ['can' => 'accounts.modify'];
            $routes->get('', [FtpApi::class, 'index'])->name('')->where('username', $user);
            $routes->post('', [FtpApi::class, 'store'])->name('.store')->where('username', $user)->meta($modify);
            $routes->get('/sessions', [FtpApi::class, 'sessions'])->name('.sessions')->where('username', $user);
            $routes->delete('/sessions/{pid}', [FtpApi::class, 'kill'])->name('.kill')->where('username', $user)->where('pid', $id)->meta($modify);
            $routes->put('/{login}', [FtpApi::class, 'update'])->name('.update')->where('username', $user)->where('login', $login)->meta($modify);
            $routes->delete('/{login}', [FtpApi::class, 'destroy'])->name('.destroy')->where('username', $user)->where('login', $login)->meta($modify);
        }, name: 'api.account_ftp', meta: ['can' => 'accounts.manage', 'csrf' => false, 'rate_limit' => '120/1m']);

        // SSH keys: an owner or a sub-user with ssh or sftp (SshPages checks
        // which; a route asks for one capability, or all of a list).
        $routes->group('/panel/ssh', static function (RouteCollector $routes) use ($id): void {
            $routes->get('', [SshPages::class, 'index'])->name('');
            $routes->post('/keys', [SshPages::class, 'store'])->name('.store');
            $routes->post('/generate', [SshPages::class, 'generate'])->name('.generate');
            $routes->post('/keys/{id}/delete', [SshPages::class, 'delete'])->name('.delete')->where('id', $id);
        }, name: 'user.ssh', meta: ['can' => 'user.panel']);

        // Changing an account's SSH keys is changing the account.
        $routes->group('/admin/api/v1/accounts/{username}/ssh-keys', static function (RouteCollector $routes): void {
            $user = '[a-z][a-z0-9]{1,15}';
            $modify = ['can' => 'accounts.modify'];
            $routes->get('', [SshApi::class, 'index'])->name('')->where('username', $user);
            $routes->post('', [SshApi::class, 'store'])->name('.store')->where('username', $user)->meta($modify);
            $routes->delete('/{key}', [SshApi::class, 'destroy'])->name('.destroy')->where('username', $user)->where('key', '[A-Za-z0-9:+/%=._@() -]{1,90}')->meta($modify);
        }, name: 'api.account_ssh', meta: ['can' => 'accounts.manage', 'csrf' => false, 'rate_limit' => '120/1m']);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $account = 'The account.';
        $commands->add('files:list', [FileCommands::class, 'list'])
            ->describe('List a folder in an account\'s home.')
            ->argument('username', $account)
            ->argument('path', 'The folder, from the home (default: the home).', required: false, default: '');
        $commands->add('files:read', [FileCommands::class, 'read'])
            ->describe('Print a text file (4 MB at most).')
            ->argument('username', $account)
            ->argument('path', 'The file, from the home.');
        $commands->add('files:write', [FileCommands::class, 'write'])
            ->describe('Replace a file\'s content with standard input (4 MB at most).')
            ->argument('username', $account)
            ->argument('path', 'The file, from the home.')
            ->option('expect', 'Only if the file\'s SHA-256 is still this.')
            ->flag('create', 'The file must be new.');
        $commands->add('files:upload', [FileCommands::class, 'upload'])
            ->describe('Copy a file from this machine into an account\'s home.')
            ->argument('username', $account)
            ->argument('local', 'The file on this machine.')
            ->argument('dir', 'The folder to put it in, from the home (default: the home).', required: false, default: '')
            ->option('name', 'Its name there (default: its name here).')
            ->flag('overwrite', 'Replace a file of that name.');
        $commands->add('files:download', [FileCommands::class, 'download'])
            ->describe('Copy a file out of an account\'s home to this machine.')
            ->argument('username', $account)
            ->argument('path', 'The file, from the home.')
            ->argument('local', 'Where to save it here (must not exist).');
        $commands->add('files:mkdir', [FileCommands::class, 'mkdir'])
            ->describe('Make a folder.')
            ->argument('username', $account)
            ->argument('path', 'The new folder, from the home.');
        $commands->add('files:rename', [FileCommands::class, 'rename'])
            ->describe('Rename a file or folder, or move it to another path.')
            ->argument('username', $account)
            ->argument('from', 'Its path, from the home.')
            ->argument('to', 'Its new path, from the home.');
        $commands->add('files:move', [FileCommands::class, 'move'])
            ->describe('Move a file or folder into another folder.')
            ->argument('username', $account)
            ->argument('path', 'What to move, from the home.')
            ->argument('to', 'The folder to move it into.');
        $commands->add('files:copy', [FileCommands::class, 'copy'])
            ->describe('Copy a file or folder into another folder.')
            ->argument('username', $account)
            ->argument('path', 'What to copy, from the home.')
            ->argument('to', 'The folder to copy it into.');
        $commands->add('files:delete', [FileCommands::class, 'delete'])
            ->describe('Delete a file, or a folder with everything in it.')
            ->argument('username', $account)
            ->argument('path', 'What to delete, from the home.');
        $commands->add('files:chmod', [FileCommands::class, 'chmod'])
            ->describe('Set a file\'s or folder\'s permissions (not recursively).')
            ->argument('username', $account)
            ->argument('path', 'The file or folder, from the home.')
            ->argument('mode', 'Such as 644 or 755.');
        $commands->add('files:compress', [FileCommands::class, 'compress'])
            ->describe('Pack a file or folder into a new archive (.zip, .tar.gz, .tgz or .tar).')
            ->argument('username', $account)
            ->argument('path', 'What to pack, from the home.')
            ->argument('to', 'The archive to make, from the home.');
        $commands->add('files:extract', [FileCommands::class, 'extract'])
            ->describe('Unpack an archive into a folder; files already there are left as they are.')
            ->argument('username', $account)
            ->argument('path', 'The archive, from the home.')
            ->argument('to', 'The folder to unpack into (made when missing; default: the home).', required: false, default: '');
        $commands->add('files:usage', [FileCommands::class, 'usage'])
            ->describe('Show what in a folder takes the account\'s disk space, largest first.')
            ->argument('username', $account)
            ->argument('path', 'The folder, from the home (default: the home).', required: false, default: '');
        $commands->add('files:sweep', [FileCommands::class, 'sweep'])
            ->describe('Remove staged uploads and downloads nobody collected.');

        $commands->add('ftp:list', [FtpCommands::class, 'list'])
            ->describe('List an account\'s FTP logins and their folders.')
            ->argument('username', $account);
        $commands->add('ftp:create', [FtpCommands::class, 'create'])
            ->describe('Make an FTP login; its password is read from standard input.')
            ->argument('username', $account)
            ->argument('login', 'A name and one of the account\'s domains, such as bob@example.com.')
            ->option('folder', 'The folder it is shut in, from the home (default: public_html; / for the whole home).');
        $commands->add('ftp:password', [FtpCommands::class, 'password'])
            ->describe('Change an FTP login\'s password, read from standard input.')
            ->argument('username', $account)
            ->argument('login', 'The login.');
        $commands->add('ftp:folder', [FtpCommands::class, 'folder'])
            ->describe('Change the folder an FTP login is shut in (made when missing).')
            ->argument('username', $account)
            ->argument('login', 'The login.')
            ->argument('folder', 'From the home; / for the whole home.');
        $commands->add('ftp:delete', [FtpCommands::class, 'delete'])
            ->describe('Remove an FTP login (its files stay).')
            ->argument('username', $account)
            ->argument('login', 'The login.');
        $commands->add('ftp:sessions', [FtpCommands::class, 'sessions'])
            ->describe('List the FTP sessions open now.')
            ->argument('username', 'Only this account\'s.', required: false);
        $commands->add('ftp:kill', [FtpCommands::class, 'kill'])
            ->describe('End an FTP session.')
            ->argument('pid', 'Its process id (ftp:sessions lists them).');
        $commands->add('ftp:settings', [FtpCommands::class, 'settings'])
            ->describe('Show the server\'s FTP settings, or change them (Pure-FTPd restarts).')
            ->option('tls', 'optional (plain FTP too), required (logins over TLS) or strict (logins and transfers over TLS).')
            ->option('passive-from', 'First passive port.')
            ->option('passive-to', 'Last passive port.')
            ->option('passive-ip', 'The address given out for passive transfers behind NAT ("" for none).')
            ->option('max-clients', 'Clients at once.')
            ->option('max-per-ip', 'Clients at once from one address.')
            ->flag('apply', 'Send the settings to the server again, as the panel has them.');
        $commands->add('ssh:keys', [SshCommands::class, 'keys'])
            ->describe('Show an account\'s SSH access and keys.')
            ->argument('username', $account);
        $commands->add('ssh:add', [SshCommands::class, 'add'])
            ->describe('Add an SSH public key, read from standard input (as in a .pub file).')
            ->argument('username', $account)
            ->option('name', 'What to call it (default: the key\'s own comment).');
        $commands->add('ssh:generate', [SshCommands::class, 'generate'])
            ->describe('Make an Ed25519 key pair: the public key is added, the private key printed (and not kept).')
            ->argument('username', $account)
            ->argument('name', 'What to call it.');
        $commands->add('ssh:delete', [SshCommands::class, 'delete'])
            ->describe('Remove an SSH key.')
            ->argument('username', $account)
            ->argument('key', 'Its name, or its fingerprint (SHA256:...).');
        $commands->add('ssh:settle', [SshCommands::class, 'settle'])
            ->describe('Send SSH key changes the agent has not answered yet.');
        $commands->add('ssh:sync', [SshCommands::class, 'sync'])
            ->describe('Send an account\'s SSH keys to the server again, as the panel has them.')
            ->argument('username', $account, required: false)
            ->flag('all', 'Every account.');
        $commands->add('ftp:settle', [FtpCommands::class, 'settle'])
            ->describe('Send FTP changes the agent has not answered yet.');
        $commands->add('ftp:sync', [FtpCommands::class, 'sync'])
            ->describe('Send an account\'s FTP logins to the server again, as the panel has them.')
            ->argument('username', $account, required: false)
            ->flag('all', 'Every account.');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('files:sweep')->hourly()->withoutOverlapping(600);
        $schedules->command('ftp:settle')->everyMinute()->withoutOverlapping(600);
        $schedules->command('ssh:settle')->everyMinute()->withoutOverlapping(600);
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'user', 'section' => 'Panel.section_hosting', 'key' => 'files', 'label' => 'Files.title', 'icon' => 'folder', 'route' => 'user.files', 'can' => 'feature.file_manager', 'order' => 18];
        $items[] = ['area' => 'user', 'section' => 'Panel.section_hosting', 'key' => 'ftp', 'label' => 'Files.ftp_title', 'icon' => 'upload', 'route' => 'user.ftp', 'can' => 'feature.ftp', 'order' => 19];
        $items[] = ['area' => 'user', 'section' => 'Panel.section_hosting', 'key' => 'ssh', 'label' => 'Files.ssh_title', 'icon' => 'terminal', 'route' => 'user.ssh', 'can' => ['feature.ssh', 'feature.sftp'], 'order' => 20];
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_server', 'key' => 'ftp', 'label' => 'Files.ftp_title', 'icon' => 'upload', 'route' => 'admin.ftp', 'can' => 'server.ftp', 'order' => 47];

        return $items;
    });

    $module->onBoot(static function (FtpListeners $listeners, SshListeners $ssh, HookEngine $hooks, FilterEngine $filters): void {
        $hooks->add('accounts.changed', [$listeners, 'accountChanged'], 20, 'Files', 2);
        $filters->add('accounts.show', [$listeners, 'accountShow'], 25, 'Files', 3);
        $hooks->add('accounts.changed', [$ssh, 'accountChanged'], 21, 'Files', 2);
        $filters->add('accounts.show', [$ssh, 'accountShow'], 26, 'Files', 3);
    });
};
