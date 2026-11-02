<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Looks at the structure of the database the application is connected to RIGHT NOW (inside a school: the school's own
 * database). Laravel's `Schema::getTables()` with no argument lists the tables of EVERY database on a MySQL or
 * PostgreSQL server, so on a server that holds all the schools' databases it would mix them up; this always names the
 * current database. It also asks the live connection instead of the cached `Schema` facade.
 */
final class CurrentDatabase
{
    /** The schema builder of the current default connection. */
    public static function schema(): Builder
    {
        return DB::connection()->getSchemaBuilder();
    }

    /** @return list<string> the names of the tables of the current database (no SQLite internals). */
    public static function tables(): array
    {
        $connection = DB::connection();
        $schema = self::schemaFor($connection->getDriverName(), (string) $connection->getDatabaseName(), fn () => (string) $connection->scalar('select current_schema()'));

        return array_values(array_filter(
            array_map(fn (array $t) => $t['name'], self::schema()->getTables($schema)),
            fn (string $name) => ! str_starts_with($name, 'sqlite_'),
        ));
    }

    /**
     * The schema argument that limits `getTables()` to the current database: the database name on MySQL/MariaDB, the
     * current schema on PostgreSQL, nothing on SQLite (one file is one database).
     *
     * @param  callable(): string  $postgresSchema
     */
    public static function schemaFor(string $driver, string $database, callable $postgresSchema): ?string
    {
        return match ($driver) {
            'mysql', 'mariadb' => $database,
            'pgsql' => $postgresSchema(),
            default => null,
        };
    }
}
