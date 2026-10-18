<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('created_by')->constrained('users');
            $t->enum('type', ['poll', 'survey', 'approval']); // approval = e.g. trip permission
            $t->string('title');
            $t->text('description')->nullable();
            $t->timestamp('opens_at')->nullable();
            $t->timestamp('closes_at')->nullable();
            $t->timestamps();
        });

        Schema::create('survey_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $t->enum('type', ['single_choice', 'multiple_choice', 'text', 'yes_no']);
            $t->string('text');
            $t->json('options')->nullable();
            $t->boolean('is_required')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        // Who must respond: a guardian, optionally per child (approvals are per child)
        Schema::create('survey_recipients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->nullable()->constrained()->cascadeOnDelete();
            $t->unique(['survey_id', 'user_id', 'student_id']);
            $t->timestamps();
        });

        Schema::create('survey_responses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->nullable()->constrained()->cascadeOnDelete();
            $t->enum('decision', ['approved', 'declined'])->nullable(); // for approval type
            $t->timestamp('submitted_at')->useCurrent();
            $t->unique(['survey_id', 'user_id', 'student_id']);
            $t->timestamps();
        });

        Schema::create('survey_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('survey_response_id')->constrained()->cascadeOnDelete();
            $t->foreignId('survey_question_id')->constrained()->cascadeOnDelete();
            $t->json('value');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['survey_answers', 'survey_responses', 'survey_recipients',
                  'survey_questions', 'surveys'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
