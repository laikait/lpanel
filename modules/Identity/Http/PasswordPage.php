<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\AuthManager;
use App\Engine\Auth\PasswordReset;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Logging\LogManager;
use App\Engine\Mail\MailException;
use App\Engine\Security\Secret;
use App\Modules\Shared\Ui\Pages;

/**
 * GET/POST /password/forgot and GET/POST /password/reset (?token=…).
 *
 * The forgot form answers the same for every login, registered or not, and
 * for root (whose password is the server's, so it gets no link). The link
 * works once, for an hour (Auth\PasswordReset), and using it ends every
 * session the account had. It does not sign the visitor in: they sign in
 * with the new password, and with their second factor if they have one.
 */
final class PasswordPage
{
    private const SENT = 'If that account has an email address, a link to choose a new password is on its way. It works for one hour.';

    public function __construct(
        private readonly Pages $pages,
        private readonly PasswordReset $resets,
        private readonly AuthManager $auth,
        private readonly LogManager $logs,
    ) {}

    public function forgot(Request $request): Response
    {
        if ($this->auth->check()) {
            return new RedirectResponse($this->pages->url('account.security'), 303);
        }

        return $this->pages->render($request, '@Identity/forgot', ['error' => null, 'sent' => false, 'old' => '']);
    }

    public function sendLink(Request $request): Response
    {
        $login = $request->input('login');

        if (!\is_string($login) || \trim($login) === '' || \mb_strlen($login) > 190) {
            return $this->pages->render($request, '@Identity/forgot', ['error' => 'Enter your login name.', 'sent' => false, 'old' => ''], 422);
        }

        try {
            $allowed = $this->resets->request(\trim($login), $request->ip());
        } catch (MailException $e) {
            // Only an account with an address reaches the mailer, so a failure
            // must look like success here: a 500 would say "this one exists".
            $this->logs->channel('mail')->error('A password reset email could not be sent: ' . $e->getMessage());
            $allowed = true;
        }

        if (!$allowed) {
            return $this->pages->render($request, '@Identity/forgot', [
                'error' => 'Too many links were asked for. Try again in 15 minutes.',
                'sent' => false,
                'old' => \mb_substr($login, 0, 190),
            ], 429)->withHeader('Retry-After', '900');
        }

        return $this->pages->render($request, '@Identity/forgot', ['error' => null, 'sent' => true, 'message' => self::SENT, 'old' => '']);
    }

    public function show(Request $request): Response
    {
        $token = $request->query('token');
        $valid = \is_string($token) && $this->resets->check($token) !== null;

        return $this->form($request, $valid ? $token : '', $valid ? null : 'This link has expired or has already been used. Ask for a new one.', $valid ? 200 : 410);
    }

    public function reset(Request $request): Response
    {
        $token = $request->input('token');
        $password = $request->input('password');
        $confirm = $request->input('password_confirmation');

        if (!\is_string($token) || $this->resets->check($token) === null) {
            return $this->form($request, '', 'This link has expired or has already been used. Ask for a new one.', 410);
        }

        if (!\is_string($password) || \strlen($password) < 12 || \strlen($password) > 1024) {
            return $this->form($request, $token, 'Choose a password of at least 12 characters.', 422);
        }

        if ($password !== $confirm) {
            return $this->form($request, $token, 'The two passwords are not the same.', 422);
        }

        if ($this->resets->reset($token, new Secret($password)) === null) {
            return $this->form($request, '', 'This link has expired or has already been used. Ask for a new one.', 410);
        }

        $this->pages->flash('success', 'Your password has changed and every other session has ended. Sign in with the new password.');

        return new RedirectResponse($this->pages->url('login.show'), 303);
    }

    private function form(Request $request, string $token, ?string $error, int $status): Response
    {
        return $this->pages->render($request, '@Identity/reset', ['reset_token' => $token, 'error' => $error, 'reset_url' => $this->pages->url('password.reset.submit')], $status)
            // The token is in this page's URL: it must not leave in a Referer header.
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
