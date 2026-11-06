<?php

declare(strict_types=1);

use App\Modules\Finance\Services\Posting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** A clearing account for money moving between boxes, then the journal entries for everything the school did before the journal existed. */
    public function up(): void
    {
        if (! DB::table('ledger_accounts')->where('code', '1190')->exists()) {
            DB::table('ledger_accounts')->insert(['code' => '1190', 'name_ar' => 'تحويلات بين الصناديق', 'name_en' => 'Funds in transit', 'type' => 'asset', 'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(Posting::class)->rebuildAll();
    }

    public function down(): void {}
};
