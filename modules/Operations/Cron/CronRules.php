<?php

declare(strict_types=1);

namespace App\Modules\Operations\Cron;

/**
 * What a scheduled job may be, as the agent checks it (internal/cron): a
 * schedule of five fields of numbers, ranges, steps and lists (or @hourly,
 * @daily, @weekly, @monthly, @yearly) that never runs more often than every
 * five minutes, and a command that is one line of text. The panel checks the
 * same so a form can say what is wrong; the agent checks again.
 */
final class CronRules
{
    public const MAX_JOBS = 20;

    public const MAX_COMMAND = 1000;

    public const MIN_MINUTES = 5;

    private const SHORTCUTS = ['@hourly', '@daily', '@weekly', '@monthly', '@yearly', '@annually', '@midnight'];

    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    private const FIELD = '/^(\*|[0-9]+(-[0-9]+)?)(\/[0-9]+)?(,(\*|[0-9]+(-[0-9]+)?)(\/[0-9]+)?)*$/';

    /**
     * @return array<string, string> field => language key, empty when the job is fine
     */
    public static function check(string $schedule, string $command): array
    {
        $errors = [];
        $error = self::schedule($schedule);

        if ($error !== null) {
            $errors['schedule'] = $error;
        }

        if ($command === '' || \strlen($command) > self::MAX_COMMAND || \trim($command) !== $command || \preg_match('/[\x00-\x1f\x7f]/', $command) === 1) {
            $errors['command'] = 'Operations.cron_error_command';
        }

        return $errors;
    }

    /** The schedule as it is kept: fields separated by single spaces. */
    public static function normalize(string $schedule): string
    {
        return \implode(' ', \preg_split('/\s+/', \trim($schedule)) ?: []);
    }

    private static function schedule(string $schedule): ?string
    {
        $schedule = \trim($schedule);

        if (\in_array($schedule, self::SHORTCUTS, true)) {
            return null;
        }

        $fields = \preg_split('/\s+/', $schedule) ?: [];

        if (\count($fields) !== 5) {
            return 'Operations.cron_error_schedule';
        }

        $sets = [];

        foreach ($fields as $i => $field) {
            if (\preg_match(self::FIELD, $field) !== 1) {
                return 'Operations.cron_error_schedule';
            }

            $set = self::expand($field, self::RANGES[$i][0], self::RANGES[$i][1]);

            if ($set === null) {
                return 'Operations.cron_error_schedule';
            }

            $sets[$i] = $set;
        }

        $perHour = \max(1, \intdiv(60, self::MIN_MINUTES));

        if (\count($sets[0]) > $perHour) {
            return 'Operations.cron_error_toofast';
        }

        $minutes = \array_keys($sets[0]);
        \sort($minutes);

        if (\count($minutes) > 1 && \count($sets[1]) === 24) {
            foreach ($minutes as $i => $minute) {
                $next = $minutes[($i + 1) % \count($minutes)];

                if ((($next - $minute + 60) % 60) < self::MIN_MINUTES) {
                    return 'Operations.cron_error_toofast';
                }
            }
        }

        return null;
    }

    /** @return array<int, true>|null the numbers a field names, or null when it is wrong */
    private static function expand(string $field, int $lo, int $hi): ?array
    {
        $out = [];

        foreach (\explode(',', $field) as $part) {
            $step = 1;

            if (\str_contains($part, '/')) {
                [$part, $stepText] = \explode('/', $part, 2);

                if (!\ctype_digit($stepText) || (int) $stepText < 1 || (int) $stepText > $hi) {
                    return null;
                }

                $step = (int) $stepText;
            }

            $a = $lo;
            $b = $hi;

            if ($part !== '*') {
                $bounds = \explode('-', $part, 2);

                if (!\ctype_digit($bounds[0]) || (isset($bounds[1]) && !\ctype_digit($bounds[1]))) {
                    return null;
                }

                $a = (int) $bounds[0];
                $b = (int) ($bounds[1] ?? $bounds[0]);
            }

            if ($a < $lo || $b > $hi || $a > $b) {
                return null;
            }

            for ($v = $a; $v <= $b; $v += $step) {
                $out[$v] = true;
            }
        }

        return $out;
    }
}
