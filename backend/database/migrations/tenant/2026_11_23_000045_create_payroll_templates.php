<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Rules the school writes itself ("Exam supervision +15", "Late over 3 times -5%"), and a category on payslip lines (private lessons, clubs).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_templates', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120);
            $t->string('kind', 12); // bonus | allowance | deduction
            $t->string('type', 10)->default('amount'); // amount | percent (of the base salary)
            $t->decimal('value', 12, 2);
            $t->string('category', 30)->nullable(); // private_lessons | summer_club | club | other
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::table('payslip_items', function (Blueprint $table): void {
            $table->string('category', 30)->nullable()->after('detail');
        });
    }

    public function down(): void
    {
        Schema::table('payslip_items', fn (Blueprint $table) => $table->dropColumn('category'));
        Schema::dropIfExists('payroll_templates');
    }
};
