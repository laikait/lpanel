<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Nav\Model;

/**
 * One item in an already-built tree, together with its already-visible
 * children.
 *
 * Not a Model: nothing here is persisted, and a node's shape (an item plus
 * its children) is not a row NavRepository could have read back from
 * storage. It exists so a template writes `node.item.label` and
 * `node.children`, rather than reading a bare associative array whose keys
 * nothing checks.
 */
final class NavNode
{
    /** @param list<NavNode> $children */
    public function __construct(
        public readonly NavItem $item,
        public readonly array $children,
    ) {}
}
