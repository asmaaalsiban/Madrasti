<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

/**
 * Defaults every new school starts with.
 */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('point_categories')->insert(array_map(
            fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now],
            [
                ['name_ar' => 'مشاركة فعالة', 'name_en' => 'Participation', 'points' => 1],
                ['name_ar' => 'التزام بالنظام', 'name_en' => 'Following rules', 'points' => 1],
                ['name_ar' => 'مساعدة الآخرين', 'name_en' => 'Helping others', 'points' => 2],
                ['name_ar' => 'إنجاز الواجب', 'name_en' => 'Homework done', 'points' => 1],
                ['name_ar' => 'عدم الانتباه', 'name_en' => 'Not paying attention', 'points' => -1],
                ['name_ar' => 'عدم إحضار الواجب', 'name_en' => 'Missing homework', 'points' => -1],
            ],
        ));

        DB::table('badges')->insert(array_map(
            fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now],
            [
                ['name_ar' => 'نجم الأسبوع', 'name_en' => 'Star of the week', 'description' => null, 'icon' => '🌟', 'points_required' => null],
                ['name_ar' => 'متفوق', 'name_en' => 'High achiever', 'description' => null, 'icon' => '🏆', 'points_required' => 100],
                ['name_ar' => 'منضبط', 'name_en' => 'Disciplined', 'description' => null, 'icon' => '🛡️', 'points_required' => null],
            ],
        ));
    }
}
