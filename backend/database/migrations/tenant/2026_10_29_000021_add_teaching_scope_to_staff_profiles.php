<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whom a teacher teaches: a range of grades and boys / girls only. Empty = everybody.
        Schema::table('staff_profiles', function (Blueprint $t) {
            $t->foreignId('grade_from_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            $t->foreignId('grade_to_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            $t->enum('teaches_gender', ['male', 'female'])->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $t) {
            $t->dropConstrainedForeignId('grade_from_id');
            $t->dropConstrainedForeignId('grade_to_id');
            $t->dropColumn('teaches_gender');
        });
    }
};
