<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The pricing plans shown on the public website. The Super Admin decides all of it: names, prices, features, which one is "popular", which are shown.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t): void {
            $t->id();
            $t->string('name_ar');
            $t->string('name_en');
            $t->decimal('price_monthly', 10, 2);
            $t->decimal('price_yearly', 10, 2); // the monthly price when paid yearly
            $t->string('currency', 3)->default('JOD');
            $t->unsignedInteger('max_students')->nullable(); // null = no limit
            $t->text('description_ar')->nullable();
            $t->text('description_en')->nullable();
            $t->json('features_ar');
            $t->json('features_en');
            $t->boolean('is_popular')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
