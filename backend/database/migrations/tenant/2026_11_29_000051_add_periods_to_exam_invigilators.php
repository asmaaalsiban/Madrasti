<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A watcher can have only some of the periods of an exam (the teacher of 1-A in period 2 and of 1-B in period 3 watches each in its own period).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('exam_invigilators', 'period_from')) {
            Schema::table('exam_invigilators', function (Blueprint $table): void {
                $table->unsignedTinyInteger('period_from')->nullable();
                $table->unsignedTinyInteger('period_to')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('exam_invigilators', fn (Blueprint $table) => $table->dropColumn(['period_from', 'period_to']));
    }
};
