<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The track of a secondary grade (health, business...): grades with the same order but different tracks are alternatives at the same level. */
    public function up(): void
    {
        if (! Schema::hasColumn('grade_levels', 'track')) {
            Schema::table('grade_levels', function (Blueprint $t): void {
                $t->string('track', 60)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('grade_levels', 'track')) {
            Schema::table('grade_levels', fn (Blueprint $t) => $t->dropColumn('track'));
        }
    }
};
