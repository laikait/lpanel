<?php

declare(strict_types=1);

namespace App\Modules\Operations\Updates;

use App\Engine\Config\Config;
use App\Engine\Core\Application;

/**
 * Which release of LPanel this is: the VERSION file the installer puts next to
 * the code (config operations.version_file names another, for tests).
 */
final class PanelRelease
{
    public function __construct(
        private readonly Application $app,
        private readonly Config $config,
    ) {}

    /** The release, or '' for a copy that was not installed from a bundle (development). */
    public function installed(): string
    {
        $file = $this->config->get('operations.version_file');
        $file = \is_string($file) && $file !== '' ? $file : $this->app->basePath('VERSION');
        $v = \is_file($file) ? \trim((string) \file_get_contents($file)) : '';

        return Updates::validVersion($v) ? $v : '';
    }
}
