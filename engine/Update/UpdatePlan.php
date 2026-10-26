<?php

declare(strict_types=1);

namespace App\Engine\Update;

/**
 * What an update would do, decided before anything is written.
 */
final class UpdatePlan
{
    public const EDITED = 'edited here';

    public const DELETED_HERE = 'deleted here, changed by the release';

    public const IN_THE_WAY = 'a different file is already here';

    public const REMOVED_BUT_EDITED = 'edited here, removed by the release';

    /**
     * @param list<string>          $update      owned files to replace
     * @param list<string>          $add         owned files to create
     * @param list<string>          $delete      owned files the release dropped
     * @param array<string, string> $conflicts   path => why it cannot be applied safely
     * @param list<string>          $seedAdd     new seed files to create
     * @param list<string>          $seedChanged seed files whose shipped copy changed; written as <path>.dist
     * @param ?array<string, mixed> $composer    the merged composer.json, or null when it does not change
     * @param list<string>          $composerNotes
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array $update = [],
        public readonly array $add = [],
        public readonly array $delete = [],
        public readonly array $conflicts = [],
        public readonly array $seedAdd = [],
        public readonly array $seedChanged = [],
        public readonly ?array $composer = null,
        public readonly array $composerNotes = [],
    ) {}

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /** Whether applying it would change no file but framework.json. */
    public function isEmpty(): bool
    {
        return $this->update === [] && $this->add === [] && $this->delete === [] && $this->conflicts === []
            && $this->seedAdd === [] && $this->seedChanged === [] && $this->composer === null;
    }
}
