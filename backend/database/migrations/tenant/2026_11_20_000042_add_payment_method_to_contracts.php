<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How each person is paid (bank transfer, cheque, cash) is part of their contract; a payslip starts with it and the accountant can change it per month.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_contracts', function (Blueprint $table): void {
            $table->string('payment_method', 20)->nullable()->after('base_salary');
            $table->string('payment_details')->nullable()->after('payment_method');
        });
        Schema::table('payslips', function (Blueprint $table): void {
            $table->string('payment_details')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', fn (Blueprint $table) => $table->dropColumn('payment_details'));
        Schema::table('staff_contracts', fn (Blueprint $table) => $table->dropColumn(['payment_method', 'payment_details']));
    }
};
