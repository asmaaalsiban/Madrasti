<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The chart of accounts, the journal (balanced entries) and the date up to which the books are locked. */
    public function up(): void
    {
        if (! Schema::hasTable('ledger_accounts')) {
            Schema::create('ledger_accounts', function (Blueprint $t): void {
                $t->id();
                $t->string('code', 20)->unique();
                $t->string('name_ar', 120);
                $t->string('name_en', 120);
                $t->string('type', 12); // asset, liability, equity, income, expense
                $t->boolean('is_system')->default(false);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('journal_entries')) {
            Schema::create('journal_entries', function (Blueprint $t): void {
                $t->id();
                $t->date('entry_date');
                $t->string('memo', 255);
                $t->string('source_type', 20)->nullable();
                $t->unsignedBigInteger('source_id')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index('entry_date');
                $t->index(['source_type', 'source_id']);
            });
        }
        if (! Schema::hasTable('journal_lines')) {
            Schema::create('journal_lines', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
                $t->foreignId('ledger_account_id')->constrained('ledger_accounts');
                $t->decimal('debit', 14, 2)->default(0);
                $t->decimal('credit', 14, 2)->default(0);
                $t->string('note', 255)->nullable();
            });
        }
        if (DB::table('ledger_accounts')->count() === 0) {
            $now = now();
            $rows = [
                ['1000', 'النقدية', 'Cash', 'asset'], ['1100', 'البنوك', 'Banks', 'asset'], ['1200', 'ذمم الطلاب (الأقساط)', 'Student receivables', 'asset'],
                ['2000', 'ذمم الموردين', 'Suppliers payable', 'liability'], ['2100', 'رواتب مستحقة', 'Salaries payable', 'liability'],
                ['3000', 'حقوق الملكية', 'Equity', 'equity'],
                ['4000', 'إيراد الأقساط الدراسية', 'Tuition income', 'income'], ['4100', 'إيرادات أخرى', 'Other income', 'income'],
                ['5000', 'الرواتب', 'Salaries', 'expense'], ['5100', 'الإيجار', 'Rent', 'expense'], ['5200', 'كهرباء ومياه واتصالات', 'Utilities', 'expense'],
                ['5300', 'الصيانة', 'Maintenance', 'expense'], ['5400', 'القرطاسية والمستلزمات', 'Supplies', 'expense'], ['5500', 'مصاريف أخرى', 'Other expenses', 'expense'],
            ];
            foreach ($rows as [$code, $ar, $en, $type]) {
                DB::table('ledger_accounts')->insert(['code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'type' => $type, 'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
