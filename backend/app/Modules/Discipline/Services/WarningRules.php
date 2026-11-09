<?php

declare(strict_types=1);

namespace App\Modules\Discipline\Services;

use App\Models\User;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Discipline\Models\Warning;
use App\Modules\Discipline\Models\WarningRule;
use App\Shared\Enums\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Runs the school's warning rules (daily, or when the admin asks). For each active rule it counts what each student or member of staff
 * did in the last `window_days` days, and raises ONE warning for whoever reached the threshold. It never raises the same rule twice for the
 * same person within the window, and never again after the admin withdrew one (that was a decision).
 */
class WarningRules
{
    public function __construct(private readonly Warnings $warnings, private readonly Notifier $notifier) {}

    /** @return array{created: int, pending: int} */
    public function evaluate(?Carbon $today = null): array
    {
        $today ??= now();
        $created = 0;
        $pending = 0;
        foreach (WarningRule::where('is_active', true)->get() as $rule) {
            $from = $today->copy()->subDays($rule->window_days - 1)->toDateString();
            foreach ($this->counts($rule, $from, $today->toDateString()) as $subjectId => $count) {
                if ($count < $rule->threshold || $this->alreadyRaised($rule, $subjectId, $from)) {
                    continue;
                }
                $w = Warning::create([
                    'subject_type' => $rule->subject_type, 'student_id' => $rule->subject_type === 'student' ? $subjectId : null, 'user_id' => $rule->subject_type === 'staff' ? $subjectId : null,
                    'kind' => 'rule', 'rule_id' => $rule->id, 'category' => $rule->category, 'level' => $rule->level, 'title' => $rule->title,
                    'body' => $this->text($rule, $count), 'issued_on' => $today->toDateString(), 'status' => 'pending',
                ]);
                if ($rule->auto_send) {
                    $this->warnings->release($w);
                    $created++;
                } else {
                    $pending++;
                }
            }
        }
        if ($pending > 0) {
            $admins = User::where('role', Role::Admin->value)->where('is_active', true)->pluck('id');
            $this->notifier->toUsers($admins, 'warnings_pending', ['count' => $pending], '/admin/warnings');
        }

        return ['created' => $created, 'pending' => $pending];
    }

    /** @return array<int, int> person id => how many times, for those with at least one */
    private function counts(WarningRule $rule, string $from, string $to): array
    {
        if ($rule->subject_type === 'student') {
            $enrolled = DB::table('enrollments')->join('academic_years', 'academic_years.id', '=', 'enrollments.academic_year_id')->where('academic_years.is_current', true)->pluck('enrollments.student_id');
            $q = match ($rule->metric) {
                'absent_days' => DB::table('student_attendances')->where('status', 'absent'),
                'late_count' => DB::table('student_attendances')->where('status', 'late'),
                default => DB::table('behavior_notes')->where('type', 'negative'),
            };
            $dateColumn = $rule->metric === 'negative_notes' ? 'noted_on' : 'date';

            return $q->whereIn('student_id', $enrolled)->whereBetween($dateColumn, [$from, $to])->selectRaw('student_id as id, count(*) as n')->groupBy('student_id')->pluck('n', 'id')->map(fn ($n) => (int) $n)->all();
        }
        $status = $rule->metric === 'late_count' ? 'late' : 'absent';

        return DB::table('staff_attendances')->join('users', 'users.id', '=', 'staff_attendances.user_id')->where('users.is_active', true)->where('staff_attendances.status', $status)
            ->whereBetween('staff_attendances.date', [$from, $to])->selectRaw('staff_attendances.user_id as id, count(*) as n')->groupBy('staff_attendances.user_id')->pluck('n', 'id')->map(fn ($n) => (int) $n)->all();
    }

    private function alreadyRaised(WarningRule $rule, int $subjectId, string $from): bool
    {
        return Warning::where('rule_id', $rule->id)->where($rule->subject_type === 'student' ? 'student_id' : 'user_id', $subjectId)->where('issued_on', '>=', $from)->exists();
    }

    /** The rule's text, or a plain sentence with the numbers. Tokens: {count} and {days}. */
    private function text(WarningRule $rule, int $count): string
    {
        $body = $rule->body !== null && trim($rule->body) !== '' ? $rule->body : __('discipline.auto_body.'.$rule->metric);

        return str_replace(['{count}', '{days}'], [(string) $count, (string) $rule->window_days], $body);
    }
}
