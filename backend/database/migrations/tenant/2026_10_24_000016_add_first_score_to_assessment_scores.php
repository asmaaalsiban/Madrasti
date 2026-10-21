<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The score of the first attempt, kept when a failed student sits the exam again (status "retake").
        Schema::table('assessment_scores', function (Blueprint $t) {
            $t->decimal('first_score', 6, 2)->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_scores', function (Blueprint $t) {
            $t->dropColumn('first_score');
        });
    }
};
