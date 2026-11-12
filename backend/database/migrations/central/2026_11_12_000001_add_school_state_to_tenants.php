<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->unsignedInteger('max_students')->nullable();
            $t->timestamp('exit_window_ends_at')->nullable();
            $t->timestamp('closed_at')->nullable();
        });

        Schema::create('central_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('super_admin_id')->nullable();
            $t->string('action');
            $t->string('tenant_id')->nullable()->index();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('super_admin_login_codes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('super_admin_id')->index();
            $t->string('code_hash');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('super_admin_login_codes');
        Schema::dropIfExists('central_audit_logs');
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['max_students', 'exit_window_ends_at', 'closed_at']);
        });
    }
};
