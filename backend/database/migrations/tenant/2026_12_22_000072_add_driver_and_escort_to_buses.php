<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A bus's driver and escort can be chosen from the school's staff (the typed driver name and phone stay for a driver who is not registered). */
    public function up(): void
    {
        foreach (['driver_id', 'escort_id'] as $column) {
            if (! Schema::hasColumn('buses', $column)) {
                Schema::table('buses', function (Blueprint $t) use ($column): void {
                    $t->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['driver_id', 'escort_id'] as $column) {
            if (Schema::hasColumn('buses', $column)) {
                Schema::table('buses', function (Blueprint $t) use ($column): void {
                    $t->dropConstrainedForeignId($column);
                });
            }
        }
    }
};
