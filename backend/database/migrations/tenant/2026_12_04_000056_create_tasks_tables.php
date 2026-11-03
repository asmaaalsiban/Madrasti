<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tasks given to staff: the principal/management to any teacher, a head of department to the teachers of their department.
// Every assignee has their own status (todo / doing / done) and note.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            Schema::create('tasks', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('created_by')->constrained('users');
                $t->string('title');
                $t->text('description')->nullable();
                $t->date('due_on')->nullable()->index();
                $t->string('priority', 10)->default('normal'); // low | normal | high
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('task_assignees')) {
            Schema::create('task_assignees', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->string('status', 10)->default('todo'); // todo | doing | done
                $t->string('note', 500)->nullable();
                $t->timestamp('done_at')->nullable();
                $t->timestamps();
                $t->unique(['task_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignees');
        Schema::dropIfExists('tasks');
    }
};
