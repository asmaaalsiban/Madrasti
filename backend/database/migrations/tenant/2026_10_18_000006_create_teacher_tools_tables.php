<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_id')->constrained('users');
            $t->foreignId('classroom_id')->constrained();
            $t->foreignId('subject_id')->constrained();
            $t->date('week_starts_on');
            $t->string('title');
            $t->text('objectives')->nullable();
            $t->longText('content')->nullable();
            $t->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('review_note')->nullable();
            $t->boolean('shared_with_parents')->default(false);
            $t->unique(['teacher_id', 'classroom_id', 'subject_id', 'week_starts_on'], 'lesson_plans_week_unique');
            $t->timestamps();
        });

        Schema::create('curriculum_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained();
            $t->string('title');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $t->date('completed_on')->nullable();
            $t->timestamps();
        });

        Schema::create('daily_summaries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained();
            $t->foreignId('teacher_id')->constrained('users');
            $t->date('date');
            $t->text('summary');
            $t->timestamp('published_at')->nullable();
            $t->unique(['classroom_id', 'subject_id', 'date']);
            $t->timestamps();
        });

        Schema::create('homework_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained();
            $t->foreignId('teacher_id')->constrained('users');
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('attachment_path')->nullable();
            $t->timestamp('due_at');
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });

        Schema::create('homework_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('homework_assignment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->text('body')->nullable();
            $t->string('file_path')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->decimal('score', 6, 2)->nullable();
            $t->text('feedback')->nullable();
            $t->unique(['homework_assignment_id', 'student_id'], 'hw_submission_unique');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['homework_submissions', 'homework_assignments', 'daily_summaries',
                  'curriculum_units', 'lesson_plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
