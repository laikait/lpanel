<?php

declare(strict_types=1);

namespace App\Modules\Panel\Ui;

use App\Engine\Template\ThemeRegistry;

/**
 * `{{ icon('server') }}`: one symbol from the active template's sprite as
 * inline SVG.
 *
 * The sprite is a published asset of the template in use
 * (templates/admin/nova/assets/icons.svg), so the browser caches it once and
 * every icon on every page is a small <use> reference.
 */
final class Icons
{
    /** @var array<string, string> template => the sprite's URL */
    private array $sprites = [];

    public function __construct(private readonly ThemeRegistry $themes) {}

    public function svg(string $name, string $class = ''): string
    {
        $theme = $this->themes->active() ?? throw new \LogicException('icon() needs a template: pages are rendered through PanelPage.');
        $sprite = $this->sprites[$theme] ??= asset()->template($theme, 'icons.svg');
        $name = \preg_replace('/[^a-z0-9-]/', '', $name) ?? 'dot';
        $class = \htmlspecialchars(\trim('ic ' . $class), \ENT_QUOTES);

        return '<svg class="' . $class . '" aria-hidden="true"><use href="'
            . \htmlspecialchars($sprite . '#' . $name, \ENT_QUOTES) . '"></use></svg>';
    }
}
