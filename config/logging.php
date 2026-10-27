<?php

declare(strict_types=1);

use App\Engine\Config\Env;

/*
 * Where log lines go: a comma-separated LOG_WRITERS of file (system/Logs),
 * stderr, syslog or database. LPHP's default is none at all, which would also
 * swallow a development email sent with MAIL_TRANSPORT=log.
 */
return [
    'writers' => \array_values(\array_filter(\array_map('trim', \explode(',', Env::string('LOG_WRITERS', '') ?? '')))),
];
