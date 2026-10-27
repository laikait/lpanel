<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Tokens\ApiToken;
use App\Modules\Identity\Tokens\TokenStore;
use App\Modules\Identity\Users\User;
use App\Modules\Identity\Users\UserStore;
use App\Modules\Shared\Ui\Pages;

/**
 * /account/tokens: API tokens for your own scripts.
 *
 * A token acts as you, with your roles as they are at each request, on the
 * /api/token/v1 routes only. It is shown once, when it is made. Root has
 * none: a token would be a way in as root that skips root's IP allowlist
 * and lockout (ADR-0010), so automation runs as an administrator instead.
 */
final class TokensPage
{
    public function __construct(
        private readonly Pages $pages,
        private readonly TokenStore $tokens,
        private readonly UserStore $users,
        private readonly AuditLog $audit,
    ) {}

    public function index(Request $request, Identity $identity): Response
    {
        return $this->page($request, $identity, []);
    }

    public function store(Request $request, Identity $identity): Response
    {
        $user = $this->user($identity);
        $name = $request->input('name');
        $days = \filter_var($request->input('days'), \FILTER_VALIDATE_INT);

        if ($user->kind !== User::KIND_PANEL) {
            return $this->page($request, $identity, ['error' => 'Root cannot have API tokens. Create an administrator for automation.'], 403);
        }

        if (!\is_string($name) || \trim($name) === '' || \mb_strlen(\trim($name)) > 100) {
            return $this->page($request, $identity, ['error' => 'Give the token a name of up to 100 characters, so you know what uses it.', 'old' => ['days' => $days]], 422);
        }

        if (!\is_int($days) || !\in_array($days, TokenStore::LIFETIMES, true)) {
            return $this->page($request, $identity, ['error' => 'Choose how long the token lasts.', 'old' => ['name' => $name]], 422);
        }

        if ($this->tokens->activeCount($user->id) >= TokenStore::MAX_ACTIVE) {
            return $this->page($request, $identity, ['error' => \sprintf('You have %d active tokens, the most allowed. Revoke one first.', TokenStore::MAX_ACTIVE)], 422);
        }

        [$token, $plain] = $this->tokens->issue($user->id, \trim($name), $days);
        $this->audit->record('api_credential.create', 'succeeded', $identity, 'api_credential', $token->uid, ['name' => $token->name, 'days' => $days]);

        // Shown on this response only (no-store); from here on only its fingerprint exists.
        return $this->page($request, $identity, ['created' => ['name' => $token->name, 'value' => $plain]], 201);
    }

    public function revoke(Identity $identity, string $id): Response
    {
        $user = $this->user($identity);

        if ($this->tokens->revoke($user->id, $id)) {
            $this->audit->record('api_credential.revoke', 'succeeded', $identity, 'api_credential', $id);
            $this->pages->flash('success', 'The token is revoked. Anything still using it will get 401.');
        }

        return new RedirectResponse($this->pages->url('account.tokens'), 303);
    }

    private function user(Identity $identity): User
    {
        return $this->users->byUid($identity->id) ?? throw new \RuntimeException('The signed-in account no longer exists.');
    }

    /** @param array<string, mixed> $data */
    private function page(Request $request, Identity $identity, array $data, int $status = 200): Response
    {
        $user = $this->user($identity);

        return $this->pages->render($request, '@Identity/tokens', $data + [
            'tokens' => \array_map(fn(ApiToken $t): array => [
                'token' => $t,
                'state' => $t->state(),
                'revoke' => $this->pages->url('account.tokens.revoke', ['id' => $t->uid]),
            ], $this->tokens->forUser($user->id)),
            'allowed' => $user->kind === User::KIND_PANEL,
            'lifetimes' => TokenStore::LIFETIMES,
            'error' => null,
            'old' => [],
            'created' => null,
            'store_url' => $this->pages->url('account.tokens.store'),
            // For the example on the page only; links that leave the panel use APP_URL.
            'api_base' => \substr($request->url(), 0, \strlen($request->url()) - \strlen($request->uri())) . $request->basePath() . '/api/token/v1',
        ], $status, $identity, 'tokens');
    }
}
