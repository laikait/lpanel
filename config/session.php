<?php

declare(strict_types=1);

use App\Engine\Config\Env;

/*
 * A panel session that can change servers should not live for ever. It ends
 * after two idle hours (the default) and, here, after twelve hours however busy
 * it is kept, so a session kept warm by an open tab still ends.
 */
return [
    'absolute' => Env::int('SESSION_ABSOLUTE', 43200),
];
