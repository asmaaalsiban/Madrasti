<?php

namespace App\Console\Commands;

use App\Modules\People\Actions\SaveStudent;
use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Developer tool (see loadtest/README.md): grows ONE school to a realistic size so a load test means something: every section filled up to
 * `--per-class` students with a parent account, a score for each of them in every published assessment, `--days` of student attendance,
 * a few notifications per account, behavior notes and audit-log rows. Never runs in production.
 * Run it for one school: `php artisan tenants:run loadtest:seed --tenants=demo --option=per-class=25`.
 */
class LoadtestSeed extends Command
{
    protected $signature = 'loadtest:seed {--per-class=25 : Students in every section} {--days=60 : School days of attendance to fill} {--notes=40 : Notifications per account} {--audit=40000 : Audit-log rows} {--tokens= : Write sign-in tokens of some admins, teachers, parents and students to this JSON file}';

    protected $description = 'Grow this school to a realistic size for load testing (never in production)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('This is a load-testing tool: it never runs in production.');

            return self::FAILURE;
        }
        $perClass = max(1, (int) $this->option('per-class'));
        $year = DB::table('academic_years')->where('is_current', true)->value('id');
        if ($year === null) {
            $this->error('No current academic year: seed the demo school first.');

            return self::FAILURE;
        }

        $added = $this->students($year, $perClass);
        $this->info("$added student(s) added (with a parent account each)");
        $this->info($this->scores($year).' assessment score(s) added');
        $this->info($this->attendance($year, max(1, (int) $this->option('days'))).' attendance row(s) added');
        $this->info($this->notifications(max(0, (int) $this->option('notes'))).' notification(s) added');
        $this->info($this->behavior($year).' behavior note(s) added');
        $this->info($this->audit(max(0, (int) $this->option('audit'))).' audit-log row(s) added');
        if ($this->option('tokens')) {
            $this->info($this->tokens((string) $this->option('tokens')));
        }
        foreach (['students', 'users', 'enrollments', 'student_attendances', 'assessment_scores', 'notifications', 'audit_logs', 'installments'] as $t) {
            $this->line(str_pad($t, 22).DB::table($t)->count());
        }

        return self::SUCCESS;
    }

    private function students(int $year, int $perClass): int
    {
        $saver = app(SaveStudent::class);
        $added = 0;
        $next = (int) DB::table('students')->where('student_number', 'like', 'L-%')->count() + 1;
        foreach (DB::table('classrooms')->where('academic_year_id', $year)->orderBy('id')->get() as $room) {
            $have = DB::table('enrollments')->where('classroom_id', $room->id)->count();
            for ($i = $have; $i < $perClass; $i++, $next++) {
                $saver->create([
                    'student_number' => 'L-'.$next, 'name_ar' => 'طالب تجريبي '.$next, 'name_en' => 'Load Student '.$next, 'classroom_id' => $room->id,
                    // Two children per family, like a real school.
                    'guardian_email' => 'loadparent'.intdiv($next, 2).'@load.test', 'guardian_name' => 'ولي أمر '.intdiv($next, 2), 'guardian_phone' => '0588'.str_pad((string) intdiv($next, 2), 6, '0', STR_PAD_LEFT),
                ]);
                $added++;
            }
        }

        return $added;
    }

    private function scores(int $year): int
    {
        $count = 0;
        foreach (DB::table('assessments')->join('classrooms', 'classrooms.id', '=', 'assessments.classroom_id')->where('classrooms.academic_year_id', $year)->get(['assessments.id', 'assessments.classroom_id', 'assessments.max_score']) as $a) {
            $have = DB::table('assessment_scores')->where('assessment_id', $a->id)->pluck('student_id')->flip();
            $rows = [];
            foreach (DB::table('enrollments')->where('classroom_id', $a->classroom_id)->pluck('student_id') as $studentId) {
                if ($have->has($studentId)) {
                    continue;
                }
                $rows[] = ['assessment_id' => $a->id, 'student_id' => $studentId, 'score' => round($a->max_score * (35 + crc32($studentId.'|'.$a->id) % 66) / 100, 1), 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('assessment_scores')->insert($chunk);
            }
            $count += count($rows);
        }

        return $count;
    }

    /** The last `$days` school days (Sunday to Thursday), a status for every enrolled student that has no row yet. */
    private function attendance(int $year, int $days): int
    {
        $dates = [];
        for ($d = now()->startOfDay(); count($dates) < $days; $d = $d->copy()->subDay()) {
            if (in_array($d->dayOfWeek, [0, 1, 2, 3, 4], true) && $d->lte(now())) {
                $dates[] = $d->toDateString();
            }
        }
        $count = 0;
        $marker = DB::table('users')->where('role', 'teacher')->value('id');
        foreach (DB::table('classrooms')->where('academic_year_id', $year)->pluck('id') as $roomId) {
            $students = DB::table('enrollments')->where('classroom_id', $roomId)->pluck('student_id');
            $have = DB::table('student_attendances')->where('classroom_id', $roomId)->get(['student_id', 'date'])->map(fn ($r) => $r->student_id.'|'.substr($r->date, 0, 10))->flip();
            $rows = [];
            foreach ($dates as $date) {
                foreach ($students as $studentId) {
                    if ($have->has($studentId.'|'.$date)) {
                        continue;
                    }
                    $h = crc32($studentId.$date) % 100;
                    $rows[] = ['student_id' => $studentId, 'classroom_id' => $roomId, 'date' => $date, 'status' => $h < 90 ? 'present' : ($h < 95 ? 'late' : ($h < 98 ? 'absent' : 'excused')), 'marked_by' => $marker, 'created_at' => now(), 'updated_at' => now()];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('student_attendances')->insert($chunk);
            }
            $count += count($rows);
        }

        return $count;
    }

    private function notifications(int $each): int
    {
        $rows = [];
        $count = 0;
        $have = DB::table('notifications')->selectRaw('notifiable_id, count(*) as n')->groupBy('notifiable_id')->pluck('n', 'notifiable_id');
        foreach (DB::table('users')->whereIn('role', ['admin', 'teacher', 'parent'])->pluck('id') as $userId) {
            for ($i = (int) ($have[$userId] ?? 0); $i < $each; $i++) {
                $rows[] = ['id' => (string) Str::uuid(), 'type' => 'app', 'notifiable_type' => (new User)->getMorphClass(), 'notifiable_id' => $userId, 'data' => json_encode(['kind' => 'announcement', 'params' => ['title' => 'Notice '.$i], 'link' => '/announcements']),
                    'read_at' => $i % 3 === 0 ? null : now(), 'created_at' => now()->subMinutes($i * 37), 'updated_at' => now()];
                if (count($rows) >= 1000) {
                    DB::table('notifications')->insert($rows);
                    $count += count($rows);
                    $rows = [];
                }
            }
        }
        if ($rows !== []) {
            DB::table('notifications')->insert($rows);
            $count += count($rows);
        }

        return $count;
    }

    private function behavior(int $year): int
    {
        $teacher = DB::table('users')->where('role', 'teacher')->value('id');
        $rows = [];
        $have = DB::table('behavior_notes')->selectRaw('student_id, count(*) as n')->groupBy('student_id')->pluck('n', 'student_id');
        foreach (DB::table('enrollments')->where('academic_year_id', $year)->pluck('student_id') as $studentId) {
            for ($i = (int) ($have[$studentId] ?? 0); $i < 3; $i++) {
                $rows[] = ['student_id' => $studentId, 'teacher_id' => $teacher, 'type' => $i === 0 ? 'negative' : 'positive', 'note' => 'Load test note '.$i, 'visible_to_parent' => true, 'noted_on' => now()->subDays($i * 5)->toDateString(), 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('behavior_notes')->insert($chunk);
        }

        return count($rows);
    }

    private function audit(int $rows): int
    {
        $user = DB::table('users')->where('role', 'admin')->value('id');
        $rows = max(0, $rows - DB::table('audit_logs')->count()); // only tops up to the wanted size
        for ($i = 0; $i < $rows; $i += 1000) {
            $batch = [];
            for ($j = $i; $j < min($rows, $i + 1000); $j++) {
                $batch[] = ['user_id' => $user, 'action' => 'updated', 'auditable_type' => 'App\\Modules\\Grading\\Models\\AssessmentScore', 'auditable_id' => $j, 'old_values' => '{"score":"50"}', 'new_values' => '{"score":"60"}', 'ip_address' => '127.0.0.1', 'user_agent' => 'load', 'created_at' => now()->subSeconds($j * 30)];
            }
            DB::table('audit_logs')->insert($batch);
        }

        return $rows;
    }

    /** Sign-in tokens, so a load test does not pay for passwords (it logs in separately for the login scenario). */
    private function tokens(string $file): string
    {
        // The generated accounts must change their password at first sign-in; a test token skips that.
        $make = function (User $u): string {
            $u->forceFill(['must_change_password' => false])->save();

            return $u->createToken('loadtest')->plainTextToken;
        };
        $out = ['admin' => [], 'teacher' => [], 'parent' => [], 'student' => []];
        foreach (User::where('role', 'admin')->limit(2)->get() as $u) {
            $out['admin'][] = ['token' => $make($u)];
        }
        foreach (User::where('role', 'teacher')->where('is_active', true)->limit(25)->get() as $u) {
            $out['teacher'][] = ['token' => $make($u)];
        }
        foreach (User::where('role', 'parent')->whereHas('children')->with('children:id')->limit(150)->get() as $u) {
            $out['parent'][] = ['token' => $make($u), 'child' => $u->children->first()->id];
        }
        foreach (User::where('role', 'student')->whereHas('student')->with('student:id,user_id')->limit(150)->get() as $u) {
            $out['student'][] = ['token' => $make($u), 'child' => $u->student->id];
        }
        file_put_contents($file, json_encode($out));

        return 'tokens written to '.$file.': '.collect($out)->map(fn ($v, $k) => $k.' '.count($v))->implode(', ');
    }
}
