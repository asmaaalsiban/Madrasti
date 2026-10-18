<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requested_by')->constrained('users');
            $t->string('title');
            $t->text('description')->nullable();
            $t->unsignedSmallInteger('quantity')->default(1);
            $t->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $t->enum('status', ['open', 'approved', 'done', 'rejected'])->default('open')->index();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->enum('action', ['created', 'updated', 'deleted']);
            $t->morphs('auditable');
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('procurement_requests');
    }
};
