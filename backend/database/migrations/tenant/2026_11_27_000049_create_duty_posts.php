<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where staff do their supervision duty (the gate, the yard at break...), when, how many people, and on which days.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('duty_posts')) {
            Schema::create('duty_posts', function (Blueprint $t): void {
                $t->id();
                $t->string('name', 120);
                $t->time('starts_at');
                $t->time('ends_at');
                $t->unsignedTinyInteger('staff_count')->default(1);
                $t->json('days')->nullable(); // 0 = Sunday ... 6 = Saturday; null = every working day
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('duty_assignments', 'duty_post_id')) {
            Schema::table('duty_assignments', function (Blueprint $table): void {
                $table->foreignId('duty_post_id')->nullable()->after('user_id')->constrained('duty_posts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('duty_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('duty_post_id');
        });
        Schema::dropIfExists('duty_posts');
    }
};
