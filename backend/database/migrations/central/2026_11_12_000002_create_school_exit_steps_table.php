<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per preparation step of a school's exit: the full archive and the readable handover package.
        Schema::create('school_exit_steps', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id');
            $t->string('step'); // archive | package
            $t->string('status')->default('pending'); // pending | running | done | failed
            $t->string('path')->nullable(); // where the file is on the backup disk
            $t->text('error')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('updated_at')->nullable(); // a step untouched for longer than a job may run is stale
            $t->unique(['tenant_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_exit_steps');
    }
};
