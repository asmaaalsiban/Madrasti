<?php

declare(strict_types=1);

namespace App\Central\Actions;

use App\Models\User;
use App\Modules\People\Actions\SaveStudent;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;

/**
 * Fills the current (tenant) database with demo data so a developer can try the admin screens.
 * Safe to run repeatedly: rows are matched by natural keys and never duplicated.
 */
class SeedDemoSchool
{
    /** [email name, Arabic name, staff type, subject code]: the first three are teaching staff and homeroom teachers. */
    private const STAFF = [
        ['teacher1', 'أحمد علي', StaffType::Teacher, 'ARB'],
        ['teacher2', 'سارة خالد', StaffType::Teacher, 'MTH'],
        ['teacher3', 'محمد يوسف', StaffType::HeadOfDepartment, 'SCI'],
        ['nurse1', 'نور حسن', StaffType::Nurse, null],
    ];

    private const GRADES = [
        ['الصف الأول', 'Grade 1'], ['الصف الثاني', 'Grade 2'], ['الصف الثالث', 'Grade 3'],
        ['الصف الرابع', 'Grade 4'], ['الصف الخامس', 'Grade 5'], ['الصف السادس', 'Grade 6'],
    ];

    /** [student number, Arabic name, English name, classroom, parent number]: two siblings share parent 1. */
    private const STUDENTS = [
        ['D-1001', 'أحمد خالد', 'Ahmad Khaled', '1-A', 1],
        ['D-1002', 'ليلى خالد', 'Layla Khaled', '1-A', 1],
        ['D-1003', 'يوسف سامي', 'Yousef Sami', '1-B', 2],
        ['D-1004', 'مريم نبيل', 'Mariam Nabil', '2-A', 3],
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
            ['name' => 'Admin', 'password' => $adminPassword, 'role' => Role::Admin, 'is_active' => true, 'must_change_password' => false],
        );

        $yearId = $this->firstOrInsert('academic_years', ['name' => '2026/2027'], [
            'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'is_current' => true,
        ]);

        foreach (self::SUBJECTS as $code => [$ar, $en]) {
            $this->firstOrInsert('subjects', ['code' => $code], ['name_ar' => $ar, 'name_en' => $en]);
        }

        $teacherIds = [];
        foreach (self::STAFF as [$local, $name, $type, $subjectCode]) {
            $user = User::updateOrCreate(
                ['email' => $local.'@'.$teacherDomain],
                ['name' => $name, 'password' => $teacherPassword, 'role' => $type->accountRole(), 'is_active' => true, 'must_change_password' => false],
            );
            $user->staffProfile()->updateOrCreate(['user_id' => $user->id], [
                'staff_type' => $type,
                'subject_id' => $subjectCode ? DB::table('subjects')->where('code', $subjectCode)->value('id') : null,
            ]);
            if ($type->canBeHomeroom()) {
                $teacherIds[] = $user->id;
            }
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

        $this->seedStudents($teacherDomain, $yearId);

        return [
            'users' => User::count(),
            'academic_years' => DB::table('academic_years')->count(),
            'grade_levels' => DB::table('grade_levels')->count(),
            'subjects' => DB::table('subjects')->count(),
            'classrooms' => DB::table('classrooms')->count(),
            'students' => DB::table('students')->count(),
        ];
    }

    /** A few students with their accounts and parents, created the way the admin screen creates them (default password). */
    private function seedStudents(string $domain, int $yearId): void
    {
        $saver = app(SaveStudent::class);

        foreach (self::STUDENTS as [$number, $nameAr, $nameEn, $classroom, $parent]) {
            if (DB::table('students')->where('student_number', $number)->exists()) {
                continue;
            }

            // The student's email is generated (e.g. ahmad.d1001@school2.edu).
            $saver->create([
                'student_number' => $number,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'classroom_id' => DB::table('classrooms')->where('name', $classroom)->where('academic_year_id', $yearId)->value('id'),
                'guardian_email' => 'parent'.$parent.'@'.$domain,
                'guardian_phone' => '059910000'.$parent,
            ]);
        }
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
