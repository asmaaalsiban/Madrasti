<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a student is evaluated on; the school can add its own and hide or remove the ready-made ones.
        Schema::create('behavior_criteria', function (Blueprint $t) {
            $t->id();
            $t->string('name_ar');
            $t->string('name_en');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // One teacher's evaluation of one student for one week (Sunday to Saturday). Months and terms are built from these.
        Schema::create('behavior_evaluations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained('users');
            $t->date('week_starts_on');
            $t->date('evaluated_on');
            $t->text('note')->nullable();
            $t->boolean('visible_to_parent')->default(true);
            $t->unique(['student_id', 'teacher_id', 'week_starts_on'], 'behavior_week_unique');
            $t->timestamps();
        });

        Schema::create('behavior_evaluation_scores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('behavior_evaluation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('behavior_criterion_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('score');
            $t->unique(['behavior_evaluation_id', 'behavior_criterion_id'], 'behavior_score_unique');
        });

        $now = now();
        DB::table('behavior_criteria')->insert(array_map(fn (array $c, int $i) => [
            'name_ar' => $c[0], 'name_en' => $c[1], 'sort_order' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], [
            ['الانضباط', 'Discipline'], ['الاحترام', 'Respect'], ['المشاركة', 'Participation'], ['التعاون', 'Cooperation'],
            ['المسؤولية', 'Responsibility'], ['النظافة والمحافظة على الممتلكات', 'Cleanliness and care of property'], ['الصدق والأمانة', 'Honesty'],
        ], range(0, 6)));
    }

    public function down(): void
    {
        foreach (['behavior_evaluation_scores', 'behavior_evaluations', 'behavior_criteria'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
