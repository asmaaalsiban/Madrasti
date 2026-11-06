<?php

declare(strict_types=1);

use App\Modules\Finance\Services\Posting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The tax a bill contains, and the two tax accounts (tax we paid on purchases, tax we owe on sales). */
    public function up(): void
    {
        if (! Schema::hasColumn('expenses', 'tax_amount')) {
            Schema::table('expenses', function (Blueprint $t): void {
                $t->decimal('tax_amount', 14, 2)->default(0)->after('amount');
            });
        }
        $now = now();
        foreach ([['1300', 'ضريبة مدخلات (مدفوعة على المشتريات)', 'Input tax (paid on purchases)', 'asset'], ['2200', 'ضريبة مستحقة (مخرجات)', 'Tax payable (charged on sales)', 'liability']] as [$code, $ar, $en, $type]) {
            if (! DB::table('ledger_accounts')->where('code', $code)->exists()) {
                DB::table('ledger_accounts')->insert(['code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'type' => $type, 'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        app(Posting::class)->rebuildAll();
    }

    public function down(): void
    {
        if (Schema::hasColumn('expenses', 'tax_amount')) {
            Schema::table('expenses', function (Blueprint $t): void {
                $t->dropColumn('tax_amount');
            });
        }
    }
};
