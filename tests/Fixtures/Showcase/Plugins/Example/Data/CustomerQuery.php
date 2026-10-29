<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Data;

use App\Engine\Cache\Cache;
use App\Engine\Data\DataSource;
use App\Engine\Data\Page;
use App\Engine\Data\Query;
use App\Engine\Model\ModelManager;
use App\Tests\Fixtures\Showcase\Plugins\Example\Model\Customer;
use App\Tests\Fixtures\Showcase\Plugins\Example\Model\CustomerListRecord;

/**
 * Reads that exist to be fast rather than to be domain operations.
 *
 * A repository and a query are different jobs, which is why they are different
 * classes. The repository is where a customer is registered, renamed and
 * discarded, and it deals in domain models because those operations have rules
 * to enforce. This deals in read models, because a list screen has none: it
 * wants three columns, in order, in pages, and it wants them without building
 * anything that could be saved by accident.
 */
final class CustomerQuery
{
    /** One key, named once, because the code that invalidates it has to spell it the same way. */
    public const TOTAL = 'customers.total';

    private readonly Cache $cache;

    public function __construct(
        private readonly DataSource $source,
        private readonly ModelManager $models,
        Cache $cache,
    ) {
        // A module takes its own namespace, so clearing this module's cached
        // answers cannot clear anybody else's.
        $this->cache = $cache->namespace('plugins.example');
    }

    /**
     * A page of customers for a list screen.
     *
     * pageInto() reads only the columns CustomerListRecord declares and never
     * hydrates a Customer. On three rows that is invisible; on a hundred
     * thousand it is the difference between a page and a timeout.
     */
    public function listPage(int $page, int $perPage): Page
    {
        return $this->query()->orderBy('id')->pageInto(CustomerListRecord::class, $page, $perPage);
    }

    /**
     * How many customers there are, without reading a single row of them.
     *
     * Cached, because a count over a large table is the kind of query that is
     * cheap on three rows and expensive on three million, and every list screen
     * asks for it. The TTL is a backstop rather than the plan: the plan is that
     * whoever changes the number says so -- see forgetTotal(), which the
     * customer.created listener calls.
     */
    public function total(): int
    {
        $total = $this->cache->remember(self::TOTAL, fn(): int => $this->query()->count(), 300);

        return \is_int($total) ? $total : 0;
    }

    /** Say that the count is wrong now. Called when a customer is created. */
    public function forgetTotal(): void
    {
        $this->cache->delete(self::TOTAL);
    }

    private function query(): Query
    {
        return Query::on($this->source, 'customers', $this->models, Customer::class);
    }
}
