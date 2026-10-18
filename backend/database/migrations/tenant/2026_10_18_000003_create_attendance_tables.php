<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained();
            $t->date('date');
            $t->enum('status', ['present', 'absent', 'late', 'excused']);
            $t->string('note')->nullable();
            $t->foreignId('marked_by')->constrained('users');
            $t->unique(['student_id', 'date']);
            $t->index(['classroom_id', 'date']);
            $t->timestamps();
        });

        // HR: staff attendance and worked hours
        Schema::create('staff_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('job_title')->nullable();
            $t->date('hired_on')->nullable();
            $t->unsignedSmallInteger('weekly_hours')->nullable();
            $t->timestamps();
        });

        Schema::create('staff_attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->time('check_in')->nullable();
            $t->time('check_out')->nullable();
            $t->unsignedSmallInteger('worked_minutes')->default(0);
            $t->enum('status', ['present', 'absent', 'late', 'leave']);
            $t->string('note')->nullable();
            $t->unique(['user_id', 'date']);
            $t->timestamps();
        });

        Schema::create('staff_evaluations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('evaluator_id')->constrained('users');
            $t->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('score', 5, 2);
            $t->text('strengths')->nullable();
            $t->text('improvements')->nullable();
            $t->date('evaluated_on');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['staff_evaluations', 'staff_attendances', 'staff_profiles', 'student_attendances'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
