<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A bank transfer (or CliQ) paid by a parent is an online payment that a person confirms: the reference of the transfer, who decided and why.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('online_payments', 'transfer_reference')) {
            Schema::table('online_payments', function (Blueprint $t): void {
                $t->string('transfer_reference', 100)->nullable();
                $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('reviewed_at')->nullable();
                $t->string('review_note', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('online_payments', 'transfer_reference')) {
            Schema::table('online_payments', function (Blueprint $t): void {
                $t->dropForeign(['reviewed_by']);
                $t->dropColumn(['transfer_reference', 'reviewed_by', 'reviewed_at', 'review_note']);
            });
        }
    }
};
