<?php

declare(strict_types=1);

namespace App\Engine\Http;

/**
 * The envelope, so that every endpoint does not reinvent it.
 *
 *     return ApiResponse::item($customer);                 // {"data": …}
 *     return ApiResponse::collection($rows, $page->meta()); // {"data": […], "meta": …}
 *     return ApiResponse::created($customer, $url);        // 201 + Location
 *     return ApiResponse::noContent();                     // 204, no body at all
 *
 * Six static factories over JsonResponse. There is no ApiController to extend,
 * no Resource class to write and no Transformer: a read model is already the
 * shape of an answer, and a second object that turns one shape into another is
 * the ceremony this framework exists to avoid.
 *
 * **Nothing imposes this.** The dispatcher still accepts a plain array, a
 * JsonSerializable or a Response, so an application that wants a different
 * envelope -- or none -- writes one and the framework never notices. What this
 * removes is the fifteenth hand-written ['data' => …] wrapper, not the choice.
 *
 * **It does not know what a Page is.** page() would be the obvious convenience
 * and would put App\Engine\Data inside the HTTP layer, which is the coupling
 * this framework keeps spending effort to avoid. $page->meta() at the call site
 * costs eleven characters and keeps transport ignorant of storage.
 */
final class ApiResponse
{
    /**
     * A single resource.
     *
     * @param array<string, string> $headers
     */
    public static function item(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status, $headers);
    }

    /**
     * A list, with whatever the caller knows about it.
     *
     * meta is where pagination goes, and Page::meta() is shaped to drop
     * straight in. Links belong here too -- but they are built by the caller,
     * because building a URL needs the router and the route's own name, and
     * neither is something the HTTP layer is allowed to know.
     *
     * @param iterable<mixed>       $items
     * @param array<string, mixed>  $meta
     * @param array<string, string> $headers
     */
    public static function collection(iterable $items, array $meta = [], int $status = 200, array $headers = []): JsonResponse
    {
        $data = \is_array($items) ? \array_values($items) : \iterator_to_array($items, false);

        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new JsonResponse($payload, $status, $headers);
    }

    /**
     * 201, and the Location of what was created.
     *
     * Location is optional only because not everything created has a URL. When
     * it does, sending it is not a nicety: it is how a client learns the
     * identity the server assigned without parsing the body for it.
     *
     * @param array<string, string> $headers
     */
    public static function created(mixed $data, ?string $location = null, array $headers = []): JsonResponse
    {
        if ($location !== null && $location !== '') {
            $headers['Location'] = $location;
        }

        return self::item($data, 201, $headers);
    }

    /**
     * 204, with no body and no Content-Type.
     *
     * Not a JsonResponse: a 204 that carries "null" and a JSON content type is
     * a 204 that some clients will try to parse. The correct body for "nothing
     * to say" is nothing.
     *
     * @param array<string, string> $headers
     */
    public static function noContent(array $headers = []): Response
    {
        return new Response('', 204, $headers);
    }

    /**
     * 202, for work that has been taken but not done.
     *
     * The honest status for anything queued. Returning 200 with a made-up
     * result is how a client ends up believing something finished.
     *
     * @param array<string, string> $headers
     */
    public static function accepted(mixed $data = null, array $headers = []): JsonResponse
    {
        return self::item($data, 202, $headers);
    }
}
