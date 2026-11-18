<?php

declare(strict_types=1);

namespace App\Modules\Files\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Files\Ssh\SshActions;
use App\Modules\Files\Ssh\SshKeys;
use App\Modules\Files\Ssh\SshOutcome;
use App\Modules\Files\Ssh\SshSync;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;
use App\Modules\Web\AutoSsl;
use App\Engine\Auth\Authorizer;

/**
 * "SSH access" in the User panel: what the package allows (a jailed shell
 * and SFTP, or SFTP alone), the keys that may log in, adding a key or
 * having one made, and how to connect.
 *
 * A key the panel makes is shown on the page that answers the form, once:
 * it is not kept, not put in the session and not sent again.
 */
final class SshPages
{
    public function __construct(
        private readonly SshKeys $keys,
        private readonly SshActions $actions,
        private readonly SshSync $sync,
        private readonly Subusers $subusers,
        private readonly Authorizer $authorizer,
        private readonly AutoSsl $ssl,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->page($request, $session, $identity);
    }

    public function store(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $old = ['name' => (string) $request->input('name', ''), 'key' => (string) $request->input('key', '')];
        $outcome = $this->actions->add($identity, $request, $account, $old['name'], $old['key']);

        return $this->after($request, $session, $identity, $outcome, 'Files.ssh_added', $old, 'add');
    }

    public function generate(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $name = (string) $request->input('name', '');
        [$outcome, $private] = $this->actions->generate($identity, $request, $account, $name);

        if ($private === null) {
            return $this->after($request, $session, $identity, $outcome, 'Files.ssh_generated', ['gen_name' => $name], 'gen');
        }

        // Answered here, not after a redirect: the private key goes nowhere else.
        $key = $this->keys->key((int) $account['id'], \trim($name));

        return $this->page($request, $session, $identity, ['generated' => [
            'name' => \trim($name),
            'private' => $private,
            'file' => 'id_ed25519_' . \preg_replace('/[^a-z0-9]+/', '_', \strtolower(\trim($name))),
            'fingerprint' => $key['fingerprint'] ?? '',
            'pending' => $outcome->status === SshOutcome::PENDING,
        ]]);
    }

    public function delete(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->account($identity);
        $key = (\ctype_digit($id) ? $this->keys->key((int) $account['id'], (int) $id) : null) ?? throw HttpException::notFound();

        return $this->after($request, $session, $identity, $this->actions->delete($identity, $request, $account, $key), 'Files.ssh_deleted');
    }

    /**
     * Back to the page with a notice; a refused form shows again with its
     * error beside the field and what was typed.
     *
     * @param array<string, string> $old
     */
    private function after(Request $request, Session $session, Identity $identity, SshOutcome $outcome, string $done, array $old = [], string $form = ''): Response
    {
        if ($outcome->status === SshOutcome::REFUSED && $form !== '' && $outcome->code !== '') {
            return $this->page($request, $session, $identity, [
                'errors' => [$form . '_' . $outcome->code => (string) $outcome->message],
                'old' => $old,
            ], 422);
        }

        match ($outcome->status) {
            SshOutcome::REFUSED => PanelPage::notice($session, 'error', (string) $outcome->message),
            SshOutcome::FAILED => PanelPage::notice($session, 'error', 'Files.ssh_refused'),
            SshOutcome::PENDING => PanelPage::notice($session, 'warning', 'Files.ssh_pending'),
            default => PanelPage::notice($session, 'success', $done),
        };

        return new RedirectResponse($this->router->url('user.ssh'), 303);
    }

    /** @param array<string, mixed> $extra */
    private function page(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $account = $this->account($identity);
        $id = (int) $account['id'];
        $keys = \array_map(fn(array $k): array => $k + [
            'line' => SshKeys::line($k),
            'delete_url' => $this->router->url('user.ssh.delete', ['id' => $k['id']]),
        ], $this->keys->keys($id));

        return $this->pages->render($request, $session, $identity, 'user', '@Files/ssh', $extra + [
            'account' => $account,
            'access' => $this->actions->access($account),
            'keys' => $keys,
            'max_keys' => SshKeys::MAX_KEYS,
            'host' => $this->ssl->hostname(),
            'state' => $this->sync->stateOf($id),
            'generated' => null,
            'errors' => [],
            'old' => [],
            'store_url' => $this->router->url('user.ssh.store'),
            'generate_url' => $this->router->url('user.ssh.generate'),
        ], title: 'Files.ssh_title', active: 'ssh', status: $status);
    }

    /**
     * The account, for an owner or a sub-user allowed SSH or SFTP.
     *
     * @return array<string, mixed>
     */
    private function account(Identity $identity): array
    {
        if (!$this->authorizer->allows($identity, 'feature.ssh') && !$this->authorizer->allows($identity, 'feature.sftp')) {
            throw HttpException::forbidden();
        }

        return $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
    }
}
