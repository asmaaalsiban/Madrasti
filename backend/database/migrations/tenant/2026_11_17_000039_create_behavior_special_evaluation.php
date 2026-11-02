<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A "special evaluation": criteria written for ONE student (a child with an individual plan, a particular difficulty...)
        // that only appear when that student is evaluated. The general criteria are untouched.
        Schema::create('behavior_student_criteria', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('behavior_special_scores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('behavior_evaluation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('behavior_student_criterion_id')->constrained('behavior_student_criteria')->cascadeOnDelete();
            $t->unsignedTinyInteger('score');
            $t->unique(['behavior_evaluation_id', 'behavior_student_criterion_id'], 'behavior_special_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_special_scores');
        Schema::dropIfExists('behavior_student_criteria');
    }
};
