<?php

declare(strict_types=1);

namespace App\Engine\System\Audit;

/**
 * One audited system operation.
 *
 * What is in here is chosen, field by field, by the code that records it, and
 * never includes a value that could be a secret: an argument's value, an
 * environment variable's value, a file's contents, stdout or stderr. Counts,
 * names, paths, exit codes and durations are what an auditor needs to say what
 * happened; the values are what an attacker would need, and a log is the place
 * they would look.
 *
 * The time, the request id and the correlation id are not fields: the log adds
 * them to every record, which is also what ties an authorization record to the
 * operation that followed it.
 */
final class AuditRecord
{
    /**
     * @param string                                     $event   "system.command.completed", "system.service.changed", ...
     * @param ?string                                    $target  the unit, path, job or program acted on
     * @param array<string, string|int|float|bool|null> $context
     */
    public function __construct(
        public readonly string $event,
        public readonly AuditOutcome $outcome,
        public readonly ?string $target = null,
        public readonly array $context = [],
    ) {}

    /** @return array<string, string|int|float|bool|null> for a log record */
    public function toArray(): array
    {
        return ['event' => $this->event, 'outcome' => $this->outcome->value, 'target' => $this->target, ...$this->context];
    }
}
