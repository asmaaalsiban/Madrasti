<?php

declare(strict_types=1);

namespace App\Central\Actions;

use App\Models\User;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;

/**
 * Fills the current (tenant) database with demo data so a developer can try the admin screens.
 * Safe to run repeatedly: rows are matched by natural keys and never duplicated.
 */
class SeedDemoSchool
{
    private const TEACHERS = ['أحمد علي', 'سارة خالد', 'محمد يوسف'];

    private const GRADES = [
        ['الصف الأول', 'Grade 1'], ['الصف الثاني', 'Grade 2'], ['الصف الثالث', 'Grade 3'],
        ['الصف الرابع', 'Grade 4'], ['الصف الخامس', 'Grade 5'], ['الصف السادس', 'Grade 6'],
    ];

    private const SUBJECTS = [
        'ARB' => ['اللغة العربية', 'Arabic'], 'ENG' => ['اللغة الإنجليزية', 'English'],
        'MTH' => ['الرياضيات', 'Math'], 'SCI' => ['العلوم', 'Science'],
        'SOC' => ['الاجتماعيات', 'Social Studies'], 'REL' => ['التربية الإسلامية', 'Islamic Studies'],
        'ART' => ['التربية الفنية', 'Art'], 'PE' => ['التربية الرياضية', 'Physical Education'],
    ];

    /**
     * @return array<string, int> how many rows of each kind exist after seeding
     */
    public function handle(string $adminEmail, string $adminPassword, string $teacherDomain, string $teacherPassword): array
    {
        User::updateOrCreate(
            ['email' => $adminEmail],
            ['name' => 'Admin', 'password' => $adminPassword, 'role' => Role::Admin, 'is_active' => true],
        );

        $teacherIds = [];
        foreach (self::TEACHERS as $i => $name) {
            $teacherIds[] = User::updateOrCreate(
                ['email' => 'teacher'.($i + 1).'@'.$teacherDomain],
                ['name' => $name, 'password' => $teacherPassword, 'role' => Role::Teacher, 'is_active' => true],
            )->id;
        }

        $yearId = $this->firstOrInsert('academic_years', ['name' => '2026/2027'], [
            'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true,
        ]);

        foreach (self::SUBJECTS as $code => [$ar, $en]) {
            $this->firstOrInsert('subjects', ['code' => $code], ['name_ar' => $ar, 'name_en' => $en]);
        }

        foreach (self::GRADES as $i => [$ar, $en]) {
            $gradeId = $this->firstOrInsert('grade_levels', ['name_en' => $en], ['name_ar' => $ar, 'sort_order' => $i + 1]);
            foreach (['A', 'B'] as $j => $section) {
                $this->firstOrInsert('classrooms', [
                    'name' => ($i + 1).'-'.$section, 'grade_level_id' => $gradeId, 'academic_year_id' => $yearId,
                ], [
                    'capacity' => 30,
                    'homeroom_teacher_id' => $j === 0 ? ($teacherIds[$i] ?? null) : null,
                ]);
            }
        }

        return [
            'users' => User::count(),
            'academic_years' => DB::table('academic_years')->count(),
            'grade_levels' => DB::table('grade_levels')->count(),
            'subjects' => DB::table('subjects')->count(),
            'classrooms' => DB::table('classrooms')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $keys  columns that identify the row
     * @param  array<string, mixed>  $values  extra columns, only written on insert
     */
    private function firstOrInsert(string $table, array $keys, array $values): int
    {
        $id = DB::table($table)->where($keys)->value('id');

        return $id ?? DB::table($table)->insertGetId($keys + $values + ['created_at' => now(), 'updated_at' => now()]);
    }
}
