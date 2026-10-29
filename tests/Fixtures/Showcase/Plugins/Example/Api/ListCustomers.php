<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Api;

use App\Engine\Config\Config;
use App\Engine\Error\ErrorDocument;
use App\Engine\Filter\FilterEngine;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Routing\Router;
use App\Tests\Fixtures\Showcase\Plugins\Example\Data\CustomerQuery;
use App\Tests\Fixtures\Showcase\Shared\Schema\PaginationSchema;

/**
 * An invokable handler.
 *
 * No base class, no controller directory, no route-model binding. The query and
 * the filter engine arrive through the constructor, which is the pattern module
 * classes should use: the global apply_filter() helper exists for module.php
 * files and templates, not for classes that can be injected.
 *
 * Note what this endpoint never touches. It asks a CustomerQuery, not a
 * CustomerRepository, and gets read models back -- three columns, read from
 * three columns. No domain model is built, so none can reach the response, and
 * the work does not grow with the size of a Customer.
 */
final class ListCustomers
{
    public function __construct(
        private readonly CustomerQuery $customers,
        private readonly FilterEngine $filters,
        private readonly Router $router,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // A query string is all strings. The shared schema converts and bounds
        // it in one call, so per_page cannot become 100000 by typing it.
        $query = \is_array($request->query()) ? $request->query() : [];

        // What a request does not say, the installation decides. page_size is
        // declared by this module and overridden in config/Example.php,
        // which is why the default here is a lookup rather than a number.
        $query['per_page'] ??= $this->config->int('Example.page_size', 25);

        $paging = PaginationSchema::schema()->validate($query);

        if (!$paging->isValid()) {
            return ErrorDocument::validation(
                fields: $paging->messages(),
                message: 'The pagination parameters are not usable.',
            )->toResponse();
        }

        /** @var array{page: int, per_page: int} $page */
        $page = PaginationSchema::schema()->deserialize($query);

        $results = $this->customers->listPage($page['page'], $page['per_page']);

        /** @var list<mixed> $records */
        $records = $this->filters->apply('example.customers.list', $results->items(), $request);

        return ApiResponse::collection(
            $records,
            ['count' => \count($records)] + $results->meta() + $this->links($results->page, $results->pages()),
        );
    }

    /**
     * Where to go next, built from the route's own name.
     *
     * Pagination links live here rather than in ApiResponse because building
     * one needs the router and the name of the route being paginated, and
     * neither is something the HTTP layer is allowed to know. The envelope is
     * generic; what goes in it is this endpoint's business.
     *
     * @return array<string, array<string, string|null>>
     */
    private function links(int $current, int $pages): array
    {
        $url = fn(int $page): string => $this->router->url('customers.json', ['page' => $page]);

        return ['links' => [
            'self' => $url($current),
            'prev' => $current > 1 ? $url($current - 1) : null,
            'next' => $current < $pages ? $url($current + 1) : null,
        ]];
    }
}
