<?php

declare(strict_types=1);

// The web server's document root is this directory, and nothing else in the
// project is inside it: engine/, modules/, config/ and vendor/ are one level up,
// out of reach of any URL.

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../engine/bootstrap.php';

exit($app->run());
