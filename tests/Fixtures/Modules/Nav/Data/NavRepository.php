<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Nav\Data;

use App\Engine\Auth\AuthException;
use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Cache\Cache;
use App\Engine\Config\Config;
use App\Engine\Data\DataSource;
use App\Engine\Data\Repository;
use App\Engine\Model\ModelManager;
use App\Tests\Fixtures\Modules\Nav\Model\NavItem;
use App\Tests\Fixtures\Modules\Nav\Model\NavNode;

/**
 * A menu, by location: `header`, `footer`, `admin_sidebar`, `user_panel`, or
 * any other name an application chooses. One flat table, no foreign key (see
 * the migration), assembled into a tree here rather than in the database --
 * the table a menu needs is small enough that fetching it whole and grouping
 * in PHP is simpler, and just as fast, on every dialect.
 */
final class NavRepository extends Repository
{
    public function __construct(
        DataSource $source,
        ModelManager $models,
        private readonly Cache $cache,
        private readonly Authorizer $authorizer,
        private readonly Config $config,
    ) {
        parent::__construct($source, $models);
    }

    protected function model(): string
    {
        return NavItem::class;
    }

    protected function collection(): string
    {
        return 'nav_items';
    }

    /**
     * The tree for one location, with anything $identity may not see already
     * removed.
     *
     * @return list<NavNode>
     */
    public function tree(string $location, Identity $identity): array
    {
        $key = \sprintf('tree.%s.%s', $location, \implode(',', $identity->roles));

        return $this->cache->namespace('nav')->remember(
            $key,
            fn(): array => $this->visible($this->build($this->rowsFor($location)), $identity),
            $this->config->int('Nav.cache_ttl', 300),
        );
    }

    /**
     * Every enabled row for one location, in display order.
     *
     * @return list<NavItem>
     */
    private function rowsFor(string $location): array
    {
        $rows = [];

        foreach ($this->query()->whereIs('location', $location)->whereIs('enabled', true)->orderBy('order')->get() as $row) {
            $rows[] = $row instanceof NavItem
                ? $row
                : throw new \LogicException('The repository stored something other than a NavItem.');
        }

        return $rows;
    }

    /**
     * Every row, every location, disabled ones included -- what an admin
     * screen needs to see rather than what a visitor is allowed to.
     *
     * @return list<NavItem>
     */
    public function all(): array
    {
        $rows = [];

        foreach ($this->query()->orderBy('location')->orderBy('order')->get() as $row) {
            $rows[] = $row instanceof NavItem
                ? $row
                : throw new \LogicException('The repository stored something other than a NavItem.');
        }

        return $rows;
    }

    /**
     * Every location's tree, disabled items included and nothing pruned by
     * capability -- the admin view sees the whole thing, not what a
     * particular visitor is allowed to.
     *
     * @return array<string, list<NavNode>>
     */
    public function adminTree(): array
    {
        $byLocation = [];

        foreach ($this->all() as $item) {
            $byLocation[$item->location()][] = $item;
        }

        \ksort($byLocation);

        return \array_map(fn(array $rows): array => $this->build($rows), $byLocation);
    }

    public function find(int $id): ?NavItem
    {
        $row = $this->query()->whereIs('id', $id)->first();

        return $row instanceof NavItem
            ? $row
            : null;
    }

    /**
     * @param array<string, mixed> $attributes already checked against NavItemSchema
     */
    public function create(array $attributes): NavItem
    {
        $location = $attributes['location'] ?? '';
        $label = $attributes['label'] ?? '';

        $stored = $this->persist(new NavItem(
            null,
            \is_int($attributes['parentId'] ?? null) ? $attributes['parentId'] : null,
            \is_string($location) ? $location : '',
            \is_string($label) ? $label : '',
            \is_string($attributes['url'] ?? null) ? $attributes['url'] : null,
            \is_string($attributes['route'] ?? null) ? $attributes['route'] : null,
            \is_string($attributes['capability'] ?? null) ? $attributes['capability'] : null,
            \is_int($attributes['order'] ?? null) ? $attributes['order'] : 0,
            (bool) ($attributes['enabled'] ?? true),
        ));

        $this->cache->namespace('nav')->clear();

        return $stored instanceof NavItem
            ? $stored
            : throw new \LogicException('The repository stored something other than a NavItem.');
    }

    /**
     * @param array<string, mixed> $attributes already checked against NavItemSchema
     */
    public function update(NavItem $item, array $attributes): NavItem
    {
        if (\array_key_exists('parentId', $attributes)) {
            $parentId = $attributes['parentId'];
            $item->reparent(\is_int($parentId) ? $parentId : null);
        }

        if (\is_string($attributes['label'] ?? null)) {
            $item->rename($attributes['label']);
        }

        if (\array_key_exists('url', $attributes)) {
            $url = $attributes['url'];
            $item->setUrl(\is_string($url) ? $url : null);
        }

        if (\array_key_exists('route', $attributes)) {
            $route = $attributes['route'];
            $item->setRoute(\is_string($route) ? $route : null);
        }

        if (\array_key_exists('capability', $attributes)) {
            $capability = $attributes['capability'];
            $item->setCapability(\is_string($capability) ? $capability : null);
        }

        if (\is_int($attributes['order'] ?? null)) {
            $item->reorder($attributes['order']);
        }

        if (\array_key_exists('enabled', $attributes)) {
            $attributes['enabled'] ? $item->enable() : $item->disable();
        }

        $stored = $this->persist($item);
        $this->cache->namespace('nav')->clear();

        return $stored instanceof NavItem
            ? $stored
            : throw new \LogicException('The repository stored something other than a NavItem.');
    }

    public function delete(NavItem $item): void
    {
        $this->remove($item);
        $this->cache->namespace('nav')->clear();
    }

    /**
     * Swap `order` with the previous ('up') or next ('down') sibling -- same
     * location, same parentId. Nothing happens at either end of the list:
     * there is no sibling to swap with, so the request is simply a no-op
     * rather than an error a form has to handle specially.
     */
    public function move(NavItem $item, string $direction): void
    {
        $siblings = \array_values(\array_filter(
            $this->all(),
            static fn(NavItem $candidate): bool => $candidate->location() === $item->location()
                && $candidate->parentId() === $item->parentId(),
        ));

        \usort($siblings, static fn(NavItem $a, NavItem $b): int => $a->order() <=> $b->order());

        $index = null;

        foreach ($siblings as $position => $sibling) {
            if ($sibling->identity() === $item->identity()) {
                $index = $position;

                break;
            }
        }

        if ($index === null) {
            return;
        }

        $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;

        if ($targetIndex < 0 || $targetIndex >= \count($siblings)) {
            return;
        }

        $target = $siblings[$targetIndex];
        $itemOrder = $item->order();

        $item->reorder($target->order());
        $target->reorder($itemOrder);

        $this->persist($item);
        $this->persist($target);
        $this->cache->namespace('nav')->clear();
    }

    /**
     * Rows into a tree, by parentId. A parentId naming a row that is not in
     * this result -- a deleted parent, a stale reference -- is not an error:
     * the item surfaces as a root instead of disappearing.
     *
     * @param list<NavItem> $rows
     *
     * @return list<NavNode>
     */
    private function build(array $rows): array
    {
        $ids = [];

        /** @var array<int, list<NavItem>> $byParent */
        $byParent = [];

        foreach ($rows as $row) {
            $id = $row->identity();

            if ($id !== null) {
                $ids[$id] = true;
            }

            $byParent[$row->parentId() ?? 0][] = $row;
        }

        $roots = [];

        foreach ($rows as $row) {
            $parentId = $row->parentId();

            if ($parentId === null || $parentId === 0 || !isset($ids[$parentId])) {
                $roots[] = $this->node($row, $byParent);
            }
        }

        return $roots;
    }

    /** @param array<int, list<NavItem>> $byParent */
    private function node(NavItem $item, array $byParent): NavNode
    {
        $children = $byParent[$item->identity() ?? 0] ?? [];

        return new NavNode(
            $item,
            \array_map(fn(NavItem $child): NavNode => $this->node($child, $byParent), $children),
        );
    }

    /**
     * The tree with every node $identity has no capability for pruned, along
     * with its children -- a menu never shows a link to a page it then
     * refuses.
     *
     * @param list<NavNode> $nodes
     *
     * @return list<NavNode>
     */
    private function visible(array $nodes, Identity $identity): array
    {
        $result = [];

        foreach ($nodes as $node) {
            if (!$this->allowed($node->item, $identity)) {
                continue;
            }

            $result[] = new NavNode($node->item, $this->visible($node->children, $identity));
        }

        return $result;
    }

    private function allowed(NavItem $item, Identity $identity): bool
    {
        $capability = $item->capability();

        if ($capability === null) {
            return true;
        }

        try {
            return $this->authorizer->allows($identity, $capability);
        } catch (AuthException) {
            // A capability nothing declares -- a typo, or a module that owned
            // it since removed -- hides the item rather than breaking every
            // page that renders this menu.
            return false;
        }
    }
}
