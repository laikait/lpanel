<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Accounts\Accounts;
use App\Modules\Mail\Mailboxes;
use App\Modules\Mail\MailChecks;
use App\Modules\Mail\MailHosts;
use App\Modules\Mail\MailSetup;
use App\Modules\Mail\Webmail;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;

/**
 * Reaching mail: webmail, mail clients and deliverability.
 *
 * User panel: "Open webmail" for a mailbox (sign-on with no password), each
 * mailbox's client setup page (with an Apple profile to download), and the
 * deliverability checks of the account's domains.
 *
 * Admin panel: the deliverability checks of the server (its address's
 * reverse name and the blocklists) and of any account's domains.
 *
 * Public, from customers' sites only (127.0.0.1:7449): mail clients' own
 * setup requests, Thunderbird's autoconfig and Outlook's autodiscover.
 */
final class MailClientPages
{
    public function __construct(
        private readonly Mailboxes $mail,
        private readonly Webmail $webmail,
        private readonly MailSetup $setup,
        private readonly MailChecks $checks,
        private readonly MailHosts $hosts,
        private readonly Accounts $accounts,
        private readonly Subusers $subusers,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    // -------------------------------------------------------------------------
    // User panel

    public function webmail(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        [$url, $why] = $this->webmail->signOn($identity, $request, $account, $this->mailbox($account, $id));

        if ($url === null) {
            PanelPage::notice($session, 'error', (string) $why);

            return new RedirectResponse($this->router->url('user.mail'), 303);
        }

        return new RedirectResponse($url, 303);
    }

    public function setupPage(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $box = $this->mailbox($account, $id);

        return $this->pages->render($request, $session, $identity, 'user', '@Mail/setup', [
            'box' => $box,
            'settings' => $this->setup->settings($account, (string) $box['address']),
            'profile_url' => $this->router->url('user.mail.profile', ['id' => $box['id']]),
            'webmail_url' => $this->router->url('user.mail.webmail', ['id' => $box['id']]),
            'webmail_address' => $this->hosts->webmailUrl(),
            'back_url' => $this->router->url('user.mail'),
            'active_account' => $account['status'] === Accounts::ACTIVE,
        ], title: 'Mail.setup_title', active: 'mail');
    }

    public function profile(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $box = $this->mailbox($account, $id);
        $name = (string) \preg_replace('/[^a-z0-9.@_-]/', '-', (string) $box['address']);

        return new Response($this->setup->appleProfile($account, (string) $box['address']), 200, [
            'Content-Type' => 'application/x-apple-aspen-config',
            'Content-Disposition' => 'attachment; filename="' . $name . '.mobileconfig"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function checks(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $result = $this->checks->run($this->mail->domains((int) $account['id']));

        return $this->pages->render($request, $session, $identity, 'user', '@Mail/checks', $this->checkData($result) + [
            'tabs' => MailToolPages::userTabs($this->router),
            'tab' => 'checks',
            'area' => 'user',
        ], title: 'Mail.checks_title', active: 'mail');
    }

    // -------------------------------------------------------------------------
    // Admin panel

    public function adminChecks(Request $request, Session $session, Identity $identity): Response
    {
        $username = \strtolower(\trim((string) $request->query('account', '')));
        $account = $username === '' ? null : $this->accounts->findByUsername($identity, $username);
        $result = $this->checks->run($account === null ? [] : $this->mail->domains((int) $account['id']));

        return $this->pages->render($request, $session, $identity, 'admin', '@Mail/checks', $this->checkData($result) + [
            'tabs' => MailToolPages::adminTabs($this->router),
            'tab' => 'checks',
            'area' => 'admin',
            'account' => $username,
            'unknown_account' => $username !== '' && $account === null,
            'form_url' => $this->router->url('admin.mail.checks'),
        ], title: 'Mail.checks_title', active: 'mail');
    }

    /**
     * @param array{domains: list<array<string, mixed>>, server: array<string, mixed>, error: ?string} $result
     *
     * @return array<string, mixed>
     */
    private function checkData(array $result): array
    {
        $domains = \array_map(static fn(array $d): array => $d + ['worst' => MailChecks::worst($d['checks'])], $result['domains']);
        $all = \array_merge(...\array_merge([(array) ($result['server']['checks'] ?? [])], \array_column($domains, 'checks')));

        return [
            'domains' => $domains,
            'server' => $result['server'],
            'error' => $result['error'],
            'counts' => [
                'ok' => \count(\array_filter($all, static fn(array $c): bool => $c['state'] === MailChecks::OK)),
                'warn' => \count(\array_filter($all, static fn(array $c): bool => $c['state'] === MailChecks::WARN)),
                'bad' => \count(\array_filter($all, static fn(array $c): bool => $c['state'] === MailChecks::BAD)),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Mail clients (public, from customers' sites)

    public function autoconfig(Request $request): Response
    {
        $address = $request->query('emailaddress');
        $xml = $this->setup->autoconfig(\is_string($address) ? $address : null, $request->host());

        return self::xml($xml);
    }

    public function autodiscover(Request $request): Response
    {
        return self::xml($this->setup->autodiscover($request->method() === 'POST' ? $request->body() : ''));
    }

    private static function xml(?string $xml): Response
    {
        if ($xml === null) {
            return new Response("Not found.\n", 404, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function account(Identity $identity): array
    {
        return $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function mailbox(array $account, string $id): array
    {
        return $this->mail->mailbox((int) $account['id'], (int) $id) ?? throw HttpException::notFound();
    }
}
