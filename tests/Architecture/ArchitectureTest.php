<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Engine\Config\ConfigCache;
use App\Engine\Error\ErrorContext;
use App\Engine\Error\ErrorDocument;
use App\Engine\Error\ErrorPage;
use App\Engine\Logging\Level;
use App\Engine\Module\ModuleManager;
use App\Engine\Session\SessionManager;
use App\Engine\Session\Stores\ArrayStore as SessionArrayStore;
use App\Engine\Support\Extensions;
use App\Tests\Support\TestCase;
use App\Tests\Unit\Cache\StoreConformanceTest;
use App\Tests\Unit\Data\BulkWritesConformanceTest;
use App\Tests\Unit\Queue\StoreConformanceTest as QueueStoreConformanceTest;
use App\Tests\Unit\Scheduler\LockConformanceTest;
use App\Tests\Unit\Security\CounterConformanceTest;
use App\Tests\Unit\Session\StoreConformanceTest as SessionStoreConformanceTest;
use App\Tests\Unit\Support\HelpersTest;

/**
 * Executable architecture rules.
 *
 * These are the invariants that erode quietly. Each one is cheap to violate in
 * a single commit and expensive to walk back a year later, so each one gets a
 * test rather than a paragraph in a document nobody re-reads.
 */
final class ArchitectureTest extends TestCase
{
    /** @return list<string> absolute paths of every PHP file under engine/ */
    private function engineFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath('engine'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        \sort($files);

        return $files;
    }

    /** @return list<string> absolute paths of every PHP file under modules/ */
    private function moduleFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath('modules'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        \sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return \str_replace([$this->basePath() . \DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
    }

    /**
     * Function calls made in the global namespace from a PHP file.
     *
     * A token scan rather than a regex: it will not be fooled by the function's
     * name appearing inside a string, a comment or a docblock.
     *
     * @return list<string>
     */
    private function globalFunctionCalls(string $path): array
    {
        $source = \file_get_contents($path);
        self::assertIsString($source);

        $tokens = \token_get_all($source);
        $calls = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || $token[0] !== \T_STRING) {
                continue;
            }

            // Must be followed by "(" to be a call.
            $next = $tokens[$index + 1] ?? null;

            if ($next !== '(') {
                continue;
            }

            // Look back past whitespace and comments, so that "function add_hook("
            // is recognised as a declaration rather than counted as a call.
            $previous = null;

            for ($i = $index - 1; $i >= 0; --$i) {
                $candidate = $tokens[$i];

                if (\is_array($candidate)
                    && \in_array($candidate[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }

                $previous = $candidate;

                break;
            }

            // Skip anything qualified or declared: Foo::bar(), $x->bar(),
            // new Bar(), Ns\bar(), function bar().
            if (\is_array($previous) && \in_array(
                $previous[0],
                [\T_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_NEW, \T_FUNCTION, \T_NAME_QUALIFIED, \T_NS_SEPARATOR],
                true,
            )) {
                continue;
            }

            $calls[] = $token[1];
        }

        return $calls;
    }

    /**
     * Does this file call this function, qualified or not?
     *
     * globalFunctionCalls() deliberately reports only UNQUALIFIED calls, which
     * is what the facade rules need -- a global helper is written add_hook(),
     * never \add_hook(). Everything else in this codebase is written \substr()
     * because the coding standard says so, so a rule about native functions
     * needs the other question answered.
     *
     * The boundaries matter: without the lookbehind, "rand(" would match inside
     * "str_shuffle(" -- no -- inside nothing, but "shuffle(" would match inside
     * "str_shuffle(", and a rule that fires on the wrong function is a rule
     * somebody deletes.
     */
    private function callsFunction(string $path, string $name): bool
    {
        return \preg_match(
            // Not after "->", "::" or "function ": $x->exec(), self::system()
            // and a method declared as system() are not the native function.
            '/(?<![A-Za-z0-9_$>:])(?<!function )\\\\?' . \preg_quote($name, '/') . '\s*\(/',
            $this->codeWithoutComments($path),
        ) === 1;
    }

    /**
     * A file's source with its comments and docblocks removed.
     *
     * A rule about what the code does should not be tripped by a docblock
     * explaining it. Documentation that shows a SELECT is documentation.
     */
    private function codeWithoutComments(string $path): string
    {
        $source = \file_get_contents($path);
        self::assertIsString($source);

        $code = '';

        foreach (\token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= \is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    // ---- the facade ban ---------------------------------------------------

    /**
     * Engine code takes HookEngine and FilterEngine by constructor injection.
     * The moment engine code starts calling add_hook(), the global bridge has
     * become load-bearing infrastructure rather than an author convenience, and
     * the argument that it is not a facade stops holding.
     */
    public function test_no_engine_file_calls_a_global_helper(): void
    {
        foreach ($this->engineFiles() as $path) {
            // Separators differ between the directory iterator and basePath(),
            // so compare the normalised relative path rather than the absolute one.
            if ($this->relative($path) === 'engine/Support/helpers.php') {
                continue;
            }

            $offenders = \array_intersect($this->globalFunctionCalls($path), HelpersTest::PERMITTED);

            self::assertSame(
                [],
                \array_values($offenders),
                \sprintf(
                    '%s calls %s. Engine code must take HookEngine/FilterEngine by constructor injection.',
                    $this->relative($path),
                    \implode(', ', $offenders),
                ),
            );
        }
    }

    /**
     * __callStatic resolving arbitrary services IS the facade pattern. There is
     * no legitimate use for it in this framework.
     */
    public function test_no_engine_class_uses_magic_static_dispatch(): void
    {
        foreach ($this->engineFiles() as $path) {
            $source = \file_get_contents($path);
            self::assertIsString($source);

            self::assertStringNotContainsString(
                'function __callStatic',
                $source,
                \sprintf('%s declares __callStatic. That is the facade pattern.', $this->relative($path)),
            );
        }
    }

    public function test_no_engine_class_is_named_like_a_facade(): void
    {
        foreach ($this->engineFiles() as $path) {
            self::assertStringNotContainsString(
                'Facade',
                \basename($path),
                \sprintf('%s is named like a facade.', $this->relative($path)),
            );
        }
    }

    /**
     * Extensions is the single permitted piece of static framework state, and
     * its safety rests on being closed and on every member being justified.
     *
     * The justification is the same one for all of them: the specification says
     * authors reach hooks, filters, assets and templates globally, from
     * module.php files and from templates themselves, where there is no
     * constructor to inject into. A member
     * without that justification would make this a service locator with a
     * fixed key set, which is a facade wearing a different hat.
     *
     * So the test asserts the names rather than the count. A count is only ever
     * one commit away from being the next number; a name has to be argued for.
     */
    public function test_extensions_exposes_exactly_the_justified_subsystems(): void
    {
        $methods = \array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(Extensions::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        \sort($methods);

        self::assertSame(
            ['assets', 'filters', 'hooks', 'init', 'isInitialised', 'reset', 'templates'],
            $methods,
            'Extensions gained a member. Name the line in the specification that says authors reach it globally, '
            . 'or inject it like everything else in the framework.',
        );
    }

    /**
     * The other half of that argument: no dispatch by name, ever.
     *
     * A lookup that takes a string is what turns a closed holder into a service
     * locator, whatever the class happens to be called.
     */
    public function test_extensions_resolves_nothing_by_name(): void
    {
        foreach ((new \ReflectionClass(Extensions::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                self::assertNotSame(
                    'string',
                    $type instanceof \ReflectionNamedType ? $type->getName() : null,
                    \sprintf(
                        'Extensions::%s() takes a string. A lookup by name is a service locator.',
                        $method->getName(),
                    ),
                );
            }
        }
    }

    public function test_the_global_function_set_is_exactly_the_permitted_names(): void
    {
        $source = \file_get_contents($this->basePath('engine/Support/helpers.php'));
        self::assertIsString($source);

        $declared = [];

        foreach (\token_get_all($source) as $index => $token) {
            if (\is_array($token) && $token[0] === \T_FUNCTION) {
                $declared[] = $index;
            }
        }

        $tokens = \token_get_all($source);
        $names = [];

        foreach ($declared as $index) {
            for ($i = $index + 1, $end = \count($tokens); $i < $end; ++$i) {
                $token = $tokens[$i];

                if (\is_array($token) && $token[0] === \T_STRING) {
                    $names[] = $token[1];

                    break;
                }
            }
        }

        \sort($names);
        $permitted = HelpersTest::PERMITTED;
        \sort($permitted);

        self::assertSame(
            $permitted,
            $names,
            'helpers.php declares a different set of functions than the permitted list. '
            . 'Adding one is a deliberate design decision, not a drive-by commit.',
        );
    }

    // ---- layering ---------------------------------------------------------

    /**
     * The engine provides infrastructure; modules provide business capability.
     * A reference in the other direction would mean the framework has grown an
     * opinion about a particular application.
     */
    public function test_the_engine_never_references_a_module(): void
    {
        foreach ($this->engineFiles() as $path) {
            $source = \file_get_contents($path);
            self::assertIsString($source);

            self::assertStringNotContainsString(
                'App\\Modules',
                $source,
                \sprintf('%s references a module. The engine must not know about application code.', $this->relative($path)),
            );
        }
    }

    /**
     * HTTP is a transport concern and routing is a dispatch concern. Keeping
     * Request and Response ignorant of routing is what lets both be reused and
     * tested without a router, and is an explicit architectural invariant.
     */
    public function test_the_http_layer_does_not_know_about_routing(): void
    {
        foreach ($this->engineFiles() as $path) {
            if (!\str_contains($this->relative($path), 'engine/Http/')) {
                continue;
            }

            $source = \file_get_contents($path);
            self::assertIsString($source);

            foreach (['App\\Engine\\Routing', 'App\\Engine\\Dispatch', 'App\\Engine\\Module'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf('%s references %s. HTTP must not be coupled to the router.', $this->relative($path), $forbidden),
                );
            }
        }
    }

    // ---- exceptions -------------------------------------------------------

    /**
     * Catching FrameworkException should mean "the framework said no". The two
     * container exceptions are the documented carve-out: they implement PSR-11
     * interfaces instead, which is the entire point of using PSR-11.
     */
    public function test_engine_exceptions_are_catchable_as_a_group(): void
    {
        $psrExempt = [
            \App\Engine\Container\ContainerException::class,
            \App\Engine\Container\EntryNotFoundException::class,
        ];

        foreach ($this->engineFiles() as $path) {
            if (!\str_ends_with($path, 'Exception.php')) {
                continue;
            }

            $class = $this->classNameFor($path);

            if (!\class_exists($class) || \in_array($class, $psrExempt, true)) {
                continue;
            }

            self::assertTrue(
                \is_subclass_of($class, \App\Engine\Error\FrameworkException::class)
                    || $class === \App\Engine\Error\FrameworkException::class,
                \sprintf('%s does not extend FrameworkException and is not a documented PSR carve-out.', $class),
            );
        }
    }

    private function classNameFor(string $path): string
    {
        $relative = \substr($this->relative($path), \strlen('engine/'), -\strlen('.php'));

        return 'App\\Engine\\' . \str_replace('/', '\\', $relative);
    }

    // ---- the model layer --------------------------------------------------

    /**
     * The engine provides model infrastructure. A business entity living here
     * would mean the framework has decided what your application's domain is,
     * and every application that disagreed would be fighting it.
     */
    public function test_no_business_model_lives_in_the_engine(): void
    {
        $infrastructure = [
            'Attributes.php',
            'Encrypted.php',
            'Model.php',
            'ModelCollection.php',
            'ModelException.php',
            'ModelManager.php',
            'ReadModel.php',
            'Relation.php',
            'RelationManager.php',
            'RelationType.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->relative($path), 'engine/Model/')) {
                $found[] = \basename($path);
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame(
            $infrastructure,
            $found,
            'engine/Model/ holds infrastructure only. User, Customer, Invoice and Payment belong to modules.',
        );
    }

    /**
     * The line between "a model" and "an ORM entity". Any one of these methods
     * on the base class would put persistence back inside the domain object,
     * which is the architecture this framework was specified to avoid.
     */
    public function test_the_model_base_class_has_no_persistence_api(): void
    {
        $methods = \array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(\App\Engine\Model\Model::class))->getMethods(),
        );

        foreach ([
            'save', 'delete', 'create', 'update', 'insert', 'persist', 'refresh',
            'find', 'findAll', 'first', 'all', 'where', 'query', 'with', 'load',
        ] as $forbidden) {
            self::assertNotContains(
                $forbidden,
                $methods,
                \sprintf('Model::%s() would make this an active record.', $forbidden),
            );
        }
    }

    /**
     * A domain model is not an API representation. Serialising one is how
     * internal state reaches a public payload without anyone deciding to.
     */
    public function test_a_domain_model_is_not_serialisable(): void
    {
        self::assertFalse(
            \is_subclass_of(\App\Engine\Model\Model::class, \JsonSerializable::class),
            'Model implements JsonSerializable. Project it into a ReadModel instead.',
        );
    }

    /**
     * The model layer knows nothing about transport or storage. That is what
     * lets it be used and tested without either, and what keeps Model, Schema,
     * Repository, Query and Database separate concepts rather than one.
     */
    public function test_the_model_layer_is_independent_of_transport_and_storage(): void
    {
        foreach ($this->engineFiles() as $path) {
            if (!\str_contains($this->relative($path), 'engine/Model/')) {
                continue;
            }

            $source = \file_get_contents($path);
            self::assertIsString($source);

            foreach ([
                'App\\Engine\\Http',
                'App\\Engine\\Routing',
                'App\\Engine\\Dispatch',
                'App\\Engine\\Container',
                'App\\Engine\\Data',
                'App\\Engine\\Database',
                'App\\Engine\\Schema',
                // Leading backslash on purpose: that is how a global class is
                // referenced from a namespaced file, so prose about PDO in a
                // docblock does not trip the rule but "new \PDO" does.
                '\\PDO',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf('%s references %s. The model layer must not depend on it.', $this->relative($path), $forbidden),
                );
            }
        }
    }

    // ---- the schema layer -------------------------------------------------

    public function test_no_business_schema_lives_in_the_engine(): void
    {
        $infrastructure = [
            'Field.php',
            'FieldType.php',
            'Schema.php',
            'SchemaException.php',
            'ValidationError.php',
            'ValidationResult.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->relative($path), 'engine/Schema/')) {
                $found[] = \basename($path);
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame(
            $infrastructure,
            $found,
            'engine/Schema/ holds infrastructure only. CustomerSchema and its kind belong to modules.',
        );
    }

    /**
     * The structural difference between a schema and a form-request object.
     *
     * A FormRequest is resolved out of a handler signature, knows it is an HTTP
     * request, and throws a status code. If the schema layer cannot see HTTP at
     * all, it cannot grow into one no matter who is in a hurry.
     */
    public function test_the_schema_layer_cannot_become_a_form_request(): void
    {
        foreach ($this->engineFiles() as $path) {
            if (!\str_contains($this->relative($path), 'engine/Schema/')) {
                continue;
            }

            $source = \file_get_contents($path);
            self::assertIsString($source);

            foreach ([
                'App\\Engine\\Http',
                'App\\Engine\\Routing',
                'App\\Engine\\Dispatch',
                'App\\Engine\\Model',
                'App\\Engine\\Container',
                'App\\Engine\\Module',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf(
                        '%s references %s. A schema describes data; it must not know how the data arrived.',
                        $this->relative($path),
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * Nothing to extend and nothing to implement. A schema is a value built by
     * a factory, which is the other half of not being a form-request base class.
     */
    public function test_the_schema_types_are_final(): void
    {
        foreach ([
            \App\Engine\Schema\Schema::class,
            \App\Engine\Schema\Field::class,
            \App\Engine\Schema\ValidationResult::class,
            \App\Engine\Schema\ValidationError::class,
        ] as $class) {
            self::assertTrue(
                (new \ReflectionClass($class))->isFinal(),
                \sprintf('%s is not final. A schema is declared, not subclassed.', $class),
            );
        }
    }

    // ---- the data layer ---------------------------------------------------

    public function test_no_business_repository_lives_in_the_engine(): void
    {
        $infrastructure = [
            'ArraySource.php',
            'Bulk.php',
            'BulkWrites.php',
            'Criterion.php',
            'Cursor.php',
            'CursorPage.php',
            'DataException.php',
            'DataSource.php',
            'Direction.php',
            'Operator.php',
            'Order.php',
            'Page.php',
            'Query.php',
            'Repository.php',
            'Seek.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->relative($path), 'engine/Data/')) {
                $found[] = \basename($path);
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame(
            $infrastructure,
            $found,
            'engine/Data/ holds infrastructure only. CustomerRepository and its kind belong to modules.',
        );
    }

    /**
     * The repository base contributes no API, which is what makes every public
     * method on a real repository one somebody named after something the
     * application does. A find()/findAll()/save()/delete() base is a table
     * gateway with a longer name.
     */
    public function test_the_repository_base_publishes_nothing_but_its_constructor(): void
    {
        $public = \array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(\App\Engine\Data\Repository::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        self::assertSame(
            ['__construct'],
            $public,
            'Repository gained a public method. Every repository would then have it, whether it meant anything or not.',
        );
    }

    /**
     * The data layer never sees a connection, a statement or a dialect.
     *
     * DataSource is the whole seam. Keeping SQL out of this directory is what
     * lets the same repository run against an array in a test and a database in
     * production, and it is what keeps the database phase free to own
     * connections, transactions and drivers without anything above it caring.
     */
    public function test_the_data_layer_knows_nothing_about_sql_or_transport(): void
    {
        foreach ($this->engineFiles() as $path) {
            if (!\str_contains($this->relative($path), 'engine/Data/')) {
                continue;
            }

            $source = \file_get_contents($path);
            self::assertIsString($source);

            foreach ([
                'App\\Engine\\Http',
                'App\\Engine\\Routing',
                'App\\Engine\\Dispatch',
                'App\\Engine\\Database',
                '\\PDO',
                'SELECT ',
                'INSERT ',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf('%s references %s. The data layer speaks through DataSource only.', $this->relative($path), $forbidden),
                );
            }
        }
    }

    /**
     * Transactions, connections and nested-transaction strategy belong to the
     * database layer. A transaction() here would make every source pretend to
     * have one, including the array in a test.
     */
    public function test_the_data_source_does_not_reach_into_the_database_layer(): void
    {
        $methods = \array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(\App\Engine\Data\DataSource::class))->getMethods(),
        );

        \sort($methods);

        self::assertSame(['count', 'delete', 'fetch', 'insert', 'update'], $methods);
    }

    // ---- the database layer -----------------------------------------------

    public function test_the_database_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'Aggregate.php',
            'Capability.php',
            'Column.php',
            'ColumnType.php',
            'Condition.php',
            'Connection.php',
            'ConnectionConfig.php',
            'ConnectionManager.php',
            'DatabaseException.php',
            'ForeignKey.php',
            'Grammar.php',
            'Index.php',
            'IsolationLevel.php',
            'JoinClause.php',
            'MySqlGrammar.php',
            'PostgresGrammar.php',
            'QueryBuilder.php',
            'QueryState.php',
            'RawExpression.php',
            'SqlServerGrammar.php',
            'SqlSource.php',
            'SqliteGrammar.php',
            'Table.php',
            'Tables.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->relative($path), 'engine/Database/')) {
                $found[] = \basename($path);
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * Migrations sit on the database layer, never the other way round.
     *
     * The table builder is usable from a script with no modules at all; the
     * runner that finds migrations in modules is a separate layer that uses
     * it. If anything in engine/Database named a module or a migration, that
     * would stop being true.
     */
    public function test_the_database_layer_knows_nothing_of_migrations_or_modules(): void
    {
        $checked = 0;

        foreach ($this->engineFiles() as $path) {
            if (!\str_contains($this->relative($path), 'engine/Database/')) {
                continue;
            }

            ++$checked;
            $code = $this->codeWithoutComments($path);

            foreach (['App\\Engine\\Migration\\', 'App\\Engine\\Module\\'] as $namespace) {
                self::assertStringNotContainsString($namespace, $code, \sprintf('%s depends on %s.', $this->relative($path), $namespace));
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    /**
     * "Database should remain usable independently of Model."
     *
     * A Connection is for a report, a migration or a one-off script as much as
     * for a repository. If it knew what a model was, the data layer would be
     * the only way in.
     */
    public function test_a_connection_knows_nothing_about_models(): void
    {
        foreach (['Connection.php', 'ConnectionConfig.php', 'ConnectionManager.php'] as $file) {
            $source = \file_get_contents($this->basePath('engine/Database/' . $file));
            self::assertIsString($source);

            foreach ([
                'App\\Engine\\Model\\',
                'App\\Engine\\Data\\',
                'App\\Engine\\Schema\\',
                'App\\Engine\\Http\\',
                'App\\Engine\\Config\\',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf('engine/Database/%s references %s; the database must stand on its own.', $file, $forbidden),
                );
            }
        }
    }

    /**
     * Only the Grammar builds SQL, and only from checked identifiers.
     *
     * Everything else in the database layer passes SQL through from a caller or
     * from the Grammar. Keeping statement construction in one file is what
     * makes the injection boundary one file wide.
     */
    public function test_only_the_grammar_builds_statements(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Database/') || \str_ends_with($relative, 'Grammar.php')) {
                continue;
            }

            foreach ([
                'SELECT ', 'INSERT INTO', 'UPDATE ', 'DELETE FROM', 'SAVEPOINT', 'SAVE TRANSACTION',
                // Structure too: the table builder describes, the grammar writes.
                'CREATE TABLE', 'DROP TABLE', 'CREATE INDEX', 'CREATE UNIQUE INDEX', 'FOREIGN KEY',
            ] as $statement) {
                self::assertStringNotContainsString(
                    $statement,
                    $this->codeWithoutComments($path),
                    \sprintf('%s builds SQL. Statement construction belongs in Grammar, where identifiers are checked.', $relative),
                );
            }
        }
    }

    /**
     * The framework never decides which source an application reads through.
     *
     * Bootstrap wires the connections; a module chooses whether to read from a
     * database, from memory, or from something else entirely.
     */
    public function test_bootstrap_does_not_choose_the_applications_data_source(): void
    {
        $source = \file_get_contents($this->basePath('engine/Bootstrap/Bootstrap.php'));
        self::assertIsString($source);

        foreach (['DataSource::class', 'SqlSource::class', 'ArraySource::class'] as $binding) {
            self::assertStringNotContainsString(
                $binding,
                $source,
                'Bootstrap binds a data source. Which source an application reads through is its own decision.',
            );
        }
    }

    // ---- the asset layer --------------------------------------------------

    public function test_the_asset_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'AssetException.php',
            'AssetKind.php',
            'AssetManager.php',
            'AssetReference.php',
            'AssetRegistry.php',
            'AssetResolver.php',
            'AssetServer.php',
            'AssetSource.php',
            'AssetVersioning.php',
            'Manifest.php',
            'MimeTypes.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->relative($path), 'engine/Asset/')) {
                $found[] = \basename($path);
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * "Separate asset resolution from asset delivery."
     *
     * The specification asks for it, and the reason is that they have different
     * lifetimes: a URL is generated in a template, a CLI job or a queued mail,
     * where there is no request at all, while delivery is one HTTP handler that
     * a web server should eventually take over. If AssetManager could see a
     * Request the two would grow together and neither could be replaced.
     */
    public function test_asset_resolution_cannot_see_http(): void
    {
        foreach (['AssetManager.php', 'AssetRegistry.php', 'AssetResolver.php', 'AssetSource.php', 'Manifest.php'] as $file) {
            $source = \file_get_contents($this->basePath('engine/Asset/' . $file));
            self::assertIsString($source);

            foreach ([
                'App\\Engine\\Http',
                'App\\Engine\\Routing',
                'App\\Engine\\Dispatch',
                'App\\Engine\\Container',
                'App\\Engine\\Module',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf(
                        'engine/Asset/%s references %s. Resolution builds a URL; only AssetServer delivers one.',
                        $file,
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * Every path that becomes a file goes through AssetResolver.
     *
     * This is the rule that keeps the traversal, containment, symlink and
     * extension checks in one readable place. A second file in this directory
     * opening something by path would be a second place to get it wrong, and
     * the one nobody remembers to look at.
     */
    public function test_only_the_resolver_turns_an_asset_path_into_a_file(): void
    {
        // AssetReference is the resolver's own result type: holding one already
        // means every check passed, which is the entire point of the type.
        $permitted = ['AssetResolver.php', 'AssetReference.php', 'AssetSource.php', 'Manifest.php'];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Asset/') || \in_array(\basename($path), $permitted, true)) {
                continue;
            }

            foreach (['file_get_contents', 'is_file', 'realpath', 'glob', 'scandir', 'opendir'] as $call) {
                self::assertStringNotContainsString(
                    $call . '(',
                    $this->codeWithoutComments($path),
                    \sprintf(
                        '%s reaches the filesystem directly. Asset paths become files in AssetResolver, '
                        . 'where traversal, containment and extension are checked.',
                        $relative,
                    ),
                );
            }
        }
    }

    /**
     * PHP is not an asset, and neither is anything else that runs.
     *
     * The served set is an allow list rather than a deny list, which is what
     * makes this assertion possible at all: a deny list can only be tested
     * against the extensions somebody thought of.
     */
    public function test_no_executable_or_configuration_extension_is_servable(): void
    {
        foreach ([
            'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'inc',
            'env', 'ini', 'htaccess', 'sh', 'bat', 'cmd', 'exe', 'dll', 'so',
            'sql', 'lock', 'log', 'yml', 'yaml', 'html', 'htm', 'xhtml',
        ] as $extension) {
            self::assertFalse(
                \App\Engine\Asset\MimeTypes::isServable($extension),
                \sprintf('.%s is on the served list. It should not be reachable through an asset URL.', $extension),
            );
        }
    }

    // ---- the template layer -----------------------------------------------

    public function test_the_template_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'Escaper.php',
            'PhpTemplateEngine.php',
            'TemplateEngine.php',
            'TemplateException.php',
            'TemplateFile.php',
            'TemplateHelper.php',
            'TemplateHelperCollector.php',
            'TemplateHelpers.php',
            'TemplateManager.php',
            'TemplateRegistry.php',
            'TemplateSource.php',
            'TemplateView.php',
            'ThemeProvider.php',
            'ThemeRegistry.php',
            'TwigTemplateEngine.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->relative($path), 'engine/Template/')) {
                $found[] = \basename($path);
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * Rendering produces a string, not a response.
     *
     * That is what lets the same template be rendered into an email, a PDF
     * pipeline or a test assertion. A render() that returned a Response would
     * make HTTP the only destination, and a template system with one
     * destination is a view layer bolted to a controller.
     */
    public function test_the_template_layer_cannot_see_http(): void
    {
        foreach ($this->engineFiles() as $path) {
            if (!\str_contains($this->relative($path), 'engine/Template/')) {
                continue;
            }

            $source = \file_get_contents($path);
            self::assertIsString($source);

            foreach ([
                'App\\Engine\\Http',
                'App\\Engine\\Routing',
                'App\\Engine\\Dispatch',
                'App\\Engine\\Container',
                'App\\Engine\\Data',
                'App\\Engine\\Database',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf(
                        '%s references %s. A template renders to a string; the handler decides what to do with it.',
                        $this->relative($path),
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * "Twig must remain optional." Optional means one file.
     *
     * If Twig appeared anywhere else -- a type hint on the manager, a check in
     * the registry -- then removing the package would break the framework, and
     * "optional" would mean "installed by default".
     */
    public function test_twig_is_confined_to_its_own_engine(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Template/') || \str_ends_with($relative, 'TwigTemplateEngine.php')) {
                continue;
            }

            $source = \file_get_contents($path);
            self::assertIsString($source);

            self::assertStringNotContainsString(
                'Twig\\',
                $source,
                \sprintf('%s references Twig. Only TwigTemplateEngine may.', $relative),
            );
        }
    }

    /**
     * Twig is the default engine and the default template ships .twig files,
     * so it is a runtime requirement. Left in require-dev, `composer install
     * --no-dev` -- which is how production installs -- would produce an
     * application whose front page and 404 page cannot be rendered.
     */
    public function test_twig_is_a_runtime_requirement_because_the_default_template_needs_it(): void
    {
        $composer = \file_get_contents($this->basePath('composer.json'));
        self::assertIsString($composer);

        /** @var array{require: array<string, string>, require-dev?: array<string, string>} $manifest */
        $manifest = \json_decode($composer, true, 16, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('twig/twig', $manifest['require']);
        self::assertArrayNotHasKey('twig/twig', $manifest['require-dev'] ?? []);
        self::assertNotEmpty(\glob($this->basePath('templates/*.twig')) ?: []);
    }

    /**
     * modules/ autoloads through one PSR-4 root, so directory names are namespace
     * segments, letter for letter.
     *
     * A mismatch -- modules/shared holding App\Modules\Shared -- works on
     * Windows and macOS, whose filesystems ignore case, and is "Class not found"
     * on the Linux server it is deployed to. Nothing on a developer's machine
     * fails, so this reads the names as they are stored rather than asking the
     * filesystem whether a path exists.
     */
    public function test_module_directories_match_their_namespace_case_for_case(): void
    {
        $composer = \file_get_contents($this->basePath('composer.json'));
        self::assertIsString($composer);

        /** @var array{autoload: array{psr-4: array<string, string>}} $manifest */
        $manifest = \json_decode($composer, true, 16, \JSON_THROW_ON_ERROR);

        self::assertSame('modules/', $manifest['autoload']['psr-4']['App\\Modules\\'] ?? null, 'App\\Modules\\ no longer maps to modules/.');

        // The default root is modules/, so modules/<Name> holds App\Modules\<Name>.
        self::assertSame(['modules'], \App\Engine\Bootstrap\Bootstrap::defaults()['modules']['paths']);

        // And every class under modules/ sits where PSR-4 will look for it.
        $read = 0;
        $root = $this->basePath('modules');

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = \file_get_contents($file->getPathname());
            self::assertIsString($source);
            ++$read;

            if (\preg_match('/^namespace (App\\\\Modules\\\\[^;]+);/m', $source, $namespace) !== 1) {
                continue;
            }

            $directory = \str_replace('\\', '/', \substr($file->getPath(), \strlen($root) + 1));

            self::assertSame(
                \str_replace('\\', '/', \substr($namespace[1], \strlen('App\\Modules\\'))),
                $directory,
                \sprintf('%s declares %s, which PSR-4 looks for in modules/ with exactly that case.', $this->relative($file->getPathname()), $namespace[1]),
            );
        }

        // A fresh installation has no classes under modules/, only module.php,
        // so the rule may have nothing to check. What it must not do is read
        // nothing, which is what a wrong path looks like.
        self::assertGreaterThan(0, $read, 'no PHP file under modules/, so this rule reads nothing.');
    }

    /**
     * Twig first, PHP second: the order Bootstrap adds engines is the order
     * they win in, so it is pinned here rather than left to whoever next
     * reorders two lines.
     */
    public function test_twig_is_registered_ahead_of_php(): void
    {
        $source = \file_get_contents($this->basePath('engine/Bootstrap/Bootstrap.php'));
        self::assertIsString($source);

        $twig = \strpos($source, '->addEngine(new TwigTemplateEngine(');
        $php = \strpos($source, '->addEngine(new PhpTemplateEngine(');

        self::assertIsInt($twig, 'Bootstrap no longer registers the Twig engine');
        self::assertIsInt($php, 'Bootstrap no longer registers the PHP engine');
        self::assertLessThan($php, $twig, 'the PHP engine is registered first, so it would win over Twig');
    }

    /**
     * The minimum PHP version is declared twice, and the two must agree.
     *
     * composer.json states it; phpstan.neon is what ENFORCES it. Analysing at
     * the declared minimum is the only thing that reports readonly classes or
     * never-returning arrow functions before they reach a host that cannot
     * parse them -- a parse error, note, not a runtime error, so the file does
     * not have to be reached for the deployment to be broken.
     *
     * The dangerous drift is one-directional and quiet: lower the composer
     * requirement to widen support, forget phpstan, and the analyser goes on
     * cheerfully permitting syntax the newly supported version cannot read.
     * Nothing fails until somebody installs it.
     */
    public function test_the_minimum_php_version_is_the_one_that_is_analysed(): void
    {
        $composer = \file_get_contents($this->basePath('composer.json'));
        self::assertIsString($composer);

        /** @var array{require: array<string, string>} $manifest */
        $manifest = \json_decode($composer, true, 16, \JSON_THROW_ON_ERROR);

        $declared = $manifest['require']['php'];

        self::assertMatchesRegularExpression(
            '/^\^\d+\.\d+$/',
            $declared,
            'require.php is expected to be a caret constraint such as ^8.2.',
        );

        $parts = \explode('.', \substr($declared, 1));
        $expected = \sprintf('%d%02d00', (int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0));

        $config = \file_get_contents($this->basePath('phpstan.neon'));
        self::assertIsString($config);

        self::assertStringContainsString(
            'min: ' . $expected,
            $config,
            \sprintf(
                'composer.json requires PHP %s, so phpstan.neon is expected to analyse from %s. '
                . 'Static analysis is what keeps the requirement honest, so the two move together.',
                $declared,
                $expected,
            ),
        );
    }

    /**
     * A template must not be able to resolve arbitrary services.
     *
     * TemplateView is everything a template can reach. If it could hand out a
     * container, a template could run a query, and "what does this page do"
     * would stop having an answer you can read in the handler.
     */
    public function test_a_template_cannot_reach_the_container(): void
    {
        $methods = \array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(\App\Engine\Template\TemplateView::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        \sort($methods);

        self::assertSame(
            // filter() and call() reach only the helpers modules declared in
            // module.php, by name -- never a class, never a service.
            ['__construct', 'asset', 'call', 'data', 'escaper', 'exists', 'filter', 'get', 'has', 'local', 'render', 'withData'],
            $methods,
            'TemplateView gained a method. Everything a template can reach is listed here on purpose.',
        );
    }

    // ---- the REST surface -------------------------------------------------

    /**
     * "Do not build a completely separate API framework."
     *
     * The strongest possible statement of that is structural: there is no
     * engine/Rest/ and no engine/Api/ directory, because the specified layout
     * does not have one. Everything the REST phase added lives in Http/ and
     * Error/ next to the things it extends, and a module's Api/ directory holds
     * the handlers -- which is the only place the word Api appears.
     */
    public function test_there_is_no_separate_api_framework(): void
    {
        foreach (['engine/Rest', 'engine/Api', 'engine/Resource', 'engine/Serializer'] as $directory) {
            self::assertDirectoryDoesNotExist(
                $this->basePath($directory),
                $directory . '/ exists. REST uses the same kernel, router and dispatcher as everything else.',
            );
        }
    }

    /**
     * No base class for an API handler, and nothing an API handler must
     * implement.
     *
     * ApiResponse is six static factories; the moment it gains a constructor or
     * a protected method it has become something to extend, and the next thing
     * after that is an ApiController.
     */
    public function test_the_api_conveniences_are_not_something_to_inherit(): void
    {
        foreach ([
            \App\Engine\Http\ApiResponse::class,
            \App\Engine\Http\Negotiator::class,
            \App\Engine\Http\MediaType::class,
            \App\Engine\Error\ErrorDocument::class,
        ] as $class) {
            $reflection = new \ReflectionClass($class);

            self::assertTrue($reflection->isFinal(), $class . ' is not final.');

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PROTECTED) as $method) {
                self::fail(\sprintf('%s::%s() is protected, which only matters to a subclass.', $class, $method->getName()));
            }
        }

        // Nothing for a handler to extend or implement means every one of them
        // is a plain class that happens to return a Response.
        self::assertSame(
            [],
            (new \ReflectionClass(\App\Engine\Http\ApiResponse::class))->getInterfaceNames(),
        );
    }

    /**
     * The envelope does not know what a Page is.
     *
     * ApiResponse::page() would be the obvious convenience and would put the
     * data layer inside the HTTP layer. $page->meta() at the call site costs
     * eleven characters and keeps transport ignorant of storage.
     */
    public function test_the_http_layer_still_knows_nothing_about_storage(): void
    {
        foreach ($this->engineFiles() as $path) {
            if (!\str_contains($this->relative($path), 'engine/Http/')) {
                continue;
            }

            // Comments stripped: ApiResponse's docblock explains at length why
            // it does not take a Page, and prose about a rule should not trip
            // the rule.
            $code = $this->codeWithoutComments($path);

            foreach (['App\\Engine\\Data', 'App\\Engine\\Database', 'App\\Engine\\Model', 'App\\Engine\\Schema'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    \sprintf('%s references %s. HTTP carries answers; it does not know where they came from.', $this->relative($path), $forbidden),
                );
            }
        }
    }

    /**
     * One error shape, produced in one place.
     *
     * Before ErrorDocument existed, a handler's validation failure and the
     * kernel's uncaught exception were built by two pieces of code that agreed
     * on a shape by coincidence. A second literal 'error' => [...] anywhere in
     * the engine is how that would come back.
     */
    public function test_only_the_error_document_builds_an_error_body(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_ends_with($relative, 'ErrorDocument.php')) {
                continue;
            }

            self::assertStringNotContainsString(
                "'error' => [",
                $this->codeWithoutComments($path),
                \sprintf('%s builds an error body by hand. ErrorDocument is the one shape.', $relative),
            );
        }
    }

    // ---- the contract with the web server ---------------------------------

    /**
     * The document root holds the front controller and assets, and nothing else.
     *
     * Everything in public/ can be requested by URL, whatever routes exist. A
     * second PHP file here is executed by the web server directly -- outside
     * routing, authentication, CSRF and rate limits -- and anything else is
     * handed out as it is. So the directory's contents are a closed list.
     */
    public function test_the_document_root_holds_only_the_front_controller_and_assets(): void
    {
        $public = $this->basePath('public');

        $entries = \array_values(\array_diff(\scandir($public) ?: [], ['.', '..']));
        \sort($entries);

        self::assertSame(['.htaccess', 'assets', 'index.php'], $entries, 'public/ is the document root: only index.php, .htaccess and assets/ belong in it.');

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($public . \DIRECTORY_SEPARATOR . 'assets', \FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            self::assertDoesNotMatchRegularExpression(
                '/\.(php\d?|phtml|phar|inc)$/i',
                $file->getFilename(),
                \sprintf('%s is PHP inside the document root, which the web server would execute directly.', $this->relative($file->getPathname())),
            );
        }
    }

    /**
     * Served as a directory -- XAMPP at http://localhost/framework -- the project
     * forwards every request into public/, and refuses everything without
     * mod_rewrite rather than serving the source.
     *
     * Forwarded without exception: a list of names to deny is a list somebody
     * forgets to extend. And the redirect Apache adds to a real directory is
     * switched off everywhere below the project, or /engine answers 301 where
     * /nope answers 404.
     */
    public function test_the_project_directory_forwards_everything_into_public(): void
    {
        $htaccess = \file_get_contents($this->basePath('.htaccess'));
        self::assertIsString($htaccess);

        $rules = \preg_match_all('/^\s*RewriteRule\s+(.+)$/m', $htaccess, $matches);

        self::assertSame(1, $rules, 'the project .htaccess forwards; any other rule belongs in public/.htaccess');
        self::assertSame('^(.*)$ public/$1 [L]', \trim($matches[1][0]));
        self::assertMatchesRegularExpression('~<IfModule !mod_rewrite\.c>\s*Require all denied\s*</IfModule>~', $htaccess, 'without mod_rewrite the project directory must refuse everything');
        self::assertMatchesRegularExpression(
            "~<If \"! -f '%\\{REQUEST_FILENAME\\}/public/index\\.php'\">\\s*DirectorySlash Off\\s*</If>~",
            $htaccess,
            'DirectorySlash must be off below the project directory, and only there',
        );
    }

    /** public/.htaccess routes what is not a file to the front controller, and passes Authorization on. */
    public function test_the_document_root_routes_to_the_front_controller(): void
    {
        $htaccess = \file_get_contents($this->basePath('public/.htaccess'));
        self::assertIsString($htaccess);

        self::assertStringContainsString('RewriteRule ^ index.php [L]', $htaccess);
        self::assertStringContainsString('[E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]', $htaccess, 'without it a bearer token never reaches PHP under Apache');
        self::assertStringContainsString('\.(?!well-known/) - [F,L]', $htaccess, 'dotfiles in public/, .htaccess included, must be refused');
    }

    /**
     * `composer serve` and nginx:make serve public/ too.
     *
     * Three ways to serve one application, and the one that differs is the one
     * nobody tests by hand: the development router refuses to run with another
     * document root, and the generated server block roots at public/.
     */
    public function test_the_development_server_and_nginx_serve_only_public(): void
    {
        $composer = \file_get_contents($this->basePath('composer.json'));
        $router = \file_get_contents($this->basePath('server'));

        self::assertIsString($composer);
        self::assertIsString($router);

        self::assertStringContainsString('php -S 127.0.0.1:8080 -t public server', $composer);
        self::assertStringContainsString("\$_SERVER['DOCUMENT_ROOT']", $router, 'the router must check it was started with -t public');
        self::assertStringContainsString("require \$public . '/index.php';", $router);

        $nginx = \App\Engine\Cli\Commands\NginxMakeCommand::serverBlock('_', '/srv/app', '80', 'unix:/run/php/php-fpm.sock');

        self::assertStringContainsString('root /srv/app/public;', $nginx);
        self::assertStringNotContainsString('root /srv/app;', $nginx);
    }

    /** A file the framework writes, or keeps, must not be inside the document root. */
    private function assertOutsideTheDocumentRoot(string $path, string $what): void
    {
        $public = \rtrim(\str_replace('\\', '/', $this->basePath('public')), '/') . '/';

        self::assertStringStartsNotWith(
            \strtolower($public),
            \strtolower(\str_replace('\\', '/', $path)) . '/',
            \sprintf('%s is inside public/, where any URL can reach it.', $what),
        );
    }

    /**
     * The web server and the asset manager have to agree about what is public.
     *
     * This rule exists because the two disagreed: .htaccess denied every *.json
     * anywhere, while MimeTypes listed .json as servable -- so an import map or
     * an i18n bundle would have been refused by Apache before a single line of
     * framework code ran, and the failure would have looked like a permissions
     * problem rather than a policy. A blanket deny by extension is what causes
     * that, so the rule is that the deny list names files, not extensions.
     */
    public function test_the_web_server_does_not_blanket_deny_a_servable_extension(): void
    {
        $contents = \file_get_contents($this->basePath('.htaccess')) . \file_get_contents($this->basePath('public/.htaccess'));

        \preg_match_all('/FilesMatch\s+"([^"]+)"/', $contents, $matches);

        foreach ($matches[1] as $pattern) {
            // A pattern that begins by matching a dot is matching an extension
            // and nothing else, wherever the file happens to be.
            self::assertStringStartsNotWith(
                '\\.',
                $pattern,
                \sprintf(
                    '.htaccess denies "%s", which is a blanket deny by extension. That refuses published assets '
                    . 'of that type before any framework check runs. Deny metadata by name instead.',
                    $pattern,
                ),
            );
        }

        // The two the asset manager is most likely to publish, and the two a
        // blanket extension deny would have caught.
        self::assertTrue(\App\Engine\Asset\MimeTypes::isServable('json'));
        self::assertTrue(\App\Engine\Asset\MimeTypes::isServable('map'));
    }

    // ---- the console ------------------------------------------------------

    /**
     * The console layer holds infrastructure only.
     *
     * Commands/ is the exception in the list below: those are the framework's
     * own commands, and they are here rather than in a module because there is
     * no module the framework could put them in. They are still ordinary
     * classes registered through the ordinary collector.
     */
    public function test_the_console_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/Cli/Command.php',
            'engine/Cli/CommandArgument.php',
            'engine/Cli/CommandCollector.php',
            'engine/Cli/CommandDispatcher.php',
            'engine/Cli/CommandOption.php',
            'engine/Cli/CommandRegistry.php',
            'engine/Cli/ConsoleException.php',
            'engine/Cli/ConsoleKernel.php',
            'engine/Cli/CoreCommands.php',
            'engine/Cli/Input.php',
            'engine/Cli/Output.php',
            'engine/Cli/Commands/AboutCommand.php',
            'engine/Cli/Commands/AssetListCommand.php',
            'engine/Cli/Commands/AuthAccessCommand.php',
            'engine/Cli/Commands/AuthHashCommand.php',
            'engine/Cli/Commands/CacheClearCommand.php',
            'engine/Cli/Commands/CacheWarmCommand.php',
            'engine/Cli/Commands/ConfigCacheCommand.php',
            'engine/Cli/Commands/ConfigListCommand.php',
            'engine/Cli/Commands/DbSeedCommand.php',
            'engine/Cli/Commands/DownCommand.php',
            'engine/Cli/Commands/FrameworkManifestCommand.php',
            'engine/Cli/Commands/FrameworkRollbackCommand.php',
            'engine/Cli/Commands/FrameworkUpdateCommand.php',
            'engine/Cli/Commands/HelpCommand.php',
            'engine/Cli/Commands/LogStatusCommand.php',
            'engine/Cli/Commands/McpListCommand.php',
            'engine/Cli/Commands/McpStdioCommand.php',
            'engine/Cli/Commands/MigrateCommand.php',
            'engine/Cli/Commands/MigrateRollbackCommand.php',
            'engine/Cli/Commands/MigrateStatusCommand.php',
            'engine/Cli/Commands/ModuleListCommand.php',
            'engine/Cli/Commands/ModuleMakeCommand.php',
            'engine/Cli/Commands/NginxMakeCommand.php',
            'engine/Cli/Commands/OpenApiAuthCommand.php',
            'engine/Cli/Commands/OpenApiDocsCommand.php',
            'engine/Cli/Commands/OpenApiExportCommand.php',
            'engine/Cli/Commands/QueueFailedCommand.php',
            'engine/Cli/Commands/QueueStatusCommand.php',
            'engine/Cli/Commands/QueueWorkCommand.php',
            'engine/Cli/Commands/RouteListCommand.php',
            'engine/Cli/Commands/ScheduleListCommand.php',
            'engine/Cli/Commands/ScheduleRunCommand.php',
            'engine/Cli/Commands/ScheduleUnlockCommand.php',
            'engine/Cli/Commands/SecurityCheckCommand.php',
            'engine/Cli/Commands/SecurityKeyCommand.php',
            'engine/Cli/Commands/SessionGcCommand.php',
            'engine/Cli/Commands/SystemCronInstallCommand.php',
            'engine/Cli/Commands/SystemCronListCommand.php',
            'engine/Cli/Commands/SystemCronRemoveCommand.php',
            'engine/Cli/Commands/SystemInfoCommand.php',
            'engine/Cli/Commands/SystemServiceRestartCommand.php',
            'engine/Cli/Commands/SystemServiceStatusCommand.php',
            'engine/Cli/Commands/SystemSystemdGenerateCommand.php',
            'engine/Cli/Commands/SystemSystemdInstallCommand.php',
            'engine/Cli/Commands/SystemSystemdRemoveCommand.php',
            'engine/Cli/Commands/TemplateListCommand.php',
            'engine/Cli/Commands/UpCommand.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Cli/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * There is nothing to extend and nothing to implement.
     *
     * This is the whole of "do not create an Artisan clone", stated in the one
     * form that cannot drift. A Command base class is how a command stops being
     * an ordinary object: it arrives with $this->argument(), then $this->output,
     * then $this->info(), and then a module's command can only be written one
     * way. Here a command handler is whatever a route handler is.
     */
    public function test_there_is_nothing_in_the_console_for_a_command_to_extend(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Cli/')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            self::assertStringNotContainsString(
                'abstract class',
                $code,
                \sprintf('%s declares an abstract class. A command must not have a base class.', $relative),
            );

            self::assertStringNotContainsString(
                'interface ',
                $code,
                \sprintf(
                    '%s declares an interface. A command handler is a callable resolved through the container, '
                    . 'exactly as a route handler is; an interface would make it a second kind of thing.',
                    $relative,
                ),
            );

            // Tokens, not text. A substring scan fires on the variable name
            // $unprotected and on the word "protected" inside a message, and a
            // rule that cries wolf gets worked around rather than obeyed --
            // usually by renaming the innocent thing, which leaves the rule
            // looking correct and the next author puzzled.
            self::assertNotContains(
                \T_PROTECTED,
                \array_map(
                    static fn(array|string $token): int|string => \is_array($token) ? $token[0] : $token,
                    \token_get_all((string) \file_get_contents($path)),
                ),
                \sprintf('%s has a protected member, which only a subclass could want.', $relative),
            );
        }
    }

    /**
     * A command is not a request handler.
     *
     * The console shares the bootstrap, the container, the modules and the
     * hooks -- everything except HTTP. If Request or Response appeared here it
     * would mean a command was being answered as though it were a web request,
     * and the two contexts would start having to agree about things they have
     * no reason to agree about.
     */
    public function test_the_console_does_not_reach_for_http(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Cli/')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            foreach (['App\Engine\Http', 'App\Engine\Core\HttpKernel'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    \sprintf('%s references %s. A command is not a request.', $relative, $forbidden),
                );
            }
        }
    }

    /**
     * The console generates one file, and only that one.
     *
     * make:controller and friends are how a framework stops being a library you
     * call and becomes a thing you live inside: the generated file has a shape
     * the framework then quietly depends on, and the shape is undocumented
     * because the generator is the documentation. Every command here answers a
     * question instead, except module:make, which writes the module.php that
     * modules.md documents line for line and nothing beside it.
     */
    public function test_no_command_writes_source_code(): void
    {
        $commands = [
            'about',
            'help',
            'module:list',
            // The one generator: module.php, the documented skeleton, plus only the --with parts.
            'module:make',
            'nginx:make',
            'queue:failed',
            'queue:status',
            'queue:work',
            'route:list',
            // Writes a JSON description of the routes, never code.
            'openapi:export',
            // Write the docs switches to system/Runtime, never code.
            'openapi:docs',
            'openapi:auth',
            'schedule:list',
            'schedule:run',
            'schedule:unlock',
            'security:check',
            'security:key',
            'auth:access',
            'auth:hash',
            'session:gc',
            'asset:list',
            'template:list',
            'log:status',
            'cache:clear',
            'cache:warm',
            'config:cache',
            'config:list',
            // Operating-system administration. None of them writes code, and
            // none runs an arbitrary command: there is deliberately no system:exec.
            'system:info',
            'system:service:status',
            'system:service:restart',
            'system:cron:list',
            'system:cron:install',
            'system:cron:remove',
            // Writes unit files that run the framework's own commands, never code.
            'system:systemd:generate',
            'system:systemd:install',
            'system:systemd:remove',
            // Replace the framework's own files with a release's, never generate any.
            'framework:update',
            'framework:rollback',
            'framework:manifest',
            // Writes one state file under system/Runtime.
            'down',
            'up',
            // An interface to capabilities modules register, not a way to run code.
            'mcp:list',
            'mcp:stdio',
            // Run migrations a module wrote by hand. There is no make:migration:
            // the name rule is documented, and checked when the file is read.
            'migrate',
            'migrate:status',
            'migrate:rollback',
            // Run seeders a module wrote by hand; there is no make:seeder.
            'db:seed',
        ];

        $registry = new \App\Engine\Cli\CommandRegistry();
        \App\Engine\Cli\CoreCommands::register($registry);

        $names = $registry->names();
        \sort($names);
        \sort($commands);

        self::assertSame(
            $commands,
            $names,
            'The framework\'s own command list changed. Adding one is a deliberate act; '
            . 'adding a generator is a different kind of framework.',
        );

        foreach ($names as $name) {
            self::assertStringStartsNotWith('make:', $name);
            self::assertStringStartsNotWith('generate:', $name);
        }
    }

    // ---- errors -----------------------------------------------------------

    /**
     * An exception that quotes a message it did not write must withhold it.
     *
     * This is the tripwire for the one mistake in this area that costs
     * something real. A factory taking $previous and interpolating
     * $previous->getMessage() produces a message that may contain a DSN, a
     * credential or a path -- PDO quotes the DSN back verbatim on a connection
     * failure -- and nothing downstream can tell that apart from a sentence the
     * framework wrote itself.
     *
     * The check is per file rather than per factory, which is coarse in the
     * right direction: a class with one wrapping factory and one safe one still
     * has to say so, and saying so is one call.
     */
    public function test_an_exception_that_quotes_another_withholds_its_message(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_ends_with($relative, 'Exception.php')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            if (!\str_contains($code, 'previous->getMessage()')) {
                continue;
            }

            self::assertStringContainsString(
                'withheld()',
                $code,
                \sprintf(
                    '%s interpolates another exception\'s message but never calls withheld(). That message came '
                    . 'from outside this framework and may carry a credential, so it must not be repeated.',
                    $relative,
                ),
            );
        }
    }

    /**
     * Error rendering and error recording stay independent.
     *
     * The specification asks for logging to be independent of error rendering,
     * and what keeps that true is that this layer has nowhere to write. Every
     * handled error fires error.reported and a logger is a listener on it. The
     * moment a file handle or an error_log() call appears here, rendering and
     * recording are one thing again, and changing either means touching both.
     *
     * Writing to STDERR is not recording -- that is the console rendering, and
     * it is the console's only way to reach a person.
     */
    public function test_the_error_layer_records_nothing(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Error/')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            foreach (['error_log(', 'syslog(', 'openlog(', 'file_put_contents(', 'fopen('] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    \sprintf(
                        '%s calls %s. Errors are announced on the error.reported hook and written by a '
                        . 'listener; this layer renders and does not record.',
                        $relative,
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * The page of last resort asks the application for nothing.
     *
     * An error page that links a stylesheet renders unstyled, or not at all,
     * exactly when it is needed -- which is when something is broken, quite
     * possibly the asset pipeline itself. An application is welcome to supply
     * its own template; the fallback has to survive without one.
     */
    public function test_the_built_in_error_page_has_no_dependencies(): void
    {
        $code = $this->codeWithoutComments($this->basePath('engine/Error/ErrorPage.php'));

        foreach (['AssetManager', 'Router', 'Config', 'Container'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                \sprintf('ErrorPage references %s. The fallback page must render when nothing else works.', $forbidden),
            );
        }

        $html = (new ErrorPage())->render(ErrorDocument::of(500));

        self::assertStringNotContainsString('<link', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('http', $html);
    }

    /**
     * Every context an error can be rendered into is one of the three named.
     *
     * The specification lists browser, REST and console, and production is a
     * fourth item on a different axis: it decides how much is said, not to
     * whom. A fourth case here would mean that distinction had collapsed.
     */
    public function test_the_error_audiences_are_exactly_the_three_the_specification_names(): void
    {
        self::assertSame(
            ['api', 'browser', 'console'],
            \array_values(\array_map(
                static fn(ErrorContext $context): string => $context->value,
                (static function (): array {
                    $cases = ErrorContext::cases();
                    \usort($cases, static fn(ErrorContext $a, ErrorContext $b): int => $a->value <=> $b->value);

                    return $cases;
                })(),
            )),
        );
    }

    // ---- logging -----------------------------------------------------------

    /**
     * The logging layer holds infrastructure only.
     */
    public function test_the_logging_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/Logging/Context.php',
            'engine/Logging/ErrorLog.php',
            'engine/Logging/Level.php',
            'engine/Logging/LineFormatter.php',
            'engine/Logging/LogManager.php',
            'engine/Logging/LogRecord.php',
            'engine/Logging/LogTableMigration.php',
            'engine/Logging/LogWriter.php',
            'engine/Logging/Logger.php',
            'engine/Logging/LoggingException.php',
            'engine/Logging/McpLog.php',
            'engine/Logging/ScheduleLog.php',
            'engine/Logging/SystemAuditLog.php',
            'engine/Logging/Writers/DatabaseWriter.php',
            'engine/Logging/Writers/FileWriter.php',
            'engine/Logging/Writers/StreamWriter.php',
            'engine/Logging/Writers/SyslogWriter.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Logging/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * The dependency runs one way: logging knows about errors, never the
     * reverse.
     *
     * "Logging must be independent from error rendering" is checkable, and this
     * is the check. The moment engine/Error mentions a logger, the two are one
     * subsystem again: changing where records go means touching the code that
     * renders a page, and an application cannot have one without the other.
     * What connects them instead is a hook, and a hook has no compile-time
     * direction at all.
     */
    public function test_error_rendering_knows_nothing_about_logging(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Error/')) {
                continue;
            }

            foreach (['Logging', 'Logger', 'LogManager', 'log('] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $this->codeWithoutComments($path),
                    \sprintf(
                        '%s refers to %s. Errors are announced on the error.reported hook; '
                        . 'a logger listens to it and is not a dependency of this layer.',
                        $relative,
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * Only one file in the logging layer knows what an error is.
     *
     * ErrorLog is the bridge and is meant to be deletable. If the rest of the
     * layer started reaching for HttpException or ErrorContext, deleting it
     * would stop being a one-line decision.
     */
    public function test_the_bridge_is_the_only_part_that_knows_about_errors(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Logging/') || \str_ends_with($relative, 'ErrorLog.php')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            foreach (['ErrorContext', 'HttpException', 'ErrorDocument'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    \sprintf('%s refers to %s. Only ErrorLog bridges the two.', $relative, $forbidden),
                );
            }
        }
    }

    /**
     * Nothing but the manager decides what happens when a writer fails.
     *
     * "Logging must not cause application failure" is enforced in exactly one
     * place. A writer that caught its own exceptions would look safer and be
     * worse: the manager could no longer tell that it had stopped working, and
     * log:status would report a healthy writer that writes nothing.
     */
    public function test_only_the_manager_swallows_a_writer_failure(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Logging/Writers/')) {
                continue;
            }

            self::assertStringNotContainsString(
                'catch (',
                $this->codeWithoutComments($path),
                \sprintf(
                    '%s catches its own failure. A writer reports; the manager decides, retires it '
                    . 'and records why, which is what keeps log:status honest.',
                    $relative,
                ),
            );
        }
    }

    /**
     * A log file is never reachable over HTTP.
     *
     * This is the sharpest edge the logging phase adds. The default file writer
     * puts records under system/Logs, and those records contain exactly what
     * the error layer spent a phase keeping out of responses: DSNs, messages,
     * paths, request context. One misconfigured directory and the thing that
     * exists to record an incident becomes one.
     *
     * system/ is outside public/, so this is a regression guard on both halves
     * at once -- the directory the writer chooses and where the document root
     * is.
     */
    public function test_the_log_directory_is_outside_the_document_root(): void
    {
        $directory = 'system' . \DIRECTORY_SEPARATOR . 'Logs';

        self::assertDirectoryExists(
            $this->basePath($directory),
            'the default file writer targets this directory, so it has to be the one kept out of public/',
        );

        $this->assertOutsideTheDocumentRoot($this->basePath($directory), 'The log directory');
    }

    /**
     * The level set is the eight RFC 5424 severities and nothing else.
     *
     * The specification's list also contains "log", which is the name of the
     * method that takes a level rather than a level itself. A ninth case would
     * have no place in the ordering, and "is this record severe enough to
     * write" would stop having an answer.
     */
    public function test_the_levels_are_the_eight_standard_severities(): void
    {
        self::assertSame(
            ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
            Level::names(),
        );

        // The backing values are the RFC 5424 codes, which is what a log line
        // and every parser of one expects.
        self::assertSame([0, 1, 2, 3, 4, 5, 6, 7], \array_map(
            static fn(Level $level): int => $level->value,
            Level::cases(),
        ));

        // And what goes to syslog() is the platform\'s own constant, which is
        // not the same number everywhere. On Windows there is no syslog, so PHP
        // collapses the eight onto the event log's three types -- passing the
        // backing value there would file every record under the wrong severity
        // on the one platform where nothing would look broken.
        foreach ([
            'emergency' => \LOG_EMERG,
            'alert' => \LOG_ALERT,
            'critical' => \LOG_CRIT,
            'error' => \LOG_ERR,
            'warning' => \LOG_WARNING,
            'notice' => \LOG_NOTICE,
            'info' => \LOG_INFO,
            'debug' => \LOG_DEBUG,
        ] as $label => $priority) {
            self::assertSame(
                $priority,
                Level::fromName($label)->priority(),
                \sprintf('%s must reach syslog() as this platform\'s own priority', $label),
            );
        }
    }
    // ---- the cache -------------------------------------------------------------

    /**
     * The cache layer holds infrastructure only.
     */
    public function test_the_cache_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/Cache/AtomicStore.php',
            'engine/Cache/Cache.php',
            'engine/Cache/CacheEntry.php',
            'engine/Cache/CacheException.php',
            'engine/Cache/CacheStore.php',
            'engine/Cache/CacheTableMigration.php',
            'engine/Cache/Lock.php',
            'engine/Cache/PrunableStore.php',
            'engine/Cache/Stores/ArrayStore.php',
            'engine/Cache/Stores/DatabaseStore.php',
            'engine/Cache/Stores/FileStore.php',
            'engine/Cache/Stores/NullStore.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Cache/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * There is no static cache API.
     *
     * The specification bans one by name, next to DB::table() and User::find(),
     * and the ban is worth more than the convenience it costs. Cache::get()
     * would mean a class that caches has a dependency its constructor does not
     * mention, cannot be handed a store that keeps nothing, and cannot be
     * tested without whatever the static resolves to at that moment.
     *
     * Constants are not an API; a static method is. So this checks methods.
     * The exception class is exempt, because a named constructor on a throwable
     * is how every exception in this framework is built and has nothing to do
     * with reaching a cache without being handed one.
     */
    public function test_the_cache_offers_nothing_statically(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Cache/') || $relative === 'engine/Cache/CacheException.php') {
                continue;
            }

            $class = 'App\\Engine\\' . \str_replace(
                ['engine/', '/', '.php'],
                ['', '\\', ''],
                $relative,
            );

            if (!\class_exists($class)) {
                continue;
            }

            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                self::assertFalse(
                    $method->isStatic(),
                    \sprintf(
                        '%s::%s() is static. A cache is injected -- that is the whole difference between '
                        . 'this and the facade the specification refuses.',
                        $relative,
                        $method->getName(),
                    ),
                );
            }
        }
    }

    /**
     * Every store is held to the same contract.
     *
     * A cache backend is easy to write and easy to write subtly differently: a
     * stored null that reads as a miss, an expired entry that reads as a value,
     * a namespace flush that takes somebody else's keys with it. The
     * conformance test is where those are pinned, and this makes adding a store
     * without running it a failing test rather than a discovery in production.
     *
     * NullStore is the deliberate exception, and the only one: it conforms to
     * the interface and keeps nothing, which is exactly what it is for.
     */
    public function test_every_store_runs_the_conformance_suite(): void
    {
        $stores = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Cache/Stores/')) {
                $stores[] = \basename($relative, '.php');
            }
        }

        // NullStore keeps nothing, so the contract about keeping things cannot
        // be asked of it. Everything else has to be in the provider.
        $exercised = ['NullStore'];

        foreach (StoreConformanceTest::stores() as $make) {
            $exercised[] = (new \ReflectionClass($make[0]()))->getShortName();
        }

        // Building a database store made its table on each server; the suite's
        // own cleanup drops them. And one store runs on several databases.
        StoreConformanceTest::tearDownAfterClass();
        $exercised = \array_values(\array_unique($exercised));

        \sort($stores);
        \sort($exercised);

        self::assertSame(
            $stores,
            $exercised,
            'a cache store exists that the conformance test never runs against',
        );
    }

    /**
     * The cache is below everything that uses it.
     *
     * It is handed to the asset manager, the template manager and to modules,
     * and it must not know that any of them exist -- otherwise "which store"
     * stops being a configuration decision and becomes a dependency graph.
     *
     * The database is below it, not above: the database store keeps entries in
     * a table, and its table is a migration. The migration interface is all it
     * takes of the migration layer, which knows modules.
     */
    public function test_the_cache_layer_depends_on_nothing_above_it(): void
    {
        $allowed = [
            'App\\Engine\\Cache',
            'App\\Engine\\Support',
            'App\\Engine\\Error\\FrameworkException',
            'App\\Engine\\Database',
            'App\\Engine\\Migration\\Reversible',
        ];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Cache/')) {
                continue;
            }

            \preg_match_all('/App\\\\Engine\\\\[A-Za-z\\\\]+/', $this->codeWithoutComments($path), $matches);

            foreach ($matches[0] as $reference) {
                $permitted = false;

                foreach ($allowed as $prefix) {
                    if (\str_starts_with($reference, $prefix)) {
                        $permitted = true;
                    }
                }

                self::assertTrue($permitted, \sprintf(
                    '%s refers to %s, which is a layer that should be asking the cache rather than the other way round.',
                    $relative,
                    $reference,
                ));
            }
        }
    }

    // ---- the queue ---------------------------------------------------------------

    /**
     * The queue layer holds infrastructure only.
     */
    public function test_the_queue_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/Queue/Backoff.php',
            'engine/Queue/Job.php',
            'engine/Queue/JobOutcome.php',
            'engine/Queue/JobRunner.php',
            'engine/Queue/Queue.php',
            'engine/Queue/QueueException.php',
            'engine/Queue/QueueStore.php',
            'engine/Queue/QueueTableMigration.php',
            'engine/Queue/QueuedJob.php',
            'engine/Queue/Stores/DatabaseStore.php',
            'engine/Queue/Stores/FileStore.php',
            'engine/Queue/Stores/MemoryStore.php',
            'engine/Queue/Stores/SyncStore.php',
            'engine/Queue/Worker.php',
            'engine/Queue/WorkerOptions.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Queue/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * No job ships with the framework.
     *
     * Jobs are module-owned, which is the same rule that keeps business models,
     * schemas and repositories out of engine/. A framework that ships a job has
     * decided what an application's background work is, and the moment one
     * exists the next is easier to justify.
     */
    public function test_no_job_lives_in_the_engine(): void
    {
        // Delivery, not work: it sends a message the application composed and
        // queued itself, and decides nothing about what background work exists.
        $plumbing = ['engine/Mail/SendMessageJob.php'];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_starts_with($relative, 'engine/Queue/') || \in_array($relative, $plumbing, true)) {
                continue;
            }

            self::assertStringNotContainsString(
                'implements Job',
                $this->codeWithoutComments($path),
                \sprintf('%s declares a job. Jobs belong to the module that owns the work.', $relative),
            );
        }
    }

    /**
     * A job runs where there is no request.
     *
     * The whole point of queueing work is that it happens somewhere else --
     * usually in a process started from a cron line with no HTTP anywhere near
     * it. A queue that reached for Request or Response would be a queue that
     * only works when a browser is waiting, which is the opposite of the
     * requirement.
     */
    public function test_the_queue_layer_cannot_see_http(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Queue/')) {
                continue;
            }

            foreach (['App\\Engine\\Http', 'Request', 'Response', '$_SERVER', '$_POST'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $this->codeWithoutComments($path),
                    \sprintf('%s refers to %s. A worker runs with no request in sight.', $relative, $forbidden),
                );
            }
        }
    }

    /**
     * Work is dispatched through an injected queue, never a static one.
     *
     * Same argument as the cache, which the specification makes for us: a class
     * that queues work has a dependency, and a dependency belongs in a
     * constructor where a test can replace it with a memory store and read what
     * was queued. Queue::push() as a static would take that away.
     *
     * Only the class an application touches is held to this. Named constructors
     * elsewhere in the layer -- WorkerOptions::once(), Backoff::fixed() -- are
     * ways of building a value, not ways of reaching a queue.
     */
    public function test_the_queue_is_never_reached_statically(): void
    {
        foreach ((new \ReflectionClass(\App\Engine\Queue\Queue::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertFalse(
                $method->isStatic(),
                \sprintf(
                    'Queue::%s() is static. Dispatching work is done through an injected Queue.',
                    $method->getName(),
                ),
            );
        }
    }

    /**
     * Every store is held to the same contract.
     *
     * More important here than it is for the cache. The ways queue backends
     * quietly differ -- handing one job to two workers, reserving a delayed job
     * early, losing an abandoned reservation -- all look fine in development
     * and cost money in production, so a store that the conformance suite never
     * runs against is a failing test rather than a discovery later.
     *
     * SyncStore is the deliberate exception: it keeps nothing, because it runs
     * the job instead of storing it.
     */
    public function test_every_queue_store_runs_the_conformance_suite(): void
    {
        $stores = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Queue/Stores/')) {
                $stores[] = \basename($relative, '.php');
            }
        }

        $exercised = ['SyncStore'];

        foreach (QueueStoreConformanceTest::stores() as $make) {
            $exercised[] = (new \ReflectionClass($make[0]()))->getShortName();
        }

        // Building a database store made its table on each server; the suite's
        // own cleanup drops them. And one store runs on several databases.
        QueueStoreConformanceTest::tearDownAfterClass();
        $exercised = \array_values(\array_unique($exercised));

        \sort($stores);
        \sort($exercised);

        self::assertSame(
            $stores,
            $exercised,
            'a queue store exists that the conformance test never runs against',
        );
    }

    /**
     * Queued work is not readable over HTTP.
     *
     * A job file holds a serialised object -- an invoice id, an email address,
     * whatever the work needs -- and it is written into a directory the web
     * server can see. Worse, it is a payload that something unserialises, so a
     * directory anybody can write to is a directory that can hand a worker an
     * object of its choosing.
     */
    public function test_the_queue_directory_is_outside_the_document_root(): void
    {
        $this->assertOutsideTheDocumentRoot($this->basePath('system/Queue'), 'The file queue store');
    }

    // ---- security -----------------------------------------------------------------

    /**
     * The security layer holds infrastructure only.
     */
    public function test_the_security_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/Security/Counter.php',
            'engine/Security/CounterStore.php',
            'engine/Security/Counters/FileStore.php',
            'engine/Security/Counters/MemoryStore.php',
            'engine/Security/Csrf.php',
            'engine/Security/Encrypter.php',
            'engine/Security/Guard.php',
            'engine/Security/RateLimit.php',
            'engine/Security/RateLimiter.php',
            'engine/Security/RequestLimits.php',
            'engine/Security/Secret.php',
            'engine/Security/SecurityException.php',
            'engine/Security/SecurityHeaders.php',
            'engine/Security/SignedUrl.php',
            'engine/Security/Signer.php',
            'engine/Security/UploadPolicy.php',
            'engine/Security/Webhook.php',
            'engine/Security/Webhooks.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Security/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * The two classes that compare secrets still reach for hash_equals.
     *
     * == and === are the obvious thing to write here and are wrong in a way
     * nothing demonstrates in development: they return as soon as two bytes
     * differ, so the time they take measures how many leading bytes matched,
     * and over enough requests that is a way to learn a token one byte at a
     * time.
     *
     * **What this test actually catches is removal, not misuse.** A file-level
     * scan cannot tell which comparison in a class is the one that matters, and
     * a rule that tried -- banning === outright -- would fire on `$at === false`
     * two lines away and be deleted by the first person it inconvenienced. So
     * this is a tripwire for hash_equals disappearing, and the real protection
     * is the rule below: nothing outside Signer computes an HMAC, so there is
     * exactly one comparison in the codebase that has to be right.
     */
    public function test_secrets_are_compared_in_constant_time(): void
    {
        foreach ([
            \App\Engine\Security\Signer::class,
            \App\Engine\Security\Secret::class,
        ] as $class) {
            $path = (new \ReflectionClass($class))->getFileName();
            self::assertIsString($path);

            self::assertTrue(
                $this->callsFunction($path, 'hash_equals'),
                \sprintf(
                    '%s no longer compares in constant time. A === here is a way to forge a '
                    . 'signature one byte at a time.',
                    $this->relative($path),
                ),
            );
        }
    }

    /**
     * Nothing in the engine compares a signature by hand.
     *
     * The companion to the rule above. hash_hmac is the primitive and Signer is
     * the only place it belongs: a second implementation is a second chance to
     * compare the result wrongly, and the one that gets it wrong will be the
     * one written in a hurry to solve an adjacent problem.
     */
    public function test_only_the_signer_computes_a_signature(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_ends_with($relative, 'engine/Security/Signer.php')) {
                continue;
            }

            self::assertFalse(
                $this->callsFunction($path, 'hash_hmac'),
                \sprintf(
                    '%s computes its own HMAC. Signing goes through Security\\Signer, which is also '
                    . 'the only place the comparison is known to be constant time.',
                    $relative,
                ),
            );
        }
    }

    /**
     * Randomness used for security comes from the CSPRNG.
     *
     * rand() and mt_rand() are seeded pseudo-randomness: fast, fine for
     * choosing a sample row, and predictable enough that a token built from one
     * can be guessed by anyone who has seen a few. The distinction is invisible
     * at the call site, which is exactly why it needs a test rather than a
     * convention.
     */
    public function test_security_randomness_is_not_guessable(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Security/')) {
                continue;
            }

            foreach (['rand', 'mt_rand', 'srand', 'mt_srand', 'uniqid', 'shuffle', 'str_shuffle'] as $weak) {
                self::assertFalse(
                    $this->callsFunction($path, $weak),
                    \sprintf(
                        '%s calls %s(). Anything a token is built from comes from random_bytes().',
                        $relative,
                        $weak,
                    ),
                );
            }
        }
    }

    /**
     * A secret never becomes a string by accident.
     *
     * Secret exists so that a key cannot be echoed into a message, dumped into
     * a ticket or serialised into a queue file, and the guarantee is only worth
     * anything while reveal() is the single way out. A second accessor -- a
     * value(), a get(), a toString that returned the real thing -- would make
     * `grep -rn 'reveal()'` stop being a complete list of where secrets are
     * used, which is the property the class is actually for.
     */
    public function test_a_secret_has_exactly_one_way_out(): void
    {
        $reflection = new \ReflectionClass(\App\Engine\Security\Secret::class);
        $revealing = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getReturnType() instanceof \ReflectionNamedType
                && $method->getReturnType()->getName() === 'string'
                && $method->getNumberOfParameters() === 0) {
                $revealing[] = $method->getName();
            }
        }

        \sort($revealing);

        self::assertSame(
            ['__toString', 'jsonSerialize', 'reveal'],
            $revealing,
            'Secret gained another way to produce a string. Only reveal() may return the real value.',
        );
    }

    /**
     * The security layer decides; it does not report.
     *
     * A guard that logged its own refusals would put a remotely-triggerable
     * write into the path an attacker controls -- a thousand bad tokens a
     * second is a thousand log lines a second, which is a way to fill a disk
     * and to bury the entry that mattered. Refusals are HttpExceptions, and the
     * error layer already announces those on error.reported for a logger to
     * hear.
     */
    public function test_the_security_layer_does_not_write_its_own_log(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Security/')) {
                continue;
            }

            foreach (['Logging', 'Logger', 'LogManager', 'error_log'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $this->codeWithoutComments($path),
                    \sprintf(
                        '%s refers to %s. A refusal is an HttpException; the error layer announces it '
                        . 'and a logger listens, which is also what keeps a flood of refusals from '
                        . 'becoming a flood of writes.',
                        $relative,
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * Every counter store is held to the same contract.
     *
     * The cache, queue and schedule-lock suites again. The failure this one
     * guards against is the quietest of the four: a counter store that is not
     * atomic does not throw, does not log and does not look wrong -- it simply
     * lets through rather more requests than the limit says, under exactly the
     * load that made somebody want a limit.
     */
    public function test_every_counter_store_runs_the_conformance_suite(): void
    {
        $stores = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Security/Counters/')) {
                $stores[] = \basename($relative, '.php');
            }
        }

        $exercised = [];

        foreach (CounterConformanceTest::stores() as $make) {
            $exercised[] = (new \ReflectionClass($make[0]()))->getShortName();
        }

        \sort($stores);
        \sort($exercised);

        self::assertSame(
            $stores,
            $exercised,
            'a counter store exists that the conformance test never runs against',
        );
    }

    /**
     * CSRF is opt-out, not opt-in, and the default is in one place.
     *
     * The direction is the whole decision. Opt-in means the route somebody adds
     * in a hurry is unprotected, and that is reliably the one that matters.
     * This pins the default so that flipping it has to be deliberate rather
     * than a plausible-looking change to a condition.
     */
    public function test_csrf_protects_every_unsafe_method_by_default(): void
    {
        $csrf = new \App\Engine\Security\Csrf(new \App\Engine\Security\Signer());

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            self::assertTrue($csrf->protects($method), $method . ' is no longer checked by default.');
        }

        foreach (\App\Engine\Security\Csrf::SAFE_METHODS as $method) {
            self::assertFalse($csrf->protects($method), $method . ' changes nothing and needs no proof.');
        }
    }

    /**
     * The development router is not readable over HTTP.
     *
     * It is a PHP file whose name does not end in .php, so a web server that
     * reached it would not execute it and would hand over the source instead. It
     * stays outside public/, and this checks that it does.
     */
    public function test_the_development_router_is_outside_the_document_root(): void
    {
        self::assertFileExists($this->basePath('server'), 'the development router has moved');
        $this->assertOutsideTheDocumentRoot($this->basePath('server'), 'The development router');
    }

    // ---- the scheduler -----------------------------------------------------------

    /**
     * The scheduler layer holds infrastructure only.
     */
    public function test_the_scheduler_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/Scheduler/CronExpression.php',
            'engine/Scheduler/Locks/FileLock.php',
            'engine/Scheduler/Locks/MemoryLock.php',
            'engine/Scheduler/Schedule.php',
            'engine/Scheduler/ScheduleCollector.php',
            'engine/Scheduler/ScheduleLock.php',
            'engine/Scheduler/ScheduleOutcome.php',
            'engine/Scheduler/ScheduleRegistry.php',
            'engine/Scheduler/ScheduleResult.php',
            'engine/Scheduler/ScheduleTarget.php',
            'engine/Scheduler/Scheduler.php',
            'engine/Scheduler/SchedulerException.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Scheduler/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * No schedule ships with the framework.
     *
     * The same rule that keeps jobs, models and repositories out of engine/,
     * and the one with the sharpest consequence: a framework that ships a
     * schedule has decided that something runs on somebody else's machine at an
     * hour they did not choose. Every schedule in an application is declared by
     * a module, which is a file its author wrote and can delete.
     */
    public function test_no_schedule_is_declared_in_the_engine(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_starts_with($relative, 'engine/Scheduler/')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            foreach (['ScheduleCollector $', '->dailyAt(', '->everyMinute(', '->cron('] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    \sprintf('%s declares scheduled work. When something runs is the application\'s decision.', $relative),
                );
            }
        }
    }

    /**
     * A scheduled task runs where there is no request.
     *
     * The strongest form of the rule the queue already has. Scheduled work
     * happens in a process cron started at 3am with no browser anywhere near
     * it, so a scheduler that reached for a Request would work only when run by
     * hand -- which is exactly the condition under which somebody would test it
     * and conclude it was fine.
     */
    public function test_the_scheduler_layer_cannot_see_http(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Scheduler/')) {
                continue;
            }

            foreach (['App\\Engine\\Http', 'Request', 'Response', '$_SERVER', '$_POST'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $this->codeWithoutComments($path),
                    \sprintf('%s refers to %s. A scheduled task runs with no request in sight.', $relative, $forbidden),
                );
            }
        }
    }

    /**
     * Nothing in the scheduler sleeps, loops on the clock, or shells out.
     *
     * Three temptations, one rule. sleep() and a while-true would turn this
     * into a daemon -- a process to supervise, restart on deployment and watch
     * for having silently died -- when cron already solves that and is already
     * installed. exec() would turn a scheduled command into a second PHP
     * process with its own configuration, its own log and a command line that
     * has to be quoted correctly on two operating systems.
     *
     * The check is a token scan, so a docblock explaining why there is no
     * sleep() does not trip the rule that says there is none.
     */
    public function test_the_scheduler_is_not_a_daemon(): void
    {
        $forbidden = ['sleep', 'usleep', 'exec', 'shell_exec', 'passthru', 'proc_open', 'popen', 'system'];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Scheduler/')) {
                continue;
            }

            foreach ($this->globalFunctionCalls($path) as $call) {
                self::assertNotContains(
                    $call,
                    $forbidden,
                    \sprintf(
                        '%s calls %s(). The scheduler is cron\'s shape: it decides, it runs what is due, '
                        . 'it exits. It does not hold a process open and it does not start one.',
                        $relative,
                        $call,
                    ),
                );
            }
        }
    }

    /**
     * Every lock is held to the same contract.
     *
     * The cache and queue conformance suites again, and here the stakes are at
     * their plainest: a lock implementation that is not atomic, or that forgets
     * to expire, fails in exactly one way -- two copies of the billing run --
     * and it fails at three in the morning on a machine under load, which is
     * the one condition a developer never reproduces.
     */
    public function test_every_schedule_lock_runs_the_conformance_suite(): void
    {
        $locks = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Scheduler/Locks/')) {
                $locks[] = \basename($relative, '.php');
            }
        }

        $exercised = [];

        foreach (LockConformanceTest::locks() as $make) {
            $exercised[] = (new \ReflectionClass($make[0]()))->getShortName();
        }

        \sort($locks);
        \sort($exercised);

        self::assertSame(
            $locks,
            $exercised,
            'a schedule lock exists that the conformance test never runs against',
        );
    }

    /**
     * There is no lock that does not lock.
     *
     * CacheStore has a NullStore and QueueStore has a sync store, because "do
     * not cache" and "run it here" are both things somebody legitimately wants.
     * The equivalent here would be a lock that always says yes, and it is not
     * shipped on purpose: it has exactly one behaviour, two copies of a task
     * running at once, and its existence would make the most dangerous
     * configuration in the scheduler the easiest one to reach for.
     *
     * A schedule that may safely overlap says so with ->allowOverlapping(),
     * where the decision sits next to the task it affects and is visible in
     * `schedule:list`.
     */
    public function test_no_lock_implementation_is_a_no_op(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Scheduler/Locks/')) {
                continue;
            }

            self::assertStringNotContainsString(
                'Null',
                \basename($relative),
                \sprintf('%s looks like a lock that grants everything. See this test for why there is none.', $relative),
            );
        }
    }

    /**
     * Only the bridge in the logging layer knows the scheduler exists.
     *
     * ErrorLog's rule, applied to ScheduleLog. The scheduler announces on
     * schedule.finished and something listens; if the scheduler itself reached
     * for a Logger, "logging is independent" would stop being true the second
     * time it was claimed.
     */
    public function test_the_scheduler_does_not_reach_for_a_logger(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Scheduler/')) {
                continue;
            }

            foreach (['Logging', 'Logger', 'LogManager'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $this->codeWithoutComments($path),
                    \sprintf(
                        '%s refers to %s. Schedules are announced on schedule.finished; '
                        . 'Logging\\ScheduleLog listens to it and is not a dependency of this layer.',
                        $relative,
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * Schedule locks are not readable over HTTP.
     *
     * A lock file names a task, a host and a process id, which is a small but
     * real disclosure of what a machine does at night and when it is busy.
     * Worse is the other direction: a lock directory anybody could write to is
     * a directory where anybody could hold every schedule permanently, which
     * stops the billing run without producing a single error.
     */
    public function test_the_schedule_directory_is_outside_the_document_root(): void
    {
        $this->assertOutsideTheDocumentRoot($this->basePath('system/Schedule'), 'The schedule lock directory');
    }

    // ---- configuration -------------------------------------------------------

    /**
     * The configuration layer holds infrastructure only.
     */
    public function test_the_configuration_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/Config/Config.php',
            'engine/Config/ConfigCache.php',
            'engine/Config/ConfigLoader.php',
            'engine/Config/ConfigurationException.php',
            'engine/Config/DotEnv.php',
            'engine/Config/Env.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Config/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * One file reads the environment.
     *
     * Everything in the environment is text, and turning text into a setting
     * has real edge cases -- "false" is a non-empty string, an empty variable is
     * one somebody meant to fill in. Done in eighteen places, two of them end up
     * disagreeing, and the disagreement is found in production.
     *
     * It also buys the configuration cache its invalidation: because every read
     * goes through one class, that class can record what it was asked, and that
     * record is the fingerprint which notices the environment has moved.
     */
    public function test_only_one_file_reads_the_environment(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if ($relative === 'engine/Config/Env.php') {
                continue;
            }

            self::assertNotContains(
                'getenv',
                $this->globalFunctionCalls($path),
                \sprintf(
                    '%s reads the environment directly. Env is the one place that does, which is what '
                    . 'keeps the conversions consistent and lets the cache know when they have changed.',
                    $relative,
                ),
            );

            // DotEnv is the writer: a .env file fills gaps in $_ENV, which is
            // visible to this process only and costs nothing.
            if ($relative === 'engine/Config/DotEnv.php') {
                continue;
            }

            self::assertStringNotContainsString(
                '$_ENV',
                $this->codeWithoutComments($path),
                \sprintf('%s reaches into $_ENV rather than asking Env.', $relative),
            );
        }
    }

    /**
     * Every variable the engine reads is written down in .env.example.
     *
     * The environment is the one part of the configuration surface with no
     * defaults file to read: config/ shows what an application has chosen, and
     * Bootstrap::defaults() shows what the framework assumes, but the list of
     * variable names exists only inside the calls that read them. Somebody
     * setting up a deployment needs that list, and a list maintained by hand is
     * a list that is wrong within two phases.
     */
    public function test_every_environment_variable_is_documented(): void
    {
        $documented = \file_get_contents($this->basePath('.env.example'));
        self::assertIsString($documented, 'the environment surface has no documentation at all');

        $names = [];

        foreach ($this->engineFiles() as $path) {
            \preg_match_all(
                '/Env::(?:raw|has|string|bool|int|list)\(\s*\'([A-Z][A-Z0-9_]*)\'/',
                $this->codeWithoutComments($path),
                $matches,
            );

            foreach ($matches[1] as $name) {
                $names[$name] = $this->relative($path);
            }
        }

        self::assertNotSame([], $names, 'no environment variable is read anywhere, which cannot be right');

        foreach ($names as $name => $where) {
            self::assertStringContainsString(
                $name,
                $documented,
                \sprintf('%s reads %s, which .env.example does not mention.', $where, $name),
            );
        }
    }

    /**
     * Nothing writes to the environment.
     *
     * putenv() is documented as not thread-safe, this framework is developed on
     * a ZTS build, and a process that mutates its own environment is a process
     * whose configuration depends on what has already run.
     */
    public function test_nothing_writes_to_the_environment(): void
    {
        foreach ($this->engineFiles() as $path) {
            self::assertNotContains(
                'putenv',
                $this->globalFunctionCalls($path),
                $this->relative($path) . ' calls putenv(), which is not thread-safe and mutates a global.',
            );
        }
    }

    /**
     * Configuration is the bottom of the stack and depends on nothing above it.
     *
     * It is built before the container exists, before a module has been found
     * and before anything can be logged, so a reference to a layer above is
     * either a cycle or a thing that is not there yet. Support is allowed
     * because Path is string handling, and the exception base class is allowed
     * because an exception has to have one.
     */
    public function test_the_configuration_layer_depends_on_nothing_above_it(): void
    {
        $allowed = ['App\\Engine\\Config', 'App\\Engine\\Support', 'App\\Engine\\Error\\FrameworkException'];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Config/')) {
                continue;
            }

            \preg_match_all('/App\\\\Engine\\\\[A-Za-z\\\\]+/', $this->codeWithoutComments($path), $matches);

            foreach ($matches[0] as $reference) {
                $permitted = false;

                foreach ($allowed as $prefix) {
                    if (\str_starts_with($reference, $prefix)) {
                        $permitted = true;
                    }
                }

                self::assertTrue($permitted, \sprintf(
                    '%s refers to %s, which is read after this layer rather than before it.',
                    $relative,
                    $reference,
                ));
            }
        }
    }

    /**
     * The compiled configuration is not readable over HTTP.
     *
     * It is the one file in the project that holds every setting at once,
     * database credentials included, so it must be written somewhere the web
     * server never serves. Both halves are checked here: where it is written, and
     * that it is outside public/.
     */
    public function test_the_configuration_cache_is_outside_the_document_root(): void
    {
        self::assertStringContainsString(
            'system',
            ConfigCache::file($this->basePath()),
            'the cache has moved out of system/',
        );

        $this->assertOutsideTheDocumentRoot(ConfigCache::file($this->basePath()), 'The configuration cache');
    }
    // ---- sessions ---------------------------------------------------------

    /**
     * PHP's own session handling is not used anywhere, by anyone.
     *
     * The specification asks for this directly, and the reasons are worth
     * keeping written down. session_start() puts the data in a superglobal, the
     * id in engine state and the policy in ini settings -- so a session cannot
     * be constructed in a test, cannot be swapped for a fake, and behaves
     * differently depending on what the SAPI did before the script ran. It also
     * holds a lock on the session for the whole request, which is why one slow
     * endpoint blocks every other request from the same browser.
     *
     * The rule covers the whole project rather than engine/: a module that
     * called session_start() would get a second, invisible session sitting
     * beside this one, and the two would disagree.
     */
    public function test_nothing_uses_php_s_own_session_machinery(): void
    {
        $banned = [
            'session_start',
            'session_id',
            'session_regenerate_id',
            'session_destroy',
            'session_set_save_handler',
            'session_write_close',
        ];

        foreach ([...$this->engineFiles(), ...$this->moduleFiles()] as $path) {
            $source = $this->codeWithoutComments($path);

            self::assertStringNotContainsString(
                '$_SESSION',
                $source,
                \sprintf('%s uses $_SESSION. The session is an object you ask for.', $this->relative($path)),
            );

            foreach ($banned as $function) {
                self::assertFalse(
                    $this->callsFunction($path, $function),
                    \sprintf('%s calls %s(). See engine/Session/Session.php.', $this->relative($path), $function),
                );
            }
        }
    }

    /**
     * Every session store is held to the same contract.
     *
     * The fifth suite, and the one whose store is most likely to be swapped
     * late: one machine becomes three, the file store becomes the database
     * store, and it happens on the day the traffic arrived. Whatever
     * differences exist between two implementations are discovered then.
     */
    public function test_every_session_store_runs_the_conformance_suite(): void
    {
        $stores = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Session/Stores/')) {
                $stores[] = \basename($relative, '.php');
            }
        }

        $exercised = [];

        foreach (SessionStoreConformanceTest::stores() as $make) {
            $exercised[] = (new \ReflectionClass($make[0]()))->getShortName();
        }

        // Building a database store made its table on each server; the suite's
        // own cleanup drops them. And one store runs on several databases.
        SessionStoreConformanceTest::tearDownAfterClass();
        $exercised = \array_values(\array_unique($exercised));

        \sort($stores);
        \sort($exercised);

        self::assertSame(
            $stores,
            $exercised,
            'a session store exists that the conformance test never runs against',
        );
    }

    /**
     * The session cookie is HttpOnly, and that is not configurable.
     *
     * This cookie IS the credential: script that can read it can be the user
     * from anywhere. Every other attribute here is a judgement call somebody
     * may need to make differently -- the name, the domain, SameSite, how long
     * it lives -- and this one is not, so it is not offered. A setting that
     * exists is a setting somebody switches off at four in the afternoon to
     * make a widget work.
     */
    public function test_the_session_cookie_cannot_be_made_readable_by_script(): void
    {
        $parameters = [];

        foreach ((new \ReflectionClass(SessionManager::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $parameters[] = \strtolower($parameter->getName());
        }

        self::assertNotContains('httponly', $parameters, 'HttpOnly has become configurable');

        $manager = new SessionManager(new SessionArrayStore());

        self::assertTrue($manager->cookie(\str_repeat('a', 64))->httpOnly);
        self::assertTrue($manager->forgetCookie()->httpOnly);
    }

    /**
     * The security layer does not know that sessions exist.
     *
     * Guard rotates the CSRF token when the session id changes, and it does it
     * because something told it that happened -- not because it holds a
     * SessionManager. The direction matters: security is wired into bootstrap
     * before sessions are, and a dependency the other way would make the two
     * phases impossible to reason about separately.
     */
    public function test_the_security_layer_does_not_depend_on_the_session_layer(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Security/')) {
                continue;
            }

            $source = $this->codeWithoutComments($path);

            self::assertStringNotContainsString(
                'App\\Engine\\Session',
                $source,
                \sprintf('%s references the session layer. Use the session.regenerated hook.', $relative),
            );
        }
    }

    /**
     * A session id is generated in exactly one place.
     *
     * The id is a bearer credential, so its generator is the single line of
     * this framework where using the wrong function -- uniqid(), mt_rand(), a
     * hash of the time -- turns every account into one that can be guessed at.
     * One place means one line to review and one line that can be wrong.
     */
    public function test_only_one_class_generates_a_session_id(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Session/') || \str_ends_with($relative, 'SessionId.php')) {
                continue;
            }

            self::assertFalse(
                $this->callsFunction($path, 'random_bytes'),
                \sprintf('%s makes its own random id. SessionId::generate() is the one place.', $relative),
            );
        }
    }

    /**
     * A session's data is written down as JSON, never serialize()d.
     *
     * PHP's sessions serialize, so an object put in one comes back as an
     * object: usually a stale copy of a row that changed an hour ago, and
     * occasionally a class that no longer exists, which is a fatal error on a
     * page nobody touched. unserialize() on stored data is also the object
     * injection class of bug, and the store's contents are exactly the thing an
     * attacker who has reached the filesystem would edit.
     */
    public function test_no_session_is_written_with_serialize(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Session/')) {
                continue;
            }

            foreach (['serialize', 'unserialize'] as $function) {
                self::assertFalse(
                    $this->callsFunction($path, $function),
                    \sprintf('%s calls %s(). A session payload is JSON.', $relative, $function),
                );
            }
        }
    }
    // ---- authentication and authorization ---------------------------------

    /**
     * There is no Gate and there is no Policy.
     *
     * Named in the specification's list of things not to clone, and the reason
     * is worth restating: a Gate is a registry of closures keyed by ability
     * strings, so the closure IS the decision and it can say yes to anything.
     * A Policy is the same thing discovered by class-name convention. Both make
     * "who may do this" a question you can only answer by running the code.
     *
     * Here a grant is data -- a role holds capabilities -- and the only thing a
     * closure may do is take permission away. See Authorizer.
     */
    public function test_there_is_no_gate_and_no_policy(): void
    {
        foreach ($this->engineFiles() as $path) {
            $name = \basename($this->relative($path), '.php');

            self::assertNotContains(
                $name,
                ['Gate', 'Gates', 'Policy', 'Policies', 'PolicyRegistry'],
                \sprintf('%s is a Gate or a Policy by name.', $this->relative($path)),
            );
        }

        // The substantive half. A Gate's defining feature is not its name: it
        // is that you can hand it a decision. UploadPolicy is called a policy
        // and is a value object describing allowed uploads, which is why the
        // name check above is narrow and this one is the real rule.
        $methods = \array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(\App\Engine\Auth\Authorizer::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        \sort($methods);

        self::assertSame(
            ['__construct', 'allows', 'authorize', 'capabilitiesFor', 'denies', 'grantsFor'],
            $methods,
            'Authorizer gained a method. If it takes a callback, it has become a Gate: a decision '
            . 'registered in code rather than declared as data, which is the thing the specification '
            . 'names. Narrowing a decision is what the authorization.decision filter is for.',
        );
    }

    /**
     * The framework does not know what a user is.
     *
     * The specification asks for authentication to be modular rather than
     * embedded in the kernel, and this is the form that takes: engine/Auth
     * names no table, no column and no model. UserProvider is two methods, and
     * everything that knows what is behind them lives in a module.
     *
     * The frozen method list is the load-bearing part. A third method is how an
     * interface starts describing a users table -- byEmail(), then
     * withRoles(), then countActive() -- and by the fourth the framework has an
     * opinion about a schema it does not own.
     */
    public function test_the_framework_does_not_know_what_a_user_is(): void
    {
        $methods = \array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(\App\Engine\Auth\UserProvider::class))->getMethods(),
        );

        \sort($methods);

        self::assertSame(
            ['byId', 'byLogin', 'describe'],
            $methods,
            'UserProvider gained a method. Everything the framework needs to know about a user is here.',
        );

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Auth/')) {
                continue;
            }

            $source = $this->codeWithoutComments($path);

            foreach (['SELECT ', 'INSERT ', 'UPDATE ', 'users'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    \sprintf('%s mentions "%s". The auth layer has no storage of its own.', $relative, $forbidden),
                );
            }
        }
    }

    /**
     * One class hashes passwords.
     *
     * The same argument as Signer being the only HMAC: a second call site is a
     * second chance to compare with ===, to forget the salt, or to pick an
     * algorithm because it was faster. One place means one place to review.
     */
    public function test_only_one_class_hashes_a_password(): void
    {
        foreach ([...$this->engineFiles(), ...$this->moduleFiles()] as $path) {
            $relative = $this->relative($path);

            if (\str_ends_with($relative, 'engine/Auth/Password.php')) {
                continue;
            }

            foreach (['password_hash', 'password_verify', 'password_needs_rehash'] as $function) {
                self::assertFalse(
                    $this->callsFunction($path, $function),
                    \sprintf('%s calls %s(). Auth\\Password is the one place.', $relative, $function),
                );
            }
        }
    }

    /**
     * An Identity carries no credential.
     *
     * It is handed to handlers, put in log context and returned in JSON by any
     * application that wants a /me endpoint. A password hash reaching it would
     * be published by the first such endpoint somebody wrote, and nothing about
     * that code would look wrong.
     */
    public function test_an_identity_carries_nothing_secret(): void
    {
        $reflection = new \ReflectionClass(\App\Engine\Auth\Identity::class);

        foreach ($reflection->getProperties() as $property) {
            foreach (['password', 'hash', 'secret', 'token', 'credential'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    \strtolower($property->getName()),
                    \sprintf('Identity::$%s looks like a credential.', $property->getName()),
                );
            }
        }

        // Account is where the hash lives, and it is what the provider returns
        // rather than what a handler is given.
        self::assertTrue(
            (new \ReflectionClass(\App\Engine\Auth\Account::class))->hasProperty('passwordHash'),
            'the hash has moved somewhere other than Account',
        );
    }

    /**
     * The key naming the logged-in account cannot be written from application
     * code.
     *
     * Otherwise any path that puts a user-supplied key into the session -- a
     * fill() over request input is the obvious one -- would be a way to log in
     * as anybody.
     */
    public function test_the_session_key_that_holds_the_login_is_reserved(): void
    {
        $key = \App\Engine\Auth\Authenticators\SessionAuthenticator::KEY;

        self::assertStringStartsWith(
            \App\Engine\Session\Session::RESERVED_PREFIX,
            $key,
            'the authentication key is no longer inside the framework namespace',
        );

        $session = new \App\Engine\Session\Session(\App\Engine\Session\SessionId::generate());

        $this->expectException(\App\Engine\Session\SessionException::class);

        $session->set($key, 'anybody');
    }

    /**
     * The security layer and the session layer do not know about the auth
     * layer.
     *
     * Bootstrap wires them in order -- security, then session, then auth -- and
     * each one only ever hears about the ones before it through named hooks. A
     * reference pointing backwards would make the three impossible to reason
     * about, or to boot, separately.
     */
    public function test_nothing_underneath_authentication_depends_on_it(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Security/') && !\str_contains($relative, 'engine/Session/')) {
                continue;
            }

            self::assertStringNotContainsString(
                'App\\Engine\\Auth',
                $this->codeWithoutComments($path),
                \sprintf('%s references the auth layer. Use a hook; see Guard::onSessionRegenerated().', $relative),
            );
        }
    }
    // ---- module dependencies ----------------------------------------------

    /**
     * A module that uses another module's classes says so.
     *
     * The dependency system can only check what is declared. A module that
     * reaches into another's namespace without declaring it still works -- until
     * the other module is disabled, removed or upgraded, at which point it fails
     * with a class-not-found error on whatever request first touched the
     * import, instead of refusing to boot with both modules named.
     *
     * So the declarations are checked against the code: every reference to
     * another module's namespace, anywhere under a module's directory, has to
     * be matched by requires() or optionally() in its module.php. Shared is
     * included -- it always registers first, but its API still has a version.
     */
    public function test_a_module_declares_every_module_whose_classes_it_uses(): void
    {
        $registry = $this->application()->boot()->container()->get(ModuleManager::class)->registry();

        foreach ($registry->contexts() as $context) {
            $declared = \array_map(
                static fn(\App\Engine\Module\Dependency $dependency): string => $dependency->id,
                $context->declaredDependencies(),
            );

            foreach ($this->filesUnder($context->path()) as $path) {
                foreach ($this->modulesReferencedBy($path) as $referenced) {
                    if ($referenced === $context->id()) {
                        continue;
                    }

                    self::assertContains(
                        $referenced,
                        $declared,
                        \sprintf(
                            '%s uses %s but %s/module.php does not declare it. Add $module->requires(\'%s\', ...).',
                            $this->relative($path),
                            $referenced,
                            $context->id(),
                            $referenced,
                        ),
                    );
                }
            }
        }
    }

    /**
     * Shared first, in the application as it actually boots.
     *
     * The resolver enforces this by refusing any dependency of Shared's; this
     * checks the outcome, so a change to the resolver that moved a module ahead
     * of Shared is caught even if every unit test of it still passed.
     */
    public function test_the_real_application_registers_shared_first(): void
    {
        $registry = $this->application()->boot()->container()->get(ModuleManager::class)->registry();

        $ranks = \array_map(
            static fn(\App\Engine\Module\ModuleDefinition $definition): int => $definition->kind->rank(),
            $registry->definitions(),
        );

        $sorted = $ranks;
        \sort($sorted);

        self::assertSame($sorted, $ranks, 'modules registered out of kind order');
        self::assertSame('Shared', $registry->ids()[0] ?? null);
    }

    /**
     * The discovery cache holds what discovery found, and nothing resolved.
     *
     * A cached dependency graph would be stale the first time somebody edited a
     * module.php, and this cache deliberately has no automatic invalidation. So
     * the cached shape is pinned: adding a field here is adding something that
     * will one day be silently wrong.
     *
     * Phase 27 added two, and localization a third, deliberately, and all are
     * answers the same directory walk gives -- whether assets/, Templates/ and
     * lang/ exist. They go stale exactly
     * when the path would (somebody changed the module's directory), not when
     * somebody edits a declaration, which is the line this test holds.
     */
    public function test_the_discovery_cache_carries_no_dependency_data(): void
    {
        $definition = \App\Engine\Module\ModuleDefinition::create('/modules/Example', 'Example');

        self::assertSame(
            ['id', 'kind', 'path', 'entryFile', 'directory', 'assets', 'templates', 'lang'],
            \array_keys($definition->toArray()),
            'The discovery cache gained a field. If it is resolved data, it will go stale.',
        );
    }

    /**
     * One authority decides the registration order.
     *
     * If anything other than the manager could set it, "why did this module
     * register before that one" would stop having one place to look.
     */
    public function test_only_the_module_manager_sets_the_registration_order(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_ends_with($relative, 'engine/Module/ModuleManager.php')
                || \str_ends_with($relative, 'engine/Module/ModuleRegistry.php')) {
                continue;
            }

            self::assertStringNotContainsString(
                '->setOrder(',
                $this->codeWithoutComments($path),
                \sprintf('%s sets the module order. Only ModuleManager::resolve() does.', $relative),
            );
        }
    }

    // ---- performance (Phase 27) ----------------------------------------------

    /**
     * Only discovery asks the filesystem about module directories.
     *
     * A boot from the discovery cache touches no module directory, and that is
     * true only while nothing after discovery goes back to ask. The questions
     * were each asked once, by the walk, and written into the definition --
     * registration reads $definition->hasAssets rather than calling is_dir(),
     * and the day somebody "simplifies" that back is the day every cached
     * request pays for two stats per module again, invisibly, because nothing
     * breaks.
     *
     * ModuleRegistry is exempt for the one file it owns, the cache itself.
     */
    public function test_only_discovery_probes_module_directories(): void
    {
        $probes = ['is_dir', 'is_file', 'file_exists', 'scandir', 'glob', 'opendir', 'readdir', 'realpath', 'filemtime', 'stat'];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_contains($relative, 'engine/Module/')
                || \str_ends_with($relative, 'engine/Module/ModuleDiscovery.php')
                || \str_ends_with($relative, 'engine/Module/ModuleRegistry.php')) {
                continue;
            }

            foreach ($probes as $probe) {
                self::assertFalse(
                    $this->callsFunction($path, $probe),
                    \sprintf(
                        '%s calls %s(). Only ModuleDiscovery asks the filesystem about modules; a cached boot must '
                        . 'not ask again. Record the answer on ModuleDefinition instead.',
                        $relative,
                        $probe,
                    ),
                );
            }
        }
    }

    /**
     * The discovery cache is written by cache:warm and by nothing else.
     *
     * It used to be written by whichever request first found it missing, which
     * is how a cache comes to be built on a developer's laptop halfway through
     * adding a module and then deployed. A deployment step writes it now, at a
     * moment when nothing is about to change.
     */
    public function test_only_cache_warm_writes_the_discovery_cache(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_ends_with($relative, 'engine/Module/ModuleRegistry.php')
                || \str_ends_with($relative, 'engine/Cli/Commands/CacheWarmCommand.php')) {
                continue;
            }

            self::assertStringNotContainsString(
                '->writeCache(',
                $this->codeWithoutComments($path),
                \sprintf('%s writes the module discovery cache. Only cache:warm does.', $relative),
            );
        }
    }

    /**
     * Every subject the specification lists for benchmarking is benchmarked,
     * and nothing is filed under a subject it does not list.
     *
     * The list is the specification's section 50, copied verbatim into the
     * suite. A subject quietly dropped because its benchmark was awkward to
     * write is the failure this catches; the second half stops the list being
     * satisfied by renaming.
     */
    public function test_every_specified_subject_has_a_benchmark(): void
    {
        $measured = [];

        foreach (\App\Tests\Benchmark\Suite::all($this->basePath()) as $benchmark) {
            self::assertContains(
                $benchmark->subject,
                \App\Tests\Benchmark\Suite::SUBJECTS,
                \sprintf('"%s" is filed under a subject the specification does not list.', $benchmark->key()),
            );

            $measured[$benchmark->subject] = true;
        }

        foreach (\App\Tests\Benchmark\Suite::SUBJECTS as $subject) {
            self::assertArrayHasKey($subject, $measured, \sprintf('Nothing benchmarks "%s".', $subject));
        }

        self::assertSame([
            'Application boot', 'Module discovery', 'Route resolution', 'Dependency resolution',
            'Database queries', 'Model hydration', 'Read-model queries', 'Asset resolution',
            'Template rendering', 'Hook execution', 'Filter execution',
        ], \App\Tests\Benchmark\Suite::SUBJECTS, 'the subject list is the specification\'s, not a place to trim');
    }

    /**
     * Every source that offers bulk writes runs the bulk conformance suite.
     *
     * The same shape as the five store rules: a set-based write is exactly
     * where a second implementation drifts, and it drifts on the day an
     * application swaps its source.
     */
    public function test_every_bulk_source_runs_the_conformance_suite(): void
    {
        $implementations = [];

        foreach ($this->engineFiles() as $path) {
            $code = $this->codeWithoutComments($path);

            if (\preg_match('/\bclass\s+(\w+)[^{]*\bimplements\b[^{]*\bBulkWrites\b/', $code, $match) === 1) {
                $implementations[] = $match[1];
            }
        }

        $exercised = [];

        foreach (BulkWritesConformanceTest::sources() as $make) {
            $exercised[] = (new \ReflectionClass($make[0]()))->getShortName();
        }

        \sort($implementations);
        \sort($exercised);

        self::assertNotSame([], $implementations);
        self::assertSame($implementations, $exercised, 'a BulkWrites source exists that the conformance suite never runs');
    }

    // ---- observability (Phase 28) --------------------------------------------

    /**
     * Instrumentation is attached from outside; nothing measured knows it is.
     *
     * The hook and filter engines, the module manager and the connections each
     * expose an observe() seam and know nothing else. The day HookEngine checks
     * "is the profiler on" is the day every subsystem grows that check, "off"
     * becomes a flag somebody forgot rather than an absence of wiring, and a
     * subsystem cannot be used without the profiler it now imports.
     *
     * The tracer is different on purpose, and not covered: it is always on,
     * it is identity rather than measurement, and the queue and the application
     * legitimately need to ask it which unit of work is running.
     */
    public function test_no_subsystem_references_the_profiler(): void
    {
        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Observability/') || \str_contains($relative, 'engine/Bootstrap/')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            foreach (['Observability\\Profiler', 'Observability\\Report'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    \sprintf('%s references %s. Expose an observe() seam and let the bootstrap attach it.', $relative, $forbidden),
                );
            }
        }
    }

    /**
     * "Do not build a giant debug dashboard initially." Held to, structurally.
     *
     * What is observed leaves through the log and through response headers --
     * channels every deployment already has and already reads. The day a file
     * under engine/Observability opens a file of its own, prints, or sets a
     * header directly, it has started to become the dashboard: a store nobody
     * rotates, a page nobody secured, an endpoint nobody audited.
     */
    public function test_observability_keeps_nothing_of_its_own(): void
    {
        $forbidden = [
            'fopen', 'file_put_contents', 'fwrite', 'mkdir', 'touch', 'tempnam', 'error_log',
            'header', 'setcookie', 'printf', 'print_r', 'var_dump', 'session_start', 'curl_init',
            'fsockopen', 'stream_socket_client', 'syslog',
        ];

        $files = \array_filter(
            $this->engineFiles(),
            fn(string $path): bool => \str_contains($this->relative($path), 'engine/Observability/'),
        );

        self::assertNotSame([], $files);

        foreach ($files as $path) {
            foreach ($forbidden as $function) {
                self::assertFalse(
                    $this->callsFunction($path, $function),
                    \sprintf('%s calls %s(). Observability reports through the log and response headers only.', $this->relative($path), $function),
                );
            }

            foreach (\token_get_all((string) \file_get_contents($path)) as $token) {
                self::assertFalse(
                    \is_array($token) && \in_array($token[0], [\T_ECHO, \T_PRINT, \T_INLINE_HTML], true),
                    \sprintf('%s writes output directly.', $this->relative($path)),
                );
            }
        }
    }

    /**
     * Every observe() seam in the engine is connected to something.
     *
     * A seam nothing attaches to is a subsystem the profiler silently does not
     * see -- the category simply never appears in a summary, and nobody notices
     * an absence. So each class that offers one must be named, as a parameter
     * type, in the code that does the attaching.
     */
    public function test_every_observation_seam_is_connected(): void
    {
        $wiring = '';

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->relative($path), 'engine/Observability/')) {
                $wiring .= $this->codeWithoutComments($path);
            }
        }

        $seams = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_contains($relative, 'engine/Observability/')) {
                continue;
            }

            if (\preg_match('/public function observe\(/', $this->codeWithoutComments($path)) === 1) {
                $seams[] = \basename($relative, '.php');
            }
        }

        \sort($seams);

        self::assertSame(
            ['Connection', 'ConnectionManager', 'FilterEngine', 'HookEngine', 'ModuleManager'],
            $seams,
            'the set of observation seams changed; connect the new one and add it here',
        );

        // Connection is reached through its manager, which is what hands the
        // observer to every connection it opens.
        foreach (\array_diff($seams, ['Connection']) as $seam) {
            self::assertMatchesRegularExpression(
                '/\b' . $seam . '\s+\$\w+/',
                $wiring,
                \sprintf('%s offers observe() but nothing in engine/Observability attaches to it.', $seam),
            );
        }
    }

    // ---- system operations (docs/plans/system.md) ------------------------------

    /**
     * The system layer is built phase by phase, and each phase adds what it
     * uses. A file that appears here without a phase that needs it is the start
     * of the unrestricted shell the plan exists to prevent.
     */
    public function test_the_system_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/System/Audit/AuditOutcome.php',
            'engine/System/Audit/AuditRecord.php',
            'engine/System/Audit/SystemAudit.php',
            'engine/System/Command/Command.php',
            'engine/System/Command/CommandException.php',
            'engine/System/Command/CommandExecutor.php',
            'engine/System/Command/CommandFailedException.php',
            'engine/System/Command/CommandNotFoundException.php',
            'engine/System/Command/CommandTimeoutException.php',
            'engine/System/Command/CommandPolicy.php',
            'engine/System/Command/CommandPolicyException.php',
            'engine/System/Command/CommandResult.php',
            'engine/System/Command/CommandSlot.php',
            'engine/System/Command/ConcurrencyLimit.php',
            'engine/System/Command/Invocation.php',
            'engine/System/Command/ShellCommand.php',
            'engine/System/Cron/CronChange.php',
            'engine/System/Cron/CronException.php',
            'engine/System/Cron/CronJob.php',
            'engine/System/Cron/CronManager.php',
            'engine/System/Cron/CronTable.php',
            'engine/System/Cron/CronValidationException.php',
            'engine/System/Cron/ScheduleRunJob.php',
            'engine/System/Cron/UserCrontab.php',
            'engine/System/Filesystem/FileInfo.php',
            'engine/System/Filesystem/FilesystemException.php',
            'engine/System/Filesystem/FilesystemPolicy.php',
            'engine/System/Filesystem/FilesystemPolicyException.php',
            'engine/System/Filesystem/PathTraversalException.php',
            'engine/System/Filesystem/SystemFilesystem.php',
            'engine/System/Permission/PermissionException.php',
            'engine/System/Permission/PermissionManager.php',
            'engine/System/Process/Process.php',
            'engine/System/Process/ProcessException.php',
            'engine/System/Process/ProcessManager.php',
            'engine/System/Security/SystemAuthorizationException.php',
            'engine/System/Security/SystemAuthorizer.php',
            'engine/System/Security/SystemCapability.php',
            'engine/System/Security/SystemOperation.php',
            'engine/System/Service/ServiceAction.php',
            'engine/System/Service/ServiceException.php',
            'engine/System/Service/ServiceManager.php',
            'engine/System/Service/ServiceNotFoundException.php',
            'engine/System/Service/ServicePolicy.php',
            'engine/System/Service/ServiceStatus.php',
            'engine/System/SystemInfo/Disk.php',
            'engine/System/SystemInfo/Memory.php',
            'engine/System/SystemInfo/SystemInfo.php',
            'engine/System/Systemd/SystemdException.php',
            'engine/System/Systemd/SystemdManager.php',
            'engine/System/Systemd/SystemdUnits.php',
            'engine/System/Systemd/UnitFile.php',
            'engine/System/SystemConfig.php',
            'engine/System/SystemDisabledException.php',
            'engine/System/SystemException.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_starts_with($relative, 'engine/System/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * Nothing in the engine hands a string to a shell.
     *
     * exec(), system(), passthru(), popen() and backticks all take one string
     * and give it to /bin/sh, which is where an argument with a semicolon in it
     * becomes a second command. The plan's first rule is that the executable and
     * its arguments stay apart all the way down; the only process API that can
     * do that is proc_open() with an array, and only engine/System may call it.
     * Shell execution, when it arrives, is an explicit mode of that same path.
     */
    public function test_only_the_system_layer_starts_a_process_and_never_through_a_shell_string(): void
    {
        $shellStrings = ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'pcntl_exec'];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            foreach ($shellStrings as $function) {
                self::assertFalse(
                    $this->callsFunction($path, $function),
                    \sprintf('%s calls %s(), which runs a string through a shell.', $relative, $function),
                );
            }

            $tokens = \token_get_all((string) \file_get_contents($path));

            self::assertNotContains('`', $tokens, \sprintf('%s uses the backtick operator, which is shell_exec().', $relative));

            if (!\str_starts_with($relative, 'engine/System/')) {
                self::assertFalse(
                    $this->callsFunction($path, 'proc_open'),
                    \sprintf('%s starts a process. Starting processes belongs to engine/System.', $relative),
                );

                continue;
            }

            // Inside the layer, proc_open() is only ever handed the argv array.
            // Given a string, it runs /bin/sh -c on Linux and cmd.exe on Windows.
            // "proc_open()" with nothing inside is a name in a message, not a call.
            \preg_match_all('/\\\\?proc_open\((?!\))\s*([^,]*),/', $this->codeWithoutComments($path), $calls);

            foreach ($calls[1] as $first) {
                self::assertSame('$argv', \trim($first), \sprintf('%s passes proc_open() something other than $argv.', $relative));
            }
        }
    }

    /**
     * Cron is the trigger; the Scheduler is the scheduler.
     *
     * engine/System borrows exactly one thing from the Scheduler, the grammar of
     * a cron expression, so that a schedule means the same in a crontab and in a
     * module. Reaching for anything else -- the registry, the collector, locks,
     * the runner -- would be the start of a second scheduling engine, and one
     * that installs its tasks as crontab lines nobody reviews. In the other
     * direction the Scheduler stays what its own tests say: it decides and runs
     * what is due, and starting processes or editing crontabs is not in it.
     */
    public function test_system_cron_and_the_scheduler_stay_apart(): void
    {
        $separator = \preg_quote(\chr(92), '/');
        $allowed = ['CronExpression', 'SchedulerException'];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);
            $code = $this->codeWithoutComments($path);

            if (\str_starts_with($relative, 'engine/System/')) {
                \preg_match_all('/App' . $separator . 'Engine' . $separator . 'Scheduler' . $separator . '(\w+)/', $code, $matches);

                foreach ($matches[1] as $class) {
                    self::assertContains(
                        $class,
                        $allowed,
                        \sprintf('%s uses Scheduler\\%s. System may use the cron grammar, not the scheduling engine.', $relative, $class),
                    );
                }
            }

            if (\str_starts_with($relative, 'engine/Scheduler/')) {
                self::assertDoesNotMatchRegularExpression(
                    '/App' . $separator . 'Engine' . $separator . 'System/',
                    $code,
                    \sprintf('%s reaches into engine/System. The scheduler runs what is due; it does not manage the OS.', $relative),
                );
            }
        }
    }

    // ---- MCP (docs/plans/mcp.md) ---------------------------------------------------

    /** Built phase by phase, like engine/System: a new file is added on purpose. */
    public function test_the_mcp_layer_holds_infrastructure_only(): void
    {
        $infrastructure = [
            'engine/MCP/Capability.php',
            'engine/MCP/CapabilityKind.php',
            'engine/MCP/McpCollector.php',
            'engine/MCP/McpRegistry.php',
            'engine/MCP/McpServer.php',
            'engine/MCP/McpSession.php',
            'engine/MCP/Transport/HttpTransport.php',
            'engine/MCP/Transport/StdioTransport.php',
            'engine/MCP/RegistryException.php',
            'engine/MCP/McpAuthorizer.php',
            'engine/MCP/McpConfig.php',
            'engine/MCP/McpContext.php',
            'engine/MCP/McpContractException.php',
            'engine/MCP/McpError.php',
            'engine/MCP/McpErrorCode.php',
            'engine/MCP/McpException.php',
            'engine/MCP/PlainData.php',
            'engine/MCP/Prompt/Prompt.php',
            'engine/MCP/Prompt/PromptArgument.php',
            'engine/MCP/Prompt/PromptException.php',
            'engine/MCP/Prompt/PromptMessage.php',
            'engine/MCP/Prompt/PromptProvider.php',
            'engine/MCP/Prompt/PromptResult.php',
            'engine/MCP/Resource/Resource.php',
            'engine/MCP/Resource/ResourceContents.php',
            'engine/MCP/Resource/ResourceException.php',
            'engine/MCP/Resource/ResourceReader.php',
            'engine/MCP/Tool/Tool.php',
            'engine/MCP/Tool/ToolException.php',
            'engine/MCP/Tool/ToolResult.php',
            'engine/MCP/Tool/ToolRunner.php',
            'engine/MCP/Validation/SchemaValidator.php',
            'engine/MCP/Validation/ValidationException.php',
            'engine/MCP/Protocol/MessageParser.php',
            'engine/MCP/Protocol/Notification.php',
            'engine/MCP/Protocol/ProtocolException.php',
            'engine/MCP/Protocol/ProtocolVersion.php',
            'engine/MCP/Protocol/Request.php',
            'engine/MCP/Protocol/Response.php',
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (\str_starts_with($relative, 'engine/MCP/')) {
                $found[] = $relative;
            }
        }

        \sort($found);
        \sort($infrastructure);

        self::assertSame($infrastructure, $found);
    }

    /**
     * MCP is an interface, and reaches nothing an interface should not.
     *
     * The plan's invariants, as a rule about imports: MCP is not arbitrary SQL,
     * filesystem, shell or PHP access. A tool calls an application service,
     * which a module wrote and authorizes; the MCP layer itself never holds a
     * database connection, a system manager or a console command, so there is no
     * path by which it could expose one generically. And it does not run the
     * console to reuse its logic -- both call the same service.
     */
    public function test_mcp_is_an_interface_and_reaches_no_infrastructure_directly(): void
    {
        $forbidden = [
            'App\\Engine\\System', 'App\\Engine\\Database', 'App\\Engine\\Data\\', 'App\\Engine\\Cli',
            'App\\Engine\\Model', 'App\\Modules\\',
        ];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_starts_with($relative, 'engine/MCP/')) {
                continue;
            }

            $code = $this->codeWithoutComments($path);

            foreach ($forbidden as $name) {
                self::assertStringNotContainsString($name, $code, \sprintf('%s refers to %s. MCP reaches application services, not infrastructure.', $relative, $name));
            }

            foreach (\token_get_all((string) \file_get_contents($path)) as $token) {
                self::assertFalse(
                    \is_array($token) && \in_array($token[0], [\T_EVAL, \T_INCLUDE, \T_INCLUDE_ONCE, \T_REQUIRE, \T_REQUIRE_ONCE], true),
                    \sprintf('%s evaluates or includes code. MCP never runs PHP it was given.', $relative),
                );
            }

            foreach (['call_user_func', 'call_user_func_array', 'unserialize'] as $function) {
                self::assertFalse(
                    $this->callsFunction($path, $function),
                    \sprintf('%s calls %s(), a way to reach a callable or object a client named.', $relative, $function),
                );
            }

            // Nor the filesystem or a process, even by a function rather than
            // an import. The STDIO transport reads and writes the two streams
            // it is handed, and opens nothing.
            foreach ([
                'fopen', 'file_get_contents', 'file_put_contents', 'file', 'readfile', 'unlink', 'rename', 'copy',
                'mkdir', 'rmdir', 'scandir', 'glob', 'opendir', 'exec', 'shell_exec', 'system', 'passthru',
                'proc_open', 'popen', 'pcntl_exec', 'mail',
            ] as $function) {
                self::assertFalse(
                    $this->callsFunction($path, $function),
                    \sprintf('%s calls %s(). MCP reaches files and processes only through a module\'s service.', $relative, $function),
                );
            }

            self::assertNotContains('`', \token_get_all((string) \file_get_contents($path)), \sprintf('%s uses the backtick operator, which is shell_exec().', $relative));
        }
    }

    /**
     * Each operating-system mechanism is spoken in exactly one place.
     *
     * The plan's OS abstraction, as built: Linux-first, with no interface nobody
     * implements twice, and instead a rule that the Linux-specific parts stay
     * where they are. systemd is the service manager's, crontab is the cron
     * layer's, /proc and /sys are system information's, and shell redirection
     * is the one line a crontab needs. The day a second platform arrives, each
     * is one class to put behind an interface, not a search through the tree.
     */
    public function test_each_operating_system_mechanism_lives_in_one_place(): void
    {
        $mechanisms = [
            "'systemctl'" => ['engine/System/Service/ServiceManager.php'],
            "'crontab'" => ['engine/System/Cron/UserCrontab.php'],
            '/proc/' => ['engine/System/SystemInfo/SystemInfo.php'],
            '/sys/' => ['engine/System/SystemInfo/SystemInfo.php'],
            '/etc/os-release' => ['engine/System/SystemInfo/SystemInfo.php'],
            '/dev/null' => ['engine/System/Cron/CronJob.php'],
            '2>&1' => ['engine/System/Cron/CronJob.php'],
        ];

        $found = [];

        foreach ($this->engineFiles() as $path) {
            $code = $this->codeWithoutComments($path);

            foreach (\array_keys($mechanisms) as $needle) {
                if (\str_contains($code, $needle)) {
                    $found[$needle][] = $this->relative($path);
                }
            }
        }

        foreach ($mechanisms as $needle => $owners) {
            self::assertSame($owners, $found[$needle] ?? [], \sprintf('%s is spoken outside the one class that owns it.', $needle));
        }

        // And the platform is asked about only where behaviour really differs.
        $asking = [];

        foreach ($this->engineFiles() as $path) {
            if (\str_contains($this->codeWithoutComments($path), 'PHP_OS_FAMILY')) {
                $asking[] = $this->relative($path);
            }
        }

        \sort($asking);

        self::assertSame(
            [
                'engine/System/Command/CommandExecutor.php',
                'engine/System/Command/Invocation.php',
                'engine/System/Cron/UserCrontab.php',
                'engine/System/Filesystem/SystemFilesystem.php',
                'engine/System/Permission/PermissionManager.php',
                'engine/System/Process/Process.php',
                'engine/System/Service/ServiceManager.php',
                'engine/System/SystemInfo/SystemInfo.php',
            ],
            $asking,
            'a new place asks which operating system this is; add it here only if its behaviour must differ',
        );
    }

    /**
     * The system layer does not know who is asking.
     *
     * HTTP, the console and MCP all reach it the same way: through an
     * application service that has already authenticated, authorised and
     * validated. A system class that read a request or a console input would
     * be deciding for one interface what the others are not asked, and would be
     * the natural place for a route to call it directly.
     */
    public function test_the_system_layer_cannot_see_the_interfaces_that_call_it(): void
    {
        $forbidden = [
            'App\\Engine\\Http', 'App\\Engine\\Routing', 'App\\Engine\\Dispatch', 'App\\Engine\\Cli',
            'App\\Engine\\Session', 'App\\Engine\\MCP', '$_SERVER', '$_GET', '$_POST', '$_REQUEST', 'STDIN',
        ];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);

            if (!\str_starts_with($relative, 'engine/System/')) {
                continue;
            }

            foreach ($forbidden as $name) {
                self::assertStringNotContainsString(
                    $name,
                    $this->codeWithoutComments($path),
                    \sprintf('%s refers to %s. System operations are called by services, not by an interface.', $relative, $name),
                );
            }
        }
    }

    /** @return list<string> absolute paths of every PHP file under a directory */
    private function filesUnder(string $directory): array
    {
        if (!\is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        \sort($files);

        return $files;
    }

    /**
     * Module ids whose namespaces a file mentions in code.
     *
     * Two namespace roots are module roots: App\Modules\, where installed
     * modules live, and App\Tests\Fixtures\Showcase\, where the showcase plugin
     * and gateway live now that they no longer ship. Leaving the second out
     * would let the showcase -- the only application with a plugin and a
     * gateway in it -- reference its way past this rule unseen.
     *
     * @return list<string>
     */
    private function modulesReferencedBy(string $path): array
    {
        $separator = \preg_quote(\chr(92), '/');
        // App\Modules\<Name>, or the showcase's App\Tests\Fixtures\Showcase\[Plugins|Gateways\]<Name>:
        // either way the module's id is its directory name.
        $pattern = '/App' . $separator . '(?:Modules|Tests' . $separator . 'Fixtures' . $separator . 'Showcase'
            . '(?:' . $separator . '(?:Plugins|Gateways))?)' . $separator . '([A-Z][A-Za-z0-9_]*)/';

        \preg_match_all($pattern, $this->codeWithoutComments($path), $matches, \PREG_SET_ORDER);

        $ids = [];

        foreach ($matches as $match) {
            $ids[] = $match[1];
        }

        return \array_values(\array_unique($ids));
    }
}
