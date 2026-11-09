<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A stand-in escort for ONE day on one round of a bus (the usual escort is absent); the usual one stays as it is. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bus_round_substitutes')) {
            return;
        }
        Schema::create('bus_round_substitutes', function (Blueprint $t): void {
            $t->id();
            $t->date('date');
            $t->foreignId('bus_id')->constrained('buses')->cascadeOnDelete();
            $t->foreignId('transport_round_id')->constrained('transport_rounds')->cascadeOnDelete();
            $t->foreignId('escort_id')->constrained('users')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['date', 'bus_id', 'transport_round_id'], 'bus_round_substitutes_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_round_substitutes');
    }
};
