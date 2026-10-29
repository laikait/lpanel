<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module;

use App\Engine\Module\ModuleException;
use App\Engine\Module\Version;
use App\Engine\Module\VersionConstraint;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Versions and the constraints over them.
 *
 * Every accepted form is pinned to Composer's meaning, because that is what
 * anybody writing `^1.2` expects. The 0.x caret cases matter most: they are
 * where a hand-rolled implementation quietly differs, and where "compatible"
 * means the narrowest thing.
 */
final class VersionConstraintTest extends TestCase
{
    // ---- versions ----------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function versions(): array
    {
        return [
            'plain' => ['1.4.2', true],
            'zeroes' => ['0.0.0', true],
            'large' => ['10.20.300', true],
            'partial' => ['1.2', false],
            'prefixed' => ['v1.2.0', false],
            'pre-release' => ['1.2.0-beta', false],
            'leading zero' => ['01.2.0', false],
            'four parts' => ['1.2.3.4', false],
            'empty' => ['', false],
            'words' => ['latest', false],
        ];
    }

    #[DataProvider('versions')]
    public function test_only_major_minor_patch_is_a_version(string $version, bool $valid): void
    {
        self::assertSame($valid, Version::isValid($version));
    }

    public function test_an_invalid_version_names_the_module_that_declared_it(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('Module "Billing" declares version "v1.0"');

        Version::parse('v1.0', 'Billing');
    }

    public function test_versions_compare_numerically_not_as_text(): void
    {
        // As strings, "1.10.0" sorts before "1.9.0". That is the whole bug.
        self::assertSame(1, Version::parse('1.10.0')->compare(Version::parse('1.9.0')));
        self::assertSame(-1, Version::parse('0.9.9')->compare(Version::parse('1.0.0')));
        self::assertSame(0, Version::parse('2.0.0')->compare(Version::of(2)));
    }

    // ---- constraints -------------------------------------------------------

    /** @return array<string, array{string, string, bool}> */
    public static function constraints(): array
    {
        return [
            'any' => ['*', '7.3.1', true],
            'empty means any' => ['', '0.0.1', true],

            'exact' => ['1.2.3', '1.2.3', true],
            'exact, other' => ['1.2.3', '1.2.4', false],
            'equals' => ['=1.2.3', '1.2.3', true],
            'not equal' => ['!=1.2.3', '1.2.4', true],

            'caret, same major' => ['^1.2', '1.9.0', true],
            'caret, lower bound' => ['^1.2', '1.2.0', true],
            'caret, below' => ['^1.2', '1.1.9', false],
            'caret, next major' => ['^1.2', '2.0.0', false],
            'caret, full' => ['^1.2.3', '1.2.2', false],

            // 0.x: the leftmost non-zero component is the one that may not move.
            'caret 0.x, same minor' => ['^0.3', '0.3.9', true],
            'caret 0.x, next minor' => ['^0.3', '0.4.0', false],
            'caret 0.0.x, same patch' => ['^0.0.3', '0.0.3', true],
            'caret 0.0.x, next patch' => ['^0.0.3', '0.0.4', false],
            'caret ^0 is below 1.0' => ['^0', '0.9.0', true],
            'caret ^0 excludes 1.0' => ['^0', '1.0.0', false],
            'caret ^0.0 is below 0.1' => ['^0.0', '0.0.9', true],
            'caret ^0.0 excludes 0.1' => ['^0.0', '0.1.0', false],

            'tilde two parts, same major' => ['~1.2', '1.9.0', true],
            'tilde two parts, next major' => ['~1.2', '2.0.0', false],
            'tilde three parts, same minor' => ['~1.2.3', '1.2.9', true],
            'tilde three parts, next minor' => ['~1.2.3', '1.3.0', false],
            'tilde one part' => ['~1', '1.5.0', true],

            'range with a space' => ['>=1.2 <2.0', '1.5.0', true],
            'range with a comma' => ['>=1.2,<2.0', '2.0.0', false],
            'range, spaced operators' => ['>= 1.2 < 2.0', '1.2.0', true],
            'greater than' => ['>1.2.0', '1.2.0', false],
            'less or equal' => ['<=1.2.0', '1.2.0', true],

            'either, first' => ['^1.0 || ^3.0', '1.4.0', true],
            'either, second' => ['^1.0 || ^3.0', '3.1.0', true],
            'either, neither' => ['^1.0 || ^3.0', '2.0.0', false],
        ];
    }

    #[DataProvider('constraints')]
    public function test_a_constraint_means_what_composer_means(string $constraint, string $version, bool $allowed): void
    {
        self::assertSame(
            $allowed,
            VersionConstraint::parse($constraint)->allows(Version::parse($version)),
            \sprintf('"%s" against %s', $constraint, $version),
        );
    }

    /**
     * The ambiguity refused rather than resolved.
     *
     * Composer reads `1.2` as exactly 1.2.0, and the person who typed it almost
     * always meant 1.2-ish. The disagreement is invisible until 1.2.1 is
     * installed and the application will not boot, so it is refused where it
     * is written, with both spellings that say what was probably meant.
     */
    public function test_a_bare_partial_version_is_refused_with_the_alternatives(): void
    {
        try {
            VersionConstraint::parse('1.2', 'Payment');
            self::fail('a bare partial version should have been refused');
        } catch (ModuleException $e) {
            self::assertStringContainsString('Payment', $e->getMessage());
            self::assertStringContainsString('1.2.0', $e->getMessage());
            self::assertStringContainsString('^1.2', $e->getMessage());
        }
    }

    /** @return array<string, array{string}> */
    public static function unreadable(): array
    {
        return [
            'words' => ['latest'],
            'wildcard segment' => ['1.2.*'],
            'stability flag' => ['^1.0@dev'],
            'dangling or' => ['^1.0 ||'],
            'v prefix' => ['^v1.0'],
            'four parts' => ['1.2.3.4'],
        ];
    }

    #[DataProvider('unreadable')]
    public function test_what_is_not_understood_is_refused_rather_than_guessed(string $constraint): void
    {
        $this->expectException(ModuleException::class);

        VersionConstraint::parse($constraint);
    }

    public function test_a_constraint_prints_as_it_was_written(): void
    {
        self::assertSame('^1.2 || ^2.0', (string) VersionConstraint::parse('  ^1.2 || ^2.0 '));
        self::assertTrue(VersionConstraint::parse('')->isAny());
        self::assertFalse(VersionConstraint::parse('^1.0')->isAny());
    }
}
