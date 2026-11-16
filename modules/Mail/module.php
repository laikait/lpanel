<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Modules\Mail\Commands\MailCommands;
use App\Modules\Mail\Http\MailApi;
use App\Modules\Mail\Http\MailPages;
use App\Modules\Mail\MailActions;
use App\Modules\Mail\Mailboxes;
use App\Modules\Mail\MailDomains;
use App\Modules\Mail\MailListeners;
use App\Modules\Mail\MailSync;
use App\Modules\Mail\MailUsage;

/**
 * Mail: each account's domains receive and send mail. Mailboxes (IMAP,
 * POP3, SMTP with TLS), forwarders, catch-alls and autoresponders; DKIM
 * keys made on the server and published with DMARC in the account's zones;
 * the server's relay host. Exim and Dovecot do the work; the agent writes
 * their data.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Mail')
        ->version('0.1.0')
        ->description('Mailboxes, forwarders, catch-alls, autoresponders and DKIM for each account\'s domains.');

    $module->requires('Panel', '^0.1')
        ->requires('Audit', '^0.1')
        ->requires('Identity', '^0.1')
        ->requires('Agent', '^0.1')
        ->requires('Packages', '^0.1')
        ->requires('Accounts', '^0.1')
        ->requires('Subusers', '^0.1')
        ->requires('Web', '^0.1')
        ->requires('Dns', '^0.1');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(Mailboxes::class);
        $services->singleton(MailSync::class);
        $services->singleton(MailDomains::class);
        $services->singleton(MailActions::class);
        $services->singleton(MailUsage::class);
        $services->singleton(MailListeners::class);
        $services->bind(MailPages::class);
        $services->bind(MailApi::class);
        $services->bind(MailCommands::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('server.mail', 'See Exim\'s and Dovecot\'s state and every account\'s mail domains, and set the relay host for outgoing mail.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $id = '\d+';

        $routes->group('/panel/email', static function (RouteCollector $routes) use ($id): void {
            $routes->get('', [MailPages::class, 'index'])->name('');
            $routes->post('/mailboxes', [MailPages::class, 'store'])->name('.store');
            $routes->post('/mailboxes/{id}/password', [MailPages::class, 'password'])->name('.password')->where('id', $id);
            $routes->post('/mailboxes/{id}/quota', [MailPages::class, 'quota'])->name('.quota')->where('id', $id);
            $routes->post('/mailboxes/{id}/responder', [MailPages::class, 'responder'])->name('.responder')->where('id', $id);
            $routes->post('/mailboxes/{id}/responder/delete', [MailPages::class, 'responderOff'])->name('.responder.delete')->where('id', $id);
            $routes->post('/mailboxes/{id}/delete', [MailPages::class, 'delete'])->name('.delete')->where('id', $id);
            $routes->post('/forwarders', [MailPages::class, 'forward'])->name('.forward');
            $routes->post('/forwarders/{id}/delete', [MailPages::class, 'unforward'])->name('.unforward')->where('id', $id);
            $routes->post('/domains/{id}', [MailPages::class, 'domain'])->name('.domain')->where('id', $id);
        }, name: 'user.mail', meta: ['can' => 'feature.email']);

        $routes->group('/admin/email', static function (RouteCollector $routes): void {
            $routes->get('', [MailPages::class, 'admin'])->name('');
            $routes->post('/settings', [MailPages::class, 'settings'])->name('.settings');
        }, name: 'admin.mail', meta: ['can' => 'server.mail']);

        $routes->group('/admin/api/v1/email', static function (RouteCollector $routes): void {
            $routes->get('', [MailApi::class, 'server'])->name('');
            $routes->put('/settings', [MailApi::class, 'settings'])->name('.settings');
        }, name: 'api.mail', meta: ['can' => 'server.mail', 'csrf' => false, 'rate_limit' => '60/1m']);

        // Changing an account's mail is changing the account.
        $routes->group('/admin/api/v1/accounts/{username}/email', static function (RouteCollector $routes) use ($id): void {
            $user = '[a-z][a-z0-9]{1,15}';
            $address = '[a-z0-9][a-z0-9._-]{0,63}@[a-z0-9.-]{1,253}';
            $domain = '[a-z0-9][a-z0-9.-]{0,252}';
            $modify = ['can' => 'accounts.modify'];
            $routes->get('', [MailApi::class, 'index'])->name('')->where('username', $user);
            $routes->post('/mailboxes', [MailApi::class, 'store'])->name('.store')->where('username', $user)->meta($modify);
            $routes->put('/mailboxes/{address}', [MailApi::class, 'update'])->name('.update')->where('username', $user)->where('address', $address)->meta($modify);
            $routes->delete('/mailboxes/{address}', [MailApi::class, 'destroy'])->name('.destroy')->where('username', $user)->where('address', $address)->meta($modify);
            $routes->put('/mailboxes/{address}/autoresponder', [MailApi::class, 'responder'])->name('.responder')->where('username', $user)->where('address', $address)->meta($modify);
            $routes->delete('/mailboxes/{address}/autoresponder', [MailApi::class, 'responderOff'])->name('.responder.destroy')->where('username', $user)->where('address', $address)->meta($modify);
            $routes->post('/forwarders', [MailApi::class, 'forward'])->name('.forward')->where('username', $user)->meta($modify);
            $routes->delete('/forwarders/{id}', [MailApi::class, 'unforward'])->name('.unforward')->where('username', $user)->where('id', $id)->meta($modify);
            $routes->put('/domains/{domain}', [MailApi::class, 'domain'])->name('.domain')->where('username', $user)->where('domain', $domain)->meta($modify);
        }, name: 'api.account_mail', meta: ['can' => 'accounts.manage', 'csrf' => false, 'rate_limit' => '120/1m']);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('mail:list', [MailCommands::class, 'list'])
            ->describe('List an account\'s mail domains, mailboxes and forwarders.')
            ->argument('username', 'The account.');
        $commands->add('mail:create', [MailCommands::class, 'create'])
            ->describe('Make a mailbox; its password is read from standard input.')
            ->argument('username', 'The account.')
            ->argument('address', 'Such as info@example.com.')
            ->option('quota', 'Its quota in MB (0: none of its own). Default 1024.');
        $commands->add('mail:password', [MailCommands::class, 'password'])
            ->describe('Change a mailbox\'s password, read from standard input.')
            ->argument('username', 'The account.')
            ->argument('address', 'The mailbox.');
        $commands->add('mail:quota', [MailCommands::class, 'quota'])
            ->describe('Change a mailbox\'s quota.')
            ->argument('username', 'The account.')
            ->argument('address', 'The mailbox.')
            ->argument('mb', 'MB (0: none of its own).');
        $commands->add('mail:delete', [MailCommands::class, 'delete'])
            ->describe('Delete a mailbox and its mail.')
            ->argument('username', 'The account.')
            ->argument('address', 'The mailbox.')
            ->flag('yes', 'Do not ask.');
        $commands->add('mail:forward', [MailCommands::class, 'forward'])
            ->describe('Forward mail for an address to another address.')
            ->argument('username', 'The account.')
            ->argument('address', 'Such as sales@example.com.')
            ->argument('to', 'Where it goes.');
        $commands->add('mail:unforward', [MailCommands::class, 'unforward'])
            ->describe('Stop forwarding mail for an address to another.')
            ->argument('username', 'The account.')
            ->argument('address', 'The address.')
            ->argument('to', 'The destination to stop.');
        $commands->add('mail:responder', [MailCommands::class, 'responder'])
            ->describe('Turn a mailbox\'s autoresponder on (its message is read from standard input), or off with --off.')
            ->argument('username', 'The account.')
            ->argument('address', 'The mailbox.')
            ->option('subject', 'The reply\'s subject.')
            ->option('from-name', 'The name the reply comes from.')
            ->option('days', 'Answer each sender once in this many days (1–30). Default 1.')
            ->option('start', 'First day it answers (YYYY-MM-DD).')
            ->option('end', 'Last day it answers (YYYY-MM-DD).')
            ->flag('off', 'Turn it off.');
        $commands->add('mail:domain', [MailCommands::class, 'domain'])
            ->describe('Set a mail domain\'s catch-all, and whether its mail is delivered here.')
            ->argument('username', 'The account.')
            ->argument('domain', 'The domain.')
            ->option('catchall', 'refuse, discard, or an address to forward unknown addresses to.')
            ->option('local', 'yes (mail is delivered here) or no (its MX is another server).');
        $commands->add('mail:settings', [MailCommands::class, 'settings'])
            ->describe('Show or set the server\'s relay host for outgoing mail.')
            ->option('smarthost', 'host or host:port; "none" sends straight to each domain\'s mail server.');
        $commands->add('mail:info', [MailCommands::class, 'info'])
            ->describe('Show Exim\'s and Dovecot\'s state and the queue.');
        $commands->add('mail:usage', [MailCommands::class, 'usage'])
            ->describe('Measure how much mail each mailbox holds.');
        $commands->add('mail:settle', [MailCommands::class, 'settle'])
            ->describe('Send mail changes the agent has not answered yet, and delete mail waiting to go.');
        $commands->add('mail:sync', [MailCommands::class, 'sync'])
            ->describe('Send an account\'s mail to the server again, as the panel has it.')
            ->argument('username', 'The account.', required: false)
            ->flag('all', 'Every account.');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('mail:settle')->everyMinute()->withoutOverlapping(600);
        $schedules->command('mail:usage')->everyFifteenMinutes()->withoutOverlapping(900);
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_server', 'key' => 'mail', 'label' => 'Mail.title', 'icon' => 'mail', 'route' => 'admin.mail', 'can' => 'server.mail', 'order' => 45];
        $items[] = ['area' => 'user', 'section' => 'Panel.section_hosting', 'key' => 'mail', 'label' => 'Mail.title', 'icon' => 'mail', 'route' => 'user.mail', 'can' => 'feature.email', 'order' => 22];

        return $items;
    });

    $module->onBoot(static function (MailListeners $listeners, HookEngine $hooks, FilterEngine $filters): void {
        $hooks->add('accounts.changed', [$listeners, 'accountChanged'], 40, 'Mail', 2);
        $hooks->add('web.changed', [$listeners, 'webChanged'], 20, 'Mail', 1);
        $hooks->add('packages.updated', [$listeners, 'packageUpdated'], 20, 'Mail', 1);
        $hooks->add('dns.zone_reset', [$listeners, 'zoneReset'], 10, 'Mail', 1);
        $filters->add('accounts.show', [$listeners, 'accountShow'], 25, 'Mail', 3);
        $filters->add('panel.dashboard', [$listeners, 'dashboard'], 35, 'Mail', 3);
    });
};
