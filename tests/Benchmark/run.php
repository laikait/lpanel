<?php

declare(strict_types=1);

/**
 * composer bench [-- options]
 *
 *   --filter=TEXT      only benchmarks whose subject or name contains TEXT
 *   --rounds=N         timed rounds per benchmark (default 7)
 *   --save=FILE        write the results as JSON, to compare against later
 *   --compare=FILE     compare against a saved run and mark what got slower
 *   --threshold=PCT    how much slower counts as slower (default 25)
 *   --fail             exit 1 when a comparison finds something slower
 *
 * The typical use is two runs on one machine: --save before a change, --compare
 * after it. A comparison across machines, or across opcache on and off, is
 * refused rather than reported, because its numbers would mean nothing.
 */

use App\Tests\Benchmark\Runner;
use App\Tests\Benchmark\Suite;

$basePath = dirname(__DIR__, 2);

require $basePath . '/vendor/autoload.php';

$options = getopt('', ['filter:', 'rounds:', 'save:', 'compare:', 'threshold:', 'fail']);
$option = static fn(string $name): ?string => isset($options[$name]) && is_string($options[$name]) ? $options[$name] : null;

$filter = $option('filter');
$rounds = max(3, (int) ($option('rounds') ?? 7));
$threshold = (float) ($option('threshold') ?? 25) / 100;

$benchmarks = array_values(array_filter(
    Suite::all($basePath),
    static fn($benchmark): bool => $filter === null || stripos($benchmark->key(), $filter) !== false,
));

$environment = Runner::environment();

printf(
    "PHP %s, %s, opcache %s, %s\n",
    $environment['php'],
    $environment['zts'] ? 'ZTS' : 'NTS',
    $environment['opcache'] ? 'on' : 'OFF',
    $environment['os'],
);

if (!$environment['opcache']) {
    echo "Without opcache every require recompiles, so anything that loads a file measures the compiler too.\n"
        . "Production numbers need: php -d opcache.enable_cli=1 (with the opcache extension loaded).\n";
}

echo "\n";

$baseline = null;
$compare = $option('compare');

if ($compare !== null) {
    $decoded = is_file($compare) ? json_decode((string) file_get_contents($compare), true) : null;

    if (!is_array($decoded) || !is_array($decoded['results'] ?? null) || !is_array($decoded['environment'] ?? null)) {
        fwrite(\STDERR, "Nothing usable to compare against in {$compare}.\n");

        exit(1);
    }

    foreach (['php', 'opcache', 'zts', 'os'] as $key) {
        if (($decoded['environment'][$key] ?? null) !== $environment[$key]) {
            fwrite(\STDERR, sprintf(
                "Refusing to compare: the saved run had %s = %s, this one has %s. Timings only compare on one machine and one configuration.\n",
                $key,
                var_export($decoded['environment'][$key] ?? null, true),
                var_export($environment[$key], true),
            ));

            exit(1);
        }
    }

    $baseline = $decoded['results'];
}

$slower = 0;
$subject = null;

$results = (new Runner($rounds))->run($benchmarks, static function (array $result) use (&$subject, &$slower, $baseline, $threshold): void {
    if ($result['subject'] !== $subject) {
        $subject = $result['subject'];
        echo $subject, "\n";
    }

    $line = sprintf('  %-58s %11s  +/-%3.0f%%', $result['name'], Runner::format($result['ns']), $result['spread'] * 100);

    $before = $baseline[$result['subject'] . ' / ' . $result['name']]['ns'] ?? null;

    if (is_float($before) || is_int($before)) {
        $change = $before > 0 ? ($result['ns'] - $before) / $before : 0.0;
        $marker = $change > $threshold ? '  SLOWER' : ($change < -$threshold ? '  faster' : '');

        if ($change > $threshold) {
            ++$slower;
        }

        $line .= sprintf('   %+5.0f%% vs %s%s', $change * 100, Runner::format((float) $before), $marker);
    }

    echo $line, "\n";
});

$save = $option('save');

if ($save !== null) {
    $directory = dirname($save);

    if (!is_dir($directory)) {
        mkdir($directory, 0o775, true);
    }

    file_put_contents($save, json_encode(
        ['environment' => $environment, 'recorded' => date(\DATE_ATOM), 'results' => $results],
        \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
    ) . "\n");

    echo "\nSaved to {$save}\n";
}

if ($baseline !== null) {
    echo $slower === 0
        ? "\nNothing is slower than the saved run by more than " . ($threshold * 100) . "%.\n"
        : "\n{$slower} benchmark(s) slower than the saved run by more than " . ($threshold * 100) . "%.\n";
}

exit($baseline !== null && $slower > 0 && isset($options['fail']) ? 1 : 0);
