<?php

declare(strict_types=1);

namespace App\Engine\Error;

use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Response;

/**
 * One shape for every error an application returns.
 *
 * Before this existed, a 400 from a handler and a 500 from the kernel came out
 * of two different pieces of code that had agreed on a shape by coincidence. A
 * client parsing errors has to handle whatever arrives, so "by coincidence" is
 * the same as "not at all" the first time somebody edits one of them.
 *
 *     {
 *       "error": {
 *         "status": 422,
 *         "title": "Unprocessable Content",
 *         "message": "The customer could not be created.",
 *         "fields": { "email": ["must be an email address"] }
 *       }
 *     }
 *
 * status, title and message are always present and never change meaning.
 * Anything else is an addition under its own key, so a client that ignores the
 * keys it does not know keeps working.
 *
 * **Not RFC 9457 problem+json**, deliberately. That format nests the useful
 * part at the top level next to "type" and "instance" URIs that most APIs never
 * populate meaningfully, and it needs a content type browsers and tools handle
 * worse. This is the shape the demo already used, made official.
 *
 * Immutable: every with*() returns a copy, so an error can be built up and
 * passed around without a later addition changing an earlier holder's view.
 */
final class ErrorDocument implements \JsonSerializable
{
    /** @param array<string, mixed> $details */
    private function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly string $message,
        private readonly array $details = [],
    ) {}

    public static function of(int $status, string $message = ''): self
    {
        $title = Response::statusText($status);

        return new self($status, $title, $message === '' ? $title : $message);
    }

    /**
     * From something thrown.
     *
     * The message rule is the important part, and it has two halves.
     *
     * An HttpException's message is written by this framework *for the client*
     * -- "No route matches /nope" is the answer to the request, not a detail
     * about the application -- so it survives everywhere. Anything else may
     * carry a DSN, a file path or a credential, so it is replaced wholesale
     * with the status text rather than filtered: filtering means guessing which
     * substrings are secret, and that guess is wrong eventually.
     *
     * The second half is the audience. An operator at a terminal already has
     * the source and the configuration, so a framework-authored message is
     * shown to them even in production -- see ErrorContext. A browser and an
     * API client never see one.
     */
    public static function fromThrowable(
        \Throwable $e,
        bool $debug = false,
        ErrorContext $context = ErrorContext::Browser,
    ): self {
        $status = $e instanceof HttpException ? $e->status() : 500;

        $safe = $debug
            || $e instanceof HttpException
            || ($context->disclosesFrameworkMessages()
                && $e instanceof FrameworkException
                && $e->disclosesMessage());

        $document = self::of($status, $safe ? $e->getMessage() : Response::statusText($status));

        if (!$debug) {
            return $document;
        }

        return $document
            ->with('exception', $e::class)
            ->with('file', $e->getFile())
            ->with('line', $e->getLine())
            ->with('trace', \explode("\n", $e->getTraceAsString()));
    }

    /**
     * A validation failure, with everything wrong at once.
     *
     * Takes plain arrays rather than a ValidationResult on purpose: the error
     * layer has no business knowing what a schema is, and a caller writes
     * $result->messages() either way. That one call is what keeps Error and
     * Schema independent of each other.
     *
     * @param array<string, list<string>> $fields   field path to what is wrong with it
     * @param array<string, mixed>|null   $expected the contract, when it helps more than it leaks
     */
    public static function validation(array $fields, string $message = '', ?array $expected = null, int $status = 400): self
    {
        $document = self::of($status, $message === '' ? 'The request could not be processed.' : $message)
            ->with('fields', $fields);

        return $expected === null ? $document : $document->with('expected', $expected);
    }

    /**
     * Add a key alongside status/title/message.
     *
     * status, title and message cannot be overwritten this way. They are the
     * part a client is entitled to rely on, and a detail key that could shadow
     * one of them would make that reliance conditional on what the handler
     * happened to call things.
     */
    public function with(string $key, mixed $value): self
    {
        if (\in_array($key, ['status', 'title', 'message'], true)) {
            throw new \InvalidArgumentException(\sprintf(
                'The "%s" key of an error document is fixed. Add detail under a different name.',
                $key,
            ));
        }

        return new self($this->status, $this->title, $this->message, [...$this->details, $key => $value]);
    }

    /** @param array<string, list<string>> $fields */
    public function withFields(array $fields): self
    {
        return $this->with('fields', $fields);
    }

    public function detail(string $key): mixed
    {
        return $this->details[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'error' => [
                'status' => $this->status,
                'title' => $this->title,
                'message' => $this->message,
                ...$this->details,
            ],
        ];
    }

    /**
     * The shape of toArray() as a JSON Schema, for API descriptions.
     *
     * Here rather than in whatever describes the API, so the one place that
     * builds an error body is also the one place that says what it looks
     * like. Details are open: a validation error adds "fields", a 429 adds
     * "retry_after", and a client must not be told it will never see them.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['error'],
            'properties' => [
                'error' => [
                    'type' => 'object',
                    'required' => ['status', 'title', 'message'],
                    'properties' => [
                        'status' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The response, with the status already on it.
     *
     * A handler returns this rather than throwing when it has more to say than
     * a status line -- which for validation is always. Throwing would hand the
     * error to the error handler, which knows the status and nothing about
     * which fields were wrong.
     *
     * @param array<string, string> $headers
     */
    public function toResponse(array $headers = []): JsonResponse
    {
        return new JsonResponse($this->toArray(), $this->status, $headers);
    }
}
