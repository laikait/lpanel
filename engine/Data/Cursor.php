<?php

declare(strict_types=1);

namespace App\Engine\Data;

/**
 * A position in an ordered listing, as a string a client can hand back.
 *
 * It is the boundary row's value in each order column, plus which way to walk
 * from it: forward for "next", backward for "previous". Encoded as URL-safe
 * base64 JSON so it survives a query string, and opaque by intent -- a client
 * that builds its own is holding a string, not an API.
 *
 * The order it was made for travels with it, and decoding refuses a cursor
 * made for a different one. Replaying a cursor against another sort would
 * otherwise quietly seek on the wrong columns. It is not signed: it can only
 * move a read position within criteria the handler applies anyway.
 */
final class Cursor
{
    /** Longer than any honest cursor; a bigger value is refused before it is decoded. */
    private const MAX_LENGTH = 4096;

    /**
     * @param list<string> $signature "field:asc" for each order column
     * @param list<mixed>  $values    the boundary row's value in each
     */
    private function __construct(
        public readonly array $signature,
        public readonly array $values,
        public readonly bool $backward,
    ) {}

    /**
     * The cursor for $row in $orders.
     *
     * @param list<Order>          $orders
     * @param array<string, mixed> $row
     */
    public static function at(array $orders, array $row, bool $backward): self
    {
        $values = [];

        foreach ($orders as $order) {
            if (!\array_key_exists($order->field, $row)) {
                throw DataException::invalidCursor(\sprintf('the row has no "%s" column to continue from', $order->field));
            }

            /** @var mixed $value */
            $value = $row[$order->field];

            if ($value !== null && !\is_scalar($value)) {
                throw DataException::invalidCursor(\sprintf('"%s" holds %s, which a cursor cannot carry', $order->field, \get_debug_type($value)));
            }

            $values[] = $value;
        }

        return new self(self::signature($orders), $values, $backward);
    }

    /**
     * Read a cursor made for $orders.
     *
     * @param list<Order> $orders
     *
     * @throws DataException when it is malformed or was made for another order
     */
    public static function decode(string $encoded, array $orders): self
    {
        if ($encoded === '' || \strlen($encoded) > self::MAX_LENGTH) {
            throw DataException::invalidCursor('it is empty or too long');
        }

        $json = \base64_decode(\strtr($encoded, '-_', '+/'), true);
        $data = \is_string($json) ? \json_decode($json, true, 4) : null;

        if (!\is_array($data)
            || !\is_array($data['o'] ?? null) || !\array_is_list($data['o'])
            || !\is_array($data['v'] ?? null) || !\array_is_list($data['v'])
            || !\is_bool($data['b'] ?? null)) {
            throw DataException::invalidCursor('it is not a cursor this application made');
        }

        if ($data['o'] !== self::signature($orders)) {
            throw DataException::invalidCursor('it was made for a different order');
        }

        foreach ($data['v'] as $value) {
            if ($value !== null && !\is_scalar($value)) {
                throw DataException::invalidCursor('it is not a cursor this application made');
            }
        }

        /** @var list<string> $signature */
        $signature = $data['o'];

        return new self($signature, $data['v'], $data['b']);
    }

    public function encode(): string
    {
        $json = \json_encode(['o' => $this->signature, 'v' => $this->values, 'b' => $this->backward], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        return \rtrim(\strtr(\base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @param list<Order> $orders
     *
     * @return list<string>
     */
    private static function signature(array $orders): array
    {
        return \array_map(static fn(Order $order): string => $order->field . ':' . $order->direction->value, $orders);
    }
}
