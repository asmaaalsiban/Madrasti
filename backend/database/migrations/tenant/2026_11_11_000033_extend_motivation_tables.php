<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_categories', function (Blueprint $t) {
            $t->boolean('is_active')->default(true);
        });

        // A badge can be earned automatically at a points total; null means it is only given by hand.
        Schema::table('badges', function (Blueprint $t) {
            $t->unsignedInteger('points_required')->nullable();
            $t->boolean('is_active')->default(true);
        });

        // Badges earned from points have no teacher behind them.
        Schema::table('student_badges', function (Blueprint $t) {
            $t->foreignId('awarded_by')->nullable()->change();
            $t->unique(['student_id', 'badge_id']);
        });

        // The ready-made badges used word icons; the screens now show emoji.
        foreach (['star' => '🌟', 'trophy' => '🏆', 'shield' => '🛡️'] as $word => $emoji) {
            DB::table('badges')->where('icon', $word)->update(['icon' => $emoji]);
        }
    }

    public function down(): void
    {
        Schema::table('student_badges', function (Blueprint $t) {
            $t->dropUnique(['student_id', 'badge_id']);
        });
        Schema::table('badges', function (Blueprint $t) {
            $t->dropColumn(['points_required', 'is_active']);
        });
        Schema::table('point_categories', function (Blueprint $t) {
            $t->dropColumn('is_active');
        });
    }
};
