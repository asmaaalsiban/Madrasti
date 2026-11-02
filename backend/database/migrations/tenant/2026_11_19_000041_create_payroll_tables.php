<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A member of staff's contract: its dates and monthly salary. Renewing makes a new contract (with the raise) and keeps the old one.
        Schema::create('staff_contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('starts_on');
            $t->date('ends_on')->nullable(); // null: open-ended
            $t->decimal('base_salary', 12, 2);
            $t->string('status', 10)->default('active'); // active | renewed | ended
            $t->foreignId('renewed_from_id')->nullable()->constrained('staff_contracts')->nullOnDelete();
            $t->decimal('raise_amount', 12, 2)->nullable(); // the raise given when this contract renewed the previous one
            $t->decimal('raise_percent', 6, 2)->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });

        // Amounts that repeat every month on a contract: an allowance (transport...) or a deduction (insurance...).
        Schema::create('staff_contract_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_contract_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 10); // allowance | deduction
            $t->string('label');
            $t->decimal('amount', 12, 2);
            $t->timestamps();
        });

        // One month's payroll: prepared by the accountant, submitted, approved by the principal, then paid.
        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->date('month')->unique(); // the first day of the month
            $t->string('status', 10)->default('draft'); // draft | submitted | approved | paid
            $t->text('return_note')->nullable(); // why the principal sent it back
            $t->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });

        Schema::create('payslips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('staff_contract_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('base_salary', 12, 2);
            $t->decimal('additions', 12, 2)->default(0); // bonuses + allowances
            $t->decimal('deductions', 12, 2)->default(0);
            $t->decimal('net', 12, 2);
            $t->date('paid_on')->nullable();
            $t->string('payment_method', 20)->nullable();
            $t->string('reference')->nullable();
            $t->timestamps();
            $t->unique(['payroll_run_id', 'user_id']);
        });

        Schema::create('payslip_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 10); // bonus | allowance | deduction
            $t->string('label');
            $t->decimal('amount', 12, 2); // always positive: the kind says whether it adds or takes away
            $t->boolean('from_contract')->default(false);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['payslip_items', 'payslips', 'payroll_runs', 'staff_contract_items', 'staff_contracts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
