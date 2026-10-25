<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a student's fee is made of (the yearly fee, transport, books, a discount as a negative line): the installments are
        // one schedule for all of it, these lines only explain the total.
        Schema::create('student_fee_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_fee_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);
            $t->string('description');
            $t->decimal('amount', 12, 2);
            $t->timestamps();
        });

        // Who paid (the receipt is written in their name).
        Schema::table('payments', function (Blueprint $t) {
            $t->string('payer_name')->nullable()->after('reference');
        });

        $now = now();
        foreach (DB::table('student_fees')->get() as $fee) {
            DB::table('student_fee_items')->insert(['student_fee_id' => $fee->id, 'kind' => $fee->kind === 'other' ? 'other' : $fee->kind, 'description' => $fee->description, 'amount' => $fee->total_amount, 'created_at' => $now, 'updated_at' => $now]);
            if ((float) $fee->discount > 0) {
                DB::table('student_fee_items')->insert(['student_fee_id' => $fee->id, 'kind' => 'discount', 'description' => 'Discount', 'amount' => -1 * (float) $fee->discount, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropColumn('payer_name');
        });
        Schema::dropIfExists('student_fee_items');
    }
};
