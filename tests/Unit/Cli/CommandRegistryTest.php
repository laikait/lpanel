<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cli;

use App\Engine\Cli\Command;
use App\Engine\Cli\CommandCollector;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleException;
use App\Tests\Support\TestCase;

/**
 * The command registry, which is the router's counterpart.
 *
 * Same guarantees: a name resolves to exactly one thing, a name already taken
 * is refused rather than silently overridden, and ownership is recorded so a
 * conflict can name both claimants.
 */
final class CommandRegistryTest extends TestCase
{
    private function registry(string ...$names): CommandRegistry
    {
        $registry = new CommandRegistry();

        foreach ($names as $name) {
            $registry->add(new Command($name, static fn(): int => 0));
        }

        return $registry;
    }

    public function test_a_command_is_found_by_name(): void
    {
        $registry = $this->registry('customer:sync');

        self::assertTrue($registry->has('customer:sync'));
        self::assertSame('customer:sync', $registry->get('customer:sync')?->name);
    }

    public function test_an_unknown_name_resolves_to_nothing(): void
    {
        self::assertNull($this->registry()->get('nope'));
        self::assertFalse($this->registry()->has('nope'));
    }

    /** Silently overriding would mean the working command is whichever module loaded last. */
    public function test_two_modules_cannot_claim_the_same_name(): void
    {
        $registry = new CommandRegistry();
        $registry->add(new Command('customer:sync', static fn(): int => 0, 'Alpha'));

        $caught = 'nothing was thrown';

        try {
            $registry->add(new Command('customer:sync', static fn(): int => 0, 'Beta'));
        } catch (ConsoleException $e) {
            $caught = $e->getMessage();
        }

        self::assertStringContainsString('Alpha', $caught);
        self::assertStringContainsString('Beta', $caught);
        self::assertSame('Alpha', $registry->get('customer:sync')?->module, 'the first claim stands');
    }

    public function test_registration_order_is_kept_and_sorting_is_separate(): void
    {
        $registry = $this->registry('z:last', 'a:first');

        self::assertSame(['z:last', 'a:first'], \array_map(
            static fn(Command $command): string => $command->name,
            $registry->all(),
        ));

        self::assertSame(['a:first', 'z:last'], \array_map(
            static fn(Command $command): string => $command->name,
            $registry->sorted(),
        ));
    }

    public function test_commands_are_grouped_with_the_ungrouped_ones_first(): void
    {
        $groups = $this->registry('customer:sync', 'about', 'cache:clear')->grouped();

        self::assertSame(['', 'cache', 'customer'], \array_keys($groups));
    }

    public function test_counting_and_naming(): void
    {
        $registry = $this->registry('about', 'customer:sync');

        self::assertSame(2, $registry->count());
        self::assertSame(['about', 'customer:sync'], $registry->names());
    }

    // ---- suggestions --------------------------------------------------------

    public function test_a_typo_is_offered_the_name_it_meant(): void
    {
        self::assertSame(
            ['customer:sync'],
            $this->registry('customer:sync', 'about')->suggest('customer:snyc'),
        );
    }

    public function test_a_prefix_is_offered_the_names_it_starts(): void
    {
        $suggestions = $this->registry('customer:sync', 'customer:show', 'about')->suggest('customer');

        self::assertContains('customer:sync', $suggestions);
        self::assertContains('customer:show', $suggestions);
        self::assertNotContains('about', $suggestions);
    }

    /**
     * Suggesting is all it does.
     *
     * Symfony runs an unambiguous abbreviation. A command that deletes
     * something should not be reachable by a name its author never wrote.
     */
    public function test_a_suggestion_is_never_run_in_place_of_what_was_typed(): void
    {
        $registry = $this->registry('customer:sync');

        self::assertNotEmpty($registry->suggest('customer:snyc'));
        self::assertNull($registry->get('customer:snyc'));
    }

    public function test_nothing_similar_is_suggested_when_nothing_is_similar(): void
    {
        self::assertSame([], $this->registry('customer:sync')->suggest('wildly-different'));
    }

    // ---- the collector -------------------------------------------------------

    public function test_the_collector_stamps_each_command_with_its_module(): void
    {
        $registry = new CommandRegistry();
        $commands = new CommandCollector($registry, 'Example');

        $commands->add('customer:sync', static fn(): int => 0);

        self::assertSame('Example', $registry->get('customer:sync')?->module);
    }

    public function test_the_collector_returns_the_command_so_declarations_chain(): void
    {
        $registry = new CommandRegistry();
        $commands = new CommandCollector($registry);

        $commands->add('customer:sync', static fn(): int => 0)
            ->describe('Sync them.')
            ->argument('since', required: false);

        $command = $registry->get('customer:sync');
        self::assertNotNull($command);

        self::assertSame('Sync them.', $command->description());
        self::assertCount(1, $command->arguments());
    }
}
