<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The photos of a homework (a photo of the task) or of a student's answer (the pages); several of each.
        Schema::create('homework_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('homework_assignment_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('homework_submission_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->unsignedSmallInteger('position')->default(0);
            $t->timestamps();
        });

        $now = now();
        foreach (DB::table('homework_assignments')->whereNotNull('attachment_path')->get(['id', 'attachment_path']) as $row) {
            DB::table('homework_files')->insert(['homework_assignment_id' => $row->id, 'path' => $row->attachment_path, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('homework_submissions')->whereNotNull('file_path')->get(['id', 'file_path']) as $row) {
            DB::table('homework_files')->insert(['homework_submission_id' => $row->id, 'path' => $row->file_path, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('homework_assignments')->update(['attachment_path' => null]);
        DB::table('homework_submissions')->update(['file_path' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_files');
    }
};
