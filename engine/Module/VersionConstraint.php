<?php

declare(strict_types=1);

namespace App\Engine\Module;

/**
 * Which versions of a module another module can work with.
 *
 * **A subset of Composer's syntax, and it says so.** Anyone who writes
 * `^1.2` expects Composer's meaning, so every form accepted here means exactly
 * what it means there. What is not accepted is refused rather than guessed at:
 *
 * | form            | meaning                         |
 * |-----------------|---------------------------------|
 * | `*`             | any version                     |
 * | `1.2.3`         | exactly that                    |
 * | `^1.2`          | `>=1.2.0 <2.0.0`                |
 * | `^0.3`          | `>=0.3.0 <0.4.0` (0.x is unstable) |
 * | `~1.2`          | `>=1.2.0 <2.0.0`                |
 * | `~1.2.3`        | `>=1.2.3 <1.3.0`                |
 * | `>=1.2 <2.0`    | both must hold (space or comma) |
 * | `^1.0 \|\| ^2.0` | either                          |
 *
 * Not supported: `1.2.*` wildcards (`~1.2.0` says the same thing), stability
 * flags, `@dev`, and **a bare partial version**. Composer reads `1.2` as exactly
 * `1.2.0`, and a person who writes `1.2` almost always means "1.2-ish" -- a
 * disagreement that stays invisible until the day `1.2.1` is installed and the
 * application refuses to boot. Here it is refused where it is typed, with the
 * two spellings that say what was probably meant.
 *
 * Why a module system needs this at all when Composer exists: modules inside
 * `modules/` are not Composer packages. They are directories in one repository,
 * versioned by the `version()` their module.php declares, and nothing else is
 * going to check that `Payment` still fits the `Billing` next to
 * it.
 */
final class VersionConstraint
{
    public const ANY = '*';

    private const COMPARISON = '/^(>=|<=|!=|>|<|=)?\s*([0-9]+(?:\.[0-9]+){0,2})$/';

    private const RANGE = '/^([\^~])\s*([0-9]+(?:\.[0-9]+){0,2})$/';

    /**
     * Alternatives, each a list of comparisons that must all hold.
     *
     * @param list<list<array{string, Version}>> $alternatives
     */
    private function __construct(
        private readonly string $text,
        private readonly array $alternatives,
    ) {}

    /**
     * @param string $module who wrote it, for the error message
     *
     * @throws ModuleException when any part cannot be read
     */
    public static function parse(string $constraint, string $module = ''): self
    {
        $text = \trim($constraint);

        if ($text === '' || $text === self::ANY) {
            return new self(self::ANY, [[]]);
        }

        $alternatives = [];

        foreach (\explode('||', $text) as $alternative) {
            $terms = \preg_split('/[\s,]+/', \trim($alternative), -1, \PREG_SPLIT_NO_EMPTY);

            if ($terms === false || $terms === []) {
                throw ModuleException::invalidConstraint($constraint, 'an empty alternative around "||"', $module);
            }

            $comparisons = [];

            foreach (self::joinOperators($terms) as $term) {
                foreach (self::term($term, $constraint, $module) as $comparison) {
                    $comparisons[] = $comparison;
                }
            }

            $alternatives[] = $comparisons;
        }

        return new self($text, $alternatives);
    }

    public function allows(Version $version): bool
    {
        foreach ($this->alternatives as $comparisons) {
            if ($this->holds($comparisons, $version)) {
                return true;
            }
        }

        return false;
    }

    public function isAny(): bool
    {
        return $this->text === self::ANY;
    }

    public function __toString(): string
    {
        return $this->text;
    }

    /** @param list<array{string, Version}> $comparisons */
    private function holds(array $comparisons, Version $version): bool
    {
        foreach ($comparisons as [$operator, $bound]) {
            $order = $version->compare($bound);

            $ok = match ($operator) {
                '>=' => $order >= 0,
                '>' => $order > 0,
                '<=' => $order <= 0,
                '<' => $order < 0,
                '!=' => $order !== 0,
                default => $order === 0,
            };

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * `>= 1.2` arrives from preg_split as two tokens; put them back together.
     *
     * @param list<string> $terms
     *
     * @return list<string>
     */
    private static function joinOperators(array $terms): array
    {
        $joined = [];
        $pending = '';

        foreach ($terms as $term) {
            if (\in_array($term, ['>=', '<=', '!=', '>', '<', '=', '^', '~'], true)) {
                $pending .= $term;

                continue;
            }

            $joined[] = $pending . $term;
            $pending = '';
        }

        if ($pending !== '') {
            $joined[] = $pending;
        }

        return $joined;
    }

    /**
     * One term, as the comparisons it stands for.
     *
     * @return list<array{string, Version}>
     */
    private static function term(string $term, string $constraint, string $module): array
    {
        if ($term === self::ANY) {
            return [];
        }

        if (\preg_match(self::RANGE, $term, $range) === 1) {
            $parts = \array_map('intval', \explode('.', $range[2]));
            $lower = Version::of($parts[0], $parts[1] ?? 0, $parts[2] ?? 0);
            $upper = $range[1] === '^' ? self::caretUpper($parts) : self::tildeUpper($parts);

            return [['>=', $lower], ['<', $upper]];
        }

        if (\preg_match(self::COMPARISON, $term, $comparison) === 1) {
            $operator = $comparison[1];
            $parts = \array_map('intval', \explode('.', $comparison[2]));

            // The one ambiguity refused outright. See the class docblock.
            if (($operator === '' || $operator === '=') && \count($parts) < 3) {
                throw ModuleException::invalidConstraint(
                    $constraint,
                    \sprintf('"%s" is a partial version; write %s.0 for exactly that, or ^%s for a range', $term, $comparison[2], $comparison[2]),
                    $module,
                );
            }

            return [[$operator === '' ? '=' : $operator, Version::of($parts[0], $parts[1] ?? 0, $parts[2] ?? 0)]];
        }

        throw ModuleException::invalidConstraint($constraint, \sprintf('"%s" is not a version or a range', $term), $module);
    }

    /**
     * The first version a caret range excludes.
     *
     * Bump the leftmost non-zero component that was written. When every written
     * component is zero, bump the last one written: `^0` is `<1.0.0` and `^0.0`
     * is `<0.1.0`, which is what Composer does and what "0.x is unstable" means.
     *
     * @param list<int> $parts
     */
    private static function caretUpper(array $parts): Version
    {
        $position = \count($parts) - 1;

        foreach ($parts as $index => $part) {
            if ($part !== 0) {
                $position = $index;

                break;
            }
        }

        return self::bump($parts, $position);
    }

    /**
     * The first version a tilde range excludes: the second-to-last written
     * component goes up, or the major version when only one was written.
     *
     * @param list<int> $parts
     */
    private static function tildeUpper(array $parts): Version
    {
        return self::bump($parts, \max(0, \count($parts) - 2));
    }

    /** @param list<int> $parts */
    private static function bump(array $parts, int $position): Version
    {
        $padded = [$parts[0], $parts[1] ?? 0, $parts[2] ?? 0];
        $padded[$position]++;

        for ($i = $position + 1; $i < 3; ++$i) {
            $padded[$i] = 0;
        }

        return Version::of($padded[0], $padded[1], $padded[2]);
    }
}
