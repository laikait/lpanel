<?php

declare(strict_types=1);

namespace App\Engine\Database;

/**
 * How much of other transactions' work a transaction can see.
 *
 *     $connection->transaction($callback, isolation: IsolationLevel::Serializable);
 *
 * Each dialect writes the level where its database needs it -- before BEGIN
 * on MySQL and SQL Server, first thing inside on PostgreSQL -- and a level a
 * database cannot give is refused before the transaction starts, never quietly
 * replaced with a weaker one.
 */
enum IsolationLevel: string
{
    case ReadUncommitted = 'READ UNCOMMITTED';
    case ReadCommitted = 'READ COMMITTED';
    case RepeatableRead = 'REPEATABLE READ';
    case Serializable = 'SERIALIZABLE';
}
