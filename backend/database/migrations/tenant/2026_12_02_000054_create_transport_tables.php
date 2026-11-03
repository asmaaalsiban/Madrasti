<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Transport: the school's rounds (first / second / third shift), its buses (driver, area, seats, which rounds it serves) and
// where every student rides: a round, a bus that picks them up and a bus that takes them home (either may be empty, they may differ).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transport_rounds')) {
            Schema::create('transport_rounds', function (Blueprint $t): void {
                $t->id();
                $t->string('name_ar', 60);
                $t->string('name_en', 60);
                $t->time('starts_at')->nullable();
                $t->time('ends_at')->nullable();
                $t->unsignedTinyInteger('sort_order')->default(1);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('buses')) {
            Schema::create('buses', function (Blueprint $t): void {
                $t->id();
                $t->string('number', 20)->unique();
                $t->string('driver_name', 120);
                $t->string('driver_phone', 30)->nullable();
                $t->unsignedSmallInteger('capacity')->default(30);
                $t->string('area_from', 150);
                $t->string('area_to', 150);
                $t->string('notes', 500)->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('bus_round')) {
            Schema::create('bus_round', function (Blueprint $t): void {
                $t->foreignId('bus_id')->constrained('buses')->cascadeOnDelete();
                $t->foreignId('transport_round_id')->constrained('transport_rounds')->cascadeOnDelete();
                $t->primary(['bus_id', 'transport_round_id']);
            });
        }
        if (! Schema::hasTable('student_transport')) {
            Schema::create('student_transport', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('student_id')->unique()->constrained('students')->cascadeOnDelete();
                $t->foreignId('transport_round_id')->constrained('transport_rounds');
                $t->foreignId('pickup_bus_id')->nullable()->constrained('buses')->nullOnDelete();
                $t->foreignId('dropoff_bus_id')->nullable()->constrained('buses')->nullOnDelete();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_transport');
        Schema::dropIfExists('bus_round');
        Schema::dropIfExists('buses');
        Schema::dropIfExists('transport_rounds');
    }
};
