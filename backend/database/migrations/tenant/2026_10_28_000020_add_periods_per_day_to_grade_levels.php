<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How many periods a day this grade has (e.g. 6 in the lower grades); null = the whole school day.
        Schema::table('grade_levels', function (Blueprint $t) {
            $t->unsignedTinyInteger('periods_per_day')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('grade_levels', function (Blueprint $t) {
            $t->dropColumn('periods_per_day');
        });
    }
};
