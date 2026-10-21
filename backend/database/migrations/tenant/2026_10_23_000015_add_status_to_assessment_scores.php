<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // null = a normal score, "absent" = missed the assessment (makeup exam pending), "makeup" = the score is from the makeup exam.
        Schema::table('assessment_scores', function (Blueprint $t) {
            $t->string('status', 20)->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_scores', function (Blueprint $t) {
            $t->dropColumn('status');
        });
    }
};
