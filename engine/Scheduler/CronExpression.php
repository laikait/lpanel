<?php

declare(strict_types=1);

namespace App\Engine\Scheduler;

/**
 * A five-field cron expression, parsed once and then asked questions.
 *
 *     minute  hour  day-of-month  month  day-of-week
 *     0-59    0-23  1-31          1-12   0-6 (0 and 7 are both Sunday)
 *
 * Each field accepts a star, a number, a-b, a,b,c, and a /step on any of those.
 * Months and days accept their usual three-letter names, so "0 3 * * SUN" and
 * "0 3 * * 0" are the same expression. The five macros are @hourly, @daily
 * (@midnight), @weekly, @monthly and @yearly (@annually).
 *
 * There is no @reboot, which every cron has and this one cannot honestly
 * provide: @reboot means "when the daemon starts", and there is no daemon here.
 * A schedule that needs to run at deployment is a deployment step.
 *
 * Cron is the format rather than an invented one because it is the format
 * operations already reads. "At quarter past, on weekdays" is a thing somebody
 * can check against the crontab they already keep, and a scheduler whose own
 * syntax has to be learned first is a scheduler whose schedules get transcribed
 * wrongly.
 *
 * **Day-of-month and day-of-week are ORed when both are restricted.** This is
 * the genuine Vixie cron rule and it surprises people every time: "0 0 13 * FRI"
 * is not "Friday the 13th", it is "the 13th, and also every Friday". Copying
 * the rule is still right -- an expression pasted from a crontab has to mean
 * here what it means there, and a scheduler that quietly meant something
 * stricter would be the worse kind of surprise.
 *
 * Parsing is eager: each field becomes a sorted list of the values it allows,
 * so isDue() is a handful of lookups rather than five range walks, and a
 * malformed expression is a boot-time error rather than a 3am one.
 */
final class CronExpression
{
    public const MACROS = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    public const MONTHS = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    public const DAYS = [
        'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    ];

    /**
     * How far ahead nextRunAfter() is willing to look.
     *
     * Five years, because a leap-year-only expression such as "0 0 29 2 *" has
     * to be found rather than reported as never. Anything beyond that -- "0 0
     * 30 2 *", February the 30th -- genuinely never happens, and saying so is
     * better than searching forever.
     */
    public const HORIZON_DAYS = 1830;

    /** @var list<int> */
    private readonly array $minutes;

    /** @var list<int> */
    private readonly array $hours;

    /** @var list<int> */
    private readonly array $daysOfMonth;

    /** @var list<int> */
    private readonly array $months;

    /** @var list<int> */
    private readonly array $daysOfWeek;

    private readonly bool $dayOfMonthRestricted;

    private readonly bool $dayOfWeekRestricted;

    public function __construct(public readonly string $expression)
    {
        $normalised = self::MACROS[\strtolower(\trim($expression))] ?? \trim($expression);

        $split = \preg_split('/\s+/', $normalised, -1, \PREG_SPLIT_NO_EMPTY);
        $fields = $split === false ? [] : $split;

        if (\count($fields) !== 5) {
            throw SchedulerException::unparsableExpression(
                $expression,
                \sprintf('it has %d field(s) rather than 5', \count($fields)),
            );
        }

        $this->minutes = $this->field($expression, $fields[0], 0, 59, []);
        $this->hours = $this->field($expression, $fields[1], 0, 23, []);
        $this->daysOfMonth = $this->field($expression, $fields[2], 1, 31, []);
        $this->months = $this->field($expression, $fields[3], 1, 12, self::MONTHS);
        $this->daysOfWeek = $this->field($expression, $fields[4], 0, 7, self::DAYS);

        $this->dayOfMonthRestricted = \trim($fields[2]) !== '*';
        $this->dayOfWeekRestricted = \trim($fields[4]) !== '*';
    }

    public static function parse(string $expression): self
    {
        return new self($expression);
    }

    public function __toString(): string
    {
        return $this->expression;
    }

    /** Is this the minute? */
    public function isDue(\DateTimeImmutable $at): bool
    {
        return $this->matchesTime($at) && $this->matchesDate($at);
    }

    /**
     * The first minute after this one that matches, or null within the horizon.
     *
     * Days are walked first and only a matching day has its hours and minutes
     * looked at, so an expression that fires once a year costs about as much to
     * answer as one that fires every minute. The alternative -- stepping a
     * minute at a time -- is half a million iterations for a yearly schedule,
     * which is the sort of thing that makes `schedule:list` feel broken.
     */
    public function nextRunAfter(\DateTimeImmutable $at): ?\DateTimeImmutable
    {
        $cursor = $at->setTime((int) $at->format('G'), (int) $at->format('i'))->modify('+1 minute');
        $hour = (int) $cursor->format('G');
        $minute = (int) $cursor->format('i');

        for ($day = 0; $day <= self::HORIZON_DAYS; ++$day) {
            if ($this->matchesDate($cursor)) {
                foreach ($this->hours as $candidateHour) {
                    if ($candidateHour < $hour) {
                        continue;
                    }

                    foreach ($this->minutes as $candidateMinute) {
                        if ($candidateHour === $hour && $candidateMinute < $minute) {
                            continue;
                        }

                        return $cursor->setTime($candidateHour, $candidateMinute);
                    }
                }
            }

            // Past midnight, every hour and minute is back in play.
            $cursor = $cursor->modify('+1 day')->setTime(0, 0);
            $hour = 0;
            $minute = 0;
        }

        return null;
    }

    /**
     * A human sentence for the common shapes, and the expression for the rest.
     *
     * Deliberately partial. Rendering every cron expression as English is a
     * project of its own, and the translations get subtly wrong around steps
     * that do not divide the hour; the handful of shapes people actually write
     * cover almost every schedule, and anything else is shown as what it is.
     */
    public function describe(): string
    {
        if ($this->expression === '* * * * *') {
            return 'every minute';
        }

        $one = static fn(array $values): bool => \count($values) === 1;
        $all = static fn(array $values, int $size): bool => \count($values) === $size;

        $everyDate = $all($this->daysOfMonth, 31) && $all($this->months, 12);
        $everyDay = $everyDate && !$this->dayOfWeekRestricted;

        if ($one($this->minutes) && $all($this->hours, 24) && $everyDay) {
            return \sprintf('hourly at :%02d', $this->minutes[0]);
        }

        $step = $this->evenStep($this->minutes);

        if ($step !== null && $all($this->hours, 24) && $everyDay) {
            return \sprintf('every %d minutes', $step);
        }

        if ($one($this->minutes) && $one($this->hours) && $everyDay) {
            return \sprintf('daily at %02d:%02d', $this->hours[0], $this->minutes[0]);
        }

        if ($one($this->minutes) && $one($this->hours) && $everyDate && $one($this->daysOfWeek)) {
            $name = \array_search($this->daysOfWeek[0], self::DAYS, true);

            return \sprintf(
                'weekly on %s at %02d:%02d',
                \is_string($name) ? $name : 'day ' . $this->daysOfWeek[0],
                $this->hours[0],
                $this->minutes[0],
            );
        }

        if ($one($this->minutes) && $one($this->hours) && $one($this->daysOfMonth)
            && $all($this->months, 12) && !$this->dayOfWeekRestricted) {
            return \sprintf(
                'monthly on day %d at %02d:%02d',
                $this->daysOfMonth[0],
                $this->hours[0],
                $this->minutes[0],
            );
        }

        return $this->expression;
    }

    /**
     * The step of an evenly spaced set that starts at zero and wraps cleanly.
     *
     * The wrap is the part worth checking. A set spaced seven apart looks even
     * inside one hour and is not: minute 56 is followed by minute 0 of the next
     * hour, four minutes later. Calling that "every 7 minutes" would be a
     * plausible sentence and a false one, so it is left as the expression.
     *
     * @param list<int> $values
     */
    private function evenStep(array $values): ?int
    {
        $count = \count($values);

        if ($count < 2 || $values[0] !== 0 || 60 % $count !== 0) {
            return null;
        }

        $step = \intdiv(60, $count);

        foreach ($values as $index => $value) {
            if ($value !== $index * $step) {
                return null;
            }
        }

        return $step;
    }

    private function matchesTime(\DateTimeImmutable $at): bool
    {
        return \in_array((int) $at->format('i'), $this->minutes, true)
            && \in_array((int) $at->format('G'), $this->hours, true);
    }

    /**
     * Month always, then the two day fields under cron's own OR rule.
     *
     * Restricting neither means every day; restricting one means that one;
     * restricting both means either, which is the rule explained in the class
     * docblock and the one place this parser is deliberately surprising.
     */
    private function matchesDate(\DateTimeImmutable $at): bool
    {
        if (!\in_array((int) $at->format('n'), $this->months, true)) {
            return false;
        }

        $dayOfMonth = \in_array((int) $at->format('j'), $this->daysOfMonth, true);
        $dayOfWeek = \in_array((int) $at->format('w'), $this->daysOfWeek, true);

        if ($this->dayOfMonthRestricted && $this->dayOfWeekRestricted) {
            return $dayOfMonth || $dayOfWeek;
        }

        return $dayOfMonth && $dayOfWeek;
    }

    /**
     * One field, expanded into every value it allows.
     *
     * @param array<string, int> $names three-letter aliases this field accepts
     *
     * @return list<int>
     */
    private function field(string $expression, string $field, int $min, int $max, array $names): array
    {
        $allowed = [];

        foreach (\explode(',', \trim($field)) as $part) {
            foreach ($this->part($expression, $part, $min, $max, $names) as $value) {
                // Sunday is 0 and 7 in every cron there has ever been, and a
                // field holding both would report two Sundays a week.
                $allowed[$value === 7 && $max === 7 ? 0 : $value] = true;
            }
        }

        if ($allowed === []) {
            throw SchedulerException::unparsableExpression($expression, \sprintf('"%s" allows nothing', $field));
        }

        $values = \array_keys($allowed);
        \sort($values);

        return $values;
    }

    /**
     * One comma-separated part: a star, n, a-b, or any of those with /step.
     *
     * @param array<string, int> $names
     *
     * @return list<int>
     */
    private function part(string $expression, string $part, int $min, int $max, array $names): array
    {
        $part = \trim($part);
        $step = 1;

        if (\str_contains($part, '/')) {
            [$part, $stepText] = \explode('/', $part, 2);
            $part = \trim($part);

            if (\preg_match('/^[1-9][0-9]*$/', \trim($stepText)) !== 1) {
                throw SchedulerException::unparsableExpression(
                    $expression,
                    \sprintf('"%s" is not a step', $stepText),
                );
            }

            $step = (int) $stepText;
        }

        if ($part === '*' || $part === '') {
            [$from, $to] = [$min, $max];
        } elseif (\str_contains($part, '-')) {
            $bounds = \explode('-', $part, 2);
            $from = $this->value($expression, $bounds[0], $min, $max, $names);
            $to = $this->value($expression, $bounds[1], $min, $max, $names);

            if ($from > $to) {
                throw SchedulerException::unparsableExpression(
                    $expression,
                    \sprintf('the range "%s" runs backwards', $part),
                );
            }
        } else {
            $from = $this->value($expression, $part, $min, $max, $names);
            // A bare number with a step means "from here on", which is what
            // "5/10" does in cron and what a shifted star-step should mean too.
            $to = $step > 1 ? $max : $from;
        }

        $values = [];

        for ($value = $from; $value <= $to; $value += $step) {
            $values[] = $value;
        }

        return $values;
    }

    /**
     * A single number or a name, checked against the field's own range.
     *
     * @param array<string, int> $names
     */
    private function value(string $expression, string $text, int $min, int $max, array $names): int
    {
        $text = \trim($text);
        $named = $names[\strtoupper($text)] ?? null;

        if ($named === null) {
            if (\preg_match('/^[0-9]+$/', $text) !== 1) {
                throw SchedulerException::unparsableExpression(
                    $expression,
                    \sprintf('"%s" is not a number this field understands', $text),
                );
            }

            $named = (int) $text;
        }

        if ($named < $min || $named > $max) {
            throw SchedulerException::unparsableExpression(
                $expression,
                \sprintf('%d is outside %d-%d', $named, $min, $max),
            );
        }

        return $named;
    }
}
