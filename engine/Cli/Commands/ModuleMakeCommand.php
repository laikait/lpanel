<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Module\ModuleDefinition;
use App\Engine\Module\ModuleDiscovery;
use App\Engine\Module\ModuleException;
use App\Engine\Support\Path;

/**
 * Create modules/<Name>/module.php, the one file a module needs.
 *
 * **The exception to "the console generates nothing", kept as small as it can
 * be.** What it writes is the skeleton modules.md already documents, and
 * nothing else unless --with names a part: then one starter file for that part,
 * in the shape its reference page documents. Nothing is created for later, and
 * a module is still hand-written; this saves typing the first lines of it.
 *
 * **The name is the module id**, so it has to be a name discovery would accept:
 * ModuleDefinition::NAME_PATTERN, checked here and not copied. That one rule
 * also rules out `..` and drive letters.
 *
 * **One group level is allowed**: `Plugins/Billing` is modules/Plugins/Billing,
 * and its id is `Billing`, because discovery names a module after its own
 * folder. Such a module is only found when its group folder is itself a root in
 * modules.paths. That is a setting somebody else owns, so the command creates
 * config/modules.php when there is none, and otherwise says what to add and
 * never edits the file.
 *
 * **It never overwrites**, and it counts a folder that differs only by case as
 * taken: on Windows and macOS `billing` and `Billing` are the same directory,
 * and on Linux they are two modules whose ids are easily confused.
 *
 * **Success is printed last.** A failed write removes what this run created, so
 * a half-made module cannot be left looking like a finished one.
 */
final class ModuleMakeCommand
{
    public const FILE = 'module.php';

    public const CONFIG = 'config/modules.php';

    /** What --with can ask for, in the order they are written. */
    public const PARTS = ['http', 'model', 'database', 'seeder', 'templates', 'assets', 'lang'];

    public function __construct(private readonly Application $application) {}

    public function __invoke(Output $output, string $name, ?string $with = null): int
    {
        $parts = self::parts($with);

        if ($parts === null || \in_array('seeder', $parts, true) && !\in_array('database', $parts, true)) {
            $output->error(\sprintf(
                '--with takes a comma-separated list of: %s, or all. A seeder fills the table the database part creates, so it needs database too.',
                \implode(', ', self::PARTS),
            ));

            return 1;
        }

        $segments = \explode('/', \trim(\str_replace('\\', '/', $name), '/'));

        if (\count($segments) > 2 || \array_filter(
            $segments,
            static fn(string $segment): bool => \preg_match(ModuleDefinition::NAME_PATTERN, $segment) !== 1,
        ) !== []) {
            $output->error(\sprintf(
                '"%s" is not a module name. Use Billing or Plugins/Billing: each part a capital letter, then letters, digits or underscores.',
                \addcslashes($name, "\0..\37"),
            ));

            return 1;
        }

        $id = $segments[\count($segments) - 1];
        $group = \count($segments) === 2 ? $segments[0] : null;

        $clash = $this->idInUse($id);

        if ($clash !== null) {
            $output->error(\sprintf('A module called %s already exists (%s), and ids must be unique.', $id, $clash));

            return 1;
        }

        $modules = $this->application->basePath('modules');
        $created = [];
        $target = $modules;

        if ($group !== null) {
            $folder = $this->existing($modules, $group);

            // A group folder is reused as it is, but not under other letters:
            // that is a second group on Linux and the same one on Windows.
            if ($folder !== null && $folder !== $group) {
                $output->error(\sprintf('modules/%s already exists, so modules/%s was not used. Spell it the same way.', $folder, $group));

                return 1;
            }

            $target .= DIRECTORY_SEPARATOR . $group;
        }

        $taken = $this->existing($target, $id);

        if ($taken !== null) {
            $output->error(\sprintf('%s already exists, so %s was not created.', self::display($target, $taken), $name));

            return 1;
        }

        $root = $target . DIRECTORY_SEPARATOR . $id;
        $namespace = 'App\\Modules\\' . \implode('\\', $segments);
        $files = self::files($id, $namespace, $parts);
        $written = [];

        foreach ([$modules, $target, $root] as $directory) {
            if (!self::ensure($directory, $created)) {
                self::undo($created, $written);
                $output->error(\sprintf('Could not create %s. Nothing was created.', $directory));

                return 1;
            }
        }

        foreach ($files as $relative => $contents) {
            $file = $root . DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (!self::ensure(\dirname($file), $created) || @\file_put_contents($file, $contents) === false) {
                self::undo($created, $written);
                $output->error(\sprintf('Could not write %s. Nothing was created.', $file));

                return 1;
            }

            $written[] = $file;
        }

        foreach (\array_keys($files) as $relative) {
            $output->success(\sprintf('Created %s', Path::relativeTo($this->application->basePath(), $root . '/' . $relative)));
        }

        $output->line('Modules are found automatically; nothing needs registering. Check it with: php laika module:list');

        $this->registerGroup($output, $id, $target);

        return 0;
    }

    /**
     * The parts asked for, or null when one is not a part.
     *
     * @return list<string>|null
     */
    private static function parts(?string $with): ?array
    {
        if ($with === null) {
            return [];
        }

        $parts = [];

        foreach (\explode(',', $with) as $part) {
            $part = \strtolower(\trim($part));

            if ($part === 'all') {
                $parts = [...$parts, ...self::PARTS];
            } elseif (\in_array($part, self::PARTS, true)) {
                $parts[] = $part;
            } else {
                return null;
            }
        }

        return \array_values(\array_unique($parts));
    }

    /**
     * Create $directory if it is not there, noting it so a failure can remove it.
     *
     * @param list<string> $created innermost first
     */
    private static function ensure(string $directory, array &$created): bool
    {
        if (\is_dir($directory)) {
            return true;
        }

        if (!self::ensure(\dirname($directory), $created)) {
            return false;
        }

        if (!@\mkdir($directory, 0o775) && !\is_dir($directory)) {
            return false;
        }

        \array_unshift($created, $directory);

        return true;
    }

    /** Where, if anywhere, a module of this id is already installed. */
    private function idInUse(string $id): ?string
    {
        try {
            $found = ModuleDiscovery::fromConfig($this->application->config(), $this->application->basePath())->scan();
        } catch (ModuleException) {
            // Somebody else's broken module is for module:list to report.
            return null;
        }

        foreach ($found as $definition) {
            if (\strcasecmp($definition->id, $id) === 0) {
                return Path::relativeTo($this->application->basePath(), $definition->path);
            }
        }

        return null;
    }

    /**
     * Make sure the module's parent folder is a root, when that can be done safely.
     *
     * With no config/modules.php there is nothing to damage, so one is written
     * -- but only when the paths in force are the default, because a file that
     * lists only the new group would replace `modules` (config merges lists by
     * position) and a file written over paths set some other way would undo
     * them. An existing file is hand-written PHP, so it is never rewritten: the
     * line to add is printed instead.
     */
    private function registerGroup(Output $output, string $id, string $parent): void
    {
        $base = $this->application->basePath();
        $roots = ModuleDiscovery::fromConfig($this->application->config(), $base)->roots();
        $wanted = \rtrim(Path::normalize($parent), '/');

        foreach ($roots as $root) {
            if (\strcasecmp(\rtrim($root, '/'), $wanted) === 0) {
                return;
            }
        }

        $relative = Path::relativeTo($base, $wanted);
        $file = $this->application->basePath(self::CONFIG);
        $default = [Path::normalize($this->application->basePath('modules'))];

        if (!\is_file($file) && $roots === $default && self::write($file, self::config($relative))) {
            $output->line(\sprintf('Added %s to modules.paths by creating %s.', $relative, self::CONFIG));

            return;
        }

        $output->warning(\sprintf(
            '%s is not in modules.paths, so %s will not load. In %s: \'paths\' => [..., \'%s\']',
            $relative,
            $id,
            self::CONFIG,
            $relative,
        ));
    }

    private static function write(string $file, string $contents): bool
    {
        $directory = \dirname($file);

        if (!\is_dir($directory) && !@\mkdir($directory, 0o775) && !\is_dir($directory)) {
            return false;
        }

        return @\file_put_contents($file, $contents) !== false;
    }

    /** $group is a validated identifier, so it is safe inside a PHP string. */
    private static function config(string $group): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            return [
                'paths' => ['modules', '{$group}'],
            ];

            PHP;
    }

    /** The entry in $directory that is $name, or the same name in other letters. */
    private function existing(string $directory, string $name): ?string
    {
        $entries = \is_dir($directory) ? \scandir($directory) : [];

        foreach ($entries === false ? [] : $entries as $entry) {
            if (\strcasecmp($entry, $name) === 0) {
                return $entry;
            }
        }

        return null;
    }

    private function display(string $directory, string $entry): string
    {
        return Path::relativeTo($this->application->basePath(), $directory . DIRECTORY_SEPARATOR . $entry);
    }

    /**
     * Remove what this run made: the files, then the directories innermost first.
     *
     * @param list<string> $directories
     * @param list<string> $files
     */
    private static function undo(array $directories, array $files): void
    {
        foreach ($files as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
        }

        foreach ($directories as $directory) {
            if (\is_dir($directory)) {
                @\rmdir($directory);
            }
        }
    }

    /**
     * Every file to write, path inside the module => contents.
     *
     * Nothing user-typed reaches the source except $id and $namespace, and both
     * come from NAME_PATTERN, so they are safe inside PHP and HTML. The other
     * two placeholders are derived from $id.
     *
     * @param list<string> $parts
     *
     * @return array<string, string>
     */
    private static function files(string $id, string $namespace, array $parts): array
    {
        $snake = \strtolower((string) \preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $id));
        $replace = [
            '{{id}}' => $id,
            '{{ns}}' => $namespace,
            '{{snake}}' => $snake,
            '{{kebab}}' => \str_replace('_', '-', $snake),
            // Spelled in two pieces: this command writes handlers, it does not use HTTP itself.
            '{{http}}' => 'App\\Engine\\' . 'Http',
            '{{stamp}}' => \gmdate('Y_m_d_His'),
        ];
        $has = static fn(string $part): bool => \in_array($part, $parts, true);

        $files = [self::FILE => $has('http') ? self::MODULE_WITH_ROUTES : self::MODULE];
        // The handler reads the table the migration creates, so it needs both parts.
        $reads = $has('http') && $has('database');

        if ($has('http')) {
            $files['Http/Index.php'] = match (true) {
                $reads && $has('templates') => self::DB_PAGE_HANDLER,
                $reads => self::DB_TEXT_HANDLER,
                $has('templates') => self::PAGE_HANDLER,
                default => self::TEXT_HANDLER,
            };
        }

        if ($has('model')) {
            $files['Model/{{id}}.php'] = self::MODEL;
        }

        if ($has('database')) {
            $files['Database/Migrations/{{stamp}}_create_{{snake}}_table.php'] = self::MIGRATION;
        }

        if ($has('seeder')) {
            $files['Database/Seeders/{{snake}}.php'] = self::SEEDER;
        }

        if ($has('templates')) {
            $files['Templates/index.php'] = $reads ? self::LIST_TEMPLATE : self::TEMPLATE;
        }

        if ($has('assets')) {
            $files['assets/css/{{kebab}}.css'] = self::STYLESHEET;
        }

        if ($has('lang')) {
            $files['lang/en.php'] = self::LANG;
        }

        $rendered = [];

        foreach ($files as $path => $contents) {
            $rendered[\strtr($path, $replace)] = \strtr($contents, $replace);
        }

        return $rendered;
    }

    private const MODULE = <<<'PHP'
        <?php

        declare(strict_types=1);

        use App\Engine\Module\ModuleContext;

        return static function (ModuleContext $module): void {
            $module
                ->name('{{id}}')
                ->version('0.1.0')
                ->description('TODO: describe {{id}}.');
        };

        PHP;

    private const MODULE_WITH_ROUTES = <<<'PHP'
        <?php

        declare(strict_types=1);

        use App\Engine\Module\ModuleContext;
        use App\Engine\Routing\RouteCollector;
        use {{ns}}\Http\Index;

        return static function (ModuleContext $module): void {
            $module
                ->name('{{id}}')
                ->version('0.1.0')
                ->description('TODO: describe {{id}}.');

            $module->routes(static function (RouteCollector $routes): void {
                $routes->get('/{{kebab}}', Index::class)->name('{{kebab}}.index');
            });
        };

        PHP;

    private const TEXT_HANDLER = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace {{ns}}\Http;

        use {{http}}\Request;
        use {{http}}\Response;

        final class Index
        {
            public function __invoke(Request $request): Response
            {
                return (new Response('<h1>{{id}}</h1>'))->withContentType('text/html');
            }
        }

        PHP;

    private const PAGE_HANDLER = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace {{ns}}\Http;

        use {{http}}\Request;
        use {{http}}\Response;
        use App\Engine\Template\TemplateManager;

        final class Index
        {
            public function __construct(private readonly TemplateManager $templates) {}

            public function __invoke(Request $request): Response
            {
                return (new Response($this->templates->render('@{{id}}/index')))->withContentType('text/html');
            }
        }

        PHP;

    private const DB_TEXT_HANDLER = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace {{ns}}\Http;

        use App\Engine\Database\ConnectionManager;
        use {{http}}\Request;
        use {{http}}\Response;

        final class Index
        {
            public function __construct(private readonly ConnectionManager $connections) {}

            public function __invoke(Request $request): Response
            {
                $items = '';

                foreach ($this->connections->connection()->table('{{snake}}')->orderBy('name')->get() as $row) {
                    $items .= '<li>' . \htmlspecialchars((string) $row['name'], ENT_QUOTES) . '</li>';
                }

                return (new Response('<h1>{{id}}</h1><ul>' . $items . '</ul>'))->withContentType('text/html');
            }
        }

        PHP;

    private const DB_PAGE_HANDLER = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace {{ns}}\Http;

        use App\Engine\Database\ConnectionManager;
        use {{http}}\Request;
        use {{http}}\Response;
        use App\Engine\Template\TemplateManager;

        final class Index
        {
            public function __construct(
                private readonly ConnectionManager $connections,
                private readonly TemplateManager $templates,
            ) {}

            public function __invoke(Request $request): Response
            {
                $rows = $this->connections->connection()->table('{{snake}}')->orderBy('name')->get();

                return (new Response($this->templates->render('@{{id}}/index', ['rows' => $rows])))
                    ->withContentType('text/html');
            }
        }

        PHP;

    private const LIST_TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        /**
         * @var \App\Engine\Template\TemplateView $view
         * @var \App\Engine\Template\Escaper      $e
         * @var list<array<string, mixed>>        $rows
         */
        ?>
        <h1><?= $e('{{id}}') ?></h1>
        <ul>
        <?php foreach ($rows as $row): ?>
            <li><?= $e($row['name']) ?></li>
        <?php endforeach ?>
        </ul>

        PHP;

    private const MODEL = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace {{ns}}\Model;

        use App\Engine\Model\Model;

        final class {{id}} extends Model
        {
            public function __construct(private ?int $id = null) {}

            public function identity(): ?int
            {
                return $this->id;
            }
        }

        PHP;

    private const MIGRATION = <<<'PHP'
        <?php

        declare(strict_types=1);

        use App\Engine\Database\Structure\Table;
        use App\Engine\Database\Structure\Tables;
        use App\Engine\Migration\Reversible;

        return new class implements Reversible {
            public function up(Tables $tables): void
            {
                $tables->create('{{snake}}', static function (Table $table): void {
                    $table->id();
                    $table->string('name');
                    $table->timestamps();
                });
            }

            public function down(Tables $tables): void
            {
                $tables->drop('{{snake}}');
            }
        };

        PHP;

    private const SEEDER = <<<'PHP'
        <?php

        declare(strict_types=1);

        use App\Engine\Database\Connection;
        use App\Engine\Migration\Seeder;

        return new class implements Seeder {
            public function run(Connection $db): void
            {
                if (!$db->table('{{snake}}')->where('name', '{{id}}')->exists()) {
                    $db->table('{{snake}}')->insert(['name' => '{{id}}']);
                }
            }
        };

        PHP;

    private const TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        /**
         * @var \App\Engine\Template\TemplateView $view
         * @var \App\Engine\Template\Escaper      $e
         */
        ?>
        <h1><?= $e('{{id}}') ?></h1>

        PHP;

    private const STYLESHEET = <<<'PHP'
        /* Served at /assets/module/{{id}}/css/{{kebab}}.css */

        PHP;

    private const LANG = <<<'PHP'
        <?php

        declare(strict_types=1);

        return [
            'title' => '{{id}}',
        ];

        PHP;
}
