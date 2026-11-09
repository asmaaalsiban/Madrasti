<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Optional rules that raise a warning by themselves (e.g. 3 days absent in 30 days). A school with no active rule gets none. */
    public function up(): void
    {
        if (! Schema::hasTable('warning_rules')) {
            Schema::create('warning_rules', function (Blueprint $t): void {
                $t->id();
                $t->string('subject_type', 10); // student | staff
                $t->string('name', 120);
                $t->string('metric', 20); // absent_days | late_count | negative_notes (students only)
                $t->unsignedSmallInteger('threshold');
                $t->unsignedSmallInteger('window_days')->default(30);
                $t->string('category', 20)->default('absence');
                $t->unsignedTinyInteger('level')->default(1);
                $t->string('title', 150);
                $t->text('body')->nullable();
                $t->boolean('auto_send')->default(false); // true: the warning is active at once; false: it waits for the admin
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warning_rules');
    }
};
