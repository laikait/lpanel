<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Data\ArraySource;
use App\Engine\Data\DataSource;
use App\Engine\Data\Operator;
use App\Engine\Data\Query;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\SqlSource;
use App\Engine\Model\ModelCollection;
use App\Engine\Model\ModelManager;
use App\Engine\Model\Relation;
use App\Engine\Model\RelationManager;
use App\Tests\Fixtures\Data\CountryRepository;
use App\Tests\Fixtures\Data\CustomerRepository;
use App\Tests\Fixtures\Model\Customer;
use App\Tests\Fixtures\Model\CustomerListRecord;
use App\Tests\Fixtures\Model\User;
use App\Tests\Support\TestCase;

/**
 * The proof that the data layer was worth building the way it was.
 *
 * Every repository, query, read model, relation and page below is the same code
 * that runs against an array in memory, unchanged. Only the line that chooses
 * the source is different, which is the entire claim the DataSource interface
 * makes. If these pass, a module written against ArraySource in a test runs
 * against a database in production without being touched.
 */
final class SqlSourceTest extends TestCase
{
    private Connection $connection;

    private SqlSource $source;

    private ModelManager $models;

    private CustomerRepository $customers;

    protected function setUp(): void
    {
        if (!\in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->connection = new Connection(ConnectionConfig::of('test', 'sqlite::memory:'));
        $this->connection->execute(
            'CREATE TABLE customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                ownerId INTEGER NULL,
                active INTEGER NOT NULL DEFAULT 1,
                balance REAL NOT NULL DEFAULT 0
            )',
        );
        $this->connection->execute('CREATE TABLE countries (code TEXT PRIMARY KEY, label TEXT NOT NULL)');
        $this->connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL)');

        $this->source = new SqlSource($this->connection);
        $this->models = new ModelManager();
        $this->customers = new CustomerRepository($this->source, $this->models);
    }

    private function seed(): void
    {
        $this->customers->register('Ada Lovelace', 'ada@example.test', 10);
        $this->customers->register('Grace Hopper', 'grace@example.test', 11);
        $this->customers->register('Katherine Johnson', 'kat@example.test');
    }

    // ---- the same repository, a real database -----------------------------

    public function test_a_repository_writes_and_reads_through_a_real_database(): void
    {
        $ada = $this->customers->register('Ada Lovelace', 'ada@example.test', 10);

        self::assertSame(1, $ada->identity(), 'the database assigned the identity');
        self::assertFalse($ada->isNew());
        self::assertFalse($ada->isDirty());

        self::assertSame(
            ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.test'],
            $this->connection->selectOne('SELECT id, name, email FROM customers'),
        );

        self::assertSame('Ada Lovelace', $this->customers->find(1)?->name());
        self::assertSame(1, $this->customers->findByEmail('ada@example.test')?->identity());
    }

    /**
     * SQLite hands back an integer for an INTEGER column and MySQL may hand
     * back a string for the same one. The model layer converts either, which is
     * why a model can declare int and mean it.
     */
    public function test_column_types_are_converted_into_the_models_declared_types(): void
    {
        $this->connection->execute(
            'INSERT INTO customers (id, name, email, ownerId, active, balance) VALUES (?, ?, ?, ?, ?, ?)',
            [7, 'Typed', 'typed@example.test', '11', 0, '12.5'],
        );

        $customer = $this->customers->find(7);

        self::assertNotNull($customer);
        self::assertSame(7, $customer->identity());
        self::assertSame(11, $customer->ownerId());
        self::assertFalse($customer->isActive());
        self::assertSame(12.5, $customer->balance());
    }

    public function test_an_update_writes_only_the_changed_column(): void
    {
        $ada = $this->customers->register('Ada Lovelace', 'ada@example.test', 10);

        $this->customers->rename($ada, 'Ada King');

        self::assertSame('Ada King', $this->connection->scalar('SELECT name FROM customers WHERE id = 1'));
        self::assertSame(10, $this->connection->scalar('SELECT ownerId FROM customers WHERE id = 1'));
        self::assertFalse($ada->isDirty());
    }

    public function test_removing_a_model_deletes_its_row(): void
    {
        $ada = $this->customers->register('Ada Lovelace', 'ada@example.test');

        $this->customers->forget($ada);

        self::assertSame(0, $this->connection->scalar('SELECT COUNT(*) FROM customers'));
        self::assertNull($this->customers->find(1));
    }

    /** A model that assigns its own identity is stored under it. */
    public function test_a_repository_keyed_by_something_other_than_id(): void
    {
        $countries = new CountryRepository($this->source, $this->models);

        $country = $countries->add('GB', 'United Kingdom');

        self::assertSame('GB', $country->identity());
        self::assertSame('United Kingdom', $this->connection->scalar('SELECT label FROM countries WHERE code = ?', ['GB']));
        self::assertSame('United Kingdom', $countries->find('GB')?->label());

        $countries->drop($country);
        self::assertSame(0, $this->connection->scalar('SELECT COUNT(*) FROM countries'));
    }

    // ---- the query, unchanged ---------------------------------------------

    public function test_every_read_shape_works_over_sql(): void
    {
        $this->seed();

        $query = $this->customers->queryFor();

        self::assertCount(3, $query->rows());
        self::assertSame(3, $query->count());
        self::assertTrue($query->exists());
        self::assertSame(['Ada Lovelace', 'Grace Hopper', 'Katherine Johnson'], $query->orderBy('name')->column('name'));
        self::assertSame('Ada Lovelace', $query->orderBy('name')->value('name'));
        self::assertSame(3, $query->get()->count());
        self::assertSame('Ada Lovelace', $query->orderBy('name')->first()?->attribute('name'));
    }

    public function test_criteria_and_ordering_compile_and_run(): void
    {
        $this->seed();

        $query = $this->customers->queryFor();

        self::assertSame([1, 2], $query->whereNotNull('ownerId')->orderBy('id')->column('id'));
        self::assertSame([3], $query->whereNull('ownerId')->column('id'));
        self::assertSame([1, 2], $query->whereIn('id', [1, 2])->orderBy('id')->column('id'));
        self::assertSame([3], $query->whereNotIn('id', [1, 2])->column('id'));
        self::assertSame([2], $query->whereLike('name', 'grace%')->column('id'));
        self::assertSame([2, 3], $query->where('id', Operator::Gte, 2)->orderBy('id')->column('id'));
        self::assertSame([3, 2, 1], $query->orderByDesc('id')->column('id'));
    }

    /** Selecting only the columns a read model declares, against a real table. */
    public function test_read_models_are_built_from_a_narrowed_select(): void
    {
        $this->seed();

        $records = $this->customers->queryFor()->orderBy('id')->into(CustomerListRecord::class);

        self::assertCount(3, $records);
        self::assertInstanceOf(CustomerListRecord::class, $records[0]);
        self::assertSame(
            ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.test'],
            $records[0]->toArray(),
        );
    }

    public function test_pagination_counts_the_whole_and_returns_the_slice(): void
    {
        $this->seed();

        $page = $this->customers->listPage(2, 2);

        self::assertCount(1, $page);
        self::assertSame(['total' => 3, 'page' => 2, 'per_page' => 2, 'pages' => 2, 'count' => 1], $page->meta());
    }

    /** A cursor, not an array: one row is in memory at a time. */
    public function test_streaming_reads_one_row_at_a_time(): void
    {
        $this->seed();

        $names = [];

        foreach ($this->customers->queryFor()->orderBy('id')->stream() as $customer) {
            $names[] = $customer->attribute('name');
        }

        self::assertSame(['Ada Lovelace', 'Grace Hopper', 'Katherine Johnson'], $names);
    }

    public function test_chunking_walks_the_table_in_batches(): void
    {
        $this->seed();

        $sizes = [];

        $this->customers->queryFor()->orderBy('id')->chunk(2, static function (ModelCollection $batch) use (&$sizes): void {
            $sizes[] = $batch->count();
        });

        self::assertSame([2, 1], $sizes);
    }

    // ---- relations, unchanged ---------------------------------------------

    /**
     * Two queries and a link, exactly as in memory. No lazy association exists
     * to turn a loop into one query per row.
     */
    public function test_relations_are_linked_in_a_batch_over_sql(): void
    {
        $this->seed();
        $this->connection->execute('INSERT INTO users (id, username) VALUES (10, ?), (11, ?)', ['ada', 'grace']);

        $relations = new RelationManager();
        $relations->declare(Customer::class, Relation::one('owner', User::class, 'ownerId', 'id'));

        $customers = $this->customers->queryFor()->orderBy('id')->get();
        $keys = $relations->keysFor($customers, 'owner');

        self::assertSame([10, 11], $keys);

        $owners = Query::on($this->source, 'users', $this->models, User::class)->whereIn('id', $keys)->get();
        $relations->link($customers, 'owner', $owners);

        $owner = $customers->find(1)?->related('owner');

        self::assertInstanceOf(User::class, $owner);
        self::assertSame('ada', $owner->username());
        self::assertNull($customers->find(3)?->related('owner'), 'loaded and empty, not unloaded');
    }

    // ---- transactions around repository work ------------------------------

    /**
     * The application decides the boundary. Several repository writes are one
     * transaction because the caller said so, not because each method opened
     * one of its own.
     */
    public function test_several_repository_writes_commit_as_one_transaction(): void
    {
        $this->connection->transaction(function (): void {
            $this->customers->register('Ada Lovelace', 'ada@example.test');
            $this->customers->register('Grace Hopper', 'grace@example.test');
        });

        self::assertSame(2, $this->connection->scalar('SELECT COUNT(*) FROM customers'));
    }

    public function test_a_failure_rolls_every_repository_write_back(): void
    {
        try {
            $this->connection->transaction(function (): void {
                $this->customers->register('Ada Lovelace', 'ada@example.test');
                $this->customers->register('Grace Hopper', 'grace@example.test');

                throw new \RuntimeException('the third step failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->connection->scalar('SELECT COUNT(*) FROM customers'));
    }

    // ---- the claim itself -------------------------------------------------

    /**
     * The same repository code, both sources, identical answers.
     *
     * This is what the DataSource interface is for, stated as a test rather
     * than as a paragraph in a document.
     */
    public function test_the_same_repository_gives_the_same_answers_on_either_source(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'ownerId' => 10, 'active' => 1, 'balance' => 5.0],
            ['id' => 2, 'name' => 'Grace Hopper', 'email' => 'grace@example.test', 'ownerId' => 11, 'active' => 0, 'balance' => 0.0],
            ['id' => 3, 'name' => 'Katherine Johnson', 'email' => 'kat@example.test', 'ownerId' => null, 'active' => 1, 'balance' => 12.5],
        ];

        foreach ($rows as $row) {
            $this->connection->execute(
                'INSERT INTO customers (id, name, email, ownerId, active, balance) VALUES (?, ?, ?, ?, ?, ?)',
                \array_values($row),
            );
        }

        $inMemory = new CustomerRepository(new ArraySource(['customers' => $rows]), new ModelManager());

        $answers = static fn(CustomerRepository $repository): array => [
            'active' => $repository->active()->identities(),
            'owners' => $repository->ownerIds(),
            'page' => $repository->listPage(1, 2)->meta(),
            'records' => \array_map(
                static fn(mixed $record): array => $record instanceof CustomerListRecord ? $record->toArray() : [],
                $repository->listPage(1, 3)->items(),
            ),
            'byEmail' => $repository->findByEmail('grace@example.test')?->name(),
            'missing' => $repository->find(99)?->name(),
        ];

        self::assertSame($answers($inMemory), $answers($this->customers));
    }

    public function test_the_source_is_a_data_source_and_nothing_more(): void
    {
        self::assertInstanceOf(DataSource::class, $this->source);
        self::assertSame($this->connection, $this->source->connection());
    }
}
