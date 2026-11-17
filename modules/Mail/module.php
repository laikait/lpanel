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
use App\Modules\Mail\Commands\MailCommands;
use App\Modules\Mail\Http\MailApi;
use App\Modules\Mail\Http\MailClientPages;
use App\Modules\Mail\Http\MailPages;
use App\Modules\Mail\Http\MailToolPages;
use App\Modules\Mail\MailActions;
use App\Modules\Mail\MailChecks;
use App\Modules\Mail\MailHosts;
use App\Modules\Mail\MailSetup;
use App\Modules\Mail\Webmail;
use App\Modules\Mail\Mailboxes;
use App\Modules\Mail\MailDomains;
use App\Modules\Mail\MailListeners;
use App\Modules\Mail\MailQueue;
use App\Modules\Mail\MailSync;
use App\Modules\Mail\MailUsage;

/**
 * Mail: each account's domains receive and send mail. Mailboxes (IMAP,
 * POP3, SMTP with TLS), forwarders, catch-alls, autoresponders and filters;
 * spam settings and a sending limit for each account; DKIM keys made on the
 * server and published with DMARC in the account's zones; spam and virus
 * filtering, the relay host, the queue and delivery tracking; webmail
 * (Roundcube) with sign-on from the User panel, mail clients' setup
 * (autoconfig, autodiscover, a page per mailbox), a certificate for each
 * mail domain, and the deliverability checks. Exim, Dovecot, Rspamd, ClamAV
 * and Roundcube do the work; the agent writes their data.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Mail')
        ->version('0.3.0')
        ->description('Mailboxes, forwarders, catch-alls, autoresponders, filters, spam filtering and DKIM for each account\'s domains.');

    $module->requires('Panel', '^0.1')
        ->requires('Audit', '^0.1')
        ->requires('Identity', '^0.1')
        ->requires('Agent', '^0.1')
        ->requires('Packages', '^0.1')
        ->requires('Accounts', '^0.1')
        ->requires('Subusers', '^0.1')
        ->requires('Web', '^0.1')
        ->requires('Dns', '^0.1');

    $module->config([
        // Where the browser goes for webmail (the User panel's own port).
        'webmail_url' => Env::string('LPANEL_WEBMAIL_URL', '/webmail/'),
        // One-time sign-on tickets, shared with Roundcube's PHP pool.
        'webmail_tickets' => Env::string('LPANEL_WEBMAIL_TICKETS', '/var/lib/lpanel-webmail/tickets'),
    ]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(Mailboxes::class);
        $services->singleton(MailSync::class);
        $services->singleton(MailDomains::class);
        $services->singleton(MailActions::class);
        $services->singleton(MailUsage::class);
        $services->singleton(MailListeners::class);
        $services->singleton(MailQueue::class);
        $services->singleton(MailHosts::class);
        $services->singleton(MailSetup::class);
        $services->singleton(MailChecks::class);
        $services->singleton(Webmail::class);
        $services->bind(MailPages::class);
        $services->bind(MailToolPages::class);
        $services->bind(MailClientPages::class);
        $services->bind(MailApi::class);
        $services->bind(MailCommands::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('server.mail', 'See the mail servers\' state and every account\'s mail domains; set spam and virus filtering and the relay host; manage the queue and track every message.');
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
            $routes->post('/mailboxes/{id}/filters', [MailPages::class, 'filter'])->name('.filter')->where('id', $id);
            $routes->post('/filters/{id}/delete', [MailPages::class, 'unfilter'])->name('.filter.delete')->where('id', $id);
            $routes->post('/filters/{id}/move', [MailPages::class, 'moveFilter'])->name('.filter.move')->where('id', $id);
            $routes->get('/spam', [MailToolPages::class, 'spam'])->name('.spam');
            $routes->post('/spam', [MailToolPages::class, 'saveSpam'])->name('.spam.save');
            $routes->get('/track', [MailToolPages::class, 'track'])->name('.track');
            $routes->post('/mailboxes/{id}/webmail', [MailClientPages::class, 'webmail'])->name('.webmail')->where('id', $id)->meta(['rate_limit' => '30/1m']);
            $routes->get('/mailboxes/{id}/setup', [MailClientPages::class, 'setupPage'])->name('.setup')->where('id', $id);
            $routes->get('/mailboxes/{id}/setup/profile', [MailClientPages::class, 'profile'])->name('.profile')->where('id', $id);
            $routes->get('/checks', [MailClientPages::class, 'checks'])->name('.checks')->meta(['rate_limit' => '20/1m']);
        }, name: 'user.mail', meta: ['can' => 'feature.email']);

        $routes->group('/admin/email', static function (RouteCollector $routes): void {
            $routes->get('', [MailPages::class, 'admin'])->name('');
            $routes->post('/settings', [MailPages::class, 'settings'])->name('.settings');
            $routes->post('/filtering', [MailPages::class, 'filtering'])->name('.filtering');
            $routes->get('/queue', [MailToolPages::class, 'queuePage'])->name('.queue');
            $routes->post('/queue', [MailToolPages::class, 'queueAct'])->name('.queue.act');
            $routes->get('/queue/{id}', [MailToolPages::class, 'queueMessage'])->name('.queue.view')->where('id', '[0-9A-Za-z-]{16,23}');
            $routes->get('/track', [MailToolPages::class, 'adminTrack'])->name('.track');
            $routes->get('/checks', [MailClientPages::class, 'adminChecks'])->name('.checks')->meta(['rate_limit' => '20/1m']);
        }, name: 'admin.mail', meta: ['can' => 'server.mail']);

        // Mail clients' own setup requests, passed on by customers' sites
        // (the panel's nginx serves these on 127.0.0.1:7449 only). Public:
        // they carry only servers and ports.
        $routes->group('/mail-setup', static function (RouteCollector $routes): void {
            $routes->get('/autoconfig', [MailClientPages::class, 'autoconfig'])->name('.autoconfig');
            $routes->get('/autodiscover', [MailClientPages::class, 'autodiscover'])->name('.autodiscover.get');
            $routes->post('/autodiscover', [MailClientPages::class, 'autodiscover'])->name('.autodiscover');
        }, name: 'mail_setup', meta: ['csrf' => false, 'rate_limit' => '600/1m']);

        $routes->group('/admin/api/v1/email', static function (RouteCollector $routes): void {
            $routes->get('', [MailApi::class, 'server'])->name('');
            $routes->put('/settings', [MailApi::class, 'settings'])->name('.settings');
            $routes->put('/filtering', [MailApi::class, 'filtering'])->name('.filtering');
            $routes->get('/queue', [MailApi::class, 'queue'])->name('.queue');
            $routes->post('/queue', [MailApi::class, 'queueAct'])->name('.queue.act');
            $routes->get('/queue/{id}', [MailApi::class, 'queueMessage'])->name('.queue.view')->where('id', '[0-9A-Za-z-]{16,23}');
            $routes->get('/track', [MailApi::class, 'track'])->name('.track');
            $routes->get('/checks', [MailApi::class, 'serverChecks'])->name('.checks');
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
            $routes->get('/mailboxes/{address}/filters', [MailApi::class, 'filters'])->name('.filters')->where('username', $user)->where('address', $address);
            $routes->post('/mailboxes/{address}/filters', [MailApi::class, 'addFilter'])->name('.filters.store')->where('username', $user)->where('address', $address)->meta($modify);
            $routes->delete('/filters/{id}', [MailApi::class, 'removeFilter'])->name('.filters.destroy')->where('username', $user)->where('id', $id)->meta($modify);
            $routes->get('/spam', [MailApi::class, 'spam'])->name('.spam')->where('username', $user);
            $routes->put('/spam', [MailApi::class, 'setSpam'])->name('.spam.update')->where('username', $user)->meta($modify);
            $routes->get('/track', [MailApi::class, 'accountTrack'])->name('.track')->where('username', $user);
            $routes->get('/checks', [MailApi::class, 'accountChecks'])->name('.checks')->where('username', $user);
            $routes->get('/mailboxes/{address}/setup', [MailApi::class, 'setup'])->name('.setup')->where('username', $user)->where('address', $address);
            $routes->post('/mailboxes/{address}/webmail', [MailApi::class, 'webmail'])->name('.webmail')->where('username', $user)->where('address', $address)->meta($modify);
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
            ->describe('Show or set the server\'s relay host for outgoing mail, and the login to it.')
            ->option('smarthost', 'host or host:port; "none" sends straight to each domain\'s mail server.')
            ->option('relay-user', 'The user to log in to the relay host with ("none": no login). The password is read from standard input.');
        $commands->add('mail:filtering', [MailCommands::class, 'filtering'])
            ->describe('Show or set spam filtering (Rspamd), virus scanning (ClamAV) and the score that refuses mail.')
            ->option('spam', 'on or off.')
            ->option('virus', 'on or off (needs spam filtering).')
            ->option('reject', 'The score at which mail from outside is refused (5–100). Default 15.');
        $commands->add('mail:spam', [MailCommands::class, 'spam'])
            ->describe('Show or set how an account\'s mailboxes file spam, and its allowed and blocked senders.')
            ->argument('username', 'The account.')
            ->option('junk', 'File spam in Junk from this score (such as 6 or 6.5); "off" files none.')
            ->option('delete', 'Delete spam from this score; "off" deletes none.')
            ->option('allow', 'Senders never filed as spam: addresses or @domains, comma-separated ("none" empties it).')
            ->option('block', 'Senders whose mail is thrown away, the same way.');
        $commands->add('mail:filter', [MailCommands::class, 'filter'])
            ->describe('List a mailbox\'s filters, add one, or remove one with --remove.')
            ->argument('username', 'The account.')
            ->argument('address', 'The mailbox.')
            ->option('field', 'from, to or subject.')
            ->option('test', 'contains, is, begins or ends.')
            ->option('value', 'What it looks for.')
            ->option('action', 'folder, forward or discard.')
            ->option('target', 'The folder, or the address to forward a copy to.')
            ->option('remove', 'The id of a filter to remove.')
            ->flag('stop', 'Later filters do not run on what this one matches.');
        $commands->add('mail:queue', [MailCommands::class, 'queue'])
            ->describe('List Exim\'s queue, show a message, or deliver, remove, freeze or thaw messages (or flush them all).')
            ->argument('action', 'list (default), show, deliver, remove, freeze, thaw or flush.', required: false)
            ->argument('ids', 'Message ids, comma-separated.', required: false)
            ->option('search', 'Part of a sender, recipient or id.')
            ->flag('yes', 'Do not ask before removing.');
        $commands->add('mail:track', [MailCommands::class, 'track'])
            ->describe('Show what became of each message, from Exim\'s log.')
            ->option('account', 'Only this account\'s mail.')
            ->option('search', 'Part of an address or message id.')
            ->option('status', 'delivered, deferred, failed, rejected or pending.')
            ->option('days', 'Look back this many days (1, 3, 7, 14 or 30). Default 7.');
        $commands->add('mail:webmail', [MailCommands::class, 'webmail'])
            ->describe('Print a one-time address that opens webmail as a mailbox, with no password (good for a minute).')
            ->argument('username', 'The account.')
            ->argument('address', 'The mailbox.');
        $commands->add('mail:setup', [MailCommands::class, 'setup'])
            ->describe('Show what a mail client needs for a mailbox: server, ports and login.')
            ->argument('username', 'The account.')
            ->argument('address', 'The mailbox.');
        $commands->add('mail:check', [MailCommands::class, 'check'])
            ->describe('Check deliverability: SPF, DKIM, DMARC and MX of an account\'s mail domains, and this server\'s reverse name and blocklists.')
            ->argument('username', 'The account (none: only the server).', required: false)
            ->option('domain', 'Only this one of its domains.');
        $commands->add('mail:info', [MailCommands::class, 'info'])
            ->describe('Show the mail servers\' state, filtering and the queue.');
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
        $hooks->add('web.applied', [$listeners, 'webApplied'], 20, 'Mail', 1);
        $filters->add('web.state', [$listeners, 'webState'], 20, 'Mail', 2);
        $filters->add('accounts.show', [$listeners, 'accountShow'], 25, 'Mail', 3);
        $filters->add('panel.dashboard', [$listeners, 'dashboard'], 35, 'Mail', 3);
    });
};
