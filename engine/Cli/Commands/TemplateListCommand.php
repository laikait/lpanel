<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Support\Path;
use App\Engine\Template\TemplateHelper;
use App\Engine\Template\TemplateManager;
use App\Engine\Template\TemplateSource;

/**
 * The template search path, in the order it is searched.
 *
 * "Which file is actually being rendered" is the question a template system
 * gets asked most often, and precedence is the reason the answer surprises
 * people. Printing the order is most of the answer.
 */
final class TemplateListCommand
{
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly Application $application,
    ) {}

    public function __invoke(Output $output): int
    {
        $sources = $this->templates->registry()->all();

        if ($sources === []) {
            $output->line('No template directories are registered.');

            return 0;
        }

        $output->table(
            ['NAMESPACE', 'TIER', 'DIRECTORY', 'PRESENT'],
            \array_map(
                fn(TemplateSource $source): array => [
                    $source->namespace ?? '(application)',
                    $source->precedence <= TemplateSource::OVERRIDE ? 'override' : 'module',
                    Path::relativeTo($this->application->basePath(), $source->root),
                    $source->exists() ? 'yes' : 'no',
                ],
                $sources,
            ),
        );

        $output->line();
        $output->line('Renderable extensions: ' . \implode(', ', $this->templates->extensions()));

        // What templates can call besides local: every name some module
        // offered, and which module, because "where does |money come from" is
        // the second question after "which file is this".
        $helpers = [
            ...\array_values($this->templates->helpers()->filters()),
            ...\array_values($this->templates->helpers()->functions()),
        ];

        if ($helpers !== []) {
            $output->line();
            $output->table(
                ['HELPER', 'KIND', 'MODULE', 'CALLBACK', 'SAFE'],
                \array_map(
                    static fn(TemplateHelper $helper): array => [
                        $helper->name,
                        $helper->kind,
                        $helper->module,
                        $helper->describe(),
                        $helper->safe ? 'yes' : 'no',
                    ],
                    $helpers,
                ),
            );
        }

        return 0;
    }
}
