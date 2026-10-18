<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per school. stancl/tenancy creates a database per tenant.
        Schema::create('tenants', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->string('logo_path')->nullable();
            $t->string('primary_color', 9)->nullable();
            $t->string('default_locale', 5)->default('ar');
            $t->boolean('is_active')->default(true);
            $t->timestamp('subscription_ends_at')->nullable();
            $t->timestamps();
            $t->json('data')->nullable(); // stancl virtual columns
        });

        Schema::create('domains', function (Blueprint $t) {
            $t->increments('id');
            $t->string('domain', 255)->unique();
            $t->string('tenant_id');
            $t->timestamps();
            $t->foreign('tenant_id')->references('id')->on('tenants')
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tenants');
    }
};
