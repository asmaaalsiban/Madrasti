<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A school can have several buildings (e.g. boys, girls, secondary), each with floors; a floor serves a range of grades.
        Schema::create('buildings', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->enum('gender', ['male', 'female', 'mixed'])->default('mixed');
            $t->timestamps();
        });

        Schema::create('floors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('building_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->smallInteger('level')->default(0); // 0 = ground floor
            $t->enum('gender', ['male', 'female', 'mixed'])->nullable(); // null = same as the building
            $t->foreignId('grade_from_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            $t->foreignId('grade_to_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            $t->unique(['building_id', 'level']);
            $t->timestamps();
        });

        Schema::table('rooms', function (Blueprint $t) {
            $t->foreignId('floor_id')->nullable()->after('id')->constrained('floors')->nullOnDelete();
            $t->string('kind', 30)->default('classroom')->after('name'); // classroom, science_lab, computer_lab, ...
        });

        // The room where a classroom stays during the day (one classroom per room in an academic year).
        Schema::table('classrooms', function (Blueprint $t) {
            $t->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $t->unique(['room_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $t) {
            $t->dropUnique(['room_id', 'academic_year_id']);
            $t->dropConstrainedForeignId('room_id');
        });
        Schema::table('rooms', function (Blueprint $t) {
            $t->dropConstrainedForeignId('floor_id');
            $t->dropColumn('kind');
        });
        Schema::dropIfExists('floors');
        Schema::dropIfExists('buildings');
    }
};
