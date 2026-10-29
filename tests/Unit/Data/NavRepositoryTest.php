<?php

declare(strict_types=1);

namespace App\Tests\Unit\Data;

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Cache\Cache;
use App\Engine\Cache\Stores\ArrayStore;
use App\Engine\Config\Config;
use App\Engine\Data\ArraySource;
use App\Engine\Model\ModelManager;
use App\Tests\Fixtures\Modules\Nav\Data\NavRepository;
use App\Tests\Fixtures\Modules\Nav\Model\NavNode;
use App\Tests\Support\TestCase;

final class NavRepositoryTest extends TestCase
{
    private ArraySource $source;

    private AccessRegistry $access;

    private NavRepository $nav;

    protected function setUp(): void
    {
        $this->source = new ArraySource(['nav_items' => [
            ['id' => 1, 'parentId' => null, 'location' => 'header', 'label' => 'Home', 'url' => '/', 'route' => null, 'capability' => null, 'order' => 0, 'enabled' => true],
            ['id' => 2, 'parentId' => null, 'location' => 'header', 'label' => 'Admin', 'url' => '/admin', 'route' => null, 'capability' => 'nav.admin', 'order' => 1, 'enabled' => true],
            ['id' => 3, 'parentId' => 1, 'location' => 'header', 'label' => 'About', 'url' => '/about', 'route' => null, 'capability' => null, 'order' => 0, 'enabled' => true],
            ['id' => 4, 'parentId' => null, 'location' => 'header', 'label' => 'Hidden', 'url' => '/off', 'route' => null, 'capability' => null, 'order' => 2, 'enabled' => false],
            ['id' => 5, 'parentId' => 999, 'location' => 'header', 'label' => 'Orphan', 'url' => '/orphan', 'route' => null, 'capability' => null, 'order' => 3, 'enabled' => true],
            ['id' => 6, 'parentId' => null, 'location' => 'footer', 'label' => 'Terms', 'url' => '/terms', 'route' => null, 'capability' => null, 'order' => 0, 'enabled' => true],
        ]]);

        $this->access = new AccessRegistry();
        (new AccessCollector($this->access, 'Nav'))
            ->capability('nav.admin', 'See the admin link.')
            ->role('administrator', ['nav.admin']);

        $this->nav = new NavRepository(
            $this->source,
            new ModelManager(),
            new Cache(new ArrayStore()),
            new Authorizer($this->access),
            new Config(),
        );
    }

    public function test_it_nests_children_under_their_parent(): void
    {
        $tree = $this->nav->tree('header', Identity::guest());

        $home = $tree[0];
        self::assertSame('Home', $home->item->label());
        self::assertCount(1, $home->children);
        self::assertSame('About', $home->children[0]->item->label());
    }

    public function test_it_only_returns_the_requested_location(): void
    {
        $tree = $this->nav->tree('footer', Identity::guest());

        self::assertCount(1, $tree);
        self::assertSame('Terms', $tree[0]->item->label());
    }

    public function test_a_disabled_item_is_left_out(): void
    {
        $labels = \array_map(static fn(NavNode $node): string => $node->item->label(), $this->nav->tree('header', Identity::guest()));

        self::assertNotContains('Hidden', $labels);
    }

    public function test_an_orphaned_item_surfaces_as_a_root_instead_of_disappearing(): void
    {
        $labels = \array_map(static fn(NavNode $node): string => $node->item->label(), $this->nav->tree('header', Identity::guest()));

        self::assertContains('Orphan', $labels);
    }

    public function test_a_guest_does_not_see_an_item_gated_by_a_capability(): void
    {
        $labels = \array_map(static fn(NavNode $node): string => $node->item->label(), $this->nav->tree('header', Identity::guest()));

        self::assertNotContains('Admin', $labels);
    }

    public function test_a_role_that_holds_the_capability_sees_the_item(): void
    {
        $identity = new Identity('7', 'Ada', ['administrator']);
        $labels = \array_map(static fn(NavNode $node): string => $node->item->label(), $this->nav->tree('header', $identity));

        self::assertContains('Admin', $labels);
    }

    public function test_an_undeclared_capability_hides_the_item_instead_of_throwing(): void
    {
        $this->source->update('nav_items', 'id', 2, ['capability' => 'nav.no-such-capability']);

        $labels = \array_map(
            static fn(NavNode $node): string => $node->item->label(),
            $this->nav->tree('header', new Identity('7', 'Ada', ['administrator'])),
        );

        self::assertNotContains('Admin', $labels);
    }

    public function test_the_tree_is_cached_between_calls(): void
    {
        $first = $this->nav->tree('header', Identity::guest());

        // Mutate storage directly: a cache hit would still show the old tree.
        $this->source->update('nav_items', 'id', 1, ['label' => 'Changed']);

        $second = $this->nav->tree('header', Identity::guest());

        self::assertSame($first[0]->item->label(), $second[0]->item->label());
    }

    // ---- writes -------------------------------------------------------

    public function test_create_stores_a_new_item_and_returns_it_with_an_identity(): void
    {
        $item = $this->nav->create([
            'parentId' => null,
            'location' => 'footer',
            'label' => 'Privacy',
            'url' => '/privacy',
            'route' => null,
            'capability' => null,
            'order' => 1,
            'enabled' => true,
        ]);

        self::assertNotNull($item->identity());
        self::assertSame('Privacy', $item->label());
        self::assertNotNull($this->nav->find($item->identity()));
    }

    public function test_update_changes_only_the_given_fields(): void
    {
        $home = $this->nav->find(1);
        self::assertNotNull($home);

        $updated = $this->nav->update($home, ['label' => 'Renamed home']);

        self::assertSame('Renamed home', $updated->label());
        self::assertSame('/', $updated->url(), 'url was not in the update and must be untouched');
    }

    public function test_update_can_disable_and_reenable_an_item(): void
    {
        $home = $this->nav->find(1);
        self::assertNotNull($home);

        $disabled = $this->nav->update($home, ['enabled' => false]);
        self::assertFalse($disabled->isEnabled());

        $enabled = $this->nav->update($disabled, ['enabled' => true]);
        self::assertTrue($enabled->isEnabled());
    }

    public function test_delete_removes_the_item(): void
    {
        $home = $this->nav->find(1);
        self::assertNotNull($home);

        $this->nav->delete($home);

        self::assertNull($this->nav->find(1));
    }

    public function test_move_up_swaps_order_with_the_previous_sibling(): void
    {
        // Admin (order 1) moves up, ahead of Home (order 0), among header's
        // root items -- About (id 3) is a child of Home, not a sibling, so it
        // is not involved.
        $admin = $this->nav->find(2);
        self::assertNotNull($admin);

        $this->nav->move($admin, 'up');

        self::assertSame(0, $this->nav->find(2)?->order(), 'Admin took Home\'s old order');
        self::assertSame(1, $this->nav->find(1)?->order(), 'Home took Admin\'s old order');
    }

    public function test_move_at_the_start_of_the_list_is_a_no_op(): void
    {
        $home = $this->nav->find(1);
        self::assertNotNull($home);
        $originalOrder = $home->order();

        $this->nav->move($home, 'up');

        self::assertSame($originalOrder, $this->nav->find(1)?->order());
    }

    public function test_a_write_invalidates_the_cache(): void
    {
        $first = $this->nav->tree('header', Identity::guest());
        self::assertSame('Home', $first[0]->item->label());

        $home = $this->nav->find(1);
        self::assertNotNull($home);
        $this->nav->update($home, ['label' => 'Changed']);

        $second = $this->nav->tree('header', Identity::guest());
        self::assertSame('Changed', $second[0]->item->label());
    }

    public function test_admin_tree_includes_disabled_items_grouped_by_location(): void
    {
        $tree = $this->nav->adminTree();

        self::assertArrayHasKey('header', $tree);
        self::assertArrayHasKey('footer', $tree);

        $labels = \array_map(static fn(NavNode $node): string => $node->item->label(), $tree['header']);
        self::assertContains('Hidden', $labels, 'the admin view must show disabled items');
    }
}
