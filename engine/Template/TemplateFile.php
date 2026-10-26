<?php

declare(strict_types=1);

namespace App\Engine\Template;

/**
 * A template that has been found.
 *
 * Only TemplateManager builds one, and only after the name has passed every
 * check, so holding one of these means the question "is this file allowed to be
 * rendered" has already been answered. Same bargain as AssetReference in the
 * asset layer, for the same reason.
 *
 * Both a root and a path relative to it are carried, because the two engines
 * need different things: PHP includes the absolute path, and Twig wants a
 * loader root plus a name relative to it so that {% extends %} inside the file
 * resolves against the same search path everything else does.
 */
final class TemplateFile
{
    public function __construct(
        /** What the caller asked for, e.g. "customer/profile" or "@Billing/invoice". */
        public readonly string $name,
        /** The directory it was found in, normalised. */
        public readonly string $root,
        /** Its path below that directory, e.g. "customer/profile.php". */
        public readonly string $relativePath,
        public readonly string $absolutePath,
        public readonly string $extension,
    ) {}

    public function contents(): string
    {
        $contents = \file_get_contents($this->absolutePath);

        return $contents === false ? '' : $contents;
    }
}
