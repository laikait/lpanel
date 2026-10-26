<?php

declare(strict_types=1);

namespace App\Engine\MCP;

/**
 * The check that keeps objects out of anything sent to a client.
 *
 * Scalars, null and arrays of them, however deep. An object -- a model, a
 * repository, a DateTime -- is refused rather than serialised, because what it
 * serialises to is whatever its properties happen to be, credentials included.
 * A tool that means to send a value writes it out as data.
 */
final class PlainData
{
    /**
     * @param array<mixed> $value
     *
     * @throws McpContractException naming the path of the first object found
     */
    public static function assert(array $value, string $path): void
    {
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                self::assert($item, $path . '.' . $key);
            } elseif ($item !== null && !\is_scalar($item)) {
                throw McpContractException::notPlainData($path . '.' . $key, \get_debug_type($item));
            }
        }
    }
}
