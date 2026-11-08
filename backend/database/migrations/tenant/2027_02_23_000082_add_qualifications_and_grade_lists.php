<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What a teacher studied and has done (on their profile), and the exact grades of each extra subject. */
    public function up(): void
    {
        if (! Schema::hasColumn('staff_profiles', 'degree')) {
            Schema::table('staff_profiles', function (Blueprint $t): void {
                $t->string('degree', 30)->nullable();
                $t->string('specialization', 120)->nullable();
                $t->string('university', 120)->nullable();
                $t->unsignedSmallInteger('graduation_year')->nullable();
                $t->unsignedTinyInteger('experience_years')->nullable();
                $t->string('courses', 500)->nullable();
            });
        }
        if (! Schema::hasColumn('staff_subjects', 'grade_ids')) {
            Schema::table('staff_subjects', function (Blueprint $t): void {
                $t->json('grade_ids')->nullable();
            });
            // The old "from grade to grade" becomes the list of the grades between them.
            $order = DB::table('grade_levels')->pluck('sort_order', 'id');
            foreach (DB::table('staff_subjects')->whereNotNull('grade_from_id')->whereNotNull('grade_to_id')->get() as $row) {
                $from = $order[$row->grade_from_id] ?? null;
                $to = $order[$row->grade_to_id] ?? null;
                if ($from === null || $to === null) {
                    continue;
                }
                $ids = $order->filter(fn ($o) => $o >= $from && $o <= $to)->keys()->values()->all();
                DB::table('staff_subjects')->where('id', $row->id)->update(['grade_ids' => json_encode($ids)]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('staff_subjects', 'grade_ids')) {
            Schema::table('staff_subjects', function (Blueprint $t): void {
                $t->dropColumn('grade_ids');
            });
        }
        if (Schema::hasColumn('staff_profiles', 'degree')) {
            Schema::table('staff_profiles', function (Blueprint $t): void {
                $t->dropColumn(['degree', 'specialization', 'university', 'graduation_year', 'experience_years', 'courses']);
            });
        }
    }
};
