<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Engine\Session\SessionId;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A session id is a bearer credential, so this file is about two properties:
 * that one cannot be guessed, and that one this framework did not issue is
 * never mistaken for one it did.
 */
final class SessionIdTest extends TestCase
{
    public function test_an_id_is_64_hex_characters(): void
    {
        $id = SessionId::generate();

        self::assertSame(64, \strlen($id));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $id);
    }

    /**
     * Not a proof of randomness -- no test is -- but it does catch the failure
     * that matters: a generator that has quietly become a counter, a
     * timestamp, or a constant.
     */
    public function test_ids_do_not_repeat(): void
    {
        $ids = [];

        for ($i = 0; $i < 1000; ++$i) {
            $ids[SessionId::generate()] = true;
        }

        self::assertCount(1000, $ids);
    }

    /**
     * 256 bits, and the check is on the bytes rather than the string so that
     * shortening it to save cookie space has to be a deliberate act.
     */
    public function test_an_id_carries_256_bits(): void
    {
        self::assertSame(32, SessionId::BYTES);
        self::assertSame(64, SessionId::LENGTH);
    }

    /** @return array<string, array{string, bool}> */
    public static function candidates(): array
    {
        return [
            'a real one' => [\str_repeat('ab', 32), true],
            'empty' => ['', false],
            'too short' => [\str_repeat('a', 63), false],
            'too long' => [\str_repeat('a', 65), false],
            'not hex' => [\str_repeat('g', 64), false],
            'uppercase' => [\str_repeat('AB', 32), false],
            'traversal' => ['../../../etc/passwd', false],
            'a null byte' => [\str_repeat('a', 63) . "\0", false],
            'sql' => ["' OR 1=1 --" . \str_repeat('a', 53), false],
        ];
    }

    #[DataProvider('candidates')]
    public function test_only_an_id_this_framework_issued_is_valid(string $candidate, bool $valid): void
    {
        self::assertSame($valid, SessionId::isValid($candidate));
    }

    /**
     * Uppercase is refused rather than normalised, and that is deliberate.
     *
     * Normalising means two different cookie values name one session, which is
     * one more way for two things that should be the same to differ -- and the
     * only clients sending uppercase are ones this framework did not hand an id
     * to.
     */
    public function test_a_generated_id_is_always_valid(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            self::assertTrue(SessionId::isValid(SessionId::generate()));
        }
    }

    public function test_ids_are_compared_without_leaking_where_they_differ(): void
    {
        $id = SessionId::generate();

        self::assertTrue(SessionId::matches($id, $id));
        self::assertFalse(SessionId::matches($id, SessionId::generate()));
        self::assertFalse(SessionId::matches($id, \substr($id, 0, 63)));
    }
}
