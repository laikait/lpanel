<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Nav\Http;

use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Security\Csrf;
use App\Engine\Session\Session;
use App\Engine\Template\TemplateManager;
use App\Tests\Fixtures\Modules\Nav\Data\NavRepository;
use App\Tests\Fixtures\Modules\Nav\Model\NavItem;
use App\Tests\Fixtures\Modules\Nav\Schema\NavItemSchema;

/**
 * A small, unauthenticated CRUD screen over nav_items -- so a menu can be
 * built without writing SQL by hand, and so a form/template shape exists to
 * copy.
 *
 * **Unauthenticated on purpose**, not by oversight: every route these
 * methods answer is declared with a rate_limit and no `auth` meta at all
 * (module.php). CSRF still applies -- it is opt-out, not opt-in -- so a
 * request that did not load one of these forms first cannot write, but
 * anyone who can reach this host at all can manage every menu. See
 * docs/guides/navigation.md for how to put `auth`/`can` meta back once this
 * is behind a real login.
 */
final class NavAdminPages
{
    public function __construct(
        private readonly NavRepository $nav,
        private readonly TemplateManager $templates,
        private readonly Csrf $csrf,
        private readonly Router $router,
    ) {}

    public function index(Request $request): Response
    {
        $tree = $this->nav->adminTree();

        $createUrls = [];

        foreach (\array_keys($tree) as $location) {
            $createUrls[$location] = $this->router->url('nav.create', ['location' => $location]);
        }

        return $this->page('@Nav/index', [
            'tree' => $tree,
            'createUrls' => $createUrls,
            'newUrl' => $this->router->url('nav.create'),
            'links' => $this->links($tree),
        ], $request);
    }

    /**
     * A URL for every action a row in the index page can take, keyed by
     * item id -- Twig here has no url() function of its own to call per
     * row (see docs/guides/navigation.md, "The admin UI"), so these are
     * built once, in PHP, the same way `action` is built for the form.
     *
     * @param array<string, list<\App\Tests\Fixtures\Modules\Nav\Model\NavNode>> $tree
     *
     * @return array<int, array{edit: string, delete: string, move: string}>
     */
    private function links(array $tree): array
    {
        $links = [];

        foreach ($tree as $roots) {
            $this->linksFor($roots, $links);
        }

        return $links;
    }

    /**
     * @param list<\App\Tests\Fixtures\Modules\Nav\Model\NavNode> $nodes
     * @param array<int, array{edit: string, delete: string, move: string}> $links
     */
    private function linksFor(array $nodes, array &$links): void
    {
        foreach ($nodes as $node) {
            $id = $node->item->identity();

            if ($id !== null) {
                $links[$id] = [
                    'edit' => $this->router->url('nav.edit', ['id' => $id]),
                    'delete' => $this->router->url('nav.delete', ['id' => $id]),
                    'move' => $this->router->url('nav.move', ['id' => $id]),
                ];
            }

            $this->linksFor($node->children, $links);
        }
    }

    public function create(Request $request): Response
    {
        $location = (string) $request->query('location', 'header');
        $parentId = $request->query('parentId');

        return $this->form($request, 'create', [
            'action' => $this->router->url('nav.store'),
            'old' => [
                'location' => $location,
                'parentId' => $parentId !== null ? (string) $parentId : '',
                'order' => '0',
                'enabled' => '1',
            ],
            'errors' => [],
        ]);
    }

    public function store(Request $request, Session $session): Response
    {
        $input = $this->readForm($request);
        $schema = NavItemSchema::input();
        $result = $schema->validate($input);

        if (!$result->isValid()) {
            return $this->form($request, 'create', [
                'action' => $this->router->url('nav.store'),
                'old' => $input,
                'errors' => $result->messages(),
            ])->withStatus(422);
        }

        $this->nav->create($schema->deserialize($input));
        $session->flash('status', 'Item created.');

        return new RedirectResponse($this->router->url('nav.index'), 303);
    }

    public function edit(Request $request, string $id): Response
    {
        $item = $this->find($id);

        return $this->form($request, 'edit', [
            'action' => $this->router->url('nav.update', ['id' => (string) $item->identity()]),
            'old' => [
                'parentId' => $item->parentId() !== null ? (string) $item->parentId() : '',
                'location' => $item->location(),
                'label' => $item->label(),
                'url' => $item->url() ?? '',
                'route' => $item->route() ?? '',
                'capability' => $item->capability() ?? '',
                'order' => (string) $item->order(),
                'enabled' => $item->isEnabled() ? '1' : '',
            ],
            'errors' => [],
        ]);
    }

    public function update(Request $request, Session $session, string $id): Response
    {
        $item = $this->find($id);
        $input = $this->readForm($request);
        $schema = NavItemSchema::input();
        $result = $schema->validate($input);

        if (!$result->isValid()) {
            return $this->form($request, 'edit', [
                'action' => $this->router->url('nav.update', ['id' => $id]),
                'old' => $input,
                'errors' => $result->messages(),
            ])->withStatus(422);
        }

        $this->nav->update($item, $schema->deserialize($input));
        $session->flash('status', 'Item updated.');

        return new RedirectResponse($this->router->url('nav.index'), 303);
    }

    public function delete(Session $session, string $id): Response
    {
        $this->nav->delete($this->find($id));
        $session->flash('status', 'Item deleted.');

        return new RedirectResponse($this->router->url('nav.index'), 303);
    }

    public function move(Request $request, string $id): Response
    {
        $direction = (string) ($request->input('direction') ?? '');

        if ($direction === 'up' || $direction === 'down') {
            $this->nav->move($this->find($id), $direction);
        }

        return new RedirectResponse($this->router->url('nav.index'), 303);
    }

    private function find(string $id): NavItem
    {
        return $this->nav->find((int) $id) ?? throw HttpException::notFound();
    }

    /** @return array<string, mixed> */
    private function readForm(Request $request): array
    {
        $parentId = \trim((string) ($request->input('parentId') ?? ''));
        $url = \trim((string) ($request->input('url') ?? ''));
        $route = \trim((string) ($request->input('route') ?? ''));
        $capability = \trim((string) ($request->input('capability') ?? ''));

        return [
            'parentId' => $parentId === '' ? null : (int) $parentId,
            'location' => (string) ($request->input('location') ?? ''),
            'label' => (string) ($request->input('label') ?? ''),
            'url' => $url === '' ? null : $url,
            'route' => $route === '' ? null : $route,
            'capability' => $capability === '' ? null : $capability,
            'order' => (int) ($request->input('order') ?? 0),
            // A checkbox sends nothing at all when unchecked, so absence
            // means false rather than "leave it as it was".
            'enabled' => $request->input('enabled') !== null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function form(Request $request, string $view, array $data): Response
    {
        return $this->page('@Nav/form', $data + ['mode' => $view], $request);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data, Request $request): Response
    {
        $html = $this->templates->render($view, $data + [
            'token' => $this->csrf->token($request),
            'indexUrl' => $this->router->url('nav.index'),
        ]);

        return (new Response($html))->withContentType('text/html');
    }
}
