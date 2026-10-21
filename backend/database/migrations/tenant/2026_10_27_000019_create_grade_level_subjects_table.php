<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The curriculum: which subjects a grade has and how many periods a week each gets.
        Schema::create('grade_level_subjects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('grade_level_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('weekly_periods');
            $t->unique(['grade_level_id', 'subject_id']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_level_subjects');
    }
};
