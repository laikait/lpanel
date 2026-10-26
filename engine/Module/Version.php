<?php

declare(strict_types=1);

namespace App\Engine\Module;

/**
 * A module's version: exactly MAJOR.MINOR.PATCH, and nothing else.
 *
 * **Strict on purpose.** `$module->version()` was decorative until modules could
 * depend on each other; from this phase on it is compared, and a version that
 * cannot be compared is a dependency check that silently passes or silently
 * fails. So `1.2`, `v1.2.0`, `1.2.0-beta` and `01.2.0` are all refused where
 * they are written, naming the module.
 *
 * Pre-release suffixes are the notable omission. Their ordering rules are the
 * part of semantic versioning everybody gets subtly wrong -- is `1.0.0-rc.10`
 * after `1.0.0-rc.9`? -- and a module system inside one repository does not need
 * them: a module under development is `0.x`, which is what the caret rule below
 * already treats as unstable.
 */
final class Version
{
    public const PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/';

    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
    ) {}

    /**
     * @param string $module whose version this is, for the error message
     *
     * @throws ModuleException when it is not MAJOR.MINOR.PATCH
     */
    public static function parse(string $version, string $module = ''): self
    {
        if (\preg_match(self::PATTERN, $version, $parts) !== 1) {
            throw ModuleException::invalidVersion($version, $module);
        }

        return new self((int) $parts[1], (int) $parts[2], (int) $parts[3]);
    }

    public static function isValid(string $version): bool
    {
        return \preg_match(self::PATTERN, $version) === 1;
    }

    public static function of(int $major, int $minor = 0, int $patch = 0): self
    {
        return new self(\max(0, $major), \max(0, $minor), \max(0, $patch));
    }

    /** -1, 0 or 1, the way the spaceship operator answers. */
    public function compare(self $other): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
    }

    public function __toString(): string
    {
        return $this->major . '.' . $this->minor . '.' . $this->patch;
    }
}
