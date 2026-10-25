<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $t) {
            // A section group: whether the parents are in it, and whether members (not only the staff) may write.
            $t->boolean('include_parents')->default(false)->after('classroom_id');
            $t->boolean('members_can_post')->default(true)->after('include_parents');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $t) {
            $t->dropColumn(['include_parents', 'members_can_post']);
        });
    }
};
