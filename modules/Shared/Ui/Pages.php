<?php

declare(strict_types=1);

namespace App\Modules\Shared\Ui;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Security\Csrf;
use App\Engine\Session\SessionManager;
use App\Engine\Template\TemplateManager;

/**
 * Renders a page inside the LPanel layout, with what every page needs: the
 * CSRF token, the signed-in person, the sidebar they may see, and the one
 * flash message the previous request left.
 */
final class Pages
{
    private const FLASH = 'ui.flash';

    /** Shell links, by route name; a route another module has not declared is left out. */
    private const LINKS = [
        'home' => 'home',
        'login' => 'login.show',
        'login_code' => 'login.code',
        'logout' => 'logout',
        'security' => 'account.security',
        'tokens' => 'account.tokens',
        'forgot' => 'password.forgot',
    ];

    public function __construct(
        private readonly TemplateManager $templates,
        private readonly Csrf $csrf,
        private readonly Router $router,
        private readonly Authorizer $authorizer,
        private readonly Navigation $navigation,
        private readonly SessionManager $sessions,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param string               $section the sidebar entry to mark as current
     */
    public function render(
        Request $request,
        string $template,
        array $data = [],
        int $status = 200,
        ?Identity $identity = null,
        string $section = '',
    ): Response {
        $me = $identity !== null && !$identity->isGuest() ? $identity : null;

        $html = $this->templates->render($template, $data + [
            'token' => $this->csrf->token($request),
            'home' => $request->basePath() . '/',
            'me' => $me,
            'profile' => $me === null ? null : self::profile($me),
            'nav' => $me === null ? [] : $this->sidebar($me, $section),
            'section' => $section,
            'flash' => $this->sessions->isResumable($request) ? $this->sessions->session()->get(self::FLASH) : null,
            'urls' => $this->links(),
        ]);

        return (new Response($html))
            ->withStatus($status)
            ->withContentType('text/html')
            // Panel pages show codes, tokens and errors that must not be cached anywhere.
            ->withHeader('Cache-Control', 'no-store');
    }

    /** A message for the next page: "success", "danger", "warning" or "info". */
    public function flash(string $type, string $message): void
    {
        $this->sessions->session()->flash(self::FLASH, ['type' => $type, 'message' => $message]);
    }

    /** @param array<string, string|int|float> $parameters */
    public function url(string $name, array $parameters = []): string
    {
        return $this->router->url($name, $parameters);
    }

    /** @return array<string, string> */
    private function links(): array
    {
        $links = [];

        foreach (self::LINKS as $key => $route) {
            if ($this->router->route($route) !== null) {
                $links[$key] = $this->router->url($route);
            }
        }

        return $links;
    }

    /** @return list<array{title: string, items: list<array<string, mixed>>}> */
    private function sidebar(Identity $me, string $section): array
    {
        $groups = [];

        foreach ($this->navigation->items() as $item) {
            if ($item['can'] !== null && !$this->authorizer->allows($me, $item['can'])) {
                continue;
            }

            $groups[$item['section']] ??= ['title' => Navigation::SECTIONS[$item['section']], 'items' => []];
            $groups[$item['section']]['items'][] = [
                'label' => $item['label'],
                'icon' => $item['icon'],
                'url' => $item['route'] !== null && $item['soon'] === null ? $this->router->url($item['route']) : null,
                'soon' => $item['soon'],
                'active' => $item['key'] === $section,
            ];
        }

        return \array_values($groups);
    }

    /** @return array{name: string, login: string, initials: string, role: string, root: bool} */
    private static function profile(Identity $me): array
    {
        $words = \preg_split('/[\s._-]+/u', \trim($me->name), -1, \PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $initials = \mb_strtoupper(\mb_substr($words[0], 0, 1) . (isset($words[1]) ? \mb_substr($words[1], 0, 1) : ''));
        $role = match (true) {
            $me->hasRole('root') => 'root',
            $me->hasRole('admin') => 'Administrator',
            $me->hasRole('customer') => 'Customer',
            default => 'No role',
        };
        $login = $me->attribute('login', '');

        return [
            'name' => $me->name,
            'login' => \is_string($login) ? $login : '',
            'initials' => $initials,
            'role' => $role,
            'root' => $me->hasRole('root'),
        ];
    }
}
