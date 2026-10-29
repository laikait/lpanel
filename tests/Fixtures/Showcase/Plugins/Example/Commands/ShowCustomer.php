<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Commands;

use App\Engine\Cli\Output;
use App\Tests\Fixtures\Showcase\Plugins\Example\Data\CustomerRepository;

/**
 * The [class, method] handler form, on the console side.
 *
 * Exactly what a route does with [CustomerApi::class, 'show']: one class can
 * carry several related entry points, and the declared <id> argument arrives as
 * a real int because the parameter says int. "abc" never reaches this method --
 * it is refused as a usage error, with the synopsis, before the handler is
 * called.
 *
 * Exit code 1 for "no such customer" is deliberate. A script that runs this in
 * a loop needs to know the difference between a customer that was printed and
 * one that was not, and the exit code is the only thing it can read reliably.
 */
final class ShowCustomer
{
    public function __construct(private readonly CustomerRepository $customers) {}

    public function show(Output $output, int $id): int
    {
        $customer = $this->customers->find($id);

        if ($customer === null) {
            $output->error(\sprintf('No customer has id %d.', $id));

            return 1;
        }

        $output->pairs([
            'ID' => (string) $customer->identity(),
            'Name' => $customer->name(),
            'Email' => $customer->email(),
            'Owner' => $customer->ownerId() === null ? 'unassigned' : (string) $customer->ownerId(),
        ]);

        return 0;
    }
}
