<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which staff take part in the supervision duty roster (some teachers do duty, some do not). Everyone does by default.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('staff_profiles', 'does_duty')) {
            Schema::table('staff_profiles', function (Blueprint $table): void {
                $table->boolean('does_duty')->default(true);
            });
        }
    }

    public function down(): void
    {
        Schema::table('staff_profiles', fn (Blueprint $table) => $table->dropColumn('does_duty'));
    }
};
