<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Warnings given to a student (shown on their page and their parents' page) or to a member of staff. */
    public function up(): void
    {
        if (! Schema::hasTable('warnings')) {
            Schema::create('warnings', function (Blueprint $t): void {
                $t->id();
                $t->string('subject_type', 10); // student | staff
                $t->foreignId('student_id')->nullable()->constrained('students')->cascadeOnDelete();
                $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $t->string('kind', 10)->default('manual'); // manual | rule
                $t->unsignedBigInteger('rule_id')->nullable();
                $t->string('category', 20)->default('other');
                $t->unsignedTinyInteger('level')->default(1); // 1 notice, 2 first, 3 second, 4 final
                $t->string('title', 150);
                $t->text('body')->nullable();
                $t->date('issued_on');
                $t->string('status', 10)->default('active'); // pending (waits for the admin) | active | withdrawn
                $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('notified_at')->nullable();
                $t->timestamp('acknowledged_at')->nullable();
                $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('withdrawn_at')->nullable();
                $t->string('withdrawn_reason', 255)->nullable();
                $t->timestamps();
                $t->index(['student_id', 'status']);
                $t->index(['user_id', 'status']);
                $t->index(['subject_type', 'status', 'issued_on']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warnings');
    }
};
