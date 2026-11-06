<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What the school spends: suppliers, expense categories, bills (expenses) and the payments made on them out of the cash box or the bank. */
    public function up(): void
    {
        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $t): void {
                $t->id();
                $t->string('name', 150);
                $t->string('phone', 40)->nullable();
                $t->string('email', 150)->nullable();
                $t->string('notes', 500)->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('expense_categories')) {
            Schema::create('expense_categories', function (Blueprint $t): void {
                $t->id();
                $t->string('name_ar', 80);
                $t->string('name_en', 80);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $t): void {
                $t->id();
                $t->date('expense_date');
                $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $t->foreignId('expense_category_id')->constrained('expense_categories');
                $t->string('description', 255);
                $t->string('reference', 80)->nullable(); // the supplier's invoice number
                $t->decimal('amount', 14, 2);
                $t->date('due_date')->nullable();
                $t->unsignedBigInteger('procurement_request_id')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index('expense_date');
            });
        }
        if (! Schema::hasTable('expense_payments')) {
            Schema::create('expense_payments', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
                $t->foreignId('money_account_id')->constrained('money_accounts');
                $t->decimal('amount', 14, 2);
                $t->date('paid_on');
                $t->string('method', 20);
                $t->string('reference', 80)->nullable();
                $t->string('note', 255)->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }
        // What an account line came from (an expense payment, a payslip), so it can be taken back with its source.
        if (! Schema::hasColumn('account_entries', 'source_type')) {
            Schema::table('account_entries', function (Blueprint $t): void {
                $t->string('source_type', 20)->nullable();
                $t->unsignedBigInteger('source_id')->nullable();
                $t->index(['source_type', 'source_id']);
            });
        }
        if (DB::table('expense_categories')->count() === 0) {
            $now = now();
            foreach ([['رواتب', 'Salaries'], ['إيجار', 'Rent'], ['كهرباء ومياه واتصالات', 'Utilities'], ['صيانة', 'Maintenance'], ['قرطاسية ومستلزمات', 'Supplies'], ['مواصلات', 'Transport'], ['أنشطة', 'Activities'], ['أخرى', 'Other']] as [$ar, $en]) {
                DB::table('expense_categories')->insert(['name_ar' => $ar, 'name_en' => $en, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('account_entries', 'source_type')) {
            Schema::table('account_entries', function (Blueprint $t): void {
                $t->dropIndex(['source_type', 'source_id']);
                $t->dropColumn(['source_type', 'source_id']);
            });
        }
        Schema::dropIfExists('expense_payments');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('suppliers');
    }
};
