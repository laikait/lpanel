<?php

declare(strict_types=1);

namespace App\Tests\Unit\Error;

use App\Engine\Error\ErrorDocument;
use App\Engine\Http\HttpException;
use App\Tests\Support\TestCase;

/**
 * One shape for every error, and the rules that keep it one shape.
 */
final class ErrorDocumentTest extends TestCase
{
    public function test_status_title_and_message_are_always_present(): void
    {
        $document = ErrorDocument::of(404);

        self::assertSame(
            ['error' => ['status' => 404, 'title' => 'Not Found', 'message' => 'Not Found']],
            $document->toArray(),
        );
    }

    public function test_a_message_replaces_only_the_message(): void
    {
        $document = ErrorDocument::of(409, 'That invoice is already paid.');

        self::assertSame('Conflict', $document->title);
        self::assertSame('That invoice is already paid.', $document->message);
    }

    public function test_details_are_added_under_their_own_keys(): void
    {
        $document = ErrorDocument::of(400)->with('fields', ['name' => ['is required']]);

        self::assertSame(
            ['status' => 400, 'title' => 'Bad Request', 'message' => 'Bad Request', 'fields' => ['name' => ['is required']]],
            $document->toArray()['error'],
        );
    }

    /**
     * The three fixed keys are what a client is entitled to rely on. A detail
     * key that could shadow one would make that reliance conditional on what
     * the handler happened to call things.
     */
    public function test_the_fixed_keys_cannot_be_overwritten(): void
    {
        foreach (['status', 'title', 'message'] as $key) {
            try {
                ErrorDocument::of(400)->with($key, 'hijacked');
                self::fail(\sprintf('"%s" was overwritten', $key));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    public function test_it_is_immutable(): void
    {
        $original = ErrorDocument::of(400);
        $extended = $original->with('fields', ['a' => ['b']]);

        self::assertNull($original->detail('fields'));
        self::assertNotNull($extended->detail('fields'));
    }

    // ---- from an exception ------------------------------------------------

    public function test_an_http_exception_keeps_its_status_and_message(): void
    {
        $document = ErrorDocument::fromThrowable(HttpException::notFound('/nope'));

        self::assertSame(404, $document->status);
        self::assertStringContainsString('/nope', $document->message);
    }

    /**
     * Anything that is not an HttpException may carry a DSN, a path or a
     * credential in its message. Outside debug mode it is replaced wholesale
     * rather than filtered: filtering means guessing which substrings are
     * secret, and that guess is wrong eventually.
     */
    public function test_an_ordinary_exception_leaks_nothing_outside_debug_mode(): void
    {
        $document = ErrorDocument::fromThrowable(
            new \RuntimeException('SQLSTATE[28000] password=hunter2'),
            debug: false,
        );

        self::assertSame(500, $document->status);
        self::assertSame('Internal Server Error', $document->message);

        $encoded = \json_encode($document, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('hunter2', $encoded);
        self::assertStringNotContainsString('ErrorDocumentTest', $encoded);
    }

    public function test_debug_mode_adds_diagnostics_without_moving_anything(): void
    {
        $document = ErrorDocument::fromThrowable(new \LogicException('the real problem'), debug: true);
        $error = $document->toArray()['error'];

        self::assertSame('the real problem', $error['message']);
        self::assertSame(\LogicException::class, $error['exception']);
        self::assertArrayHasKey('file', $error);
        self::assertArrayHasKey('line', $error);
        self::assertIsArray($error['trace']);

        // The three fixed keys are still first and still mean the same thing.
        self::assertSame(['status', 'title', 'message'], \array_slice(\array_keys($error), 0, 3));
    }

    // ---- validation -------------------------------------------------------

    public function test_a_validation_failure_carries_every_field_at_once(): void
    {
        $document = ErrorDocument::validation(
            fields: ['name' => ['is required'], 'email' => ['must be an email address']],
            message: 'The customer could not be created.',
        );

        self::assertSame(400, $document->status);
        self::assertSame('The customer could not be created.', $document->message);
        self::assertSame(
            ['name' => ['is required'], 'email' => ['must be an email address']],
            $document->detail('fields'),
        );
    }

    public function test_the_contract_can_travel_with_the_failure(): void
    {
        $document = ErrorDocument::validation(
            fields: ['name' => ['is required']],
            expected: ['name' => ['type' => 'string', 'required' => true]],
        );

        self::assertSame(['name' => ['type' => 'string', 'required' => true]], $document->detail('expected'));
    }

    public function test_the_status_is_the_callers_choice(): void
    {
        self::assertSame(422, ErrorDocument::validation(fields: [], status: 422)->status);
    }

    // ---- as a response ----------------------------------------------------

    public function test_the_response_carries_the_status_and_the_body(): void
    {
        $response = ErrorDocument::of(403, 'Not yours.')->toResponse();

        self::assertSame(403, $response->status());
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame(
            ['error' => ['status' => 403, 'title' => 'Forbidden', 'message' => 'Not yours.']],
            $response->data(),
        );
    }

    public function test_headers_travel_with_it(): void
    {
        $response = ErrorDocument::of(405)->toResponse(['Allow' => 'GET, POST']);

        self::assertSame('GET, POST', $response->header('Allow'));
    }
}
