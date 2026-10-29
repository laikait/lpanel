<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Engine\Http\ApiResponse;
use App\Engine\Http\JsonResponse;
use App\Tests\Support\TestCase;

final class ApiResponseTest extends TestCase
{
    public function test_an_item_is_wrapped_in_data(): void
    {
        $response = ApiResponse::item(['id' => 1, 'name' => 'Ada']);

        self::assertSame(200, $response->status());
        self::assertSame(['data' => ['id' => 1, 'name' => 'Ada']], $response->data());
    }

    public function test_a_collection_is_a_list_even_when_the_keys_are_not(): void
    {
        // array_values(), because a filtered list keeps its original keys and
        // json_encode then emits an object where the client expects an array.
        // That is a breaking change nobody made on purpose.
        $response = ApiResponse::collection([3 => 'c', 7 => 'd']);

        self::assertSame('{"data":["c","d"]}', $response->body());
    }

    public function test_meta_is_omitted_when_there_is_none(): void
    {
        self::assertSame(['data' => []], ApiResponse::collection([])->data());
    }

    public function test_meta_travels_alongside_the_data(): void
    {
        $response = ApiResponse::collection(['a'], ['total' => 1, 'page' => 1]);

        self::assertSame(['data' => ['a'], 'meta' => ['total' => 1, 'page' => 1]], $response->data());
    }

    public function test_a_generator_is_materialised(): void
    {
        $items = (static function (): \Generator {
            yield 'a';
            yield 'b';
        })();

        self::assertSame(['data' => ['a', 'b']], ApiResponse::collection($items)->data());
    }

    /**
     * Location is how a client learns the identity the server assigned without
     * parsing the body for it.
     */
    public function test_created_carries_a_location(): void
    {
        $response = ApiResponse::created(['id' => 4], '/api/v1/customers/4');

        self::assertSame(201, $response->status());
        self::assertSame('/api/v1/customers/4', $response->header('Location'));
    }

    public function test_created_without_a_url_sends_no_location(): void
    {
        // Not everything created has a URL, and an empty Location header is
        // worse than none.
        self::assertNull(ApiResponse::created(['id' => 4])->header('Location'));
        self::assertNull(ApiResponse::created(['id' => 4], '')->header('Location'));
    }

    /**
     * A 204 that carries "null" and a JSON content type is a 204 some clients
     * will try to parse. The correct body for "nothing to say" is nothing.
     */
    public function test_no_content_has_no_body_and_no_content_type(): void
    {
        $response = ApiResponse::noContent();

        self::assertSame(204, $response->status());
        self::assertSame('', $response->body());
        self::assertNull($response->header('Content-Type'));
        self::assertNotInstanceOf(JsonResponse::class, $response);
    }

    public function test_accepted_is_202(): void
    {
        self::assertSame(202, ApiResponse::accepted(['job' => 'abc'])->status());
    }

    public function test_extra_headers_are_kept(): void
    {
        $response = ApiResponse::item(null, 200, ['X-Total-Count' => '3']);

        self::assertSame('3', $response->header('X-Total-Count'));
    }

    /**
     * The envelope is a convenience, not a rule: the dispatcher still accepts a
     * plain array, so an application that wants a different shape writes one.
     */
    public function test_it_produces_an_ordinary_json_response(): void
    {
        self::assertInstanceOf(JsonResponse::class, ApiResponse::item(null));
    }
}
