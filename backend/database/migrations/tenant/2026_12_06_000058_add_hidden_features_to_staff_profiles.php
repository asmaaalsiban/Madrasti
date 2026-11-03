<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The principal can hide screens from a member of staff: the keys of the hidden features (empty = sees everything their job allows).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('staff_profiles', 'hidden_features')) {
            Schema::table('staff_profiles', function (Blueprint $t): void {
                $t->json('hidden_features')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('staff_profiles', 'hidden_features')) {
            Schema::table('staff_profiles', function (Blueprint $t): void {
                $t->dropColumn('hidden_features');
            });
        }
    }
};
