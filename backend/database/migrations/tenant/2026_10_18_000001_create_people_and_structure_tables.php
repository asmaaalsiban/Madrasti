<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable()->unique();
            $t->string('phone')->nullable()->unique();
            $t->string('password');
            $t->enum('role', ['admin', 'teacher', 'student', 'parent'])->index();
            $t->string('locale', 5)->default('ar');
            $t->string('avatar_path')->nullable();
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('academic_years', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->boolean('is_current')->default(false);
            $t->timestamps();
        });

        Schema::create('terms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->timestamps();
        });

        Schema::create('grade_levels', function (Blueprint $t) { // grade level (e.g. Grade 5)
            $t->id();
            $t->string('name_ar');
            $t->string('name_en');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('classrooms', function (Blueprint $t) { // a section (e.g. 5-A)
            $t->id();
            $t->foreignId('grade_level_id')->constrained('grade_levels');
            $t->foreignId('academic_year_id')->constrained();
            $t->foreignId('homeroom_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('name');
            $t->unsignedSmallInteger('capacity')->nullable();
            $t->timestamps();
        });

        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name_ar');
            $t->string('name_en');
            $t->timestamps();
        });

        // Who teaches what to which class, and weekly load (used by the scheduler)
        Schema::create('classroom_subject_teacher', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained();
            $t->foreignId('teacher_id')->constrained('users');
            $t->unsignedTinyInteger('weekly_periods')->default(1);
            $t->unique(['classroom_id', 'subject_id']);
            $t->timestamps();
        });

        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->string('student_number')->unique();
            $t->string('name_ar');
            $t->string('name_en')->nullable();
            $t->date('birth_date')->nullable();
            $t->enum('gender', ['male', 'female'])->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('enrollments', function (Blueprint $t) { // student <-> class per year
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained();
            $t->foreignId('academic_year_id')->constrained();
            $t->unique(['student_id', 'academic_year_id']);
            $t->timestamps();
        });

        // Multi-child parents: one parent user <-> many students
        Schema::create('guardian_student', function (Blueprint $t) {
            $t->id();
            $t->foreignId('guardian_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->string('relation')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->unique(['guardian_id', 'student_id']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['guardian_student', 'enrollments', 'students', 'classroom_subject_teacher', 'subjects',
                  'classrooms', 'grade_levels', 'terms', 'academic_years', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
