<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Who gets one of the school's own rules every month, and for which months (e.g. the summer club teachers in July and August).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_rule_assignments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('payroll_template_id')->constrained('payroll_templates')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('starts_on'); // first day of the first month
            $t->date('ends_on')->nullable(); // first day of the last month; null = until removed
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['user_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_rule_assignments');
    }
};
