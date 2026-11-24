<?php

declare(strict_types=1);

namespace App\Modules\Panel\Api;

/**
 * What the API says about itself: for each route (by its name) a summary and,
 * for the ones that take a body, what the body holds. ApiDocs turns this into
 * the `openapi` meta of the routes, and a test makes sure that every API route
 * is here and that nothing here names a route that is gone, so a new endpoint
 * cannot ship undocumented.
 *
 * A body is described in words, as the controllers' own notes do, not as a
 * schema: most of the API validates with its own rules (messages translated,
 * limits per package or reseller) and a second, parallel list of fields would
 * drift from them.
 */
final class ApiCatalogue
{
    /**
     * route name => [summary, body or '' (what a PUT/POST/DELETE reads), tag].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function all(): array
    {
        $t = [
            'Packages', 'Accounts', 'Resellers', 'Security', 'Backups', 'Databases', 'Cron', 'Logs', 'Alerts', 'Updates', 'Services',
            'PHP', 'SSL', 'Domains', 'DNS', 'Files', 'FTP and SSH', 'Email',
        ];
        [$pk, $ac, $rs, $sc, $bk, $db, $cr, $lg, $al, $up, $sv, $ph, $ss, $dm, $dn, $fl, $fs, $em] = $t;

        return [
            // ---- packages, accounts, resellers
            'api.packages' => ['List the packages', '', $pk],
            'api.packages.create' => ['Create a package', '{name, disk_mb, inodes, bandwidth_mb, bandwidth_action: block|warn, domains, subdomains, mailboxes, databases, ftp_accounts, mail_per_hour, features: [..]}; 0 is unlimited', $pk],
            'api.packages.show' => ['One package', '', $pk],
            'api.accounts' => ['List the accounts you can manage', '', $ac],
            'api.accounts.create' => ['Create an account (a user, a home, a site)', '{username, domain, password, package, email?, name?}', $ac],
            'api.accounts.show' => ['One account, with its usage', '', $ac],
            'api.accounts.package' => ['Move an account to another package', '{package}', $ac],
            'api.accounts.suspend' => ['Suspend an account (sites, mail and sign-in stop; data stays)', '{reason?}', $ac],
            'api.accounts.unsuspend' => ['Bring a suspended account back', '', $ac],
            'api.accounts.terminate' => ['Remove an account and everything it owns', '', $ac],
            'api.resellers' => ['List the resellers', '', $rs],
            'api.resellers.create' => ['Create a reseller', '{username, password, name?, email?, max_accounts, disk_mb, bandwidth_mb, oversell: bool, acl: [..]}', $rs],
            'api.resellers.show' => ['One reseller, with its limits and usage', '', $rs],
            'api.resellers.limits' => ['Change a reseller\'s limits; what is left out keeps its value', '{max_accounts?, disk_mb?, bandwidth_mb?, oversell?, acl?}', $rs],

            // ---- security
            'api.isolation' => ['Account isolation: what was chosen and what the server has', '', $sc],
            'api.isolation.update' => ['Change account isolation', '{hidepid?: bool, links?: bool}', $sc],
            'api.bruteforce' => ['Brute-force blocking: settings, the busiest addresses, the latest blocks', '', $sc],
            'api.bruteforce.update' => ['Change brute-force blocking', '{enabled?, threshold?, window?, duration?}', $sc],
            'api.firewall' => ['The firewall: state, ports, allow and deny lists, blocked countries', '', $sc],
            'api.firewall.ports' => ['Open a port, or limit it to addresses', '{port, proto, sources?}', $sc],
            'api.firewall.ports.close' => ['Close a port', '', $sc],
            'api.firewall.entries' => ['Add an address to the allow or deny list', '{list: allow|deny, address, note?, duration?}', $sc],
            'api.firewall.entries.delete' => ['Remove an address from a list', '', $sc],
            'api.firewall.countries' => ['Set the blocked countries (the whole list)', '{codes: ["CN", ..], refresh?}', $sc],
            'api.waf' => ['The web application firewall: state, settings, the latest events', '', $sc],
            'api.waf.update' => ['Change the web application firewall', '{mode?: off|detect|block, paranoia?: 1-4}', $sc],

            // ---- backups
            'api.backups' => ['Backup settings, destinations, the run going on, recent runs', '', $bk],
            'api.backups.settings' => ['Change the backup schedule and retention', '', $bk],
            'api.backups.add' => ['Add a backup destination', '', $bk],
            'api.backups.change' => ['Change a backup destination', '', $bk],
            'api.backups.remove' => ['Remove a backup destination (409 while the schedule uses it)', '', $bk],
            'api.backups.run' => ['Start a backup run', '{accounts?: [..], destination?: id}; 202', $bk],
            'api.backups.show_run' => ['One backup run', '', $bk],
            'api.backups.snapshots' => ['Every account\'s snapshots at a destination (?destination=)', '', $bk],
            'api.backups.plan' => ['Whether a snapshot\'s account can be made again here', '', $bk],
            'api.backups.recreate' => ['Make an account again from a snapshot', '{snapshot, destination?, package_id?}; 202', $bk],
            'api.backups.restores' => ['Recent restores and downloads', '', $bk],
            'api.backups.show_restore' => ['One restore or download', '', $bk],
            'api.account_backups' => ['An account\'s last backup and snapshots', '', $bk],
            'api.account_restores.files' => ['Browse the files of an account\'s snapshot (?snapshot=&path=)', '', $bk],
            'api.account_restores.restore' => ['Restore files, a mailbox, a database or the whole account from a snapshot', '{snapshot, kind: files|mailbox|database|account, paths?, databases?, folder?}; 202', $bk],
            'api.account_restores.download' => ['Prepare a download from a snapshot', '{snapshot, path?, database?}; 202', $bk],

            // ---- databases
            'api.databases' => ['Database server: version, running queries', '', $db],
            'api.databases.kill' => ['Stop a running query', '', $db],
            'api.account_databases' => ['An account\'s databases, users and remote hosts', '', $db],
            'api.account_databases.store' => ['Create a database', '{name}', $db],
            'api.account_databases.users' => ['Create a database user', '{name, password, grants?}', $db],
            'api.account_databases.users.password' => ['Change a database user\'s password', '{password}', $db],
            'api.account_databases.users.destroy' => ['Remove a database user', '', $db],
            'api.account_databases.grant' => ['Give a user rights on a database', '{privileges: [..]}', $db],
            'api.account_databases.revoke' => ['Take a user\'s rights on a database away', '', $db],
            'api.account_databases.hosts' => ['Allow a remote host to connect', '{host, note?}', $db],
            'api.account_databases.hosts.destroy' => ['Stop allowing a remote host', '', $db],
            'api.account_databases.rename' => ['Rename a database', '{name}', $db],
            'api.account_databases.destroy' => ['Drop a database', '', $db],

            // ---- cron
            'api.cron' => ['An account\'s scheduled jobs', '', $cr],
            'api.cron.add' => ['Schedule a command for an account (at most one run in five minutes)', '{schedule, command}', $cr],
            'api.cron.remove' => ['Remove a scheduled job', '', $cr],

            // ---- logs and statistics
            'api.logs.sources' => ['The logs that can be read (?account= for an account\'s)', '', $lg],
            'api.logs' => ['The latest lines of a log (?source=&account=&lines=100|200|500|1000&filter=)', '', $lg],
            'api.logs.statistics' => ['GoAccess statistics of the access logs (?account=&days=7|30|90&refresh=1)', '', $lg],

            // ---- alerts
            'api.alerts' => ['Alert channels, thresholds and the latest alerts (?limit=&category=)', '', $al],
            'api.alerts.add' => ['Add a channel: email, Telegram, Slack or webhook', '{kind: email|telegram|slack|webhook, name, to | token, chat | url, signing_key?, categories?, min_severity?, enabled?}; secrets are never returned', $al],
            'api.alerts.change' => ['Change what a channel receives', '{name?, categories?, min_severity?, enabled?}', $al],
            'api.alerts.remove' => ['Remove a channel', '', $al],
            'api.alerts.test' => ['Send a test alert to a channel', '', $al],
            'api.alerts.settings' => ['Change when the server speaks up', '{disk, memory, load, ssl, quiet}; 0 turns a threshold off', $al],
            'api.alerts.show' => ['One alert, with what became of it channel by channel', '', $al],

            // ---- updates
            'api.updates' => ['The release running, what the last check found, what runs now, settings, latest runs', '', $up],
            'api.updates.check' => ['Ask the server what can be updated; 202 and a run', '', $up],
            'api.updates.apply' => ['Install updates; 202 and a run', '{scope: all|security|php|packages, packages?: [..]}', $up],
            'api.updates.panel' => ['Upgrade LPanel to the release its channel offers; 202 and a run', '{version}', $up],
            'api.updates.reboot' => ['Reboot a minute from now, or call that off', '{cancel?: bool}', $up],
            'api.updates.settings' => ['Change what is installed by itself, and when', '{os: off|security|all, php, hour, exclude, panel: notify|install, reboot}', $up],
            'api.updates.run' => ['One update run, with what it did', '', $up],

            // ---- services and tasks
            'api.tasks' => ['What the agent has done and is doing (?state=&limit=)', '', $sv],
            'api.services' => ['Every service, how it is doing, what the watchdog knows', '', $sv],
            'api.services.events' => ['What the watchdog and administrators did lately', '', $sv],
            'api.services.watch' => ['Have the watchdog restart a service, or not', '{watch: bool}', $sv],
            'api.services.act' => ['Start, stop, restart, reload, enable or disable a service', '', $sv],

            // ---- PHP and bandwidth
            'api.php_versions' => ['Every PHP version, its extensions and sites', '', $ph],
            'api.php_versions.default' => ['Choose the default PHP version', '{version}', $ph],
            'api.php_versions.job' => ['A PHP install, removal or extension job', '', $ph],
            'api.php_versions.install' => ['Install a PHP version; 202 and a job', '', $ph],
            'api.php_versions.remove' => ['Remove a PHP version (refused while sites use it); 202 and a job', '', $ph],
            'api.php_versions.extensions' => ['Set a version\'s extensions (the whole set); 202 and a job', '{extensions: [..]}', $ph],
            'api.bandwidth' => ['An account\'s bandwidth by day', '', $ac],
            'api.php' => ['An account\'s PHP settings per version', '', $ph],
            'api.php.save' => ['Change an account\'s PHP settings for a version', '', $ph],

            // ---- SSL
            'api.ssl' => ['SSL: AutoSSL settings, the server\'s certificate, every site\'s state', '', $ss],
            'api.ssl.settings' => ['Change AutoSSL settings', '', $ss],
            'api.ssl.run' => ['Run AutoSSL now; 202', '', $ss],
            'api.ssl.host_issue' => ['Issue a certificate for the server\'s hostname; 202 and an order', '', $ss],
            'api.ssl.host_install' => ['Install a certificate for the server\'s hostname', '{key, certificate}', $ss],
            'api.ssl.order' => ['One certificate order', '', $ss],
            'api.account_ssl' => ['An account\'s sites and their certificates', '', $ss],
            'api.account_ssl.options' => ['Change a site\'s AutoSSL and HTTPS redirect', '{autossl?, force_https?}', $ss],
            'api.account_ssl.issue' => ['Issue a certificate for a site; 202 and an order', '', $ss],
            'api.account_ssl.install' => ['Install a certificate for a site', '{key, certificate}', $ss],
            'api.account_ssl.remove' => ['Remove a site\'s certificate', '', $ss],
            'api.account_ssl.csr' => ['Make a certificate signing request for a site', '', $ss],

            // ---- domains
            'api.domains' => ['An account\'s domains', '', $dm],
            'api.domains.store' => ['Add a domain: addon, subdomain or alias', '{name, kind: addon|sub|alias, target?, docroot?, php?, wildcard?}', $dm],
            'api.domains.update' => ['Change a domain', '{wildcard?}', $dm],
            'api.domains.destroy' => ['Remove a domain', '', $dm],
            'api.domains.show' => ['One domain with its site settings', '', $dm],
            'api.domains.error_pages' => ['Set a domain\'s custom error pages', '', $dm],
            'api.domains.hotlink' => ['Turn hotlink protection on', '{extensions, allow}', $dm],
            'api.domains.waf' => ['Set a domain\'s web application firewall mode and exclusions', '{mode?, exclude?}', $dm],
            'api.domains.hotlink_off' => ['Turn hotlink protection off', '', $dm],
            'api.domains.redirects' => ['Add a redirect', '{path, to, code}', $dm],
            'api.domains.redirects.delete' => ['Remove a redirect', '', $dm],
            'api.domains.protected' => ['Password-protect a folder', '{path, realm?, login?, password?}', $dm],
            'api.domains.protected.delete' => ['Remove a folder\'s protection', '', $dm],
            'api.domains.protected.login' => ['Add or change a login of a protected folder', '{password}', $dm],
            'api.domains.protected.logout' => ['Remove a login of a protected folder', '', $dm],

            // ---- DNS
            'api.dns' => ['DNS: settings, state, zone count', '', $dn],
            'api.dns.settings' => ['Change DNS settings', '{ip4?, ip6?, nameservers?}', $dn],
            'api.dns.zones' => ['Every zone', '', $dn],
            'api.dns.zones.create' => ['Create a zone', '{name, template?}', $dn],
            'api.dns.zone' => ['One zone with its records', '', $dn],
            'api.dns.zones.remove' => ['Remove a zone', '', $dn],
            'api.dns.records' => ['Add a record to a zone', '{name, type, content, ttl?, priority?}', $dn],
            'api.dns.records.update' => ['Change a record', '{name?, type?, content?, ttl?, priority?}', $dn],
            'api.dns.records.delete' => ['Remove a record', '', $dn],
            'api.dns.reset' => ['Put a zone back to its template', '', $dn],
            'api.dns_own' => ['The server\'s own nameservers (as resellers see them)', '', $dn],
            'api.dns_own.save' => ['Set the nameservers a reseller\'s zones use', '{nameservers: [..]}', $dn],
            'api.account_dns' => ['An account\'s zones', '', $dn],
            'api.account_dns.zone' => ['One of an account\'s zones with its records', '', $dn],
            'api.account_dns.records' => ['Add a record to an account\'s zone', '{name, type, content, ttl?, priority?}', $dn],
            'api.account_dns.records.update' => ['Change a record of an account\'s zone', '', $dn],
            'api.account_dns.records.delete' => ['Remove a record of an account\'s zone', '', $dn],
            'api.account_dns.reset' => ['Put an account\'s zone back to its template', '{template?, nameservers?}', $dn],

            // ---- files
            'api.account_files' => ['List a folder of an account\'s home (?path=)', '', $fl],
            'api.account_files.usage' => ['Disk use of a folder', '', $fl],
            'api.account_files.content' => ['Read a text file', '', $fl],
            'api.account_files.download' => ['Download a file', '', $fl],
            'api.account_files.save' => ['Save a text file', '{path, content, expect?, create?}', $fl],
            'api.account_files.upload' => ['Upload a file (multipart)', '{dir, overwrite?} and the file', $fl],
            'api.account_files.mkdir' => ['Make a folder', '{path}', $fl],
            'api.account_files.create' => ['Make an empty file', '{path}', $fl],
            'api.account_files.rename' => ['Rename a file or folder', '{from, to}', $fl],
            'api.account_files.move' => ['Move files', '{paths: [..], to}', $fl],
            'api.account_files.copy' => ['Copy files', '{paths: [..], to}', $fl],
            'api.account_files.delete' => ['Delete files or folders', '{paths: [..]}', $fl],
            'api.account_files.chmod' => ['Change permissions', '{paths: [..], mode, recursive?}', $fl],
            'api.account_files.compress' => ['Compress files into an archive', '{paths: [..], to}', $fl],
            'api.account_files.extract' => ['Extract an archive', '{path, to, paths?, detail?}', $fl],

            // ---- FTP and SSH
            'api.ftp' => ['FTP: settings, sessions', '', $fs],
            'api.ftp.settings' => ['Change FTP settings', '', $fs],
            'api.ftp.kill' => ['Disconnect an FTP session', '', $fs],
            'api.account_ftp' => ['An account\'s FTP logins', '', $fs],
            'api.account_ftp.store' => ['Create an FTP login', '{login, password, folder, domain?, name?}', $fs],
            'api.account_ftp.sessions' => ['An account\'s open FTP sessions', '', $fs],
            'api.account_ftp.kill' => ['Disconnect one of an account\'s FTP sessions', '', $fs],
            'api.account_ftp.update' => ['Change an FTP login\'s folder or password', '{folder?, password?}', $fs],
            'api.account_ftp.destroy' => ['Remove an FTP login', '', $fs],
            'api.account_ssh' => ['An account\'s SSH access and public keys', '', $fs],
            'api.account_ssh.store' => ['Add an SSH public key, or generate one', '{key | generate: true, name?}', $fs],
            'api.account_ssh.destroy' => ['Remove an SSH key (by name or fingerprint)', '', $fs],

            // ---- email
            'api.mail' => ['Mail server: settings, state, filtering', '', $em],
            'api.mail.settings' => ['Change the relay (smart host) settings', '{smarthost?, relay_user?, relay_password?}', $em],
            'api.mail.filtering' => ['Change spam and virus filtering', '{reject?, spam?, virus?}', $em],
            'api.mail.queue' => ['The mail queue', '', $em],
            'api.mail.queue.act' => ['Act on queued messages: retry, remove, freeze', '{ids: [..], action}', $em],
            'api.mail.queue.view' => ['One queued message', '', $em],
            'api.mail.track' => ['Track what became of messages (server-wide)', '', $em],
            'api.mail.checks' => ['Deliverability checks for the server', '', $em],
            'api.account_mail' => ['An account\'s mailboxes, forwarders and mail domains', '', $em],
            'api.account_mail.store' => ['Create a mailbox', '{address, password, quota_mb?}', $em],
            'api.account_mail.update' => ['Change a mailbox\'s password or quota', '{password?, quota_mb?}', $em],
            'api.account_mail.destroy' => ['Delete a mailbox', '', $em],
            'api.account_mail.responder' => ['Set a mailbox\'s autoresponder', '', $em],
            'api.account_mail.responder.destroy' => ['Remove a mailbox\'s autoresponder', '', $em],
            'api.account_mail.forward' => ['Add a forwarder', '{address, to}', $em],
            'api.account_mail.unforward' => ['Remove a forwarder', '', $em],
            'api.account_mail.domain' => ['Change a mail domain: catch-all, local delivery', '{catchall?, local?}', $em],
            'api.account_mail.filters' => ['A mailbox\'s filters', '', $em],
            'api.account_mail.filters.store' => ['Add a filter to a mailbox', '', $em],
            'api.account_mail.filters.destroy' => ['Remove a filter', '', $em],
            'api.account_mail.spam' => ['An account\'s spam settings', '', $em],
            'api.account_mail.spam.update' => ['Change an account\'s spam settings', '{junk?, delete?, allow?, block?}', $em],
            'api.account_mail.track' => ['Track what became of an account\'s messages', '', $em],
            'api.account_mail.checks' => ['Deliverability checks for an account\'s mail domains', '', $em],
            'api.account_mail.setup' => ['The settings a mail program needs for a mailbox', '', $em],
            'api.account_mail.webmail' => ['Open webmail for a mailbox (a one-time link)', '', $em],
        ];
    }
}
