<?php

declare(strict_types=1);

/*
 * A configuration file, which is a PHP file that returns an array.
 *
 * The filename is the namespace, so everything here is reachable as "app.*".
 */

use App\Engine\Config\Env;

return [
    'name' => 'Fixture',
    'debug' => Env::bool('FIXTURE_DEBUG', false),
    'nested' => ['kept' => 'yes'],
];
