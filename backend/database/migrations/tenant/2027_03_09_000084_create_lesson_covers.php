<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A teacher who covers a lesson of an absent teacher on one date. */
    public function up(): void
    {
        if (! Schema::hasTable('lesson_covers')) {
            Schema::create('lesson_covers', function (Blueprint $t): void {
                $t->id();
                $t->date('date');
                $t->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
                $t->foreignId('classroom_id')->constrained('classrooms')->cascadeOnDelete();
                $t->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
                $t->foreignId('absent_teacher_id')->constrained('users')->cascadeOnDelete();
                $t->foreignId('cover_teacher_id')->constrained('users')->cascadeOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->unique(['date', 'period_id', 'classroom_id']);
                $t->index(['date', 'cover_teacher_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_covers');
    }
};
