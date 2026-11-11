<?php

declare(strict_types=1);

namespace App\Modules\Web\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Identity\PanelUsers;
use App\Modules\Web\DomainActions;
use App\Modules\Web\Domains;
use App\Modules\Web\PhpSettings;
use App\Modules\Web\SiteFeatures;
use App\Modules\Web\WebOutcome;
use App\Modules\Web\WebSync;

/**
 * A site's features and the account's php.ini settings from the shell, as
 * root: the same actions as the User panel and the API. Passwords are read
 * from standard input, never from the command line.
 */
final class SiteCommands
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly Domains $domains,
        private readonly SiteFeatures $features,
        private readonly PhpSettings $php,
        private readonly DomainActions $actions,
        private readonly PanelUsers $users,
        private readonly Localization $localization,
    ) {}

    public function show(Output $output, string $username, string $domain): int
    {
        [$account, $row] = $this->find($output, $username, $domain);

        if ($row === null) {
            return 1;
        }

        $id = (int) $row['id'];
        $output->line(\sprintf('%s (%s) for %s', $row['name'], $row['kind'], $account['username']));

        if ($row['kind'] === Domains::ALIAS) {
            return 0;
        }

        $output->line('  folder     ' . $row['docroot']);
        $output->line('  PHP        ' . WebSync::phpLabel((string) $row['php']));
        $output->line('  address    ' . match ($row['canonical'] ?? null) {
            'www' => 'www.' . $row['name'], 'bare' => $row['name'], default => 'either'
        });
        $output->line('  wildcard   ' . ($row['wildcard'] ? 'yes' : 'no'));
        $output->line('  hotlink    ' . ($row['hotlink'] === null ? 'off' : 'on: ' . \implode(',', $row['hotlink']['extensions']) . ($row['hotlink']['allow'] === [] ? '' : ' (also ' . \implode(',', $row['hotlink']['allow']) . ')')));

        foreach ($row['error_pages'] as $code => $page) {
            $output->line(\sprintf('  error %s  %s', $code, $page));
        }

        foreach ($this->features->redirects($id) as $r) {
            $output->line(\sprintf('  redirect   %s -> %s (%d)', $r['path'], $r['target'], $r['code']));
        }

        foreach ($this->features->protected($id) as $p) {
            $output->line(\sprintf('  protected  /%s "%s": %s', $p['path'], $p['realm'], $p['logins'] === [] ? 'no logins' : \implode(', ', $p['logins'])));
        }

        return 0;
    }

    public function redirect(Output $output, string $username, string $domain, string $path, string $to, bool $temporary = false): int
    {
        [$account, $row] = $this->find($output, $username, $domain);

        return $row === null ? 1 : $this->report($output, $this->actions->addRedirect($this->root(), null, $account, $row, ['path' => $path, 'to' => $to, 'code' => $temporary ? 302 : 301]), $path . ' now redirects to ' . $to . '.');
    }

    public function unredirect(Output $output, string $username, string $domain, string $path): int
    {
        [$account, $row] = $this->find($output, $username, $domain);
        $path = '/' . \ltrim($path, '/');
        $redirect = null;

        foreach ($row === null ? [] : $this->features->redirects((int) $row['id']) as $r) {
            $redirect = $r['path'] === $path ? $r : $redirect;
        }

        if ($row === null || $redirect === null) {
            $output->error($row === null ? '' : \sprintf('%s does not redirect %s.', $domain, $path));

            return 1;
        }

        return $this->report($output, [$this->actions->removeRedirect($this->root(), null, $account, $row, $redirect), []], $path . ' no longer redirects.');
    }

    public function errorPage(Output $output, string $username, string $domain, string $code, ?string $page = null): int
    {
        [$account, $row] = $this->find($output, $username, $domain);

        if ($row === null) {
            return 1;
        }

        $pages = $row['error_pages'];
        $pages[$code] = $page ?? '';

        return $this->report($output, $this->actions->errorPages($this->root(), null, $account, $row, $pages), $page === null ? "{$code} shows the standard page." : "{$code} shows {$page}.");
    }

    public function hotlink(Output $output, string $username, string $domain, ?string $extensions = null, ?string $allow = null, bool $off = false): int
    {
        [$account, $row] = $this->find($output, $username, $domain);

        if ($row === null) {
            return 1;
        }

        $extensions ??= \implode(',', $row['hotlink']['extensions'] ?? SiteFeatures::HOTLINK_DEFAULT);
        $allow ??= \implode(',', $row['hotlink']['allow'] ?? []);

        return $this->report($output, $this->actions->hotlink($this->root(), null, $account, $row, !$off, $extensions, $allow), 'Hotlink protection is ' . ($off ? 'off.' : 'on.'));
    }

    /** With --login, that login's password is read from standard input. */
    public function protect(Output $output, string $username, string $domain, ?string $folder = null, ?string $realm = null, ?string $login = null): int
    {
        [$account, $row] = $this->find($output, $username, $domain);

        if ($row === null) {
            return 1;
        }

        $input = ['path' => (string) $folder, 'realm' => (string) $realm];

        if ($login !== null) {
            $input += ['login' => $login, 'password' => self::password()];
        }

        return $this->report($output, $this->actions->protect($this->root(), null, $account, $row, $input), '/' . \trim((string) $folder, '/') . ' now asks for a password.');
    }

    public function unprotect(Output $output, string $username, string $domain, ?string $folder = null): int
    {
        [$account, $row, $protected] = $this->folder($output, $username, $domain, $folder);

        return $protected === null ? 1 : $this->report($output, [$this->actions->unprotect($this->root(), null, $account, $row, $protected), []], '/' . $protected['path'] . ' no longer asks for a password.');
    }

    /** Adds the login, or sets its password; the password is read from standard input. */
    public function protectLogin(Output $output, string $username, string $domain, string $folder, string $login, bool $remove = false): int
    {
        [$account, $row, $protected] = $this->folder($output, $username, $domain, $folder);

        if ($protected === null) {
            return 1;
        }

        if ($remove) {
            return $this->report($output, [$this->actions->removeLogin($this->root(), null, $account, $row, $protected, $login), []], $login . ' removed.');
        }

        return $this->report($output, $this->actions->setLogin($this->root(), null, $account, $row, $protected, $login, self::password()), $login . ' may open /' . $protected['path'] . '.');
    }

    /**
     * Show one version's settings, or replace them: --set="memory_limit=256M,display_errors=On".
     * --clear puts every one back to PHP's default.
     */
    public function phpSettings(Output $output, string $username, string $version, ?string $set = null, bool $clear = false): int
    {
        $account = $this->accounts->findByUsernameUnscoped($username);
        $version = \str_replace('.', '', $version);

        if ($account === null) {
            $output->error(\sprintf('There is no account named %s.', $username));

            return 1;
        }

        if ($set === null && !$clear) {
            foreach ($this->php->of((int) $account['id'], $version) as $directive => $value) {
                $output->line(\sprintf('%-24s %s', $directive, $value));
            }

            return 0;
        }

        $settings = $clear ? [] : $this->php->of((int) $account['id'], $version);

        foreach ($set === null ? [] : \explode(',', $set) as $pair) {
            [$directive, $value] = \array_pad(\explode('=', $pair, 2), 2, '');
            $settings[\trim($directive)] = \trim($value);
        }

        return $this->report($output, $this->actions->phpSettings($this->root(), null, $account, $version, $settings), 'PHP ' . WebSync::phpLabel($version) . ' settings saved.');
    }

    // -------------------------------------------------------------------------

    /** @param array{0: ?WebOutcome, 1: array<string, string>} $result */
    private function report(Output $output, array $result, string $done): int
    {
        [$outcome, $errors] = $result;

        if ($outcome === null) {
            foreach ($errors as $field => $key) {
                $output->error($field . ': ' . $this->localization->get($key));
            }

            return 2;
        }

        switch ($outcome->status) {
            case WebOutcome::DONE:
                $output->success($done);

                return 0;
            case WebOutcome::PENDING:
                $output->line('Recorded; the agent is not reachable' . ($outcome->message ? ' (' . $outcome->message . ')' : '') . '. web:settle will finish it.');

                return 0;
            default:
                $output->error($this->localization->get('Web.refused') . ' (' . $outcome->code . ': ' . $outcome->message . ')');

                return 1;
        }
    }

    /** @return array{0: array<string, mixed>, 1: ?array<string, mixed>} [account, domain or null] */
    private function find(Output $output, string $username, string $domain): array
    {
        $account = $this->accounts->findByUsernameUnscoped($username);
        $row = $account === null ? null : $this->domains->findByName((int) $account['id'], $domain);

        if ($row === null) {
            $output->error($account === null ? \sprintf('There is no account named %s.', $username) : \sprintf('%s has no domain %s.', $username, $domain));
        }

        return [$account ?? [], $row];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: ?array<string, mixed>} */
    private function folder(Output $output, string $username, string $domain, ?string $folder): array
    {
        [$account, $row] = $this->find($output, $username, $domain);
        $path = \trim((string) $folder, '/');

        if ($row === null) {
            return [$account, [], null];
        }

        foreach ($this->features->protected((int) $row['id']) as $p) {
            if ($p['path'] === $path) {
                return [$account, $row, $p];
            }
        }

        $output->error(\sprintf('/%s on %s is not protected.', $path, $domain));

        return [$account, $row, null];
    }

    private static function password(): string
    {
        return \rtrim((string) \fgets(\STDIN), "\r\n");
    }

    private function root(): Identity
    {
        return $this->users->root()->identity;
    }
}
