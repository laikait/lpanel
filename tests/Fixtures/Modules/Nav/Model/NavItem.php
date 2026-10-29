<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Nav\Model;

use App\Engine\Model\Model;

/**
 * One entry in a menu: a label, where it goes, who may see it, and where it
 * sits among its siblings. The tree itself -- which items are whose children
 * -- is not state this model carries; it is assembled by NavRepository from
 * a flat list of these, keyed by parent_id.
 */
final class NavItem extends Model
{
    public function __construct(
        private ?int $id,
        private ?int $parentId,
        private string $location,
        private string $label,
        private ?string $url = null,
        private ?string $route = null,
        private ?string $capability = null,
        private int $order = 0,
        private bool $enabled = true,
    ) {}

    public function identity(): ?int
    {
        return $this->id;
    }

    public function parentId(): ?int
    {
        return $this->parentId;
    }

    public function location(): string
    {
        return $this->location;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function route(): ?string
    {
        return $this->route;
    }

    public function capability(): ?string
    {
        return $this->capability;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function rename(string $label): void
    {
        $this->label = $label;
    }

    public function reorder(int $order): void
    {
        $this->order = $order;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function reparent(?int $parentId): void
    {
        $this->parentId = $parentId;
    }

    public function setUrl(?string $url): void
    {
        $this->url = $url;
    }

    public function setRoute(?string $route): void
    {
        $this->route = $route;
    }

    public function setCapability(?string $capability): void
    {
        $this->capability = $capability;
    }
}
