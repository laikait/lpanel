<?php

declare(strict_types=1);

namespace App\Modules\Operations;

use App\Engine\Http\JsonResponse;

/** An operation refused before it was queued, or a cancel refused, with a stable code for API clients. */
final class OperationException extends \RuntimeException
{
    private const TITLES = [403 => 'Forbidden', 404 => 'Not Found', 409 => 'Conflict', 422 => 'Unprocessable Content'];

    public function __construct(public readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse(['error' => [
            'status' => $this->status,
            'title' => self::TITLES[$this->status] ?? 'Error',
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ]], $this->status);
    }
}
