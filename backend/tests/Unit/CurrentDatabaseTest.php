<?php

namespace Tests\Unit;

use App\Shared\Support\CurrentDatabase;
use PHPUnit\Framework\TestCase;

class CurrentDatabaseTest extends TestCase
{
    public function test_on_a_server_with_many_databases_only_the_current_one_is_listed(): void
    {
        // The bug this guards against: with no schema Laravel lists the tables of every database on the MySQL server,
        // so a school's backup tried to read the central database's `domains` table.
        $this->assertSame('madrasati_school1', CurrentDatabase::schemaFor('mysql', 'madrasati_school1', fn () => 'x'));
        $this->assertSame('madrasati_school1', CurrentDatabase::schemaFor('mariadb', 'madrasati_school1', fn () => 'x'));
        $this->assertSame('public', CurrentDatabase::schemaFor('pgsql', 'db', fn () => 'public'));
        $this->assertNull(CurrentDatabase::schemaFor('sqlite', ':memory:', fn () => 'x'));
    }
}
