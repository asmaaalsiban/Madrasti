<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A survey goes to: all parents, the sections of a range of grades (from..to), or a hand-picked set of sections.
        Schema::table('surveys', function (Blueprint $t) {
            $t->foreignId('grade_from_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            $t->foreignId('grade_to_id')->nullable()->constrained('grade_levels')->nullOnDelete();
        });

        Schema::create('survey_classrooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->unique(['survey_id', 'classroom_id']);
        });

        // Surveys made when only one section could be chosen keep working as "chosen sections".
        foreach (DB::table('surveys')->where('audience', 'classroom')->whereNotNull('classroom_id')->get() as $survey) {
            DB::table('survey_classrooms')->insert(['survey_id' => $survey->id, 'classroom_id' => $survey->classroom_id]);
        }
        DB::table('surveys')->where('audience', 'classroom')->update(['audience' => 'classrooms']);
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_classrooms');
        Schema::table('surveys', function (Blueprint $t) {
            $t->dropConstrainedForeignId('grade_from_id');
            $t->dropConstrainedForeignId('grade_to_id');
        });
    }
};
