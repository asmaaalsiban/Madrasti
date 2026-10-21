<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Short name used to name the sections (KG1-A, 4-B): falls back to the sort order when empty.
        Schema::table('grade_levels', function (Blueprint $t) {
            $t->string('code', 10)->nullable()->after('name_en');
        });

        // Who the section is for; null (sections created before this column) means mixed.
        Schema::table('classrooms', function (Blueprint $t) {
            $t->enum('gender', ['male', 'female', 'mixed'])->nullable()->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $t) {
            $t->dropColumn('gender');
        });
        Schema::table('grade_levels', function (Blueprint $t) {
            $t->dropColumn('code');
        });
    }
};
