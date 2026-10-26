<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Command;
use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\ConsoleException;
use App\Engine\Cli\Output;
use App\Engine\Core\Application;

/**
 * The command list, or one command in detail.
 *
 * Help is generated from the declaration rather than written out again in a
 * docblock, which is the practical payoff of declaring arguments and options as
 * objects: there is no second description of the interface to fall out of date.
 */
final class HelpCommand
{
    public function __construct(private readonly CommandRegistry $commands) {}

    public function __invoke(Output $output, ?string $command = null): int
    {
        if ($command === null) {
            $this->listAll($output);

            return 0;
        }

        $found = $this->commands->get($command)
            ?? throw ConsoleException::unknownCommand($command, $this->commands->suggest($command));

        $this->describe($output, $found);

        return 0;
    }

    private function listAll(Output $output): void
    {
        $output->heading(\sprintf('App Framework %s', Application::VERSION));
        $output->line();
        $output->line('Usage:');
        $output->line('  php laika <command> [arguments] [options]');
        $output->line();

        $width = 0;

        foreach ($this->commands->names() as $name) {
            $width = \max($width, \strlen($name));
        }

        foreach ($this->commands->grouped() as $group => $commands) {
            $output->line($group === '' ? 'Commands:' : ' ' . $group);

            foreach ($commands as $command) {
                $output->line(\sprintf('  %-' . $width . 's  %s', $command->name, $command->description()));
            }

            $output->line();
        }

        $output->line('Run "php laika help <command>" for a command\'s arguments and options.');
    }

    private function describe(Output $output, Command $command): void
    {
        if ($command->description() !== '') {
            $output->line('Description:');
            $output->line('  ' . $command->description());
            $output->line();
        }

        $output->line('Usage:');
        $output->line('  php laika ' . $command->synopsis());

        if ($command->arguments() !== []) {
            $output->line();
            $output->line('Arguments:');

            $rows = [];

            foreach ($command->arguments() as $argument) {
                $rows[$argument->name] = $argument->description
                    . ($argument->default !== null ? \sprintf(' (default: %s)', $argument->default) : '');
            }

            $output->pairs($rows);
        }

        if ($command->options() !== []) {
            $output->line();
            $output->line('Options:');

            $rows = [];

            foreach ($command->options() as $option) {
                $rows[$option->synopsis()] = $option->description
                    . ($option->default !== null ? \sprintf(' (default: %s)', $option->default) : '');
            }

            $output->pairs($rows);
        }

        foreach ($command->notes() as $note) {
            $output->line();
            $output->line('  ' . $note);
        }

        if ($command->module !== null) {
            $output->line();
            $output->line(\sprintf('Declared by module %s.', $command->module));
        }
    }
}
