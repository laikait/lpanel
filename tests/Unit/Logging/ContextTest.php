<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Engine\Logging\Context;
use App\Tests\Support\TestCase;

/**
 * Making arbitrary context safe to write down.
 *
 * A log line is written at the worst possible moment -- something has already
 * gone wrong -- so the rule is that nothing in here may throw, however strange
 * the value it is handed.
 */
final class ContextTest extends TestCase
{
    private Context $context;

    protected function setUp(): void
    {
        $this->context = new Context();
    }

    // ---- normalising ----------------------------------------------------------

    public function test_scalars_survive_unchanged(): void
    {
        self::assertSame(
            ['a' => 1, 'b' => 1.5, 'c' => 'text', 'd' => true, 'e' => null],
            $this->context->normalise(['a' => 1, 'b' => 1.5, 'c' => 'text', 'd' => true, 'e' => null]),
        );
    }

    /**
     * NAN and INF have no JSON representation and would become null silently.
     *
     * The exact strings are asserted, not merely their type. PHP 8.5 warns when
     * NAN is coerced to a string, so this layer names all three itself -- and a
     * test that only checked for "a string" would keep passing if somebody put
     * the cast back and reintroduced the warning.
     */
    public function test_a_non_finite_float_becomes_text(): void
    {
        self::assertSame(
            ['n' => 'NAN', 'i' => 'INF', 'm' => '-INF'],
            $this->context->normalise(['n' => \NAN, 'i' => \INF, 'm' => -\INF]),
        );
    }

    public function test_an_object_becomes_its_class_name(): void
    {
        self::assertSame(
            ['o' => '[' . self::class . ']'],
            $this->context->normalise(['o' => $this]),
        );
    }

    public function test_something_stringable_is_asked_what_it_says(): void
    {
        $stringable = new class {
            public function __toString(): string
            {
                return 'order 41';
            }
        };

        self::assertSame(['o' => 'order 41'], $this->context->normalise(['o' => $stringable]));
    }

    public function test_a_date_becomes_a_date(): void
    {
        $when = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));

        self::assertSame(['at' => '2026-01-01T12:00:00+00:00'], $this->context->normalise(['at' => $when]));
    }

    public function test_a_backed_enum_becomes_its_value(): void
    {
        self::assertSame(
            ['level' => 3],
            $this->context->normalise(['level' => \App\Engine\Logging\Level::Error]),
        );
    }

    public function test_a_throwable_becomes_its_class_message_and_position(): void
    {
        $normalised = $this->context->normalise(['exception' => new \RuntimeException('boom')]);

        self::assertIsArray($normalised['exception']);
        self::assertSame(\RuntimeException::class, $normalised['exception']['class']);
        self::assertSame('boom', $normalised['exception']['message']);
        self::assertStringContainsString(__FILE__, $normalised['exception']['at']);
    }

    public function test_a_previous_exception_is_followed(): void
    {
        $normalised = $this->context->normalise([
            'e' => new \RuntimeException('outer', 0, new \LogicException('the real cause')),
        ]);

        self::assertIsArray($normalised['e']);
        self::assertIsArray($normalised['e']['previous']);
        self::assertSame('the real cause', $normalised['e']['previous']['message']);
    }

    public function test_a_resource_does_not_break_anything(): void
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $normalised = $this->context->normalise(['s' => $stream]);
        \fclose($stream);

        self::assertIsString($normalised['s']);
    }

    public function test_a_closure_does_not_break_anything(): void
    {
        self::assertSame(['f' => '[Closure]'], $this->context->normalise(['f' => static fn(): int => 1]));
    }

    // ---- bounds -----------------------------------------------------------------

    public function test_a_long_string_is_truncated(): void
    {
        $normalised = $this->context->normalise(['s' => \str_repeat('x', Context::MAX_STRING + 100)]);

        self::assertIsString($normalised['s']);
        self::assertSame(Context::MAX_STRING + 3, \strlen($normalised['s']));
    }

    public function test_deep_nesting_stops(): void
    {
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too far']]]]]];

        $normalised = $this->context->normalise($deep);

        self::assertStringContainsString('[array]', \json_encode($normalised) ?: '');
    }

    public function test_a_huge_array_is_cut_off_and_says_so(): void
    {
        $normalised = $this->context->normalise(\range(1, Context::MAX_ITEMS + 25));

        self::assertArrayHasKey('...', $normalised);
        self::assertSame('25 more', $normalised['...']);
    }

    /**
     * Recursion would otherwise be an infinite loop rather than a log line.
     * The depth cap is what stops it, which is worth its own test because the
     * value looks innocent.
     */
    public function test_a_self_referencing_array_terminates(): void
    {
        $recursive = ['name' => 'loop'];
        $recursive['self'] = &$recursive;

        $normalised = $this->context->normalise(['r' => $recursive]);

        self::assertStringContainsString('[array]', \json_encode($normalised) ?: '', 'the depth cap is what stops it');
    }

    // ---- redacting ---------------------------------------------------------------

    public function test_a_sensitive_key_is_replaced(): void
    {
        self::assertSame(
            ['user' => 'ada', 'password' => Context::REDACTED],
            $this->context->normalise(['user' => 'ada', 'password' => 'hunter2']),
        );
    }

    public function test_the_match_is_exact_and_case_insensitive(): void
    {
        $normalised = $this->context->normalise([
            'Authorization' => 'Bearer abc',
            'password_hint' => 'not matched',
        ]);

        self::assertSame(Context::REDACTED, $normalised['Authorization']);
        self::assertSame('not matched', $normalised['password_hint'], 'a key is matched, not a substring');
    }

    public function test_redaction_reaches_into_nested_arrays(): void
    {
        $normalised = $this->context->normalise(['payload' => ['name' => 'Ada', 'token' => 'abc123']]);

        self::assertIsArray($normalised['payload']);
        self::assertSame('Ada', $normalised['payload']['name']);
        self::assertSame(Context::REDACTED, $normalised['payload']['token']);
    }

    public function test_an_application_can_add_its_own_key_names(): void
    {
        $context = new Context(['iban', 'National_Insurance']);

        $normalised = $context->normalise(['iban' => 'GB00', 'national_insurance' => 'AB123', 'name' => 'Ada']);

        self::assertSame(Context::REDACTED, $normalised['iban']);
        self::assertSame(Context::REDACTED, $normalised['national_insurance']);
        self::assertSame('Ada', $normalised['name']);
    }

    public function test_the_defaults_are_still_there_when_more_are_added(): void
    {
        self::assertTrue((new Context(['iban']))->isSensitive('password'));
    }
}
