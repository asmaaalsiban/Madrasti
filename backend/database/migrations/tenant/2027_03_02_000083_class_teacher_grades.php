<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Grades where one class teacher teaches most subjects (kindergarten, grades 1-3), with the subjects kept for specialist teachers. */
    public function up(): void
    {
        if (! Schema::hasColumn('grade_levels', 'class_teacher_grade')) {
            Schema::table('grade_levels', function (Blueprint $t): void {
                $t->boolean('class_teacher_grade')->default(false);
            });
        }
        if (! Schema::hasColumn('grade_level_subjects', 'specialist')) {
            Schema::table('grade_level_subjects', function (Blueprint $t): void {
                $t->boolean('specialist')->default(false);
            });
        }
        if (! Schema::hasColumn('staff_profiles', 'is_class_teacher')) {
            Schema::table('staff_profiles', function (Blueprint $t): void {
                $t->boolean('is_class_teacher')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach ([['grade_levels', 'class_teacher_grade'], ['grade_level_subjects', 'specialist'], ['staff_profiles', 'is_class_teacher']] as [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
