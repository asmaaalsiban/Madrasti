<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a fee is, independent of the language of its description: the grade's yearly fee, the student's transport, or other.
        Schema::table('student_fees', function (Blueprint $t) {
            $t->string('kind', 20)->default('other')->after('fee_structure_id');
        });
        DB::table('student_fees')->whereNotNull('fee_structure_id')->update(['kind' => 'yearly']);
        DB::table('student_fees')->whereNull('fee_structure_id')->whereIn('description', ['Transport', 'المواصلات'])->update(['kind' => 'transport']);
    }

    public function down(): void
    {
        Schema::table('student_fees', function (Blueprint $t) {
            $t->dropColumn('kind');
        });
    }
};
