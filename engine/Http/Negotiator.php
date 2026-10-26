<?php

declare(strict_types=1);

namespace App\Engine\Http;

/**
 * Content negotiation, done properly rather than approximately.
 *
 * The approximate version is a substring test on the Accept header, and it is
 * wrong in the case that matters most:
 *
 *     Accept: application/json;q=0.9, text/html;q=0.8
 *
 * A client that says this wants JSON. Any check of the form "does it contain
 * text/html" says it wants HTML, and the API quietly serves a web page to a
 * program. Quality values exist precisely so a client can express a preference
 * rather than a demand, and ignoring them turns a preference into a lie.
 *
 * **This is not middleware and it is not automatic.** A route knows what it can
 * produce; the framework does not. Negotiation is a call the handler makes,
 * which is also why there is no configuration for it: the offers are a fact
 * about the endpoint, written next to the endpoint.
 *
 *     $type = Negotiator::require($request, ['text/html', 'application/json']);
 *
 *     return $type === 'application/json'
 *         ? ApiResponse::collection($rows, $meta)
 *         : new Response($this->templates->render('customers', $data));
 */
final class Negotiator
{
    /**
     * The best of what this endpoint can produce, or null if none will do.
     *
     * Offers are listed in the server's order of preference, and that order is
     * what breaks a tie the client did not break. An empty or absent Accept
     * means "anything", which by RFC 9110 is the first offer.
     *
     * @param list<string> $offered most preferred first
     */
    public static function best(?string $accept, array $offered): ?string
    {
        if ($offered === []) {
            return null;
        }

        if ($accept === null || \trim($accept) === '') {
            return $offered[0];
        }

        $ranges = MediaType::parseList($accept);

        if ($ranges === []) {
            return $offered[0];
        }

        $best = null;
        $bestScore = [-1.0, -1, 0];

        foreach ($offered as $index => $candidate) {
            $parsed = MediaType::parse($candidate);

            if ($parsed === null) {
                continue;
            }

            foreach ($ranges as $range) {
                if (!$range->matches($parsed)) {
                    continue;
                }

                // q=0 is an explicit refusal, not a weak preference.
                if ($range->quality <= 0.0) {
                    break;
                }

                $score = [$range->quality, $range->specificity(), -$index];

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $candidate;
                }

                // parseList() is ordered, so the first range that matches this
                // candidate is the best one for it.
                break;
            }
        }

        return $best;
    }

    /**
     * Negotiate or refuse.
     *
     * 406 rather than "serve JSON anyway": a client that asked for something
     * this endpoint cannot produce has a bug, and answering it with a body it
     * cannot parse turns that bug into a mystery. The offers go in the message,
     * because the one thing the client needs to know is what it could have
     * asked for instead.
     *
     * @param list<string> $offered
     *
     * @throws HttpException 406 when nothing offered is acceptable
     */
    public static function require(Request $request, array $offered): string
    {
        return self::best($request->header('Accept'), $offered)
            ?? throw HttpException::notAcceptable($offered);
    }

    /**
     * Whether the client would accept this type at all.
     *
     * Distinct from best(): this asks about one type in isolation, which is the
     * right question when there is nothing to choose between.
     */
    public static function accepts(?string $accept, string $mediaType): bool
    {
        return self::best($accept, [$mediaType]) !== null;
    }

    /**
     * Whether JSON is preferred over HTML.
     *
     * What error rendering needs, and the reason it cannot simply ask "does the
     * client accept JSON" -- a browser accepts JSON too, somewhere down its
     * list, and would then be shown a payload instead of a page.
     *
     * HTML is offered first, so a client with no preference gets the page.
     *
     * The third case is the one worth spelling out: a client that named media
     * types and included neither. It asked for application/xml, or for a vendor
     * JSON type this endpoint does not produce, and it is about to receive an
     * error it did not ask for in a format it did not ask for. It is not a
     * browser -- a browser would have said text/html -- so the machine-readable
     * body is the more useful of the two wrong answers.
     */
    public static function prefersJson(?string $accept): bool
    {
        $best = self::best($accept, ['text/html', 'application/json']);

        if ($best !== null) {
            return $best === 'application/json';
        }

        return $accept !== null && \trim($accept) !== '';
    }

    /**
     * Refuse a request body that is not in a format this endpoint reads.
     *
     * 415, and it matters more than it looks. Without this check a POST whose
     * Content-Type is text/plain reaches the schema as an empty payload, and
     * the client is told its fields are missing -- which sends whoever is
     * debugging it looking at the fields rather than at the header.
     *
     * A request with no body is not checked: there is nothing to misread.
     *
     * @param list<string> $accepted
     *
     * @throws HttpException 415 when the body is in a format this cannot read
     */
    public static function requirePayload(Request $request, array $accepted = ['application/json']): void
    {
        if ($request->body() === '') {
            return;
        }

        $contentType = $request->header('Content-Type');

        if ($contentType === null || \trim($contentType) === '') {
            throw HttpException::unsupportedMediaType(\sprintf(
                'A request body needs a Content-Type. This endpoint reads %s.',
                \implode(', ', $accepted),
            ));
        }

        $parsed = MediaType::parse($contentType);

        foreach ($accepted as $candidate) {
            if ($parsed !== null && $parsed->full() === \strtolower($candidate)) {
                return;
            }
        }

        // A vendor JSON type satisfies an application/json requirement: a
        // client sending application/vnd.example+json is sending JSON, and
        // refusing it would be pedantry rather than safety.
        if ($parsed?->isJson() === true && \in_array('application/json', $accepted, true)) {
            return;
        }

        throw HttpException::unsupportedMediaType(\sprintf(
            'This endpoint reads %s, and the request body is %s.',
            \implode(', ', $accepted),
            $parsed === null ? 'unreadable' : $parsed->full(),
        ));
    }
}
