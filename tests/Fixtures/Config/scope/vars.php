<?php

declare(strict_types=1);

/* Reports what a configuration file can see. Should be nothing but its own path. */

return ['visible' => array_keys(get_defined_vars())];
