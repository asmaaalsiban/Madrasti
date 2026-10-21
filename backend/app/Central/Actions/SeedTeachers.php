<?php

declare(strict_types=1);

namespace App\Central\Actions;

use App\Models\User;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Services\TeacherPlanner;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;

/**
 * Creates the demo teachers the curriculum needs: for every subject as many teachers as its periods require
 * (each building has its own teachers, so a building's periods are divided by the weekly load). Teachers that
 * exist already count, so running it again creates nothing. Runs inside the school (tenant) database.
 */
class SeedTeachers
{
    private const FIRST_NAMES = [
        'أحمد', 'سارة', 'محمد', 'ليلى', 'خالد', 'هدى', 'رنا', 'عمر', 'نور', 'يوسف', 'مها', 'سامر',
        'دعاء', 'طارق', 'ريم', 'بسام', 'ديما', 'زياد', 'لينا', 'ماجد', 'إيمان', 'وليد', 'سلمى', 'فادي',
    ];

    private const FAMILY_NAMES = ['العلي', 'حسن', 'يوسف', 'إبراهيم', 'سعيد', 'فؤاد', 'مراد', 'الخطيب', 'شاهين', 'درويش', 'صالح', 'قاسم', 'النجار'];

    /**
     * @return array{created: int, needed: array<string, int>}
     */
    public function handle(string $domainName, string $password, int $maxLoad): array
    {
        $planner = app(TeacherPlanner::class);
        $subjects = Subject::all()->keyBy('id');
        $created = 0;
        $needed = [];
        $n = User::whereHas('staffProfile')->count();

        foreach ($planner->demands()->groupBy('subject_id') as $subjectId => $rows) {
            $subject = $subjects[$subjectId];
            $needed[$subject->code] = $planner->teachersNeeded($rows, $maxLoad);
            $existing = DB::table('staff_profiles')->where('subject_id', $subjectId)->where('staff_type', StaffType::Teacher->value)->count();

            for ($i = $existing; $i < $needed[$subject->code]; $i++) {
                $n++;
                $user = User::create([
                    'name' => self::FIRST_NAMES[$n % count(self::FIRST_NAMES)].' '.self::FAMILY_NAMES[intdiv($n, count(self::FIRST_NAMES)) % count(self::FAMILY_NAMES)],
                    'email' => $this->email($subject->code, $i + 1, $domainName),
                    'password' => $password,
                    'role' => StaffType::Teacher->accountRole(),
                    'is_active' => true,
                    'must_change_password' => false,
                ]);
                $user->staffProfile()->create(['staff_type' => StaffType::Teacher, 'subject_id' => $subjectId]);
                $created++;
            }
        }

        return ['created' => $created, 'needed' => $needed];
    }

    private function email(string $code, int $number, string $domainName): string
    {
        do {
            $email = strtolower($code).$number.'@'.$domainName.'.test';
            $number++;
        } while (User::where('email', $email)->exists());

        return $email;
    }
}
