<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The installment plan of a yearly fee: how many, from when, every how many months, and whether a student placed in
        // the grade gets it automatically.
        Schema::table('fee_structures', function (Blueprint $t) {
            $t->unsignedTinyInteger('installments_count')->default(4)->after('amount');
            $t->date('first_due')->nullable()->after('installments_count');
            $t->unsignedTinyInteger('every_months')->default(1)->after('first_due');
            $t->boolean('auto_apply')->default(true)->after('every_months');
        });
    }

    public function down(): void
    {
        Schema::table('fee_structures', function (Blueprint $t) {
            $t->dropColumn(['installments_count', 'first_due', 'every_months', 'auto_apply']);
        });
    }
};
