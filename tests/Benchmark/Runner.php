<?php

declare(strict_types=1);

namespace App\Tests\Benchmark;

/**
 * Times benchmarks, and compares a run against a saved one.
 *
 * **How a number is produced.** The timed closure is run until a round takes
 * long enough for the clock to be meaningful (the calibration), then that many
 * calls are timed several times over. The figure reported is the median round,
 * divided by the calls in it; the spread beside it is how far apart the fastest
 * and slowest rounds were. A benchmark with a spread of 40% is telling you the
 * machine was busy, not that the code is slow.
 *
 * **What "regression" can mean here, honestly.** A timing is a fact about the
 * machine that produced it. Comparing this laptop against a CI runner is noise,
 * and so is comparing a run with opcache against one without -- which is why a
 * saved run records both, and a comparison refuses to pretend across them. The
 * useful comparison is the same machine before and after a change, which is
 * what --save and --compare are for. What must never regress regardless of
 * machine -- an asset request running no module code, a cached boot scanning no
 * directory -- is asserted exactly, in the test suite, where it fails the gate.
 */
final class Runner
{
    /** How long a calibrated round should take, in nanoseconds. */
    private const ROUND_NS = 20_000_000;

    public function __construct(
        private readonly int $rounds = 7,
    ) {}

    /**
     * @param list<Benchmark> $benchmarks
     *
     * @return array<string, array{subject: string, name: string, ns: float, spread: float, calls: int}>
     */
    public function run(array $benchmarks, ?\Closure $progress = null): array
    {
        $results = [];

        foreach ($benchmarks as $benchmark) {
            $subject = ($benchmark->prepare)();

            $calls = $this->calibrate($subject);
            $timings = [];

            for ($round = 0; $round < $this->rounds; ++$round) {
                $start = \hrtime(true);

                for ($call = 0; $call < $calls; ++$call) {
                    $subject();
                }

                $timings[] = (\hrtime(true) - $start) / $calls;
            }

            \sort($timings);
            $median = $timings[\intdiv(\count($timings), 2)];

            $results[$benchmark->key()] = [
                'subject' => $benchmark->subject,
                'name' => $benchmark->name,
                'ns' => $median,
                'spread' => $median > 0 ? ($timings[\count($timings) - 1] - $timings[0]) / $median : 0.0,
                'calls' => $calls,
            ];

            if ($progress !== null) {
                $progress($results[$benchmark->key()]);
            }
        }

        return $results;
    }

    /** How many calls make one round long enough to time. Includes a warm-up. */
    private function calibrate(\Closure $subject): int
    {
        $calls = 1;

        while (true) {
            $start = \hrtime(true);

            for ($call = 0; $call < $calls; ++$call) {
                $subject();
            }

            $elapsed = \hrtime(true) - $start;

            if ($elapsed >= self::ROUND_NS || $calls >= 1_000_000) {
                return $calls;
            }

            // Aim straight for the target, but never grow more than tenfold on
            // one noisy measurement.
            $calls = (int) \min($calls * 10, \max($calls + 1, \ceil($calls * self::ROUND_NS / \max(1, $elapsed))));
        }
    }

    /** @return array{php: string, opcache: bool, zts: bool, os: string} */
    public static function environment(): array
    {
        $status = \function_exists('opcache_get_status') ? @\opcache_get_status(false) : false;

        return [
            'php' => \PHP_VERSION,
            'opcache' => \is_array($status) && ($status['opcache_enabled'] ?? false) === true,
            'zts' => \PHP_ZTS === 1,
            'os' => \PHP_OS_FAMILY,
        ];
    }

    public static function format(float $nanoseconds): string
    {
        return match (true) {
            $nanoseconds >= 1e6 => \sprintf('%.2f ms', $nanoseconds / 1e6),
            $nanoseconds >= 1e3 => \sprintf('%.1f us', $nanoseconds / 1e3),
            default => \sprintf('%.0f ns', $nanoseconds),
        };
    }
}
