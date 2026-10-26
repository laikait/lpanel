<?php

declare(strict_types=1);

namespace App\Engine\Migration;

use App\Engine\Database\Structure\Tables;

/**
 * A migration that can be undone.
 *
 * Being undoable is part of the type, so migrate:rollback can check every
 * migration it would undo before it undoes any of them.
 */
interface Reversible extends Migration
{
    public function down(Tables $tables): void;
}
