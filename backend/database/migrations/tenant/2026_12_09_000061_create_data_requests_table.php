<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A person (or a parent for a child) asks the school to delete their data. The school decides: some records must be kept by law (payments).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('data_requests')) {
            Schema::create('data_requests', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
                $t->string('type', 12)->default('deletion'); // deletion | correction
                $t->string('status', 10)->default('pending')->index(); // pending | done | refused
                $t->string('message', 1000)->nullable();
                $t->string('answer', 1000)->nullable();
                $t->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('handled_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('data_requests');
    }
};
