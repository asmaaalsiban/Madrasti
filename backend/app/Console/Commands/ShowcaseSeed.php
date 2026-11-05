<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\OnlinePayment;
use App\Modules\Finance\Models\StudentFee;
use App\Modules\HR\Models\StaffAttendance;
use App\Modules\HR\Models\StaffContract;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskAssignee;
use App\Shared\Payments\BankTransferSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Developer tool: adds a little of everything newer to the demo school, so a demonstration (and the screenshots of the user guide) shows
 * every module with data: tasks, school buses, bank transfers, photo consent, a salary with absence, a data request. Run it for one school:
 * `php artisan tenants:run showcase:seed --tenants=demo` (after `school:seed-demo`). Never in production.
 */
class ShowcaseSeed extends Command
{
    protected $signature = 'showcase:seed';

    protected $description = 'Add demo data for the newer modules (never in production)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Never in production.');

            return self::FAILURE;
        }
        $admin = User::where('role', 'admin')->firstOrFail();
        $teachers = User::where('role', 'teacher')->whereHas('staffProfile', fn ($q) => $q->where('staff_type', 'teacher'))->orderBy('id')->limit(6)->get();

        $this->tasks($admin, $teachers);
        $this->transport();
        $this->payments();
        $this->consent();
        $this->salary($teachers->first());
        $this->info('Showcase data added.');

        return self::SUCCESS;
    }

    private function tasks(User $admin, $teachers): void
    {
        if (Task::exists()) {
            return;
        }
        $a = Task::create(['created_by' => $admin->id, 'title' => 'تسليم خطط الدروس الأسبوعية', 'description' => 'يرجى رفع خطة الأسبوع القادم قبل نهاية الدوام.', 'due_on' => now()->addDays(3)->toDateString(), 'priority' => 'high']);
        $b = Task::create(['created_by' => $admin->id, 'title' => 'تجهيز نشاط اليوم المفتوح', 'description' => 'اقتراح فكرة نشاط لكل صف.', 'due_on' => now()->addDays(10)->toDateString(), 'priority' => 'normal']);
        foreach ($teachers as $i => $t) {
            TaskAssignee::create(['task_id' => $a->id, 'user_id' => $t->id, 'status' => $i === 0 ? 'done' : ($i === 1 ? 'doing' : 'todo'), 'note' => $i === 0 ? 'تم الرفع' : null, 'done_at' => $i === 0 ? now() : null]);
            if ($i < 3) {
                TaskAssignee::create(['task_id' => $b->id, 'user_id' => $t->id]);
            }
        }
    }

    private function transport(): void
    {
        if (DB::table('buses')->exists()) {
            return;
        }
        $m1 = DB::table('transport_rounds')->insertGetId(['name_ar' => 'الجولة الصباحية الأولى', 'name_en' => 'Morning round 1', 'period' => 'morning', 'starts_at' => '06:45', 'ends_at' => '07:30', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m2 = DB::table('transport_rounds')->insertGetId(['name_ar' => 'الجولة الصباحية الثانية', 'name_en' => 'Morning round 2', 'period' => 'morning', 'starts_at' => '07:30', 'ends_at' => '08:10', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $e1 = DB::table('transport_rounds')->insertGetId(['name_ar' => 'جولة العودة', 'name_en' => 'Afternoon round', 'period' => 'evening', 'starts_at' => '13:30', 'ends_at' => '14:30', 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $buses = [];
        foreach ([['11', 'أبو علي', '0790000011', 'الجبيهة', 'المدرسة'], ['12', 'أبو سعد', '0790000012', 'خلدا', 'المدرسة'], ['13', 'أبو عمر', '0790000013', 'صويلح', 'المدرسة']] as [$n, $d, $ph, $from, $to]) {
            $buses[$n] = DB::table('buses')->insertGetId(['number' => $n, 'driver_name' => $d, 'driver_phone' => $ph, 'capacity' => 30, 'area_from' => $from, 'area_to' => $to, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach ([$m1, $m2, $e1] as $r) {
                DB::table('bus_round')->insert(['bus_id' => $buses[$n], 'transport_round_id' => $r]);
            }
        }
        foreach (Student::orderBy('id')->limit(12)->get() as $i => $s) {
            DB::table('student_transport')->insert(['student_id' => $s->id, 'pickup_round_id' => $i % 2 ? $m1 : $m2, 'pickup_bus_id' => array_values($buses)[$i % 3], 'dropoff_round_id' => $e1, 'dropoff_bus_id' => $i % 4 === 0 ? array_values($buses)[($i + 1) % 3] : array_values($buses)[$i % 3], 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function payments(): void
    {
        app(BankTransferSettings::class)->save(['enabled' => true, 'instructions' => 'اكتب اسم الطالب في ملاحظة التحويل وأرفق صورة الإشعار.', 'accounts' => [['bank' => 'البنك العربي', 'holder' => 'مدرسة الديمو', 'iban' => 'JO94CBJO0010000000000131000302', 'cliq' => 'DEMOSCHOOL']]]);
        if (OnlinePayment::where('provider', 'bank_transfer')->exists()) {
            return;
        }
        $fee = StudentFee::orderBy('id')->first();
        $parent = $fee ? DB::table('guardian_student')->where('student_id', $fee->student_id)->value('guardian_id') : null;
        if ($fee && $parent) {
            OnlinePayment::create(['reference' => (string) Str::uuid(), 'student_id' => $fee->student_id, 'student_fee_id' => $fee->id, 'user_id' => $parent, 'amount' => '150.00', 'payer_name' => 'ولي الأمر', 'provider' => 'bank_transfer', 'status' => 'pending', 'transfer_reference' => 'CLQ-20481']);
        }
    }

    private function consent(): void
    {
        foreach (Student::orderBy('id')->limit(40)->get() as $i => $s) {
            if ($i % 7 === 6) {
                $s->forceFill(['photo_consent' => false, 'photo_consent_at' => now()])->save();
            } elseif ($i % 3 !== 0) {
                $s->forceFill(['photo_consent' => true, 'photo_consent_at' => now()])->save();
            }
        }
    }

    /** A salary with some absence, so "My salary" shows the donut and the deduction. */
    private function salary(?User $teacher): void
    {
        if ($teacher === null) {
            return;
        }
        DB::table('school_settings')->updateOrInsert(['key' => 'payroll_rules'], ['value' => json_encode(['absence' => ['enabled' => true, 'type' => 'amount', 'value' => 12]]), 'created_at' => now(), 'updated_at' => now()]);
        if (! StaffContract::where('user_id', $teacher->id)->exists()) {
            StaffContract::create(['user_id' => $teacher->id, 'starts_on' => now()->startOfYear()->toDateString(), 'ends_on' => now()->endOfYear()->toDateString(), 'base_salary' => 450, 'status' => 'active', 'created_by' => 1]);
        }
        // Everybody checked in on the first working days of the month, except this teacher on two of them.
        $days = collect();
        for ($d = now()->startOfMonth(); $d->lt(now()->startOfDay()) && $days->count() < 6; $d = $d->copy()->addDay()) {
            if (in_array($d->dayOfWeek, [0, 1, 2, 3, 4], true)) {
                $days->push($d->copy());
            }
        }
        foreach ($days as $i => $day) {
            StaffAttendance::updateOrCreate(['user_id' => 1, 'date' => $day->toDateString()], ['status' => 'present', 'check_in' => '07:40:00']);
            if ($i >= 2) {
                StaffAttendance::updateOrCreate(['user_id' => $teacher->id, 'date' => $day->toDateString()], ['status' => 'present', 'check_in' => '07:41:00']);
            }
        }
    }
}
