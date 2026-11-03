<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The purchase requests of the school: a member of staff asks, the principal approves or refuses, the accountant records the purchase,
// the requester confirms it arrived. The first version of the table (never used by any screen) is replaced; it is refused if it holds rows.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('procurement_requests') && ! Schema::hasColumn('procurement_requests', 'estimated_cost')) {
            if (DB::table('procurement_requests')->exists()) {
                throw new RuntimeException('The old procurement_requests table has rows: migrate them by hand first.');
            }
            Schema::drop('procurement_requests');
        }
        if (! Schema::hasTable('procurement_requests')) {
            Schema::create('procurement_requests', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('requested_by')->constrained('users');
                $t->string('title');
                $t->text('description')->nullable();
                $t->unsignedSmallInteger('quantity')->default(1);
                $t->string('category', 30)->default('other');
                $t->string('priority', 10)->default('normal'); // low | normal | high | urgent
                $t->decimal('estimated_cost', 12, 2)->nullable();
                $t->date('needed_by')->nullable();
                $t->string('status', 12)->default('pending')->index(); // pending | approved | rejected | purchased | received
                $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('reviewed_at')->nullable();
                $t->string('review_note', 500)->nullable();
                $t->string('supplier', 150)->nullable();
                $t->decimal('actual_cost', 12, 2)->nullable();
                $t->string('purchase_ref', 100)->nullable();
                $t->foreignId('purchased_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('purchased_at')->nullable();
                $t->timestamp('received_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_requests');
    }
};
