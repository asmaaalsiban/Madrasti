<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained();
            $t->foreignId('subject_id')->constrained();
            $t->foreignId('term_id')->constrained();
            $t->foreignId('teacher_id')->constrained('users');
            $t->string('title');
            $t->enum('type', ['quiz', 'exam', 'assignment', 'project', 'participation']);
            $t->decimal('max_score', 6, 2);
            $t->decimal('weight', 5, 2)->default(0); // percent of term grade
            $t->date('held_on')->nullable();
            $t->boolean('is_published')->default(false);
            $t->timestamps();
        });

        Schema::create('assessment_scores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->decimal('score', 6, 2)->nullable();
            $t->string('remark')->nullable();
            $t->unique(['assessment_id', 'student_id']);
            $t->timestamps();
        });

        Schema::create('behavior_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('teacher_id')->constrained('users');
            $t->enum('type', ['positive', 'negative', 'neutral']);
            $t->text('note');
            $t->boolean('visible_to_parent')->default(true);
            $t->date('noted_on');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['behavior_notes', 'assessment_scores', 'assessments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
