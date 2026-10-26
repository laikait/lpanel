<?php

declare(strict_types=1);

namespace App\Engine\Template;

/**
 * What it takes to be a template engine here.
 *
 * Two methods. The engine says which file extensions it claims, and it renders
 * a file that the manager has already found — **finding is not the engine's
 * job**. That split is what keeps the resolution rules (search order, the
 * override rule, the containment check) identical no matter which engine ends
 * up rendering, and it is why "no extension is required" can be true: the
 * manager knows every extension anybody claims, so it can try them all.
 *
 * The interface is not called TemplateEngineInterface. Nothing else in this
 * framework carries that suffix -- DataSource is an interface too -- and one
 * exception would be the beginning of two conventions.
 */
interface TemplateEngine
{
    /**
     * The extensions this engine renders, without the leading dot, most
     * specific first.
     *
     * "html.twig" before "twig" matters: the manager matches the longest one,
     * so profile.html.twig is a Twig template rather than a file called
     * "profile.html" that happens to end in something.
     *
     * @return list<string>
     */
    public function extensions(): array;

    /**
     * Render, and return the output rather than printing it.
     *
     * Returning a string is what lets a template be composed into another one,
     * captured in a test, or sent as part of a response the handler builds. An
     * engine that echoed would make all three awkward.
     *
     * @param array<string, mixed> $data
     */
    public function render(TemplateFile $file, array $data, TemplateView $view): string;
}
