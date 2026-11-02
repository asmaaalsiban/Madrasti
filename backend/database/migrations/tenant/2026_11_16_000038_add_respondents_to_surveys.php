<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who answers: the parents (default), the students themselves, or both. An approval is always for parents.
        Schema::table('surveys', function (Blueprint $t) {
            $t->string('respondents', 16)->default('parents');
        });
    }

    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $t) {
            $t->dropColumn('respondents');
        });
    }
};
