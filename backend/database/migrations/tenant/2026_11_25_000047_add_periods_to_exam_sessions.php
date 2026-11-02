<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An exam takes the place of regular lessons: the periods it covers (e.g. 2 to 3).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('period_from')->nullable()->after('exam_date');
            $table->unsignedTinyInteger('period_to')->nullable()->after('period_from');
        });
    }

    public function down(): void
    {
        Schema::table('exam_sessions', fn (Blueprint $table) => $table->dropColumn(['period_from', 'period_to']));
    }
};
