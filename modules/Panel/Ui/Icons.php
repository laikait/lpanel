<?php

declare(strict_types=1);

namespace App\Modules\Panel\Ui;

/**
 * `{{ icon('server') }}`: one symbol from the Panel sprite as inline SVG.
 *
 * The sprite is a published asset, so the browser caches it once and every
 * icon on every page is a small <use> reference.
 */
final class Icons
{
    private ?string $sprite = null;

    public function svg(string $name, string $class = ''): string
    {
        $this->sprite ??= asset()->module('Panel', 'icons.svg');
        $name = \preg_replace('/[^a-z0-9-]/', '', $name) ?? 'dot';
        $class = \htmlspecialchars(\trim('ic ' . $class), \ENT_QUOTES);

        return '<svg class="' . $class . '" aria-hidden="true"><use href="'
            . \htmlspecialchars($this->sprite . '#' . $name, \ENT_QUOTES) . '"></use></svg>';
    }
}
