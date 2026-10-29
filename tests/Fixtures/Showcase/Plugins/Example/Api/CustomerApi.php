<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Api;

use App\Engine\Error\ErrorDocument;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Logging\Logger;
use App\Engine\Model\ModelCollection;
use App\Engine\Model\RelationManager;
use App\Engine\Queue\Queue;
use App\Engine\Routing\Router;
use App\Tests\Fixtures\Showcase\Plugins\Example\Data\CustomerRepository;
use App\Tests\Fixtures\Showcase\Plugins\Example\Jobs\WelcomeCustomer;
use App\Tests\Fixtures\Showcase\Plugins\Example\Model\Customer;
use App\Tests\Fixtures\Showcase\Plugins\Example\Schema\CustomerSchema;
use App\Tests\Fixtures\Showcase\Shared\Data\UserRepository;
use App\Tests\Fixtures\Showcase\Shared\Model\User;

/**
 * The REST surface for customers.
 *
 * The API belongs to the capability that owns it: this lives in the Customer
 * module, not in a global controllers directory. It uses the same kernel, the
 * same router and the same dispatcher as any other route -- there is no API
 * framework here, only a handler that happens to answer in JSON.
 *
 * Nothing is extended and nothing is implemented. The only API-specific things
 * on this page are two static factories, ApiResponse and ErrorDocument, and
 * both are conveniences the handler chose rather than machinery it inherited.
 */
final class CustomerApi
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly UserRepository $users,
        private readonly RelationManager $relations,
        private readonly Queue $queue,
        private readonly HookEngine $hooks,
        private readonly Logger $log,
        private readonly Router $router,
    ) {}

    /** {id} is coerced to int by the dispatcher; a non-numeric value is a 400. */
    public function show(int $id): JsonResponse
    {
        $customer = $this->customers->find($id)
            ?? throw HttpException::notFound(\sprintf('/api/v1/customers/%d', $id));

        $customers = ModelCollection::of(Customer::class, [$customer]);

        // The owner is loaded explicitly and in a batch, even though there is
        // one customer here. Written this way the shape does not change when
        // the endpoint becomes a list: collect the keys, issue one query, link
        // the results. A relation that fetched itself on access would look
        // tidier on this line and run one query per row on the next.
        $this->relations->link(
            $customers,
            'owner',
            $this->users->findAll($this->relations->keysFor($customers, 'owner')),
        );

        $owner = $customer->related('owner');

        // serialize() rather than an array literal: the response is shaped by
        // the same declaration that documents it, so the two cannot drift.
        return ApiResponse::item(CustomerSchema::resource()->serialize([
            'id' => $customer->identity(),
            'name' => $customer->name(),
            'email' => $customer->email(),
            'owner' => $owner instanceof User ? $owner->username() : null,
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        // 415 before anything else. A body this endpoint cannot read is a
        // different failure from a body whose fields are wrong, and reporting
        // it as the second sends whoever is debugging to look at their fields
        // rather than at their Content-Type header.
        $request->requirePayload();

        $payload = $request->json();

        $schema = CustomerSchema::input();
        $result = $schema->validate(\is_array($payload) ? $payload : []);

        if (!$result->isValid()) {
            // Returned rather than thrown: this handler owns what its own
            // failures look like, and it has more to say than a status line.
            // ErrorDocument is the same type the error handler uses for an
            // uncaught exception, so a client sees one shape whatever went
            // wrong -- which before this was true only by coincidence.
            return ErrorDocument::validation(
                // Everything wrong at once, instead of one field per round
                // trip, and the contract alongside it. Both come from the
                // declaration, so neither can drift from what is enforced.
                fields: $result->messages(),
                message: 'The customer could not be created.',
                expected: $schema->describe()['fields'],
            )->toResponse();
        }

        /** @var array<string, mixed> $payload */
        $attributes = $schema->deserialize($payload);

        $customer = $this->customers->register($attributes);

        // register() persists, and a persisted customer has the identity its
        // source assigned. Checked once, here, so everything below works with
        // an int rather than re-asking "what if it had none".
        $id = $customer->identity()
            ?? throw new \LogicException('A registered customer has no identity; the data source assigned none.');

        // Other modules -- including gateways that know nothing about this one
        // -- can react to this without any coupling in either direction. The
        // model itself is passed, so a listener reads the domain object rather
        // than guessing at the shape of an array.
        $this->hooks->do('customer.created', $customer);

        // An audit line, on the ordinary injected logger. Where it ends up is a
        // deployment decision this handler never learns: with no writers
        // configured the call costs one comparison and goes nowhere, and
        // switching on logging.writers is what makes it appear.
        //
        // The email is passed as context rather than spliced into the message,
        // so the message stays greppable and the field stays machine-readable
        // -- and so that a key on the redaction list would be caught, which a
        // sentence never can be.
        $this->log->info('Customer registered', [
            'id' => $id,
            'email' => $customer->email(),
        ]);

        // The follow-up work, which the person waiting for this response should
        // not have to wait for. What happens next is a deployment decision and
        // not this handler's business: with the default store the job runs here
        // and now, and with a queue configured it runs in a worker. The line is
        // the same either way, which is the only way this stays a decision
        // somebody can change later.
        $this->queue->push(new WelcomeCustomer($id));

        // 201 with a Location, built from the route's own name. A client learns
        // the identity the server assigned from the header rather than by
        // parsing the body for it, and the URL cannot drift from the route
        // because it is the route that produced it.
        return ApiResponse::created(
            CustomerSchema::resource()->serialize([
                'id' => $id,
                'name' => $customer->name(),
                'email' => $customer->email(),
            ]),
            $this->router->url('api.v1.customers.show', ['id' => $id]),
        );
    }
}
