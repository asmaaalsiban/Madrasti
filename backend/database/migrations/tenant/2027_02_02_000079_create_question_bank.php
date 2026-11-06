<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Lessons and multiple-choice questions written by teachers, and the quizzes students make from them. */
    public function up(): void
    {
        if (! Schema::hasTable('question_lessons')) {
            Schema::create('question_lessons', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('grade_level_id')->constrained('grade_levels')->cascadeOnDelete();
                $t->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
                $t->string('title', 150);
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->unique(['grade_level_id', 'subject_id', 'title']);
            });
        }
        if (! Schema::hasTable('questions')) {
            Schema::create('questions', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('question_lesson_id')->constrained('question_lessons')->cascadeOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->text('body');
                $t->text('explanation')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('question_options')) {
            Schema::create('question_options', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
                $t->string('body', 500);
                $t->boolean('is_correct')->default(false);
                $t->unsignedTinyInteger('position')->default(0);
            });
        }
        if (! Schema::hasTable('quizzes')) {
            Schema::create('quizzes', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $t->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
                $t->unsignedSmallInteger('question_count');
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
                $t->index(['student_id', 'created_at']);
            });
        }
        if (! Schema::hasTable('quiz_questions')) {
            Schema::create('quiz_questions', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
                $t->foreignId('question_id')->constrained('questions')->restrictOnDelete();
                $t->unsignedSmallInteger('position');
                $t->foreignId('option_id')->nullable()->constrained('question_options')->nullOnDelete();
                $t->boolean('is_correct')->nullable();
                $t->timestamp('answered_at')->nullable();
                $t->unique(['quiz_id', 'question_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quizzes');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('question_lessons');
    }
};
