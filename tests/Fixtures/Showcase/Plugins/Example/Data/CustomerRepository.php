<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Data;

use App\Engine\Data\Repository;
use App\Engine\Model\Model;
use App\Engine\Model\ModelCollection;
use App\Tests\Fixtures\Showcase\Plugins\Example\Model\Customer;

/**
 * Customer persistence, named after what the application does with customers.
 *
 * Two declarations -- which model, which collection -- and then domain
 * operations. Nothing here is inherited and nothing was generated: there is no
 * findById(), no findByName(), no save(), and no method that exists only
 * because a column does.
 */
final class CustomerRepository extends Repository
{
    protected function model(): string
    {
        return Customer::class;
    }

    protected function collection(): string
    {
        return 'customers';
    }

    public function find(int $id): ?Customer
    {
        return $this->asCustomer($this->query()->whereIs('id', $id)->first());
    }

    public function findByEmail(string $email): ?Customer
    {
        return $this->asCustomer($this->query()->whereIs('email', $email)->first());
    }

    public function all(): ModelCollection
    {
        return $this->query()->orderBy('id')->get();
    }

    /**
     * Register a customer.
     *
     * Note the return value. A new customer has no identity until it is
     * written, so what comes back is the stored customer, complete and clean.
     *
     * @param array<string, mixed> $attributes already checked against CustomerSchema
     */
    public function register(array $attributes): Customer
    {
        $name = $attributes['name'] ?? '';
        $email = $attributes['email'] ?? '';

        $stored = $this->persist(new Customer(
            null,
            \is_string($name) ? $name : '',
            \is_string($email) ? $email : '',
        ));

        return $stored instanceof Customer
            ? $stored
            : throw new \LogicException('The repository stored something other than a Customer.');
    }

    public function rename(Customer $customer, string $name): Customer
    {
        $customer->rename($name);

        // Only the name is written: the model tracked what moved.
        $stored = $this->persist($customer);

        return $stored instanceof Customer
            ? $stored
            : throw new \LogicException('The repository stored something other than a Customer.');
    }

    public function discard(Customer $customer): void
    {
        $this->remove($customer);
    }

    private function asCustomer(?Model $model): ?Customer
    {
        return $model instanceof Customer ? $model : null;
    }
}
