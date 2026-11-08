<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** More subjects a teacher teaches besides the main one, each with its own grades. */
    public function up(): void
    {
        if (! Schema::hasTable('staff_subjects')) {
            Schema::create('staff_subjects', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('staff_profile_id')->constrained('staff_profiles')->cascadeOnDelete();
                $t->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
                $t->foreignId('grade_from_id')->nullable()->constrained('grade_levels')->nullOnDelete();
                $t->foreignId('grade_to_id')->nullable()->constrained('grade_levels')->nullOnDelete();
                $t->timestamps();
                $t->unique(['staff_profile_id', 'subject_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_subjects');
    }
};
