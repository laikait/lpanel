<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Engine\Cli\CommandRegistry;
use App\Engine\Cli\CoreCommands;
use App\Engine\Core\Application;
use App\Tests\Support\TestCase;

/**
 * Documentation that is checked against the code it describes.
 *
 * A document drifts the way an architecture does: one commit at a time, each of
 * them reasonable, none of them touching the paragraph that stopped being true.
 * The README's drift pass for the release found a status line eighteen phases
 * old, helpers described as "reserved" two phases after they shipped, and a
 * command list missing six commands. So the parts of the documentation that are
 * statements of fact about the code -- which class is public, which hooks fire,
 * which version this is, which commands exist, which links go anywhere -- are
 * tests rather than prose somebody has to remember to re-read.
 *
 * What is not checked is the explanation, which no test can judge.
 */
final class DocumentationTest extends TestCase
{
    private const LEVELS = ['Stable', 'Experimental', 'Internal', 'Deprecated'];

    private const INDEX_PAGE = 'docs/README.md';

    private const LIFECYCLE_PAGE = 'docs/reference/hooks-and-filters.md';

    private const CONSOLE_PAGE = 'docs/reference/console.md';

    // ---- STABILITY.md -------------------------------------------------------

    /**
     * Every engine class has exactly one level.
     *
     * A class in a namespace with a row inherits that row's level, which is a
     * decision somebody made for the namespace. A class in a namespace with no
     * row -- a new subsystem -- is one whose public-ness nobody decided, which
     * in practice means it becomes public by being used. The row is the
     * decision; this is what makes somebody take it.
     */
    public function test_every_engine_class_has_one_stability_level(): void
    {
        $rules = $this->stabilityRules();

        foreach ($this->engineClasses() as $class) {
            self::assertNotNull(
                $this->levelOf($class, $rules),
                \sprintf('%s has no row in STABILITY.md, so nobody has decided whether it is public.', $class),
            );
        }
    }

    /** A row that matches nothing describes a class that no longer exists. */
    public function test_every_stability_row_matches_a_class(): void
    {
        $classes = $this->engineClasses();

        foreach (\array_keys($this->stabilityRules()) as $pattern) {
            $matched = \array_filter(
                $classes,
                static fn(string $class): bool => self::covers($pattern, $class),
            );

            self::assertNotEmpty($matched, \sprintf('STABILITY.md classifies %s, which matches no engine class.', $pattern));
        }
    }

    /**
     * Specification §53: "Do not promise API stability too early."
     *
     * Before 1.0.0 there is no Stable row. This relaxes on its own at 1.0.0,
     * which is the release that is supposed to promote.
     */
    public function test_nothing_is_stable_before_one_point_zero(): void
    {
        if (\version_compare(Application::VERSION, '1.0.0', '>=')) {
            $this->expectNotToPerformAssertions();

            return;
        }

        foreach ($this->stabilityRules() as $pattern => [$level]) {
            self::assertNotSame(
                'Stable',
                $level,
                \sprintf('%s is Stable in %s. Nothing is promised before 1.0.0.', $pattern, Application::VERSION),
            );
        }
    }

    /** A deprecation that does not say what replaces it and when it goes is a warning with no advice. */
    public function test_a_deprecated_row_names_its_replacement_and_its_removal(): void
    {
        foreach ($this->stabilityRules() as $pattern => [$level, $notes]) {
            if ($level !== 'Deprecated') {
                continue;
            }

            self::assertMatchesRegularExpression('/\buse\b/i', $notes, $pattern . ' is Deprecated without saying what to use instead.');
            self::assertMatchesRegularExpression('/removed in \d+\.\d+\.\d+/i', $notes, $pattern . ' is Deprecated without a removal version.');
        }
    }

    /**
     * The rule that makes the classification mean something.
     *
     * If the modules that ship with the framework -- and the showcase, which is
     * the reference a module author copies -- reach for an Internal class, then
     * a module cannot be written without one, and "Internal" is a label rather
     * than a boundary.
     */
    public function test_no_module_uses_an_internal_class(): void
    {
        $rules = $this->stabilityRules();
        $checked = 0;

        foreach ([...$this->phpFilesUnder('modules'), ...$this->phpFilesUnder(self::SHOWCASE)] as $path) {
            foreach ($this->engineNamesIn($path) as $class) {
                if (!\class_exists($class) && !\interface_exists($class) && !\enum_exists($class)) {
                    continue;
                }

                ++$checked;

                self::assertNotSame(
                    'Internal',
                    $this->levelOf($class, $rules),
                    \sprintf(
                        '%s uses %s, which STABILITY.md marks Internal. Either a module should not need it, or it is not internal.',
                        $this->relative($path),
                        $class,
                    ),
                );
            }
        }

        self::assertGreaterThan(0, $checked, 'no module references any engine class, so this checked nothing');
    }

    // ---- README.md and docs/ -------------------------------------------------

    /**
     * The lifecycle table lists exactly the hooks and filters the engine fires.
     *
     * A hook fired but undocumented is an extension point nobody can find; a
     * hook documented but never fired is one somebody will listen to for a
     * week before discovering it does not exist.
     */
    public function test_the_lifecycle_table_lists_exactly_what_the_engine_fires(): void
    {
        [$documentedHooks, $documentedFilters] = $this->lifecycleTable();
        [$firedHooks, $appliedFilters] = $this->extensionPointsInEngine();

        self::assertSame($firedHooks, $documentedHooks, self::LIFECYCLE_PAGE . ' "Lifecycle extension points": the hooks column');
        self::assertSame($appliedFilters, $documentedFilters, self::LIFECYCLE_PAGE . ' "Lifecycle extension points": the filters column');
    }

    /**
     * Every command the framework registers is in the console reference's list.
     *
     * The framework's own, meaning the ones CoreCommands declares: a command an
     * application's module adds is that module's to document, and an application
     * with one should not fail the framework's documentation test for it.
     */
    public function test_every_framework_command_is_documented(): void
    {
        $reference = $this->read(self::CONSOLE_PAGE);
        $commands = $this->shippedApplication()->boot()->container()->get(CommandRegistry::class);
        $checked = 0;

        foreach ($commands->all() as $command) {
            if ($command->module !== CoreCommands::MODULE) {
                continue;
            }

            ++$checked;
            $name = $command->name;

            self::assertMatchesRegularExpression(
                '/(?<![a-z:-])' . \preg_quote($name, '/') . '(?![a-z:-])/',
                $reference,
                \sprintf('The command "%s" is not mentioned in %s.', $name, self::CONSOLE_PAGE),
            );
        }

        self::assertGreaterThan(0, $checked, 'no command is declared by ' . CoreCommands::MODULE . ', so this checked nothing');
    }

    /**
     * Every in-page link lands on a heading, every relative link on a file, and
     * a link into another page on a heading in that page.
     *
     * Headings get renamed; the links to them do not follow. On GitHub a broken
     * anchor does nothing at all when clicked, which is the least noticeable
     * way for documentation to fail -- and with the documentation split across
     * pages, most links now cross from one file into another.
     */
    public function test_documentation_links_go_somewhere(): void
    {
        foreach ($this->documents() as $document) {
            $markdown = $this->withoutCodeBlocks($this->read($document));

            \preg_match_all('/\]\(([^)\s]+)\)/', $markdown, $matches);

            foreach ($matches[1] as $target) {
                if (\preg_match('#^[a-z]+:#i', $target) === 1) {
                    continue;
                }

                [$file, $anchor] = \array_pad(\explode('#', $target, 2), 2, null);
                $path = $file === '' ? $document : $this->normalise(\dirname($document) . '/' . $file);

                self::assertFileExists($this->basePath($path), \sprintf('%s links to %s, which does not exist.', $document, $target));

                if ($anchor === null) {
                    continue;
                }

                self::assertStringEndsWith('.md', $path, \sprintf('%s links to an anchor in %s, which is not a document.', $document, $target));
                self::assertContains(
                    $anchor,
                    $this->anchors($this->withoutCodeBlocks($this->read($path))),
                    \sprintf('%s links to %s, which is no heading in %s.', $document, $target, $path),
                );
            }
        }
    }

    /** A page nobody links to from the index is a page nobody finds. */
    public function test_every_documentation_page_is_in_the_index(): void
    {
        $index = $this->withoutCodeBlocks($this->read(self::INDEX_PAGE));
        \preg_match_all('/\]\(([^)#\s]+)(?:#[^)\s]*)?\)/', $index, $matches);

        $linked = \array_map(fn(string $target): string => $this->normalise('docs/' . $target), $matches[1]);

        foreach ($this->documents() as $document) {
            if (!\str_starts_with($document, 'docs/') || $document === self::INDEX_PAGE) {
                continue;
            }

            self::assertContains($document, $linked, \sprintf('%s is not linked from %s.', $document, self::INDEX_PAGE));
        }
    }

    /**
     * GitHub Pages builds docs/ with Jekyll, which runs every page through
     * Liquid first -- and Liquid shares Twig's delimiters. An unknown tag such
     * as {% extends %} fails the whole site's build; {{ title }} is worse, and
     * quietly renders as nothing. A page that shows Twig has to sit between raw
     * tags, written inside HTML comments so GitHub's own Markdown view hides them.
     */
    public function test_a_page_showing_twig_is_hidden_from_liquid(): void
    {
        $checked = 0;

        foreach ($this->documents() as $document) {
            $markdown = $this->read($document);

            if (!\str_starts_with($document, 'docs/') || \preg_match('/\{[{%]/', $markdown) !== 1) {
                continue;
            }

            ++$checked;
            $outside = (string) \preg_replace('/<!--[^>]*\{% raw %\} -->.*<!-- \{% endraw %\} -->/s', '', $markdown);

            self::assertDoesNotMatchRegularExpression(
                '/\{[{%]/',
                $outside,
                \sprintf('%s has Twig or Liquid syntax outside <!-- {%% raw %%} --> ... <!-- {%% endraw %%} -->, which breaks the GitHub Pages build.', $document),
            );
        }

        self::assertGreaterThan(0, $checked, 'no page shows Twig any more, so this rule reads nothing.');
    }

    // ---- versions -----------------------------------------------------------

    /**
     * One version, written once, and the changelog agrees with it.
     *
     * composer.json has no "version" because Composer takes it from the tag,
     * and a second copy is a copy that disagrees. The changelog's newest section
     * is either work not yet released or the release this code says it is.
     */
    public function test_the_version_and_the_changelog_agree(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Application::VERSION, 'Application::VERSION is MAJOR.MINOR.PATCH');

        /** @var array<string, mixed> $manifest */
        $manifest = \json_decode($this->read('composer.json'), true, 16, \JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('version', $manifest, 'composer.json must not carry a version; the git tag is the version.');

        \preg_match_all('/^## \[([^\]]+)\](.*)$/m', $this->read('CHANGELOG.md'), $sections, \PREG_SET_ORDER);
        self::assertNotEmpty($sections, 'CHANGELOG.md has no version sections');

        self::assertContains(
            $sections[0][1],
            ['Unreleased', Application::VERSION],
            'the newest changelog section is neither [Unreleased] nor the version in Application::VERSION',
        );

        foreach ($sections as [, $version, $rest]) {
            if ($version === 'Unreleased') {
                continue;
            }

            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version, 'a released changelog section is MAJOR.MINOR.PATCH');
            self::assertMatchesRegularExpression('/^ - \d{4}-\d{2}-\d{2}$/', $rest, \sprintf('[%s] has no release date', $version));
            self::assertTrue(
                \version_compare($version, Application::VERSION, '<='),
                \sprintf('CHANGELOG.md has a release %s newer than Application::VERSION %s', $version, Application::VERSION),
            );
        }
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * The rows of STABILITY.md's class table, keyed by class or "Namespace\*".
     *
     * A row that looks like a classification but does not parse is a failure
     * rather than a skip, or a typo in a level would quietly declassify a class.
     *
     * @return array<string, array{string, string}>
     */
    private function stabilityRules(): array
    {
        $rules = [];

        foreach (\explode("\n", $this->read('STABILITY.md')) as $line) {
            if (!\str_starts_with(\trim($line), '| `App\\')) {
                continue;
            }

            if (\preg_match('/^\|\s*`(App\\\\Engine\\\\[A-Za-z0-9_\\\\]+(?:\\\\\*)?)`\s*\|\s*([A-Za-z]+)\s*\|(.*)\|\s*$/', \trim($line), $row) !== 1) {
                self::fail('STABILITY.md: a row that does not parse: ' . $line);
            }

            self::assertContains($row[2], self::LEVELS, 'STABILITY.md: an unknown level in: ' . $line);
            self::assertArrayNotHasKey($row[1], $rules, 'STABILITY.md classifies ' . $row[1] . ' twice');

            $rules[$row[1]] = [$row[2], \trim($row[3])];
        }

        self::assertNotEmpty($rules, 'STABILITY.md has no class table');

        return $rules;
    }

    /**
     * The most specific row for a class: its own, else the longest namespace.
     *
     * @param array<string, array{string, string}> $rules
     */
    private function levelOf(string $class, array $rules): ?string
    {
        if (isset($rules[$class])) {
            return $rules[$class][0];
        }

        $best = null;

        foreach ($rules as $pattern => [$level]) {
            if (\str_ends_with($pattern, '\\*')
                && self::covers($pattern, $class)
                && ($best === null || \strlen($pattern) > \strlen($best[0]))) {
                $best = [$pattern, $level];
            }
        }

        return $best[1] ?? null;
    }

    private static function covers(string $pattern, string $class): bool
    {
        return \str_ends_with($pattern, '\\*')
            ? \str_starts_with($class, \substr($pattern, 0, -1))
            : $pattern === $class;
    }

    /** @return list<string> every class, interface and enum under engine/, by name */
    private function engineClasses(): array
    {
        $classes = [];
        $root = \str_replace('\\', '/', $this->basePath('engine')) . '/';

        foreach ($this->phpFilesUnder('engine') as $path) {
            $relative = \substr(\str_replace('\\', '/', $path), \strlen($root));

            if (\in_array($relative, ['bootstrap.php', 'Support/helpers.php'], true)) {
                continue;
            }

            $classes[] = 'App\\Engine\\' . \str_replace('/', '\\', \substr($relative, 0, -4));
        }

        \sort($classes);

        return $classes;
    }

    /**
     * Engine class names a file refers to in code: imports and qualified names.
     *
     * @return list<string>
     */
    private function engineNamesIn(string $path): array
    {
        $names = [];

        foreach (\token_get_all($this->read($path, absolute: true)) as $token) {
            if (!\is_array($token) || !\in_array($token[0], [\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = \ltrim($token[1], '\\');

            if (\str_starts_with($name, 'App\\Engine\\')) {
                $names[$name] = true;
            }
        }

        return \array_keys($names);
    }

    /**
     * Hook and filter names from the lifecycle table.
     *
     * @return array{list<string>, list<string>}
     */
    private function lifecycleTable(): array
    {
        $page = $this->read(self::LIFECYCLE_PAGE);
        $start = \strpos($page, '## Lifecycle extension points');
        self::assertIsInt($start, self::LIFECYCLE_PAGE . ' has no "Lifecycle extension points" section');

        $end = \preg_match('/\n#{1,2} /', $page, $next, \PREG_OFFSET_CAPTURE, $start + 1) === 1 ? $next[0][1] : null;
        $section = \substr($page, $start, $end === null ? null : $end - $start);

        $hooks = [];
        $filters = [];

        foreach (\explode("\n", $section) as $line) {
            if (!\str_starts_with($line, '| `')) {
                continue;
            }

            $cells = \explode('|', \trim($line, " |\r"));

            \preg_match_all('/`([a-z0-9_.]+)`/', $cells[0], $left);
            \preg_match_all('/^\s*`([a-z0-9_.]+)`/', $cells[1] ?? '', $right);

            \array_push($hooks, ...$left[1]);
            \array_push($filters, ...$right[1]);
        }

        \sort($hooks);
        \sort($filters);

        return [$hooks, $filters];
    }

    /**
     * Every hook fired and filter applied by engine code, by literal name.
     *
     * A name held in a class constant -- Authorizer::DECISION_FILTER -- is
     * resolved within its own file. A name that is neither, like the helpers
     * forwarding whatever they are given, is not an extension point the engine
     * defines.
     *
     * @return array{list<string>, list<string>}
     */
    private function extensionPointsInEngine(): array
    {
        $found = ['do' => [], 'apply' => []];

        foreach ($this->phpFilesUnder('engine') as $path) {
            $source = $this->read($path, absolute: true);

            \preg_match_all("/->(do|apply)\\(\\s*'([a-z0-9_.]+)'/", $source, $literals, \PREG_SET_ORDER);

            foreach ($literals as [, $method, $name]) {
                $found[$method][$name] = true;
            }

            \preg_match_all('/->(do|apply)\(\s*self::([A-Z_]+)/', $source, $constants, \PREG_SET_ORDER);

            foreach ($constants as [, $method, $constant]) {
                if (\preg_match('/const\s+' . $constant . "\\s*=\\s*'([a-z0-9_.]+)'/", $source, $value) === 1) {
                    $found[$method][$value[1]] = true;
                }
            }
        }

        $hooks = \array_keys($found['do']);
        $filters = \array_keys($found['apply']);
        \sort($hooks);
        \sort($filters);

        return [$hooks, $filters];
    }

    /**
     * GitHub's anchors for every heading: lower case, punctuation dropped,
     * spaces to hyphens, and "-1", "-2" for a repeat.
     *
     * @return list<string>
     */
    private function anchors(string $markdown): array
    {
        $anchors = [];
        $seen = [];

        \preg_match_all('/^#{1,6}\s+(.+?)\s*$/m', $markdown, $headings);

        foreach ($headings[1] as $heading) {
            $slug = (string) \preg_replace('/[^\p{L}\p{N} _-]/u', '', \mb_strtolower($heading));
            $slug = \str_replace(' ', '-', $slug);

            $count = $seen[$slug] ?? 0;
            $seen[$slug] = $count + 1;
            $anchors[] = $count === 0 ? $slug : $slug . '-' . $count;
        }

        return $anchors;
    }

    /** Fenced code, removed: "# comment" in a bash block is not a heading, and a link in code is not a link. */
    private function withoutCodeBlocks(string $markdown): string
    {
        return (string) \preg_replace('/^```.*?^```/ms', '', $markdown);
    }

    /**
     * Every document a reader is pointed at, relative to the project root.
     *
     * @return list<string>
     */
    private function documents(): array
    {
        $documents = ['README.md', 'STABILITY.md', 'CHANGELOG.md', 'UPGRADING.md'];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath('docs'), \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'md') {
                continue;
            }

            $relative = $this->relative($file->getPathname());

            // Working plans are git-ignored: present on one machine, absent from
            // every clone, so nothing published may depend on them.
            if (!\str_starts_with($relative, 'docs/plans/')) {
                $documents[] = $relative;
            }
        }

        \sort($documents);

        return $documents;
    }

    /** "docs/guides/../reference/x.md" to "docs/reference/x.md". */
    private function normalise(string $path): string
    {
        $parts = [];

        foreach (\explode('/', \str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                \array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        return \implode('/', $parts);
    }

    /** @return list<string> */
    private function phpFilesUnder(string $relative): array
    {
        $directory = $this->basePath($relative);

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

    private function read(string $path, bool $absolute = false): string
    {
        $contents = \file_get_contents($absolute ? $path : $this->basePath($path));
        self::assertIsString($contents, 'could not read ' . $path);

        return $contents;
    }

    private function relative(string $path): string
    {
        return \str_replace([$this->basePath() . \DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
    }
}
