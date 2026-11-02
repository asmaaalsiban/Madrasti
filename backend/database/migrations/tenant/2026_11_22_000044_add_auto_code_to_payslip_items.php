<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A line the system added from the commitment rules (punctuality, daily summaries, weekly plans) and the score behind it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslip_items', function (Blueprint $table): void {
            $table->string('auto_code', 30)->nullable()->after('from_contract');
            $table->string('detail', 60)->nullable()->after('auto_code');
        });
    }

    public function down(): void
    {
        Schema::table('payslip_items', fn (Blueprint $table) => $table->dropColumn(['auto_code', 'detail']));
    }
};
