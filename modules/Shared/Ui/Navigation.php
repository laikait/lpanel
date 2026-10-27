<?php

declare(strict_types=1);

namespace App\Modules\Shared\Ui;

/**
 * The sidebar, filled by the modules that own each screen.
 *
 *     $nav->add('admin', 'users', 'Users', 'users.index', 'users', can: 'users.read');
 *
 * A module adds its own entries in onBoot, so the layout knows nothing about
 * which modules exist. An entry whose capability the viewer lacks is not
 * shown; the route still checks it, the sidebar only avoids offering a door
 * that would answer 403.
 */
final class Navigation
{
    /** Sections in the order the sidebar shows them. */
    public const SECTIONS = [
        'main' => '',
        'hosting' => 'Hosting',
        'admin' => 'Administration',
        'account' => 'Your account',
    ];

    /** @var array<string, array{section: string, key: string, label: string, route: ?string, icon: string, can: ?string, order: int, soon: ?string}> */
    private array $items = [];

    /**
     * @param ?string $route a route name; null with $soon for a screen that is not built yet
     * @param ?string $soon  a short note ("M3") shown instead of a link
     */
    public function add(
        string $section,
        string $key,
        string $label,
        ?string $route,
        string $icon,
        ?string $can = null,
        int $order = 50,
        ?string $soon = null,
    ): void {
        if (!isset(self::SECTIONS[$section])) {
            throw new \InvalidArgumentException(\sprintf('Unknown navigation section "%s".', $section));
        }

        $this->items[$key] = [
            'section' => $section,
            'key' => $key,
            'label' => $label,
            'route' => $route,
            'icon' => $icon,
            'can' => $can,
            'order' => $order,
            'soon' => $soon,
        ];
    }

    /** @return list<array{section: string, key: string, label: string, route: ?string, icon: string, can: ?string, order: int, soon: ?string}> */
    public function items(): array
    {
        $items = \array_values($this->items);
        $sections = \array_flip(\array_keys(self::SECTIONS));

        \usort($items, static fn(array $a, array $b): int => [$sections[$a['section']], $a['order'], $a['label']] <=> [$sections[$b['section']], $b['order'], $b['label']]);

        return $items;
    }
}
