<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What the school plans to spend in a year on each kind of expense. */
    public function up(): void
    {
        if (! Schema::hasTable('budgets')) {
            Schema::create('budgets', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
                $t->foreignId('expense_category_id')->constrained('expense_categories')->cascadeOnDelete();
                $t->decimal('amount', 14, 2);
                $t->timestamps();
                $t->unique(['academic_year_id', 'expense_category_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
