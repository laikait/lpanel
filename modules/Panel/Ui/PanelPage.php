<?php

declare(strict_types=1);

namespace App\Modules\Panel\Ui;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Config\Config;
use App\Engine\Filter\FilterEngine;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Localization\Localization;
use App\Engine\Routing\Router;
use App\Engine\Security\Csrf;
use App\Engine\Session\Session;
use App\Engine\Template\TemplateManager;

/**
 * Renders a page inside the Admin panel or User panel shell.
 *
 * Every screen goes through here so the sidebar, the account menu, flash
 * notices, theme and text direction are the same everywhere. A handler passes
 * its own template and data; the shell's data arrives as `lp`.
 *
 * Navigation comes from the `panel.nav` filter: each module adds its items,
 * and an item is shown only when the person holds its capability.
 */
final class PanelPage
{
    public const AREAS = ['admin', 'user'];

    /** Languages written right to left. */
    private const RTL = ['ar', 'fa', 'he', 'ur'];

    public function __construct(
        private readonly TemplateManager $templates,
        private readonly Router $router,
        private readonly Csrf $csrf,
        private readonly Authorizer $authorizer,
        private readonly FilterEngine $filters,
        private readonly Localization $localization,
        private readonly Config $config,
    ) {}

    /**
     * A page inside the shell.
     *
     * @param string               $area 'admin' or 'user'
     * @param array<string, mixed> $data
     */
    public function render(
        Request $request,
        Session $session,
        Identity $identity,
        string $area,
        string $template,
        array $data = [],
        string $title = '',
        string $active = '',
        int $status = 200,
    ): Response {
        $shell = $this->common($request, $session, $area, $title) + [
            'active' => $active,
            'nav' => $this->nav($identity, $area),
            'me' => $this->me($identity),
            'logout' => $this->router->url($area . '.logout'),
            'security' => $this->router->url($area . '.security'),
            'end_impersonation' => $identity->attribute('impersonator') !== null ? $this->endImpersonation($area) : '',
        ];

        return $this->respond($template, $data, $shell, $status);
    }

    /**
     * A page without the shell: login, the code form, errors before login.
     *
     * @param string               $area 'admin' or 'user'
     * @param array<string, mixed> $data
     */
    public function bare(
        Request $request,
        Session $session,
        string $area,
        string $template,
        array $data = [],
        string $title = '',
        int $status = 200,
    ): Response {
        return $this->respond($template, $data, $this->common($request, $session, $area, $title), $status);
    }

    /**
     * Which interface a request is for, from its path: /admin… or /panel….
     *
     * @return 'admin'|'user'
     */
    public static function areaOf(Request $request): string
    {
        $path = $request->path();

        return $path === '/panel' || \str_starts_with($path, '/panel/') ? 'user' : 'admin';
    }

    /**
     * A one-request notice for the next page the shell renders.
     *
     * @param 'success'|'error'|'info'|'warning' $kind
     */
    public static function notice(Session $session, string $kind, string $message): void
    {
        $notices = $session->get('panel.notices', []);
        $notices = \is_array($notices) ? $notices : [];
        $notices[] = ['kind' => $kind, 'message' => $message];
        $session->flash('panel.notices', $notices);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $shell
     */
    private function respond(string $template, array $data, array $shell, int $status): Response
    {
        $html = $this->templates->render($template, ['lp' => $shell] + $data);

        return (new Response($html))
            ->withStatus($status)
            ->withContentType('text/html')
            // Panel pages are personal; nothing in between may keep a copy.
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function common(Request $request, Session $session, string $area, string $title): array
    {
        $locale = $this->localization->locale();
        $language = \strtolower(\substr($locale, 0, 2));
        $theme = $request->cookie('lp_theme');
        $notices = $session->get('panel.notices', []);

        return [
            'area' => $area,
            'title' => $title,
            'brand' => (string) $this->config->get('Panel.brand.name', 'LPanel'),
            'version' => (string) $this->config->get('Panel.version', ''),
            'lang' => $language,
            'dir' => \in_array($language, self::RTL, true) ? 'rtl' : 'ltr',
            'theme' => \in_array($theme, ['light', 'dark'], true) ? $theme : 'auto',
            'token' => $this->csrf->token($request),
            'notices' => \is_array($notices) ? $notices : [],
        ];
    }

    /**
     * The sidebar for this area, grouped into sections, without the items
     * this person may not use and without empty sections.
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function nav(Identity $identity, string $area): array
    {
        /** @var mixed $items */
        $items = $this->filters->apply('panel.nav', [], $area);
        $sections = [];

        foreach (\is_array($items) ? $items : [] as $item) {
            if (!\is_array($item) || ($item['area'] ?? null) !== $area || !\is_string($item['route'] ?? null)) {
                continue;
            }

            if (\is_string($item['can'] ?? null) && !$this->authorizer->allows($identity, $item['can'])) {
                continue;
            }

            $section = \is_string($item['section'] ?? null) ? $item['section'] : '';
            $sections[$section]['label'] = $section;
            $sections[$section]['items'][] = [
                'key' => (string) ($item['key'] ?? $item['route']),
                'label' => (string) ($item['label'] ?? $item['route']),
                'icon' => (string) ($item['icon'] ?? 'dot'),
                'url' => $this->router->url($item['route']),
                'order' => (int) ($item['order'] ?? 100),
            ];
        }

        foreach ($sections as &$section) {
            \usort($section['items'], static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        }

        unset($section);

        // A section sits where its first item would: People (10) above Security (80).
        \uasort($sections, static fn(array $a, array $b): int => $a['items'][0]['order'] <=> $b['items'][0]['order']);

        return \array_values($sections);
    }

    /** @return array<string, string> */
    private function me(Identity $identity): array
    {
        $name = $identity->name !== '' ? $identity->name : $identity->id;
        $words = \preg_split('/[\s._-]+/', \trim($name)) ?: [];
        $initials = '';

        foreach (\array_slice($words, 0, 2) as $word) {
            $initials .= \mb_strtoupper(\mb_substr($word, 0, 1));
        }

        return [
            'name' => $name,
            'username' => (string) $identity->attribute('username', ''),
            'kind' => (string) $identity->attribute('kind', ''),
            'initials' => $initials !== '' ? $initials : '?',
            // Set while someone is signed in as this login ("log in as").
            'impersonator' => (string) $identity->attribute('impersonator', ''),
        ];
    }

    /** Where the "log in as" banner's button goes, when that route exists. */
    private function endImpersonation(string $area): string
    {
        $name = $area === 'admin' ? 'admin.login_as.return' : 'user.login_as.end';

        return $this->router->route($name) !== null ? $this->router->url($name) : '';
    }
}
