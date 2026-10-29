<?php

declare(strict_types=1);

/*
 * Configuration for one module.
 *
 * The path is the module's id: modules/Plugins/Example has the id
 * "Example", so config/Example.php is where an application
 * overrides what that module declared for itself.
 *
 * The direction is the point. The module declares page_size = 25 in its
 * module.php; that is a default, written by whoever wrote the module. This file
 * is the decision, made by whoever runs the installation, and it wins -- even
 * though the module registers long after this file is read.
 *
 * Only the keys named here are overridden. The rest of the module's defaults
 * are untouched, so this file never has to be kept in step with the module's.
 */

return [
    'page_size' => 10,
];
