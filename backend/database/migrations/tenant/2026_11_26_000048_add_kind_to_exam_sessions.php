<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A term has several exam rounds: monthly exams (round 1, 2, 3...) that sit in lesson periods, and the final exams (round 0) with their own times.
// Written so it can be run again after a partial run (MySQL cannot roll back schema changes), and so the new unique key exists
// before the old one is dropped (the term foreign key needs an index that starts with term_id).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('exam_sessions', 'kind')) {
            Schema::table('exam_sessions', function (Blueprint $table): void {
                $table->string('kind', 10)->default('monthly')->after('term_id');
            });
        }
        if (! Schema::hasColumn('exam_sessions', 'round')) {
            Schema::table('exam_sessions', function (Blueprint $table): void {
                $table->unsignedTinyInteger('round')->default(1)->after('kind');
            });
        }
        if (! Schema::hasIndex('exam_sessions', 'exam_sessions_round_unique')) {
            Schema::table('exam_sessions', function (Blueprint $table): void {
                $table->unique(['term_id', 'kind', 'round', 'classroom_id', 'subject_id'], 'exam_sessions_round_unique');
            });
        }
        if (Schema::hasIndex('exam_sessions', ['term_id', 'classroom_id', 'subject_id'], 'unique')) {
            Schema::table('exam_sessions', function (Blueprint $table): void {
                $table->dropUnique(['term_id', 'classroom_id', 'subject_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->unique(['term_id', 'classroom_id', 'subject_id']);
        });
        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->dropUnique('exam_sessions_round_unique');
        });
        Schema::table('exam_sessions', fn (Blueprint $table) => $table->dropColumn(['kind', 'round']));
    }
};
