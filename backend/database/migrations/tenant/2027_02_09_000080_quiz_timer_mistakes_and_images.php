<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** An optional timer and the kind of a quiz (own choice, mistakes only, retake), and a picture for a question and for its explanation. */
    public function up(): void
    {
        if (! Schema::hasColumn('quizzes', 'kind')) {
            Schema::table('quizzes', function (Blueprint $t): void {
                $t->string('kind', 12)->default('practice');
                $t->unsignedSmallInteger('time_limit_minutes')->nullable();
                $t->timestamp('deadline_at')->nullable();
                $t->unsignedBigInteger('source_quiz_id')->nullable();
            });
        }
        if (! Schema::hasColumn('questions', 'image_path')) {
            Schema::table('questions', function (Blueprint $t): void {
                $t->string('image_path', 255)->nullable();
                $t->string('explanation_image_path', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quizzes', 'kind')) {
            Schema::table('quizzes', function (Blueprint $t): void {
                $t->dropColumn(['kind', 'time_limit_minutes', 'deadline_at', 'source_quiz_id']);
            });
        }
        if (Schema::hasColumn('questions', 'image_path')) {
            Schema::table('questions', function (Blueprint $t): void {
                $t->dropColumn(['image_path', 'explanation_image_path']);
            });
        }
    }
};
