<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One try to pay online. It stays "pending" until the provider says how it ended, then becomes a real Payment.
        // "review" = the provider took the money but it could not be applied (the fee was paid another way meanwhile): needs a person.
        Schema::create('online_payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique(); // ours: sent to the provider and returned in its webhook
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_fee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('installment_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->constrained(); // the parent paying
            $t->decimal('amount', 12, 2);
            $t->string('payer_name');
            $t->string('provider', 32);
            $t->string('provider_reference')->nullable();
            $t->string('status', 16)->default('pending'); // pending | paid | failed | review
            $t->string('failure_reason')->nullable();
            $t->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payments');
    }
};
