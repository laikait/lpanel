<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\ApiTokens;
use App\Modules\Panel\Ui\PanelPage;

/**
 * Your API tokens, for the REST API under /admin/api/v1.
 */
final class TokenPages
{
    /** How long a token may be made to last, in days; "never" is also offered. */
    public const LIFETIMES = [30, 90, 365];

    public function __construct(
        private readonly ApiTokens $tokens,
        private readonly AuditLog $audit,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->page($request, $session, $identity);
    }

    public function create(Request $request, Session $session, Identity $identity): Response
    {
        $name = \trim((string) $request->input('name', ''));
        $days = (string) $request->input('days', '90');

        if ($name === '' || \mb_strlen($name) > 80) {
            return $this->page($request, $session, $identity, ['errors' => ['name' => 'Identity.error_token_name'], 'old' => ['name' => $name]], 422);
        }

        $lifetime = \in_array((int) $days, self::LIFETIMES, true) ? (int) $days : null;
        $made = $this->tokens->issue($identity->id, $name, $lifetime);
        $this->audit->record('api_token.create', $identity, $request, $name, AuditLog::OK, ['id' => $made['id'], 'days' => $lifetime]);

        return $this->page($request, $session, $identity, ['new_token' => $made['token'], 'new_name' => $name]);
    }

    public function revoke(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $row = $this->tokens->find($identity->id, $id) ?? throw HttpException::notFound();
        $this->tokens->revoke($identity->id, $id);
        $this->audit->record('api_token.revoke', $identity, $request, (string) $row['name'], AuditLog::OK, ['id' => $id]);
        PanelPage::notice($session, 'success', 'Identity.token_revoked');

        return new RedirectResponse($this->router->url('admin.tokens'), 303);
    }

    /** @param array<string, mixed> $extra */
    private function page(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $tokens = [];

        foreach ($this->tokens->of($identity->id) as $row) {
            $row['revoke_url'] = $this->router->url('admin.tokens.revoke', ['id' => $row['id']]);
            $tokens[] = $row;
        }

        return $this->pages->render($request, $session, $identity, 'admin', '@Identity/tokens', $extra + [
            'tokens' => $tokens,
            'action' => $this->router->url('admin.tokens.create'),
            'lifetimes' => self::LIFETIMES,
            'api_base' => ($request->isSecure() ? 'https://' : 'http://') . $request->header('Host', 'localhost') . $request->basePath() . '/admin/api/v1',
            'errors' => [],
            'old' => [],
            'new_token' => null,
        ], title: 'Identity.tokens', active: 'tokens', status: $status);
    }
}
