<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->enum('type', ['classroom', 'lab', 'hall', 'other'])->default('classroom');
            $t->unsignedSmallInteger('capacity')->nullable();
            $t->timestamps();
        });

        // Time slots of the school day
        Schema::create('periods', function (Blueprint $t) {
            $t->id();
            $t->unsignedTinyInteger('day_of_week'); // 0 = Sunday ... 6 = Saturday
            $t->unsignedTinyInteger('number');
            $t->time('starts_at');
            $t->time('ends_at');
            $t->boolean('is_break')->default(false);
            $t->unique(['day_of_week', 'number']);
            $t->timestamps();
        });

        Schema::create('teacher_unavailabilities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('period_id')->constrained()->cascadeOnDelete();
            $t->string('reason')->nullable();
            $t->unique(['teacher_id', 'period_id']);
            $t->timestamps();
        });

        // One run of the scheduler (supports regenerate / preview / apply)
        Schema::create('schedule_generations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('academic_year_id')->constrained();
            $t->enum('kind', ['timetable', 'exams', 'duties']);
            $t->enum('status', ['queued', 'running', 'completed', 'failed', 'applied'])->default('queued');
            $t->json('constraints')->nullable();
            $t->json('report')->nullable(); // conflicts, unplaced items
            $t->foreignId('requested_by')->constrained('users');
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
        });

        Schema::create('timetable_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('academic_year_id')->constrained();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('period_id')->constrained();
            $t->foreignId('subject_id')->constrained();
            $t->foreignId('teacher_id')->constrained('users');
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('schedule_generation_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('is_locked')->default(false); // manual edits survive regeneration
            // DB-level conflict prevention
            $t->unique(['academic_year_id', 'classroom_id', 'period_id'], 'tt_class_period_unique');
            $t->unique(['academic_year_id', 'teacher_id', 'period_id'], 'tt_teacher_period_unique');
            $t->unique(['academic_year_id', 'room_id', 'period_id'], 'tt_room_period_unique');
            $t->timestamps();
        });

        Schema::create('exam_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('term_id')->constrained();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('schedule_generation_id')->nullable()->constrained()->nullOnDelete();
            $t->date('exam_date');
            $t->time('starts_at');
            $t->time('ends_at');
            $t->unique(['term_id', 'classroom_id', 'subject_id']);
            $t->timestamps();
        });

        Schema::create('exam_invigilators', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->unique(['exam_session_id', 'user_id']);
            $t->timestamps();
        });

        Schema::create('duty_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('schedule_generation_id')->nullable()->constrained()->nullOnDelete();
            $t->date('date');
            $t->time('starts_at');
            $t->time('ends_at');
            $t->string('location');
            $t->boolean('is_locked')->default(false);
            $t->index(['date', 'user_id']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['duty_assignments', 'exam_invigilators', 'exam_sessions', 'timetable_entries',
                  'schedule_generations', 'teacher_unavailabilities', 'periods', 'rooms'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
