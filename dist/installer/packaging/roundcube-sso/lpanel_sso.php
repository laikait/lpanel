<?php

/**
 * LPanel's single sign-on into Roundcube.
 *
 * The User panel's "Open webmail" asks the agent for a sign-on password for
 * the mailbox (good for a few hours; Dovecot accepts it beside the mailbox's
 * own), writes the login to a one-time ticket file and sends the browser to
 * /webmail/?_task=login&_lpanel=<ticket>. Here the ticket is read, deleted
 * at once (so it works a single time), checked for expiry, and its login is
 * used as if typed into the login form. The password never appears in a URL,
 * a cookie or the browser.
 */
class lpanel_sso extends rcube_plugin
{
    public $task = 'login';

    private const TICKETS = '/var/lib/lpanel-webmail/tickets';

    /** @var array{user: string, password: string}|null */
    private $login;

    #[\Override]
    public function init()
    {
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function startup($args)
    {
        $token = isset($_GET['_lpanel']) && is_string($_GET['_lpanel']) ? $_GET['_lpanel'] : '';

        if ($token === '' || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return $args;
        }

        $path = self::TICKETS . '/' . $token;
        $text = @file_get_contents($path);
        @unlink($path);
        $ticket = is_string($text) ? json_decode($text, true) : null;

        if (is_array($ticket) && is_string($ticket['user'] ?? null) && is_string($ticket['password'] ?? null) && (int) ($ticket['expires'] ?? 0) >= time()) {
            $this->login = ['user' => $ticket['user'], 'password' => $ticket['password']];
            // Whoever was signed in here before is signed out first.
            rcmail::get_instance()->kill_session();
            $args['action'] = 'login';
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function authenticate($args)
    {
        if ($this->login !== null) {
            $args['user'] = $this->login['user'];
            $args['pass'] = $this->login['password'];
            $args['cookiecheck'] = false;
            $args['valid'] = true;
            $this->login = null;
        }

        return $args;
    }
}
