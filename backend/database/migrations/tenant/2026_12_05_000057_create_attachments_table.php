<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One table of files for anything that can carry them (tasks, a task's hand-in, surveys and approvals...): private storage, opened only
// by people who may see the owner.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attachments')) {
            Schema::create('attachments', function (Blueprint $t): void {
                $t->id();
                $t->string('owner_type', 20); // task | task_part | survey
                $t->unsignedBigInteger('owner_id');
                $t->foreignId('uploaded_by')->constrained('users');
                $t->string('path');
                $t->string('original_name');
                $t->string('mime', 100)->nullable();
                $t->unsignedBigInteger('size')->default(0);
                $t->timestamps();
                $t->index(['owner_type', 'owner_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
