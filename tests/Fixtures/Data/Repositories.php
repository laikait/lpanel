<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Data;

use App\Engine\Data\Page;
use App\Engine\Data\Query;
use App\Engine\Data\Repository;
use App\Engine\Model\Model;
use App\Engine\Model\ModelCollection;
use App\Tests\Fixtures\Model\Country;
use App\Tests\Fixtures\Model\Customer;
use App\Tests\Fixtures\Model\CustomerListRecord;

/**
 * A repository written the way the framework intends: two declarations, and
 * then methods named after things the application does.
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

    public function active(): ModelCollection
    {
        return $this->query()->whereIs('active', true)->orderBy('name')->get();
    }

    /** The read-optimised path: three columns, no domain objects. */
    public function listPage(int $page, int $perPage): Page
    {
        return $this->query()->orderBy('name')->pageInto(CustomerListRecord::class, $page, $perPage);
    }

    /** @return list<int> */
    public function ownerIds(): array
    {
        $ids = [];

        foreach ($this->query()->whereNotNull('ownerId')->column('ownerId') as $id) {
            if (\is_int($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function register(string $name, string $email, ?int $ownerId = null): Customer
    {
        return $this->mustBeCustomer($this->persist(new Customer(null, $name, $email, $ownerId)));
    }

    public function rename(Customer $customer, string $name): Customer
    {
        $customer->rename($name);

        return $this->mustBeCustomer($this->persist($customer));
    }

    public function deactivate(Customer $customer): Customer
    {
        $customer->deactivate();

        return $this->mustBeCustomer($this->persist($customer));
    }

    public function forget(Customer $customer): void
    {
        $this->remove($customer);
    }

    /**
     * A bulk import, named for what it is.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function import(array $rows): int
    {
        return $this->insertMany($rows);
    }

    public function deactivateOwnedBy(int $ownerId): int
    {
        return $this->updateWhere($this->query()->whereIs('ownerId', $ownerId), ['active' => false]);
    }

    public function purgeInactive(): int
    {
        return $this->deleteWhere($this->query()->whereIs('active', false));
    }

    /** Exposed so tests can exercise the inherited plumbing directly. */
    public function queryFor(): Query
    {
        return $this->query();
    }

    /** @param array<string, mixed> $row */
    public function hydrateRow(array $row): Model
    {
        return $this->hydrate($row);
    }

    private function asCustomer(?Model $model): ?Customer
    {
        return $model instanceof Customer ? $model : null;
    }

    private function mustBeCustomer(Model $model): Customer
    {
        return $model instanceof Customer
            ? $model
            : throw new \LogicException('The repository stored something other than a Customer.');
    }
}

/** A repository whose rows are keyed by something other than "id". */
final class CountryRepository extends Repository
{
    protected function model(): string
    {
        return Country::class;
    }

    protected function collection(): string
    {
        return 'countries';
    }

    protected function key(): string
    {
        return 'code';
    }

    public function find(string $code): ?Country
    {
        $country = $this->query()->whereIs('code', $code)->first();

        return $country instanceof Country ? $country : null;
    }

    public function add(string $code, string $label): Country
    {
        $stored = $this->persist(new Country($code, $label));

        return $stored instanceof Country
            ? $stored
            : throw new \LogicException('The repository stored something other than a Country.');
    }

    public function drop(Country $country): void
    {
        $this->remove($country);
    }
}
