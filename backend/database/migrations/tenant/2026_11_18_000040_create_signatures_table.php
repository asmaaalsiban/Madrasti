<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Electronic signatures printed on the report card: the school's (principal, vice principal...) and a teacher's own
        // (used when that teacher is the homeroom teacher of the student's section). The uploaded photo is kept, and the
        // processed copy (paper removed, transparent) is what is printed.
        Schema::create('signatures', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 8); // school | user
            $t->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete(); // one personal signature per person
            $t->string('title')->nullable(); // school signatures: "Principal"
            $t->string('signer_name')->nullable();
            $t->string('original_path');
            $t->string('image_path');
            $t->unsignedTinyInteger('strength')->default(50); // how much of the paper is removed
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
