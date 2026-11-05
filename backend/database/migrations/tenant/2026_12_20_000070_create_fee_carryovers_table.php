<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Year-end: what a student still owed in one academic year and was carried to the next (kept so it can be shown and undone). */
    public function up(): void
    {
        if (Schema::hasTable('fee_carryovers')) {
            return;
        }
        Schema::create('fee_carryovers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('from_year_id')->constrained('academic_years')->cascadeOnDelete();
            $t->foreignId('to_year_id')->constrained('academic_years')->cascadeOnDelete();
            $t->decimal('amount', 12, 2);
            $t->json('payment_ids'); // the "carried forward" entries that closed the old fees
            $t->foreignId('new_fee_id')->nullable()->constrained('student_fees')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reverted_at')->nullable();
            $t->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['from_year_id', 'to_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_carryovers');
    }
};
