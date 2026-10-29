<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Engine\Auth\AuthException;
use App\Engine\Auth\Capability;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The matching rule, which is the whole of the grant side of the model.
 *
 * Most of this file is about the prefix rule, because that is where a wildcard
 * model goes wrong quietly: `invoice.*` covering `invoices.void` is the kind of
 * bug that grants somebody a capability nobody meant them to have, and nothing
 * about it looks wrong in a role screen.
 */
final class CapabilityTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function grants(): array
    {
        return [
            'exact' => ['invoice.void', 'invoice.void', true],
            'different' => ['invoice.void', 'invoice.issue', false],
            'everything' => ['invoice.void', '*', true],
            'prefix' => ['invoice.void', 'invoice.*', true],
            'deeper under a prefix' => ['report.finance.read', 'report.*', true],
            'one level under a prefix' => ['report.finance.read', 'report.finance.*', true],
            'a different prefix' => ['invoice.void', 'customer.*', false],
            // The one that matters. Without the separator in the comparison,
            // "invoice.*" would cover a capability belonging to a different
            // noun that happens to start with the same letters.
            'a longer noun' => ['invoices.void', 'invoice.*', false],
            'the prefix itself' => ['invoice', 'invoice.*', false],
            'a parent is not a child' => ['invoice.void.force', 'invoice.void', false],
        ];
    }

    #[DataProvider('grants')]
    public function test_a_grant_covers_what_it_should(string $required, string $grant, bool $covered): void
    {
        self::assertSame($covered, Capability::of($required)->coveredBy($grant));
    }

    public function test_any_one_of_several_grants_is_enough(): void
    {
        $capability = Capability::of('invoice.void');

        self::assertTrue($capability->coveredByAny(['customer.*', 'invoice.void']));
        self::assertFalse($capability->coveredByAny(['customer.*', 'report.*']));
        self::assertFalse($capability->coveredByAny([]));
    }

    // ---- what may be asked, and what may be granted ------------------------

    /** @return array<string, array{string, bool, bool}> */
    public static function names(): array
    {
        return [
            //                             askable, grantable
            'a plain name' => ['invoice', true, true],
            'dotted' => ['invoice.void', true, true],
            'three deep' => ['report.finance.read', true, true],
            'with a dash' => ['invoice.void-force', true, true],
            'with an underscore' => ['invoice.void_force', true, true],
            'a prefix wildcard' => ['invoice.*', false, true],
            'everything' => ['*', false, true],
            'empty' => ['', false, false],
            'uppercase' => ['Invoice.Void', false, false],
            'a bare wildcard segment' => ['invoice.*.void', false, false],
            'leading dot' => ['.invoice', false, false],
            'trailing dot' => ['invoice.', false, false],
            'double dot' => ['invoice..void', false, false],
            'starting with a digit' => ['1invoice', false, false],
            'a space' => ['invoice void', false, false],
        ];
    }

    #[DataProvider('names')]
    public function test_a_name_is_askable_grantable_or_neither(string $name, bool $askable, bool $grantable): void
    {
        self::assertSame($askable, Capability::isAskable($name), 'askable');
        self::assertSame($grantable, Capability::isGrantable($name), 'grantable');
    }

    /**
     * A wildcard is for granting, and asking with one is a mistake worth
     * refusing rather than answering.
     *
     * "May this user do anything at all under invoice" has no answer an
     * application can act on: the button either voids an invoice or it does
     * not.
     */
    public function test_a_wildcard_cannot_be_checked_for(): void
    {
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Wildcards belong in a role');

        Capability::of('invoice.*');
    }

    public function test_a_capability_prints_as_its_name(): void
    {
        self::assertSame('invoice.void', (string) Capability::of('invoice.void'));
        self::assertSame('invoice.void', Capability::of('invoice.void')->name);
    }
}
