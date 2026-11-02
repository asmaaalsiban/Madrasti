<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The yearly re-registration of students: one row per student per next academic year.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_renewals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('from_year_id')->constrained('academic_years');
            $t->foreignId('to_year_id')->constrained('academic_years');
            $t->foreignId('from_classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
            $t->foreignId('from_grade_level_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            $t->foreignId('to_grade_level_id')->nullable()->constrained('grade_levels')->nullOnDelete(); // null: last grade, nothing to move to
            $t->foreignId('to_classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
            $t->string('status', 20)->default('pending'); // pending | renewed | declined | graduating
            $t->string('decline_reason', 500)->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'to_year_id']);
            $t->index(['to_year_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_renewals');
    }
};
