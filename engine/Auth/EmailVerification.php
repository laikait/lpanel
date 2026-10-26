<?php

declare(strict_types=1);

namespace App\Engine\Auth;

use App\Engine\Hook\HookEngine;
use App\Engine\Mail\Mailer;
use App\Engine\Mail\Message;
use App\Engine\Routing\AppUrl;
use App\Engine\Security\Signer;

/**
 * Proving an account owns its email address.
 *
 *     $verification->send($account);                  // after sign-up, or "send it again"
 *
 *     // GET /verify-email?token=…
 *     $identity = $verification->verify($token);      // null: invalid or expired
 *
 * The link points at the route named auth.verification.route (email.verify)
 * under APP_URL. The token is signed (APP_KEY), lasts a day, and is bound to
 * the address it was sent to: change the address and the old link stops
 * working, so proving one address can never verify another.
 *
 * Verifying twice is harmless, so a link is not single-use; it is the
 * address that matters, and it is the same address.
 */
final class EmailVerification
{
    public const PURPOSE = 'email-verification';

    public const TTL = 86400;

    public const TEMPLATE = 'emails/verify-email';

    public function __construct(
        private readonly UserProvider $provider,
        private readonly Signer $signer,
        private readonly Mailer $mailer,
        private readonly AppUrl $urls,
        private readonly ?HookEngine $hooks = null,
        private readonly int $ttl = self::TTL,
        private readonly string $route = 'email.verify',
    ) {}

    /** @throws AuthException when the provider cannot verify, or the account has no address */
    public function send(Account $account): void
    {
        $email = $this->verifiable()->emailFor($account);

        if ($email === null || $email === '') {
            throw AuthException::noEmail($account->identity->id);
        }

        $this->mailer->queue(Message::create()
            ->to($email, $account->identity->name)
            ->subject('Confirm your email address')
            ->view(self::TEMPLATE, [
                'link' => $this->urls->route($this->route, ['token' => $this->token($account, $email)]),
                'name' => $account->identity->name,
                'hours' => \intdiv($this->ttl, 3600),
            ]));
    }

    public function token(Account $account, ?string $email = null): string
    {
        $email ??= $this->verifiable()->emailFor($account) ?? throw AuthException::noEmail($account->identity->id);

        return AccountToken::issue($this->signer, self::PURPOSE, [
            'sub' => $account->identity->id,
            'exp' => \time() + $this->ttl,
            'em' => AccountToken::fingerprint(\mb_strtolower($email)),
        ]);
    }

    /** @return ?Identity the account now verified, or null when the token is not good */
    public function verify(string $token): ?Identity
    {
        $provider = $this->verifiable();
        $claims = AccountToken::read($this->signer, self::PURPOSE, $token);

        if ($claims === null || !\is_string($claims['sub'] ?? null) || !\is_string($claims['em'] ?? null)) {
            return null;
        }

        $account = $this->provider->byId($claims['sub']);
        $email = $account === null ? null : $provider->emailFor($account);

        if ($account === null || !$account->active || $email === null
            || !Signer::matches(AccountToken::fingerprint(\mb_strtolower($email)), $claims['em'])
        ) {
            return null;
        }

        if (!$provider->isEmailVerified($account)) {
            $provider->markEmailVerified($account);
            $this->hooks?->do('auth.email_verified', $account->identity);
        }

        return $account->identity;
    }

    private function verifiable(): EmailVerifiable
    {
        return $this->provider instanceof EmailVerifiable
            ? $this->provider
            : throw AuthException::providerLacks(EmailVerifiable::class, $this->provider::class, 'Email verification');
    }
}
