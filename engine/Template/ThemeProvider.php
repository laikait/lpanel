<?php

declare(strict_types=1);

namespace App\Engine\Template;

/**
 * Where an application's themes come from, when a config list is not enough.
 *
 *     'templates' => ['theme_provider' => App\Themes\DatabaseThemes::class],
 *
 * The class is built through the container, so it may take a connection, a
 * repository or anything else bound there. It is asked once per request, and
 * that includes asset requests, which load no module -- so it should keep its
 * answer somewhere cheaper than a query if themes rarely change.
 *
 * Nothing calls this unless templates.theme_provider names it. Themes are
 * never discovered by looking at templates/: which directories are published
 * is a decision, and the filesystem is not where it is made.
 */
interface ThemeProvider
{
    /**
     * Every theme to publish: "dark" for templates/dark/, "admin/dark" for
     * templates/admin/dark/.
     *
     * @return iterable<string>
     */
    public function themes(): iterable;

    /**
     * Path prefix => theme, for error pages raised before a route matched.
     *
     *     ['/admin' => 'admin/dark']
     *
     * Merged over templates.error_themes, so an entry here wins.
     *
     * @return iterable<string, string>
     */
    public function errorThemes(): iterable;
}
