<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A joint lesson: one teacher teaches the same subject to two sections at once (e.g. sport, a language track). The
     * database no longer forbids a teacher twice in a period; the editor and the checker allow it only for a joint lesson.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('timetable_entries', 'is_joint')) {
            Schema::table('timetable_entries', function (Blueprint $t): void {
                $t->boolean('is_joint')->default(false);
            });
        }
        if (Schema::hasIndex('timetable_entries', 'tt_teacher_period_unique')) {
            Schema::table('timetable_entries', function (Blueprint $t): void {
                $t->dropUnique('tt_teacher_period_unique');
                $t->index(['academic_year_id', 'teacher_id', 'period_id'], 'tt_teacher_period_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('timetable_entries', 'tt_teacher_period_index')) {
            Schema::table('timetable_entries', fn (Blueprint $t) => $t->dropIndex('tt_teacher_period_index'));
        }
        if (Schema::hasColumn('timetable_entries', 'is_joint')) {
            Schema::table('timetable_entries', fn (Blueprint $t) => $t->dropColumn('is_joint'));
        }
    }
};
