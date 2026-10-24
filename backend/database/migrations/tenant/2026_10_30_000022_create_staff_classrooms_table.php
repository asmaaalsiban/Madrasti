<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The sections a teacher teaches, when the admin chose them (e.g. a head of department with one or two sections).
        Schema::create('staff_classrooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $t->unique(['staff_profile_id', 'classroom_id']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_classrooms');
    }
};
