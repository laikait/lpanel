<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Mail\MailActions;
use App\Modules\Mail\Mailboxes;
use App\Modules\Mail\MailOutcome;
use App\Modules\Mail\MailSync;
use App\Modules\Packages\Http\PackageApi;

/**
 * Mail through the API, with the same rules as the panels.
 *
 *   /admin/api/v1/accounts/{username}/email…   an account's (accounts.manage / accounts.modify)
 *   /admin/api/v1/email…                       the mail servers (server.mail)
 *
 * Passwords are sent in the request body and never returned.
 *
 *   200  done      201  made      202  recorded, still being carried out
 *   204  removed   409  not now   422  invalid input   502  the server refused it
 */
final class MailApi
{
    public function __construct(
        private readonly Mailboxes $mail,
        private readonly MailActions $actions,
        private readonly MailSync $sync,
        private readonly Accounts $accounts,
        private readonly AgentClient $agent,
        private readonly Localization $localization,
    ) {}

    public function index(Identity $identity, string $username): JsonResponse
    {
        return ApiResponse::item($this->present($this->account($identity, $username)));
    }

    /** {address, password, quota_mb?} */
    public function store(Request $request, Identity $identity, string $username): Response
    {
        $account = $this->account($identity, $username);
        [$local, $domain] = $this->split($account, (string) $request->input('address', ''));

        if ($domain === null) {
            return PackageApi::invalid(['address' => 'Mail.error_no_domain'], $this->localization);
        }

        $quota = $request->input('quota_mb');
        $outcome = $this->actions->createMailbox($identity, $request, $account, $domain, $local, (string) $request->input('password', ''), $quota === null ? null : (string) $quota);

        return $this->respond($outcome, $account, 201);
    }

    /** {password?, quota_mb?} */
    public function update(Request $request, Identity $identity, string $username, string $address): Response
    {
        $account = $this->account($identity, $username);
        $box = $this->mailbox($account, $address);
        $outcome = MailOutcome::done();

        if ($request->input('password') !== null) {
            $outcome = $this->actions->setPassword($identity, $request, $account, $box, (string) $request->input('password'));
        }

        if ($outcome->ok() && $request->input('quota_mb') !== null) {
            $outcome = $this->actions->setQuota($identity, $request, $account, $this->mailbox($account, $address), (string) $request->input('quota_mb'));
        }

        return $this->respond($outcome, $account);
    }

    public function destroy(Request $request, Identity $identity, string $username, string $address): Response
    {
        $account = $this->account($identity, $username);
        $outcome = $this->actions->deleteMailbox($identity, $request, $account, $this->mailbox($account, $address));

        return $outcome->status === MailOutcome::DONE ? ApiResponse::noContent() : $this->respond($outcome, $account);
    }

    /** {subject, body, from_name?, days?, start?, end?} */
    public function responder(Request $request, Identity $identity, string $username, string $address): Response
    {
        $account = $this->account($identity, $username);
        $input = ['days' => '1'];

        foreach (['subject', 'body', 'from_name', 'days', 'start', 'end'] as $key) {
            if ($request->input($key) !== null) {
                $input[$key] = (string) $request->input($key);
            }
        }

        [$outcome, $errors] = $this->actions->setResponder($identity, $request, $account, $this->mailbox($account, $address), $input);

        return $errors !== [] ? PackageApi::invalid($errors, $this->localization) : $this->respond($outcome, $account);
    }

    public function responderOff(Request $request, Identity $identity, string $username, string $address): Response
    {
        $account = $this->account($identity, $username);
        [$outcome] = $this->actions->setResponder($identity, $request, $account, $this->mailbox($account, $address), null);

        return $this->respond($outcome, $account);
    }

    /** {address, to} */
    public function forward(Request $request, Identity $identity, string $username): Response
    {
        $account = $this->account($identity, $username);
        [$local, $domain] = $this->split($account, (string) $request->input('address', ''));

        if ($domain === null) {
            return PackageApi::invalid(['address' => 'Mail.error_no_domain'], $this->localization);
        }

        return $this->respond($this->actions->addForwarder($identity, $request, $account, $domain, $local, (string) $request->input('to', '')), $account, 201);
    }

    public function unforward(Request $request, Identity $identity, string $username, string $id): Response
    {
        $account = $this->account($identity, $username);
        $forwarder = $this->mail->forwarder((int) $account['id'], (int) $id) ?? throw HttpException::notFound();
        $outcome = $this->actions->removeForwarder($identity, $request, $account, $forwarder);

        return $outcome->status === MailOutcome::DONE ? ApiResponse::noContent() : $this->respond($outcome, $account);
    }

    /** {catchall?: "refuse"|"discard"|address, local?: bool} */
    public function domain(Request $request, Identity $identity, string $username, string $domain): Response
    {
        $account = $this->account($identity, $username);
        $row = $this->mail->domain((int) $account['id'], $domain) ?? throw HttpException::notFound();
        $catchall = $request->input('catchall');
        $action = null;
        $to = '';

        if ($catchall !== null) {
            $catchall = (string) $catchall;
            [$action, $to] = \in_array($catchall, ['refuse', 'discard'], true) ? [$catchall, ''] : ['forward', $catchall];
        }

        $local = $request->input('local');
        $outcome = $this->actions->setDomain($identity, $request, $account, $row, $action, $to, $local === null ? null : \filter_var($local, \FILTER_VALIDATE_BOOLEAN));

        return $this->respond($outcome, $account);
    }

    // -------------------------------------------------------------------------
    // The mail servers

    public function server(): JsonResponse
    {
        try {
            $info = $this->agent->call('mail.info', [], timeout: 15.0);
        } catch (AgentUnavailable) {
            return new JsonResponse(['error' => 'unavailable', 'message' => $this->localization->get('Mail.error_unreachable')], 503);
        }

        return ApiResponse::item([
            'server' => $info->done() ? $info->result : null,
            'smarthost' => $this->mail->setting('smarthost'),
            'domains' => \array_map(static fn(array $d): array => [
                'name' => (string) $d['name'], 'account' => (string) $d['username'], 'mailboxes' => (int) $d['mailboxes'], 'local' => (bool) $d['local'],
            ], $this->mail->all()),
        ]);
    }

    /** {smarthost: "host[:port]" or ""} */
    public function settings(Request $request, Identity $identity): Response
    {
        $outcome = $this->actions->setSmarthost($identity, $request, (string) $request->input('smarthost', ''));

        return match ($outcome->status) {
            MailOutcome::REFUSED => $outcome->code === 'smarthost'
                ? PackageApi::invalid(['smarthost' => (string) $outcome->message], $this->localization)
                : new JsonResponse(['error' => 'conflict', 'message' => $this->localization->get((string) $outcome->message)], 409),
            MailOutcome::FAILED => new JsonResponse(['error' => $outcome->code, 'message' => $this->localization->get('Mail.refused'), 'detail' => $outcome->message], 502),
            default => ApiResponse::item(['smarthost' => $this->mail->setting('smarthost')]),
        };
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function present(array $account): array
    {
        $id = (int) $account['id'];
        $state = $this->sync->stateOf($id);

        return [
            'domains' => \array_map(static fn(array $d): array => [
                'name' => (string) $d['name'], 'local' => (bool) $d['local'],
                'catchall' => match (true) {
                    ($d['catchall'] ?? null) === null => 'refuse',
                    $d['catchall'] === Mailboxes::DISCARD => 'discard',
                    default => (string) $d['catchall'],
                },
                'dkim' => $d['dkim'],
            ], $this->mail->domains($id)),
            'mailboxes' => \array_map(static fn(array $b): array => [
                'address' => (string) $b['address'], 'quota_mb' => (int) $b['quota_mb'], 'used_bytes' => (int) $b['used_bytes'],
                'autoresponder' => $b['responder'] === null ? null : [
                    'subject' => $b['responder']['subject'], 'body' => $b['responder']['body'], 'from_name' => $b['responder']['from_name'],
                    'days' => (int) $b['responder']['days'], 'start' => $b['responder']['starts_on'], 'end' => $b['responder']['ends_on'],
                ],
                'created_at' => $b['created_at'],
            ], $this->mail->mailboxes($id)),
            'forwarders' => \array_map(static fn(array $f): array => ['id' => (int) $f['id'], 'address' => (string) $f['address'], 'to' => (string) $f['destination']], $this->mail->forwarders($id)),
            'state' => $state === null ? null : ['state' => $state['state'], 'error' => $state['error'], 'applied_at' => $state['applied_at']],
        ];
    }

    /** @param array<string, mixed> $account */
    private function respond(MailOutcome $outcome, array $account, int $done = 200): Response
    {
        return match ($outcome->status) {
            MailOutcome::REFUSED => \in_array($outcome->message, ['Mail.error_unreachable', 'Mail.error_limit'], true)
                ? new JsonResponse(['error' => 'conflict', 'message' => $this->localization->get((string) $outcome->message)], 409)
                : PackageApi::invalid([($outcome->code ?: 'input') => (string) $outcome->message], $this->localization),
            MailOutcome::FAILED => new JsonResponse(['error' => $outcome->code, 'message' => $this->localization->get('Mail.refused'), 'detail' => $outcome->message], 502),
            MailOutcome::PENDING => ApiResponse::accepted($this->present($account)),
            default => ApiResponse::item($this->present($account), $done),
        };
    }

    /**
     * An address of the account: its local part and its mail domain (null
     * when the account has no such domain).
     *
     * @param array<string, mixed> $account
     *
     * @return array{0: string, 1: ?array<string, mixed>}
     */
    private function split(array $account, string $address): array
    {
        $at = \strrpos($address, '@');

        if ($at === false) {
            return [$address, null];
        }

        return [\substr($address, 0, $at), $this->mail->domain((int) $account['id'], \strtolower(\substr($address, $at + 1)))];
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity, string $username): array
    {
        return $this->accounts->findByUsername($identity, $username) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function mailbox(array $account, string $address): array
    {
        return $this->mail->mailbox((int) $account['id'], \strtolower($address)) ?? throw HttpException::notFound();
    }
}
