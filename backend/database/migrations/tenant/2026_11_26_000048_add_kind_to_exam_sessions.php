<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A term has several exam rounds: monthly exams (round 1, 2, 3...) that sit in lesson periods, and the final exams (round 0) with their own times.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->string('kind', 10)->default('monthly')->after('term_id');
            $table->unsignedTinyInteger('round')->default(1)->after('kind');
        });
        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->dropUnique(['term_id', 'classroom_id', 'subject_id']);
            $table->unique(['term_id', 'kind', 'round', 'classroom_id', 'subject_id'], 'exam_sessions_round_unique');
        });
    }

    public function down(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->dropUnique('exam_sessions_round_unique');
            $table->unique(['term_id', 'classroom_id', 'subject_id']);
        });
        Schema::table('exam_sessions', fn (Blueprint $table) => $table->dropColumn(['kind', 'round']));
    }
};
