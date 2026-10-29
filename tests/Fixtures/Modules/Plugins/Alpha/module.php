<?php

declare(strict_types=1);

use App\Engine\Cli\CommandCollector;
use App\Engine\Cli\Output;
use App\Engine\Http\HttpException;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Tests\Fixtures\Modules\Plugins\Alpha\Handlers\ListItems;
use App\Tests\Fixtures\Modules\Plugins\Alpha\Handlers\Recorder;

return static function (ModuleContext $module): void {
    $module->name('Alpha')->version('2.1.0');

    $module->config(['page_size' => 25]);

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/items', ListItems::class)->name('items.index');

        $routes->group('/api/v1', static function (RouteCollector $routes): void {
            $routes->get('/items/{id}', static fn(int $id): array => ['id' => $id])
                ->where('id', '\d+')
                ->name('items.show');

            $routes->post('/items', static fn(): array => ['created' => true])
                ->name('items.store');
        }, name: 'api.v1.', meta: ['api' => true]);

        // A framework-authored failure: the message is ours, so it is safe
        // to show even in production.
        $routes->get('/boom', static function (): never {
            throw new HttpException(500, 'deliberate failure');
        });

        // An application failure: the message may carry anything at all, so
        // production must replace it wholesale.
        $routes->get('/kaboom', static function (): never {
            throw new \RuntimeException('dsn=secret-hunter2');
        });
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('item:count', static fn(): string => '2 items.')
            ->describe('Count the items.');

        $commands->add('item:touch', static function (Output $output, string $id, int $times, bool $force): int {
            $output->line(sprintf('touched %s x%d%s', $id, $times, $force ? ' (forced)' : ''));

            return 0;
        })
            ->describe('Touch an item.')
            ->argument('id', 'The item id.')
            ->option('times', 'How many times.', shortcut: 't', default: '1')
            ->flag('force', 'Touch it even if it has not changed.', shortcut: 'f');

        // A command that fails the way a real one does: by returning the exit
        // code a shell script will branch on.
        $commands->add('item:fail', static fn(): int => 3)
            ->describe('Always fails, with exit code 3.');

        // An application failure whose message may carry anything at all, so
        // production must replace it wholesale -- the console included.
        $commands->add('item:boom', static function (): never {
            throw new \RuntimeException('dsn=secret-hunter2');
        })
            ->describe('Throws.');

        // Returning something that is neither an exit code nor text.
        $commands->add('item:confused', static fn(): array => ['nope'])
            ->describe('Returns the wrong sort of thing.');
    });

    $module->filter('items.list', static fn(array $items): array => array_slice($items, 0, 2), priority: 20);

    $module->onBoot(static function (Recorder $recorder): void {
        $recorder->booted[] = 'Alpha';
    });
};
