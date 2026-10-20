<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per parent told about one absence: makes the message idempotent and shows what happened.
        Schema::create('absence_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_attendance_id')->constrained()->cascadeOnDelete();
            $t->foreignId('guardian_id')->constrained('users')->cascadeOnDelete();
            $t->string('phone')->nullable();
            $t->string('channel', 30);
            $t->enum('status', ['pending', 'sent', 'failed', 'skipped'])->default('pending');
            $t->string('error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->unique(['student_attendance_id', 'guardian_id']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absence_notifications');
    }
};
