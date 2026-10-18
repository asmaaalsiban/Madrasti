<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_structures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('academic_year_id')->constrained();
            $t->foreignId('grade_level_id')->constrained('grade_levels');
            $t->string('name');
            $t->decimal('amount', 12, 2);
            $t->timestamps();
        });

        // What a specific student owes for a year
        Schema::create('student_fees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('academic_year_id')->constrained();
            $t->foreignId('fee_structure_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description');
            $t->decimal('total_amount', 12, 2);
            $t->decimal('discount', 12, 2)->default(0);
            $t->timestamps();
        });

        $this->installmentsAndPayments();
    }

    private function installmentsAndPayments(): void
    {
        Schema::create('installments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_fee_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('number');
            $t->date('due_date');
            $t->decimal('amount', 12, 2);
            $t->decimal('paid_amount', 12, 2)->default(0);
            $t->enum('status', ['pending', 'partial', 'paid', 'overdue'])->default('pending')->index();
            $t->timestamp('last_reminded_at')->nullable();
            $t->unique(['student_fee_id', 'number']);
            $t->timestamps();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_fee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('installment_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 12, 2);
            $t->enum('method', ['cash', 'bank_transfer', 'card', 'cheque', 'online']);
            $t->string('reference')->nullable();
            $t->date('paid_on');
            $t->foreignId('received_by')->constrained('users');
            $t->string('note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['payments', 'installments', 'student_fees', 'fee_structures'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
