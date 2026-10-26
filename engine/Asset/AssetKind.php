<?php

declare(strict_types=1);

namespace App\Engine\Asset;

/**
 * The three logical asset namespaces.
 *
 * These are the *only* namespaces that exist. An asset URL cannot name a
 * directory, only a namespace and a path inside it, which is what "assets must
 * never expose physical application directories" means in practice: there is no
 * spelling of an asset URL that says anything about where the file lives.
 *
 * The value is both the URL segment and the registry key prefix.
 */
enum AssetKind: string
{
    /** The application's own assets: /assets/ on disk, /assets/core/... in a URL. */
    case Core = 'core';

    /** The active template, or a named one: /templates/assets/, /templates/admin/assets/. */
    case Template = 'template';

    /** A module's assets/ directory: /assets/module/Billing/... */
    case Module = 'module';

    /**
     * Whether a source of this kind is meaningless without a name.
     *
     * Template is the interesting one: it is legal both ways, because the
     * specification's API has template('css/app.css') for the active template
     * and template('admin', 'css/admin.css') for a named one.
     */
    public function requiresName(): bool
    {
        return $this === self::Module;
    }

    public function allowsName(): bool
    {
        return $this !== self::Core;
    }

    /** A human label for error messages: "module 'Billing'", "core". */
    public function describe(?string $name = null): string
    {
        return $name === null || $name === '' ? $this->value : $this->value . " '" . $name . "'";
    }
}
