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
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Dns\DnsZones;
use App\Modules\Mail\MailActions;
use App\Modules\Mail\Mailboxes;
use App\Modules\Mail\MailOutcome;
use App\Modules\Mail\MailSync;
use App\Modules\Packages\Packages;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;
use App\Modules\Web\AutoSsl;

/**
 * "Email" in both panels.
 *
 * User panel: the account's mailboxes (with their quotas and
 * autoresponders), forwarders, each domain's catch-all and DNS records, and
 * what to type into a mail client.
 *
 * Admin panel: Exim's and Dovecot's state, the queue, the relay host for
 * outgoing mail, and every account's mail domains.
 */
final class MailPages
{
    public function __construct(
        private readonly Mailboxes $mail,
        private readonly MailActions $actions,
        private readonly MailSync $sync,
        private readonly Packages $packages,
        private readonly Subusers $subusers,
        private readonly DnsZones $zones,
        private readonly AutoSsl $ssl,
        private readonly AgentClient $agent,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    // -------------------------------------------------------------------------
    // User panel

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->user($request, $session, $identity);
    }

    public function store(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $domain = $this->findDomain($account, (string) $request->input('domain', ''));
        $local = (string) $request->input('local', '');
        $outcome = $this->actions->createMailbox($identity, $request, $account, $domain, $local, (string) $request->input('password', ''), (string) $request->input('quota', ''));

        return $this->after($request, $session, $identity, $outcome, 'Mail.created', ['local' => $local, 'domain' => (string) $domain['id'], 'quota' => (string) $request->input('quota', '')], 'box');
    }

    public function password(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $outcome = $this->actions->setPassword($identity, $request, $account, $this->mailbox($account, $id), (string) $request->input('password', ''));

        return $this->after($request, $session, $identity, $outcome, 'Mail.password_changed');
    }

    public function quota(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $outcome = $this->actions->setQuota($identity, $request, $account, $this->mailbox($account, $id), (string) $request->input('quota', ''));

        return $this->after($request, $session, $identity, $outcome, 'Mail.quota_changed');
    }

    public function responder(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $box = $this->mailbox($account, $id);
        $input = [];

        foreach (['subject', 'body', 'from_name', 'days', 'start', 'end'] as $key) {
            $input[$key] = (string) $request->input($key, '');
        }

        [$outcome, $errors] = $this->actions->setResponder($identity, $request, $account, $box, $input);

        if ($errors !== []) {
            return $this->user($request, $session, $identity, ['responder_id' => (int) $box['id'], 'responder_errors' => $errors, 'responder_old' => $input], 422);
        }

        return $this->after($request, $session, $identity, $outcome, 'Mail.responder_saved');
    }

    public function responderOff(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        [$outcome] = $this->actions->setResponder($identity, $request, $account, $this->mailbox($account, $id), null);

        return $this->after($request, $session, $identity, $outcome, 'Mail.responder_off');
    }

    public function delete(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $outcome = $this->actions->deleteMailbox($identity, $request, $account, $this->mailbox($account, $id));

        return $this->after($request, $session, $identity, $outcome, 'Mail.deleted');
    }

    public function forward(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $domain = $this->findDomain($account, (string) $request->input('domain', ''));
        $local = (string) $request->input('local', '');
        $to = (string) $request->input('destination', '');
        $outcome = $this->actions->addForwarder($identity, $request, $account, $domain, $local, $to);

        return $this->after($request, $session, $identity, $outcome, 'Mail.forwarder_added', ['local' => $local, 'domain' => (string) $domain['id'], 'destination' => $to], 'fwd');
    }

    public function unforward(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $forwarder = \ctype_digit($id) ? $this->mail->forwarder((int) $account['id'], (int) $id) : null;

        if ($forwarder === null) {
            throw HttpException::notFound();
        }

        return $this->after($request, $session, $identity, $this->actions->removeForwarder($identity, $request, $account, $forwarder), 'Mail.forwarder_removed');
    }

    public function domain(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $domain = $this->findDomain($account, $id);
        $outcome = $this->actions->setDomain(
            $identity,
            $request,
            $account,
            $domain,
            (string) $request->input('catchall', 'refuse'),
            (string) $request->input('catchall_to', ''),
            $request->input('local') === null ? null : $request->input('local') === '1',
        );

        if ($outcome->status === MailOutcome::REFUSED && $outcome->code === 'catchall') {
            return $this->user($request, $session, $identity, ['domain_id' => (int) $domain['id'], 'domain_error' => (string) $outcome->message, 'domain_old' => ['catchall_to' => (string) $request->input('catchall_to', '')]], 422);
        }

        return $this->after($request, $session, $identity, $outcome, 'Mail.domain_saved');
    }

    /**
     * Back to the page with a notice; a refused form shows again with its
     * error beside the field and what was typed.
     *
     * @param array<string, string> $old
     */
    private function after(Request $request, Session $session, Identity $identity, MailOutcome $outcome, string $done, array $old = [], string $form = ''): Response
    {
        if ($outcome->status === MailOutcome::REFUSED && $form !== '' && $outcome->code !== '') {
            return $this->user($request, $session, $identity, [
                'errors' => [$form . '_' . $outcome->code => (string) $outcome->message],
                'old' => [$form => $old],
            ], 422);
        }

        match ($outcome->status) {
            MailOutcome::REFUSED => PanelPage::notice($session, 'error', (string) $outcome->message),
            MailOutcome::FAILED => PanelPage::notice($session, 'error', 'Mail.refused'),
            MailOutcome::PENDING => PanelPage::notice($session, 'warning', 'Mail.pending'),
            default => PanelPage::notice($session, 'success', $done),
        };

        return new RedirectResponse($this->router->url('user.mail'), 303);
    }

    /** @param array<string, mixed> $extra */
    private function user(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $account = $this->account($identity);
        $id = (int) $account['id'];
        $package = $this->packages->byId((int) $account['package_id']) ?? [];
        $kinds = $this->mail->domainKinds($id);
        $domains = [];

        foreach ($this->mail->domains($id) as $d) {
            $here = $this->zones->zoneHolding($id, (string) $d['name']) !== null;
            $kind = $kinds[(int) $d['domain_id']] ?? 'main';
            $records = MailSync::records($d, $kind);

            // A domain's own SPF record comes from its zone's template.
            if ($kind !== 'sub') {
                \array_unshift($records, ['name' => (string) $d['name'], 'type' => 'TXT', 'ttl' => 3600, 'content' => MailSync::SPF]);
            }

            $domains[] = $d + [
                'records' => $records,
                'dns_here' => $here,
                'catchall_action' => match (true) {
                    ($d['catchall'] ?? null) === null => 'refuse',
                    $d['catchall'] === Mailboxes::DISCARD => 'discard',
                    default => 'forward',
                },
                'save_url' => $this->router->url('user.mail.domain', ['id' => $d['id']]),
            ];
        }

        $boxes = \array_map(fn(array $b): array => $b + [
            'password_url' => $this->router->url('user.mail.password', ['id' => $b['id']]),
            'quota_url' => $this->router->url('user.mail.quota', ['id' => $b['id']]),
            'responder_url' => $this->router->url('user.mail.responder', ['id' => $b['id']]),
            'responder_off_url' => $this->router->url('user.mail.responder.delete', ['id' => $b['id']]),
            'delete_url' => $this->router->url('user.mail.delete', ['id' => $b['id']]),
            'percent' => (int) $b['quota_mb'] > 0 ? \min(100, (int) \round((int) $b['used_bytes'] * 100 / ((int) $b['quota_mb'] * 1048576))) : null,
        ], $this->mail->mailboxes($id));

        $forwarders = \array_map(fn(array $f): array => $f + ['delete_url' => $this->router->url('user.mail.unforward', ['id' => $f['id']])], $this->mail->forwarders($id));
        $limit = (int) ($package['mailboxes'] ?? 0);
        $main = null;

        foreach ($domains as $d) {
            if ($d['name'] === $account['domain']) {
                $main = $d;
            }
        }

        return $this->pages->render($request, $session, $identity, 'user', '@Mail/mail', $extra + [
            'account' => $account,
            'domains' => $domains,
            'main_domain' => $main ?? ($domains[0] ?? null),
            'mailboxes' => $boxes,
            'forwarders' => $forwarders,
            'limit' => $limit,
            'at_limit' => $limit > 0 && \count($boxes) >= $limit,
            'total_bytes' => \array_sum(\array_map(static fn(array $b): int => (int) $b['used_bytes'], $boxes)),
            'password_min' => Mailboxes::PASSWORD_MIN,
            'default_quota' => (int) ($package['disk_mb'] ?? 0) > 0 ? \min(Mailboxes::DEFAULT_QUOTA_MB, (int) $package['disk_mb']) : Mailboxes::DEFAULT_QUOTA_MB,
            'disk_mb' => (int) ($package['disk_mb'] ?? 0),
            'hostname' => $this->ssl->hostname(),
            'state' => $this->sync->stateOf($id),
            'errors' => [],
            'old' => [],
            'responder_id' => 0,
            'responder_errors' => [],
            'responder_old' => [],
            'domain_id' => 0,
            'domain_error' => null,
            'domain_old' => [],
            'store_url' => $this->router->url('user.mail.store'),
            'forward_url' => $this->router->url('user.mail.forward'),
        ], title: 'Mail.title', active: 'mail', status: $status);
    }

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
    private function findDomain(array $account, string $id): array
    {
        return (\ctype_digit($id) ? $this->mail->domain((int) $account['id'], (int) $id) : null) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function mailbox(array $account, string $id): array
    {
        return (\ctype_digit($id) ? $this->mail->mailbox((int) $account['id'], (int) $id) : null) ?? throw HttpException::notFound();
    }

    // -------------------------------------------------------------------------
    // Admin panel

    public function admin(Request $request, Session $session, Identity $identity): Response
    {
        return $this->adminPage($request, $session, $identity);
    }

    /** @param array<string, mixed> $extra */
    private function adminPage(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $info = null;

        try {
            $reply = $this->agent->call('mail.info', [], timeout: 15.0);
            $info = $reply->done() ? $reply->result : null;
        } catch (AgentUnavailable) {
        }

        $all = $this->mail->all();

        return $this->pages->render($request, $session, $identity, 'admin', '@Mail/admin', $extra + [
            'info' => $info,
            'domains' => $all,
            'mailboxes' => \array_sum(\array_map(static fn(array $d): int => (int) $d['mailboxes'], $all)),
            'smarthost' => $this->mail->setting('smarthost'),
            'hostname' => $this->ssl->hostname(),
            'failed' => $this->sync->rowsIn(MailSync::FAILED),
            'error' => null,
            'old_smarthost' => null,
            'settings_url' => $this->router->url('admin.mail.settings'),
        ], title: 'Mail.title', active: 'mail', status: $status);
    }

    public function settings(Request $request, Session $session, Identity $identity): Response
    {
        $input = (string) $request->input('smarthost', '');
        $outcome = $this->actions->setSmarthost($identity, $request, $input);

        if ($outcome->status === MailOutcome::REFUSED && $outcome->code === 'smarthost') {
            return $this->adminPage($request, $session, $identity, ['error' => (string) $outcome->message, 'old_smarthost' => $input], 422);
        }

        match ($outcome->status) {
            MailOutcome::REFUSED => PanelPage::notice($session, 'error', (string) $outcome->message),
            MailOutcome::FAILED => PanelPage::notice($session, 'error', 'Mail.refused'),
            default => PanelPage::notice($session, 'success', 'Mail.settings_saved'),
        };

        return new RedirectResponse($this->router->url('admin.mail'), 303);
    }
}
