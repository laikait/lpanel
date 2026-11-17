<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Engine\Database\ConnectionManager;
use App\Modules\Accounts\Accounts;

/**
 * How mail clients reach a mailbox, and the answers to their own setup
 * requests.
 *
 * Every site of an account with email passes Thunderbird's autoconfig
 * (/mail/config-v1.1.xml, /.well-known/autoconfig/…) and Outlook's
 * autodiscover (/autodiscover/autodiscover.xml) on to the panel, at any of
 * its names; the panel answers for the domain asked about. The answers
 * carry only what any mail client may know: servers, ports and that the
 * login is the whole address.
 */
final class MailSetup
{
    /** Ports, as the mail servers listen. */
    public const IMAP_TLS = 993;
    public const IMAP_STARTTLS = 143;
    public const POP3_TLS = 995;
    public const POP3_STARTTLS = 110;
    public const SMTP_TLS = 465;
    public const SMTP_STARTTLS = 587;

    /** Requests larger than this are not read. */
    public const MAX_REQUEST = 16384;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Accounts $accounts,
        private readonly MailHosts $hosts,
    ) {}

    /**
     * What a mail client needs for an address of the account's domain.
     *
     * @param array<string, mixed> $account
     *
     * @return array{address: string, domain: string, username: string, server: string, own_certificate: bool, imap: array{port: int, security: string}, imap_starttls: array{port: int, security: string}, pop3: array{port: int, security: string}, pop3_starttls: array{port: int, security: string}, smtp: array{port: int, security: string}, smtp_starttls: array{port: int, security: string}}
     */
    public function settings(array $account, string $address): array
    {
        $domain = \strtolower((string) \substr($address, (int) \strrpos($address, '@') + 1));
        $server = $this->hosts->serverFor($account, $domain);

        return [
            'address' => \strtolower($address),
            'domain' => $domain,
            'username' => \strtolower($address),
            'server' => $server,
            'own_certificate' => $server === 'mail.' . $domain,
            'imap' => ['port' => self::IMAP_TLS, 'security' => 'ssl'],
            'imap_starttls' => ['port' => self::IMAP_STARTTLS, 'security' => 'starttls'],
            'pop3' => ['port' => self::POP3_TLS, 'security' => 'ssl'],
            'pop3_starttls' => ['port' => self::POP3_STARTTLS, 'security' => 'starttls'],
            'smtp' => ['port' => self::SMTP_TLS, 'security' => 'ssl'],
            'smtp_starttls' => ['port' => self::SMTP_STARTTLS, 'security' => 'starttls'],
        ];
    }

    /**
     * The account a mail domain belongs to, for a name a client asked with:
     * the domain itself, or one of its setup names.
     *
     * @return array{0: array<string, mixed>, 1: string}|null [account, domain]
     */
    public function find(string $name): ?array
    {
        $name = \strtolower(\trim($name, " \t."));

        foreach ([$name, (string) \preg_replace('/^(autoconfig|autodiscover|mail|webmail)\./', '', $name)] as $candidate) {
            if ($candidate === '' || \strlen($candidate) > 253) {
                continue;
            }

            $row = $this->connections->connection()->table('mail_domains')->where('name', $candidate)->first();

            if ($row === null) {
                continue;
            }

            $account = $this->accounts->byId((int) $row['account_id']);

            if ($account !== null && \in_array($account['status'], [Accounts::ACTIVE, Accounts::SUSPENDED], true)) {
                return [$account, $candidate];
            }
        }

        return null;
    }

    /**
     * Thunderbird's autoconfig for the domain of an address (or of the name
     * the request came to), or null when no account here has it.
     */
    public function autoconfig(?string $address, string $host): ?string
    {
        $address = $address === null ? null : \strtolower(\trim($address));
        $found = $address !== null && \preg_match('/^[^@\s]{1,64}@([a-z0-9.-]{1,253})$/', $address, $m) === 1 ? $this->find($m[1]) : $this->find($host);

        if ($found === null) {
            return null;
        }

        [$account, $domain] = $found;
        $s = $this->settings($account, 'user@' . $domain);
        $e = static fn(string $v): string => \htmlspecialchars($v, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
        $server = static fn(string $tag, string $type, int $port, string $socket): string => <<<XML
                <{$tag} type="{$type}">
                  <hostname>{$e($s['server'])}</hostname>
                  <port>{$port}</port>
                  <socketType>{$socket}</socketType>
                  <authentication>password-cleartext</authentication>
                  <username>%EMAILADDRESS%</username>
                </{$tag}>

            XML;

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<clientConfig version=\"1.1\">\n"
            . '  <emailProvider id="' . $e($domain) . "\">\n"
            . '    <domain>' . $e($domain) . "</domain>\n"
            . '    <displayName>' . $e($domain) . "</displayName>\n"
            . '    <displayShortName>' . $e($domain) . "</displayShortName>\n"
            . $server('incomingServer', 'imap', self::IMAP_TLS, 'SSL')
            . $server('incomingServer', 'imap', self::IMAP_STARTTLS, 'STARTTLS')
            . $server('incomingServer', 'pop3', self::POP3_TLS, 'SSL')
            . $server('outgoingServer', 'smtp', self::SMTP_TLS, 'SSL')
            . $server('outgoingServer', 'smtp', self::SMTP_STARTTLS, 'STARTTLS')
            . "  </emailProvider>\n</clientConfig>\n";
    }

    /**
     * Outlook's autodiscover (the POX schema) for the address in the
     * request, or null when it names no address of an account here (or asks
     * for another schema, such as ActiveSync's).
     */
    public function autodiscover(string $body): ?string
    {
        if (\strlen($body) > self::MAX_REQUEST
            || \preg_match('~<EMailAddress>\s*([^<>\s@]{1,64}@[A-Za-z0-9.-]{1,253})\s*</EMailAddress>~i', $body, $m) !== 1) {
            return null;
        }

        if (\preg_match('~<AcceptableResponseSchema>\s*([^<]*?)\s*</AcceptableResponseSchema>~i', $body, $schema) === 1
            && !\str_contains(\strtolower($schema[1]), '/outlook/responseschema/2006a')) {
            return null;
        }

        $address = \strtolower($m[1]);
        $found = $this->find(\substr($address, (int) \strrpos($address, '@') + 1));

        if ($found === null) {
            return null;
        }

        $s = $this->settings($found[0], $address);
        $e = static fn(string $v): string => \htmlspecialchars($v, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
        $protocol = static fn(string $type, int $port, string $extra = ''): string => <<<XML
                  <Protocol>
                    <Type>{$type}</Type>
                    <Server>{$e($s['server'])}</Server>
                    <Port>{$port}</Port>
                    <DomainRequired>off</DomainRequired>
                    <LoginName>{$e($address)}</LoginName>
                    <SPA>off</SPA>
                    <SSL>on</SSL>
                    <AuthRequired>on</AuthRequired>
            {$extra}      </Protocol>

            XML;

        return "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
            . "<Autodiscover xmlns=\"http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006\">\n"
            . "  <Response xmlns=\"http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a\">\n"
            . "    <Account>\n      <AccountType>email</AccountType>\n      <Action>settings</Action>\n"
            . $protocol('IMAP', self::IMAP_TLS)
            . $protocol('POP3', self::POP3_TLS)
            . $protocol('SMTP', self::SMTP_TLS, "        <UsePOPAuth>on</UsePOPAuth>\n        <SMTPLast>off</SMTPLast>\n")
            . "    </Account>\n  </Response>\n</Autodiscover>\n";
    }

    /**
     * An Apple configuration profile that sets the mailbox up on iPhone,
     * iPad and Mac (it asks for the password when installed).
     *
     * @param array<string, mixed> $account
     */
    public function appleProfile(array $account, string $address): string
    {
        $s = $this->settings($account, $address);
        $e = static fn(string $v): string => \htmlspecialchars($v, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
        // The same address always gives the same profile, so installing it
        // again replaces the first.
        $uuid = static function (string $seed): string {
            $h = \md5('lpanel-mail-profile:' . $seed);

            return \strtoupper(\sprintf('%s-%s-4%s-a%s-%s', \substr($h, 0, 8), \substr($h, 8, 4), \substr($h, 13, 3), \substr($h, 17, 3), \substr($h, 20, 12)));
        };
        $id = 'lpanel.mail.' . \implode('.', \array_reverse(\explode('.', $s['domain']))) . '.' . \preg_replace('/[^a-z0-9-]/', '-', \strstr($s['address'], '@', true) ?: 'mailbox');
        $a = $e($s['address']);
        $server = $e($s['server']);

        return <<<PLIST
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
            <plist version="1.0">
            <dict>
              <key>PayloadContent</key>
              <array>
                <dict>
                  <key>EmailAccountDescription</key><string>{$a}</string>
                  <key>EmailAccountType</key><string>EmailTypeIMAP</string>
                  <key>EmailAddress</key><string>{$a}</string>
                  <key>IncomingMailServerAuthentication</key><string>EmailAuthPassword</string>
                  <key>IncomingMailServerHostName</key><string>{$server}</string>
                  <key>IncomingMailServerPortNumber</key><integer>{$s['imap']['port']}</integer>
                  <key>IncomingMailServerUseSSL</key><true/>
                  <key>IncomingMailServerUsername</key><string>{$a}</string>
                  <key>OutgoingMailServerAuthentication</key><string>EmailAuthPassword</string>
                  <key>OutgoingMailServerHostName</key><string>{$server}</string>
                  <key>OutgoingMailServerPortNumber</key><integer>{$s['smtp']['port']}</integer>
                  <key>OutgoingMailServerUseSSL</key><true/>
                  <key>OutgoingMailServerUsername</key><string>{$a}</string>
                  <key>OutgoingPasswordSameAsIncomingPassword</key><true/>
                  <key>PayloadDescription</key><string>Email account {$a}</string>
                  <key>PayloadDisplayName</key><string>{$a}</string>
                  <key>PayloadIdentifier</key><string>{$e($id)}.account</string>
                  <key>PayloadType</key><string>com.apple.mail.managed</string>
                  <key>PayloadUUID</key><string>{$uuid($s['address'] . ':account')}</string>
                  <key>PayloadVersion</key><integer>1</integer>
                </dict>
              </array>
              <key>PayloadDescription</key><string>Sets up {$a} in Mail.</string>
              <key>PayloadDisplayName</key><string>{$a}</string>
              <key>PayloadIdentifier</key><string>{$e($id)}</string>
              <key>PayloadRemovalDisallowed</key><false/>
              <key>PayloadType</key><string>Configuration</string>
              <key>PayloadUUID</key><string>{$uuid($s['address'])}</string>
              <key>PayloadVersion</key><integer>1</integer>
            </dict>
            </plist>

            PLIST;
    }
}
