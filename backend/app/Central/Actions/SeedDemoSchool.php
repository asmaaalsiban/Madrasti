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
    private const TEACHERS_PER_SUBJECT = 3;

    private const TEACHER_FIRST_NAMES = [
        'أحمد', 'سارة', 'محمد', 'ليلى', 'خالد', 'هدى', 'رنا', 'عمر', 'نور', 'يوسف', 'مها', 'سامر', 'دعاء', 'طارق', 'ريم', 'بسام',
    ];

    private const TEACHER_FAMILY_NAMES = ['علي', 'حسن', 'يوسف', 'إبراهيم', 'سعيد', 'فؤاد', 'مراد'];

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
        foreach ($this->staff() as [$local, $name, $type, $subjectCode]) {
            $user = User::updateOrCreate(
                ['email' => $local.'@'.$teacherDomain],
                ['name' => $name, 'password' => $teacherPassword, 'role' => $type->accountRole(), 'is_active' => true, 'must_change_password' => false],
            );
            $user->staffProfile()->updateOrCreate(['user_id' => $user->id], [
                'staff_type' => $type,
                'subject_id' => $subjectCode ? DB::table('subjects')->where('code', $subjectCode)->value('id') : null,
            ]);
            if ($type === StaffType::Teacher) {
                $teacherIds[$subjectCode][] = $user->id;
            }
        }

        foreach (self::GRADES as $i => [$ar, $en]) {
            $gradeId = $this->firstOrInsert('grade_levels', ['name_en' => $en], ['name_ar' => $ar, 'sort_order' => $i + 1]);
            foreach (['A', 'B'] as $j => $section) {
                $this->firstOrInsert('classrooms', [
                    'name' => ($i + 1).'-'.$section, 'grade_level_id' => $gradeId, 'academic_year_id' => $yearId,
                ], [
                    'capacity' => 30,
                ]);
            }
        }

        $this->assignTeachers($yearId, $teacherIds);
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

    /**
     * Per subject: one head of department and three teachers (the nurse comes last).
     *
     * @return list<array{0: string, 1: string, 2: StaffType, 3: ?string}>
     */
    private function staff(): array
    {
        $staff = [];
        $n = 0;
        foreach (array_keys(self::SUBJECTS) as $s => $code) {
            $staff[] = ['head'.($s + 1), $this->teacherName($n++), StaffType::HeadOfDepartment, $code];
            for ($k = 1; $k <= self::TEACHERS_PER_SUBJECT; $k++) {
                $staff[] = ['teacher'.($s * self::TEACHERS_PER_SUBJECT + $k), $this->teacherName($n++), StaffType::Teacher, $code];
            }
        }
        $staff[] = ['nurse1', 'نور حسن', StaffType::Nurse, null];

        return $staff;
    }

    private function teacherName(int $n): string
    {
        return self::TEACHER_FIRST_NAMES[$n % count(self::TEACHER_FIRST_NAMES)].' '.self::TEACHER_FAMILY_NAMES[$n % count(self::TEACHER_FAMILY_NAMES)];
    }

    /**
     * Gives every classroom a homeroom teacher (nobody twice) and spreads each subject's classrooms over its teachers.
     * An existing homeroom teacher is kept.
     *
     * @param  array<string, list<int>>  $teachersBySubject
     */
    private function assignTeachers(int $yearId, array $teachersBySubject): void
    {
        $classroomIds = DB::table('classrooms')->where('academic_year_id', $yearId)->orderBy('id')->pluck('id')->all();

        $taken = DB::table('classrooms')->whereNotNull('homeroom_teacher_id')->pluck('homeroom_teacher_id')->all();
        $free = array_values(array_diff(array_merge(...array_values($teachersBySubject)), $taken));
        foreach ($classroomIds as $classroomId) {
            if (DB::table('classrooms')->where('id', $classroomId)->whereNull('homeroom_teacher_id')->exists() && $free !== []) {
                DB::table('classrooms')->where('id', $classroomId)->update(['homeroom_teacher_id' => array_shift($free)]);
            }
        }

        foreach ($teachersBySubject as $code => $teacherIds) {
            $subjectId = DB::table('subjects')->where('code', $code)->value('id');
            foreach ($classroomIds as $i => $classroomId) {
                DB::table('classroom_subject_teacher')->updateOrInsert(
                    ['classroom_id' => $classroomId, 'subject_id' => $subjectId],
                    ['teacher_id' => $teacherIds[$i % count($teacherIds)], 'weekly_periods' => 3, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    private const STUDENTS_PER_CLASSROOM = 10;

    private const FAMILIES = [
        ['خالد', 'Khaled'], ['سامي', 'Sami'], ['نبيل', 'Nabil'], ['حداد', 'Haddad'], ['منصور', 'Mansour'], ['العلي', 'Ali'],
        ['الخطيب', 'Khatib'], ['شاهين', 'Shaheen'], ['درويش', 'Darwish'], ['الأحمد', 'Ahmad'], ['صالح', 'Saleh'], ['قاسم', 'Qasim'],
    ];

    private const BOYS = [
        ['أحمد', 'Ahmad'], ['محمد', 'Mohammad'], ['يوسف', 'Yousef'], ['عمر', 'Omar'], ['علي', 'Ali'], ['خالد', 'Khaled'],
        ['إبراهيم', 'Ibrahim'], ['حمزة', 'Hamza'], ['زيد', 'Zaid'], ['كريم', 'Karim'], ['طارق', 'Tareq'], ['سليم', 'Salim'],
    ];

    private const GIRLS = [
        ['ليلى', 'Layla'], ['مريم', 'Mariam'], ['سارة', 'Sara'], ['نور', 'Noor'], ['هدى', 'Huda'], ['رنا', 'Rana'],
        ['لينا', 'Lina'], ['دانة', 'Dana'], ['جنى', 'Jana'], ['ريم', 'Reem'], ['تالا', 'Tala'], ['ياسمين', 'Yasmin'],
    ];

    /**
     * Students with their accounts and parents, created the way the admin screen creates them (default password).
     * The four hand-made ones come first, then every classroom is filled up to ten with generated students
     * (fixed names, so running again gives the same data); about one student in three has a sibling in another classroom.
     */
    private function seedStudents(string $domain, int $yearId): void
    {
        $saver = app(SaveStudent::class);

        foreach (self::STUDENTS as [$number, $nameAr, $nameEn, $classroom, $parent]) {
            $this->createStudent($saver, $yearId, $domain, $number, $nameAr, $nameEn, $classroom, $parent, 'خالد');
        }

        $fixed = array_count_values(array_column(self::STUDENTS, 3));
        $classrooms = DB::table('classrooms')->where('academic_year_id', $yearId)->orderBy('id')->pluck('name')->all();
        $index = 0;
        foreach ($classrooms as $c => $classroom) {
            for ($seat = 1; $seat <= self::STUDENTS_PER_CLASSROOM - ($fixed[$classroom] ?? 0); $seat++, $index++) {
                $family = self::FAMILIES[($index % 84) % count(self::FAMILIES)];
                [$first, $firstEn] = $index % 2 === 0 ? self::BOYS[($index * 5) % count(self::BOYS)] : self::GIRLS[($index * 7) % count(self::GIRLS)];
                $this->createStudent(
                    $saver, $yearId, $domain, 'D-'.(2001 + $c * 10 + $seat - 1),
                    $first.' '.$family[0], $firstEn.' '.$family[1], $classroom, 4 + ($index % 84), $family[0],
                );
            }
        }
    }

    private function createStudent(SaveStudent $saver, int $yearId, string $domain, string $number, string $nameAr, string $nameEn, string $classroom, int $parent, string $family): void
    {
        if (DB::table('students')->where('student_number', $number)->exists()) {
            return;
        }

        // The student's email is generated (e.g. ahmad.d1001@school2.edu).
        $saver->create([
            'student_number' => $number,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'classroom_id' => DB::table('classrooms')->where('name', $classroom)->where('academic_year_id', $yearId)->value('id'),
            'guardian_email' => 'parent'.$parent.'@'.$domain,
            'guardian_name' => 'ولي أمر '.$family,
            'guardian_phone' => '0599'.str_pad((string) $parent, 6, '0', STR_PAD_LEFT),
        ]);
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
