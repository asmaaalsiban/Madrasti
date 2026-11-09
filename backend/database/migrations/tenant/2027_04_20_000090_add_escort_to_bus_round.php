<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The escort is chosen per bus AND per round (not one fixed escort for the whole bus). The old bus-wide escort is copied to each of its rounds. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bus_round', 'escort_id')) {
            Schema::table('bus_round', function (Blueprint $t): void {
                $t->foreignId('escort_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }
        foreach (DB::table('buses')->whereNotNull('escort_id')->get(['id', 'escort_id']) as $bus) {
            DB::table('bus_round')->where('bus_id', $bus->id)->whereNull('escort_id')->update(['escort_id' => $bus->escort_id]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bus_round', 'escort_id')) {
            Schema::table('bus_round', function (Blueprint $t): void {
                $t->dropConstrainedForeignId('escort_id');
            });
        }
    }
};
