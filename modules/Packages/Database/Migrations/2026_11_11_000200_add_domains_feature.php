<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Migration;

/**
 * "domains" became a feature in M2 (sub-users may be given the Domains page).
 * Packages made before then offered domains without saying so, so they get it.
 */
return new class implements Migration {
    public function up(Tables $tables): void
    {
        $tables->raw(
            "UPDATE packages SET features = CASE WHEN features = '[]' THEN '[\"domains\"]' "
            . "ELSE REPLACE(features, '[', '[\"domains\",') END WHERE features NOT LIKE '%\"domains\"%'",
            'mysql',
            'sqlite',
        );
    }
};
