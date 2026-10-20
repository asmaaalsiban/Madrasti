<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_profiles', function (Blueprint $t) {
            $t->string('staff_type', 40)->default('teacher')->index();
            $t->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $t->string('floor', 20)->nullable();
        });

        // Teachers created before staff types existed become staff of type "teacher".
        $now = now();
        $withProfile = DB::table('staff_profiles')->pluck('user_id');
        DB::table('users')->where('role', 'teacher')->whereNotIn('id', $withProfile)->pluck('id')->each(
            fn ($id) => DB::table('staff_profiles')->insert([
                'user_id' => $id, 'staff_type' => 'teacher', 'created_at' => $now, 'updated_at' => $now,
            ]),
        );
    }

    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $t) {
            $t->dropConstrainedForeignId('subject_id');
            $t->dropColumn(['staff_type', 'floor']);
        });
    }
};
