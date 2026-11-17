<?php

declare(strict_types=1);

use App\Engine\Cli\CommandCollector;
use App\Engine\Config\Env;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Modules\Files\Commands\FileCommands;
use App\Modules\Files\FileManager;
use App\Modules\Files\Http\FileApi;
use App\Modules\Files\Http\FilePages;

/**
 * Files: the file manager for each account's home (browse, edit, upload,
 * download, move, copy, delete, permissions, archives) and the disk usage
 * explorer. The agent does every operation as the account's own user.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Files')
        ->version('0.1.0')
        ->description('The file manager and disk usage explorer for each account.');

    $module->requires('Panel', '^0.1')
        ->requires('Audit', '^0.1')
        ->requires('Identity', '^0.1')
        ->requires('Agent', '^0.1')
        ->requires('Packages', '^0.1')
        ->requires('Accounts', '^0.1')
        ->requires('Subusers', '^0.1');

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
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('files:sweep')->hourly()->withoutOverlapping(600);
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'user', 'section' => 'Panel.section_hosting', 'key' => 'files', 'label' => 'Files.title', 'icon' => 'folder', 'route' => 'user.files', 'can' => 'feature.file_manager', 'order' => 18];

        return $items;
    });
};
