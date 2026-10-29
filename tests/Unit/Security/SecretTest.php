<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Engine\Security\Secret;
use App\Tests\Support\TestCase;

/**
 * The class whose whole job is what happens when somebody is debugging.
 *
 * Every test here is a way a plain string leaks: echoed into a message,
 * var_dumped into a ticket, json_encoded into a debug endpoint, serialised into
 * a queue file. None of those is a decision anybody made, which is why the
 * defence has to be structural rather than a rule people remember.
 */
final class SecretTest extends TestCase
{
    public function test_the_value_is_available_only_through_reveal(): void
    {
        $secret = new Secret('hunter2');

        self::assertSame('hunter2', $secret->reveal());
    }

    public function test_interpolating_it_into_a_string_shows_nothing(): void
    {
        $secret = new Secret('hunter2');

        self::assertSame('[redacted]', (string) $secret);
        self::assertSame('key: [redacted]', "key: {$secret}");
        self::assertStringNotContainsString('hunter2', \sprintf('%s', $secret));
    }

    public function test_json_encoding_it_shows_nothing(): void
    {
        self::assertSame('{"key":"[redacted]"}', (string) \json_encode(['key' => new Secret('hunter2')]));
    }

    /** The one that catches a var_dump in a controller left in by accident. */
    public function test_dumping_it_shows_nothing(): void
    {
        $dump = \print_r(new Secret('hunter2'), true);

        self::assertStringNotContainsString('hunter2', $dump);
        self::assertStringContainsString('[redacted]', $dump);
    }

    /**
     * A secret inside a queued job or a cached value is a secret written
     * somewhere it was never meant to be -- usually because a closure captured
     * it. This turns that into an error at the line that tried.
     */
    public function test_it_refuses_to_be_serialised(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be serialised');

        \serialize(new Secret('hunter2'));
    }

    public function test_comparison_does_not_leak_how_much_was_right(): void
    {
        $secret = new Secret('hunter2');

        self::assertTrue($secret->equals('hunter2'));
        self::assertFalse($secret->equals('hunter3'));
        self::assertFalse($secret->equals('hunter'));
        self::assertFalse($secret->equals(''));
    }

    public function test_an_unset_secret_knows_it_is_unset(): void
    {
        self::assertTrue((new Secret(''))->isEmpty());
        self::assertFalse((new Secret('x'))->isEmpty());
        self::assertSame(7, (new Secret('hunter2'))->length());
    }
}
