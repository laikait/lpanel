<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Engine\Database\ConnectionManager;
use App\Modules\Packages\Packages;
use App\Modules\Web\AutoSsl;
use App\Modules\Web\Domains;
use App\Modules\Web\SslSites;
use App\Modules\Web\WebSettings;

/**
 * The names mail is reached by.
 *
 * Each mail domain's site also answers to mail.<domain> (its mail server),
 * autoconfig.<domain> and autodiscover.<domain> (mail clients' setup) and
 * webmail.<domain> (which sends visitors to webmail), so AutoSSL puts them in
 * the site's certificate. A mail domain whose site's certificate covers
 * mail.<domain> has it served for that name on SMTP, IMAP and POP3 too;
 * otherwise mail clients are told to use the server's hostname, whose
 * certificate every mail server presents.
 *
 * A name that is a domain of its own (an owner's subdomain "mail.example.com")
 * stays that domain's.
 */
final class MailHosts
{
    /** The User panel's port: webmail is at /webmail/ there. */
    public const PANEL_PORT = 7444;

    /** The names each mail domain gets on its site, by their first label. */
    public const PREFIXES = ['mail', 'autoconfig', 'autodiscover', 'webmail'];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Mailboxes $mail,
        private readonly Packages $packages,
        private readonly SslSites $ssl,
        private readonly WebSettings $settings,
        private readonly AutoSsl $autoSsl,
    ) {}

    /**
     * web.state: each site of an account with email answers mail clients'
     * setup requests at all its names; main and addon sites (and their alias
     * domains) answer to the mail names too.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    public function webState(array $state, array $account): array
    {
        $package = $this->packages->byId((int) $account['package_id']);

        if (($package !== null && !Packages::has($package, 'email')) || !\is_array($state['sites'] ?? null)) {
            return $state;
        }

        $rows = $this->connections->connection()->table('domains')->where('account_id', (int) $account['id'])->get();
        $kind = [];
        $aliases = [];

        foreach ($rows as $row) {
            $kind[(string) $row['name']] = (string) $row['kind'];
        }

        foreach ($rows as $row) {
            if ($row['kind'] === Domains::ALIAS && $row['parent_id'] !== null) {
                foreach ($rows as $parent) {
                    if ((int) $parent['id'] === (int) $row['parent_id']) {
                        $aliases[(string) $parent['name']][] = (string) $row['name'];
                    }
                }
            }
        }

        $url = $this->webmailUrl();
        $wanted = [];

        foreach ($state['sites'] as $site) {
            if (($kind[(string) $site['name']] ?? Domains::SUB) === Domains::SUB) {
                continue;
            }

            foreach (\array_merge([(string) $site['name']], $aliases[(string) $site['name']] ?? []) as $base) {
                foreach (self::PREFIXES as $prefix) {
                    $wanted[] = $prefix . '.' . $base;
                }
            }
        }

        // Names that are domains in their own right (of any account) stay theirs.
        $taken = [];

        foreach (\array_chunk(\array_values(\array_unique($wanted)), 500) as $chunk) {
            foreach ($this->connections->connection()->table('domains')->whereIn('name', $chunk)->get() as $row) {
                $taken[(string) $row['name']] = true;
            }
        }

        $anyWebmail = false;

        foreach ($state['sites'] as &$site) {
            $site['mail_setup'] = true;

            if (($kind[(string) $site['name']] ?? Domains::SUB) === Domains::SUB) {
                continue;
            }

            $names = \array_values(\array_map('strval', (array) ($site['aliases'] ?? [])));
            $webmail = [];

            foreach (\array_merge([(string) $site['name']], $aliases[(string) $site['name']] ?? []) as $base) {
                foreach (self::PREFIXES as $prefix) {
                    $name = $prefix . '.' . $base;

                    if (isset($taken[$name]) || \in_array($name, $names, true) || \strlen($name) > 253 || ($prefix === 'webmail' && $url === null)) {
                        continue;
                    }

                    $names[] = $name;

                    if ($prefix === 'webmail') {
                        $webmail[] = $name;
                    }
                }
            }

            $site['aliases'] = \array_slice($names, 0, 200);
            $webmail = \array_values(\array_intersect($webmail, $site['aliases']));

            if ($webmail !== []) {
                $site['webmail'] = $webmail;
                $anyWebmail = true;
            }
        }
        unset($site);

        if ($anyWebmail) {
            $state['webmail_url'] = $url;
        }

        return $state;
    }

    /**
     * The certificate each of the account's mail domains is served with for
     * mail.<domain>: its site's, when that covers the name and is valid.
     *
     * @param array<string, mixed> $account
     *
     * @return array<string, string> mail domain => certificate id
     */
    public function certs(array $account): array
    {
        $id = (int) $account['id'];
        $tls = $this->ssl->tlsFor($id);

        if ($tls === []) {
            return [];
        }

        $sites = $this->ssl->forAccount($id);
        $rows = [];

        foreach ($this->connections->connection()->table('domains')->where('account_id', $id)->get() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        $out = [];

        foreach ($this->mail->domains($id) as $d) {
            $row = $rows[(int) $d['domain_id']] ?? null;

            if ($row === null) {
                continue;
            }

            $siteId = $row['kind'] === Domains::ALIAS && $row['parent_id'] !== null ? (int) $row['parent_id'] : (int) $row['id'];

            if (isset($tls[$siteId]) && AutoSsl::covers((array) ($sites[$siteId]['names'] ?? []), 'mail.' . $d['name'])) {
                $out[(string) $d['name']] = $tls[$siteId]['cert'];
            }
        }

        return $out;
    }

    /**
     * The server mail clients of a domain connect to: mail.<domain> when its
     * own certificate serves that name, else the server's hostname.
     *
     * @param array<string, mixed> $account
     */
    public function serverFor(array $account, string $domain): string
    {
        if (isset($this->certs($account)[$domain])) {
            return 'mail.' . $domain;
        }

        $host = $this->autoSsl->hostname();

        return $host !== '' ? $host : 'mail.' . $domain;
    }

    /** The server's hostname as set for its certificate ("" when not set). */
    public function hostname(): string
    {
        return \strtolower((string) $this->settings->get('ssl_hostname', ''));
    }

    /** Where webmail is, in full; null when the server has no hostname set. */
    public function webmailUrl(): ?string
    {
        $host = $this->hostname();

        return AutoSsl::validHost($host) ? 'https://' . $host . ':' . self::PANEL_PORT . '/webmail/' : null;
    }
}
