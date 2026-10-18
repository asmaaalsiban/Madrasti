<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name_ar');
            $t->string('name_en');
            $t->smallInteger('points'); // positive or negative
            $t->string('icon')->nullable();
            $t->timestamps();
        });

        Schema::create('point_awards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('point_category_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('teacher_id')->constrained('users');
            $t->smallInteger('points');
            $t->string('note')->nullable();
            $t->timestamp('awarded_at')->useCurrent();
            $t->index(['student_id', 'awarded_at']);
            $t->timestamps();
        });

        Schema::create('badges', function (Blueprint $t) {
            $t->id();
            $t->string('name_ar');
            $t->string('name_en');
            $t->string('description')->nullable();
            $t->string('icon')->nullable();
            $t->timestamps();
        });

        Schema::create('student_badges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $t->foreignId('awarded_by')->constrained('users');
            $t->timestamp('awarded_at')->useCurrent();
            $t->timestamps();
        });

        Schema::create('star_of_week', function (Blueprint $t) {
            $t->id();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('selected_by')->constrained('users');
            $t->date('week_starts_on');
            $t->string('reason')->nullable();
            $t->unique(['classroom_id', 'week_starts_on']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['star_of_week', 'student_badges', 'badges', 'point_awards', 'point_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
