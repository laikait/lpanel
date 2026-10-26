<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Logging\LogManager;
use App\Engine\Logging\LogWriter;

/**
 * Where records go, and whether they are getting there.
 *
 * The counterpart to asset:list and template:list, and it exists for the same
 * reason those do: a question that is otherwise expensive to answer. Logging
 * fails quietly by design -- a writer that throws is retired rather than
 * allowed to break the request -- and the price of that is an application that
 * can stop logging without saying so. This is where it says so.
 *
 * The --write option sends a real record through the real writers, because "the
 * configuration looks right" and "a line reached the disk" are different
 * claims, and only the second one is worth having at three in the morning.
 */
final class LogStatusCommand
{
    public function __construct(private readonly LogManager $logs) {}

    public function __invoke(Output $output, bool $write = false, string $channel = LogManager::DEFAULT_CHANNEL): int
    {
        $output->pairs([
            'Level' => $this->logs->minimum()->label() . ' and above',
            'Writers' => $this->logs->writers() === [] ? 'none configured' : (string) \count($this->logs->writers()),
            'Channels used' => $this->logs->channels() === [] ? '-' : \implode(', ', $this->logs->channels()),
        ]);

        if ($this->logs->writers() !== []) {
            $output->line();
            $output->table(
                ['WRITER'],
                \array_map(static fn(LogWriter $writer): array => [$writer->describe()], $this->logs->writers()),
            );
        }

        if ($this->logs->writers() === []) {
            $output->line();
            $output->line('Nothing is configured to receive records, so everything is being dropped.');
            $output->line('Set logging.writers to one or more of: file, stderr, syslog.');
        }

        foreach ($this->logs->failures() as $failure) {
            $output->warning('  ' . $failure);
        }

        if (!$write) {
            return $this->logs->isHealthy() ? 0 : 1;
        }

        $before = $this->logs->written();

        $this->logs->channel($channel)->info('log:status test record', ['written_by' => 'console']);

        $output->line();

        if ($this->logs->written() > $before) {
            $output->success(\sprintf('A test record was written to the "%s" channel.', $channel));
        } else {
            $output->error(\sprintf(
                'The test record went nowhere. It is either below the "%s" threshold or every writer refused it.',
                $this->logs->minimum()->label(),
            ));

            return 1;
        }

        return $this->logs->isHealthy() ? 0 : 1;
    }

}
