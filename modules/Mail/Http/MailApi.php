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
use App\Modules\Mail\MailChecks;
use App\Modules\Mail\MailOutcome;
use App\Modules\Mail\MailQueue;
use App\Modules\Mail\MailSetup;
use App\Modules\Mail\MailSync;
use App\Modules\Mail\Webmail;
use App\Modules\Packages\Http\PackageApi;

/**
 * Mail through the API, with the same rules as the panels.
 *
 *   /admin/api/v1/accounts/{username}/email…   an account's (accounts.manage / accounts.modify):
 *       mailboxes, autoresponders, filters, forwarders, domains, spam, track
 *   /admin/api/v1/email…                       the mail servers (server.mail):
 *       state, settings (relay host), filtering, queue, track
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
        private readonly MailQueue $queue,
        private readonly Localization $localization,
        private readonly Webmail $webmail,
        private readonly MailSetup $setup,
        private readonly MailChecks $checks,
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

    public function filters(Identity $identity, string $username, string $address): JsonResponse
    {
        $account = $this->account($identity, $username);

        return ApiResponse::item(['address' => $address, 'filters' => \array_map(self::filter(...), $this->mailbox($account, $address)['filters'])]);
    }

    /** {field, test, value, action, target?, stop?}: added after the mailbox's other filters */
    public function addFilter(Request $request, Identity $identity, string $username, string $address): Response
    {
        $account = $this->account($identity, $username);
        $input = [];

        foreach (['field', 'test', 'value', 'action', 'target', 'stop'] as $key) {
            $v = $request->input($key);
            $input[$key] = \is_bool($v) ? $v : (\is_scalar($v) ? (string) $v : '');
        }

        [$outcome, $errors] = $this->actions->addFilter($identity, $request, $account, $this->mailbox($account, $address), $input);

        if ($errors !== []) {
            return PackageApi::invalid($errors, $this->localization);
        }

        return $this->respondWith($outcome, fn(): array => ['address' => $address, 'filters' => \array_map(self::filter(...), $this->mailbox($account, $address)['filters'])], 201);
    }

    public function removeFilter(Request $request, Identity $identity, string $username, string $id): Response
    {
        $account = $this->account($identity, $username);
        $filter = $this->mail->filter((int) $account['id'], (int) $id) ?? throw HttpException::notFound();
        $outcome = $this->actions->removeFilter($identity, $request, $account, $filter);

        return $outcome->status === MailOutcome::DONE ? ApiResponse::noContent() : $this->respond($outcome, $account);
    }

    public function spam(Identity $identity, string $username): JsonResponse
    {
        return ApiResponse::item($this->presentSpam($this->account($identity, $username)));
    }

    /** {junk?: score|0, delete?: score|0, allow?: [..], block?: [..]}: what is left out stays */
    public function setSpam(Request $request, Identity $identity, string $username): Response
    {
        $account = $this->account($identity, $username);
        $now = $this->mail->spam((int) $account['id']);
        $input = [
            'junk' => $request->input('junk') ?? MailToolPages::score($now['junk']),
            'delete' => $request->input('delete') ?? MailToolPages::score($now['delete']),
            'allow' => $request->input('allow') ?? $now['allow'],
            'block' => $request->input('block') ?? $now['block'],
        ];

        foreach (['junk', 'delete'] as $key) {
            if ($input[$key] === false || $input[$key] === '0' || $input[$key] === 0) {
                $input[$key] = '';
            }
        }

        [$outcome, $errors] = $this->actions->setSpam($identity, $request, $account, $input);

        if ($errors !== []) {
            return PackageApi::invalid($errors, $this->localization);
        }

        return $this->respondWith($outcome, fn(): array => $this->presentSpam($account));
    }

    /** ?search=&status=&days= */
    public function accountTrack(Request $request, Identity $identity, string $username): Response
    {
        $account = $this->account($identity, $username);

        return $this->tracked((string) $account['username'], $request);
    }

    /** Deliverability of the account's mail domains, and of the server's address. */
    public function accountChecks(Identity $identity, string $username): Response
    {
        return $this->checked($this->mail->domains((int) $this->account($identity, $username)['id']));
    }

    /** What a mail client needs for a mailbox. */
    public function setup(Identity $identity, string $username, string $address): Response
    {
        $account = $this->account($identity, $username);

        return ApiResponse::item($this->setup->settings($account, (string) $this->mailbox($account, $address)['address']));
    }

    /**
     * A one-time address that opens webmail as the mailbox, with no password
     * (good for a minute): for a billing system's or a support tool's
     * "open webmail" link.
     */
    public function webmail(Request $request, Identity $identity, string $username, string $address): Response
    {
        $account = $this->account($identity, $username);
        [$url, $why] = $this->webmail->signOn($identity, $request, $account, $this->mailbox($account, $address), absolute: true);

        if ($url === null) {
            return new JsonResponse(['error' => 'conflict', 'message' => $this->localization->get((string) $why)], 409);
        }

        return ApiResponse::item(['url' => $url, 'expires_in' => Webmail::TICKET_SECONDS]);
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

    /**
     * {smarthost: "host[:port]" or "", relay_user?: "" for no login,
     * relay_password?: kept when left out with the same user}
     */
    public function settings(Request $request, Identity $identity): Response
    {
        $user = $request->input('relay_user');
        $outcome = $this->actions->setSmarthost(
            $identity,
            $request,
            (string) $request->input('smarthost', ''),
            $user === null ? $this->mail->setting('smarthost_user') : (string) $user,
            (string) $request->input('relay_password', ''),
        );

        return $this->serverReply($outcome, ['smarthost', 'relay_user', 'relay_password'], fn(): array => ['smarthost' => $this->mail->setting('smarthost'), 'relay_user' => $this->mail->setting('smarthost_user')]);
    }

    /** {spam: bool, virus: bool, reject?: score} */
    public function filtering(Request $request, Identity $identity): Response
    {
        $bool = static fn(mixed $v): bool => \filter_var($v, \FILTER_VALIDATE_BOOLEAN);
        $reject = $request->input('reject') ?? MailToolPages::score((int) $this->mail->setting('reject', '150'));
        $outcome = $this->actions->setFiltering($identity, $request, $bool($request->input('spam', false)), $bool($request->input('virus', false)), \is_scalar($reject) ? (string) $reject : '');

        return $this->serverReply($outcome, ['reject', 'virus'], fn(): array => [
            'spam' => $this->mail->setting('spam', 'off') === 'on', 'virus' => $this->mail->setting('virus', 'off') === 'on', 'reject' => (int) $this->mail->setting('reject', '150') / 10,
        ]);
    }

    /** ?search= */
    public function queue(Request $request): Response
    {
        [$list, $error] = $this->queue->list((string) $request->query('search', ''), 1000);

        return $list === null ? $this->unavailable($error) : ApiResponse::item($list);
    }

    public function queueMessage(string $id): Response
    {
        [$message, $error] = $this->queue->view($id);

        if ($message === null && $error === 'Mail.error_queue_none') {
            throw HttpException::notFound();
        }

        return $message === null ? new JsonResponse(['error' => 'not_found', 'message' => (string) $error], 404) : ApiResponse::item($message);
    }

    /** {action: deliver|remove|freeze|thaw|flush, ids: [..]} */
    public function queueAct(Request $request, Identity $identity): Response
    {
        $ids = $request->input('ids', []);
        $ids = \is_array($ids) ? \array_values(\array_map(static fn(mixed $v): string => \is_scalar($v) ? (string) $v : '', $ids)) : [];
        $outcome = $this->actions->queueAction($identity, $request, (string) $request->input('action', ''), $ids);

        return $this->serverReply($outcome, ['action', 'ids'], static fn(): array => $outcome->result);
    }

    /** ?account=&search=&status=&days= */
    /** Deliverability of the server's address (its reverse name, the blocklists). */
    public function serverChecks(): Response
    {
        return $this->checked([]);
    }

    /** @param list<array<string, mixed>> $domains */
    private function checked(array $domains): Response
    {
        $result = $this->checks->run($domains);

        if ($result['error'] !== null) {
            return $this->unavailable($result['error']);
        }

        $text = fn(array $c): array => $c + ['message' => $this->localization->get((string) $c['text'], (array) $c['args'])];
        $domains = [];

        foreach ($result['domains'] as $d) {
            $domains[] = ['checks' => \array_map($text, $d['checks']), 'worst' => MailChecks::worst($d['checks'])] + $d;
        }

        return ApiResponse::item([
            'domains' => $domains,
            'server' => ['checks' => \array_map($text, (array) ($result['server']['checks'] ?? []))] + $result['server'],
        ]);
    }

    public function track(Request $request): Response
    {
        $account = (string) $request->query('account', '');

        return $this->tracked($account === '' ? null : $account, $request);
    }

    private function tracked(?string $username, Request $request): Response
    {
        [$result, $error] = $this->queue->track($username, (string) $request->query('search', ''), (string) $request->query('status', ''), (int) $request->query('days', '7'), 1000);

        return $result === null ? $this->unavailable($error) : ApiResponse::item($result);
    }

    private function unavailable(?string $error): JsonResponse
    {
        return new JsonResponse(['error' => 'unavailable', 'message' => $this->localization->get((string) $error)], 503);
    }

    /**
     * @param list<string>                  $fields refusals that are invalid input
     * @param \Closure(): array<string, mixed> $done
     */
    private function serverReply(MailOutcome $outcome, array $fields, \Closure $done): Response
    {
        return match ($outcome->status) {
            MailOutcome::REFUSED => \in_array($outcome->code, $fields, true)
                ? PackageApi::invalid([$outcome->code => (string) $outcome->message], $this->localization)
                : new JsonResponse(['error' => 'conflict', 'message' => $this->localization->get((string) $outcome->message)], 409),
            MailOutcome::FAILED => new JsonResponse(['error' => $outcome->code, 'message' => $this->localization->get('Mail.refused'), 'detail' => $outcome->message], 502),
            default => ApiResponse::item($done()),
        };
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private static function filter(array $f): array
    {
        return ['id' => (int) $f['id'], 'field' => (string) $f['field'], 'test' => (string) $f['test'], 'value' => (string) $f['value'], 'action' => (string) $f['action'], 'target' => $f['target'], 'stop' => (bool) $f['stop']];
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    private function presentSpam(array $account): array
    {
        $s = $this->mail->spam((int) $account['id']);

        return ['junk' => $s['junk'] / 10, 'delete' => $s['delete'] / 10, 'allow' => $s['allow'], 'block' => $s['block'], 'filtering' => $this->mail->setting('spam', 'off') === 'on'];
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
                'filters' => \array_map(self::filter(...), $b['filters']),
                'created_at' => $b['created_at'],
            ], $this->mail->mailboxes($id)),
            'forwarders' => \array_map(static fn(array $f): array => ['id' => (int) $f['id'], 'address' => (string) $f['address'], 'to' => (string) $f['destination']], $this->mail->forwarders($id)),
            'state' => $state === null ? null : ['state' => $state['state'], 'error' => $state['error'], 'applied_at' => $state['applied_at']],
        ];
    }

    /**
     * Like respond(), with the body done() gives.
     *
     * @param \Closure(): array<string, mixed> $done
     */
    private function respondWith(MailOutcome $outcome, \Closure $done, int $status = 200): Response
    {
        return match ($outcome->status) {
            MailOutcome::REFUSED => \in_array($outcome->message, ['Mail.error_unreachable', 'Mail.error_limit'], true)
                ? new JsonResponse(['error' => 'conflict', 'message' => $this->localization->get((string) $outcome->message)], 409)
                : PackageApi::invalid([($outcome->code ?: 'input') => (string) $outcome->message], $this->localization),
            MailOutcome::FAILED => new JsonResponse(['error' => $outcome->code, 'message' => $this->localization->get('Mail.refused'), 'detail' => $outcome->message], 502),
            MailOutcome::PENDING => ApiResponse::accepted($done()),
            default => ApiResponse::item($done(), $status),
        };
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
