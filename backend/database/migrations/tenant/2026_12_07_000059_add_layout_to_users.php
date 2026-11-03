<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Each person arranges their own icons and menus: {surface: {groups: [...], items: [...]}} (see App\Modules\Auth\Http\Controllers\LayoutController).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'layout')) {
            Schema::table('users', function (Blueprint $t): void {
                $t->json('layout')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'layout')) {
            Schema::table('users', function (Blueprint $t): void {
                $t->dropColumn('layout');
            });
        }
    }
};
