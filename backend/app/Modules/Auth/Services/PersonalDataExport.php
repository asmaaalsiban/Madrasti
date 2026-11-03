<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use Illuminate\Support\Facades\DB;

/** A copy of a person's own data (or of a child's, for the parent): what the school holds about them, in a readable file. */
class PersonalDataExport
{
    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $data = [
            'exported_at' => now()->toIso8601String(),
            'account' => $user->only(['name', 'email', 'phone', 'role', 'locale', 'created_at']) + ['role' => $user->role->value],
            'children' => DB::table('guardian_student')->join('students', 'students.id', '=', 'guardian_student.student_id')->where('guardian_id', $user->id)->get(['students.id', 'students.student_number', 'students.name_ar', 'students.name_en', 'guardian_student.relation'])->all(),
        ];
        if ($user->staffProfile()->exists()) {
            $data['staff'] = [
                'profile' => $user->staffProfile()->first()?->only(['staff_type', 'job_title', 'hired_on', 'weekly_hours']),
                'attendance' => DB::table('staff_attendances')->where('user_id', $user->id)->orderBy('date')->get(['date', 'status', 'check_in', 'check_out'])->all(),
                'payslips' => DB::table('payslips')->where('user_id', $user->id)->get(['base_salary', 'additions', 'deductions', 'net', 'paid_on', 'payment_method'])->all(),
            ];
        }
        $data['notifications'] = DB::table('notifications')->where('notifiable_id', $user->id)->count();

        return $data;
    }

    /** @return array<string, mixed> */
    public function forStudent(Student $student): array
    {
        $id = $student->id;

        return [
            'exported_at' => now()->toIso8601String(),
            'student' => $student->only(['student_number', 'name_ar', 'name_en', 'birth_date', 'gender']),
            'guardians' => DB::table('guardian_student')->join('users', 'users.id', '=', 'guardian_student.guardian_id')->where('student_id', $id)->get(['users.name', 'users.email', 'users.phone', 'guardian_student.relation'])->all(),
            'enrollments' => DB::table('enrollments')->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')->where('student_id', $id)->get(['classrooms.name as classroom', 'enrollments.academic_year_id'])->all(),
            'attendance' => DB::table('student_attendances')->where('student_id', $id)->orderBy('date')->get(['date', 'status', 'note'])->all(),
            'grades' => DB::table('assessment_scores')->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')->where('student_id', $id)->where('assessments.is_published', true)->get(['assessments.title', 'assessments.held_on', 'assessment_scores.score', 'assessments.max_score'])->all(),
            // Behavior notes the school chose to show to the family.
            'behavior' => DB::table('behavior_notes')->where('student_id', $id)->where('visible_to_parent', true)->get(['noted_on', 'type', 'note'])->all(),
            'fees' => DB::table('student_fees')->where('student_id', $id)->get(['id', 'description', 'total_amount', 'discount'])->map(fn ($f) => (array) $f + ['payments' => DB::table('payments')->where('student_fee_id', $f->id)->get(['amount', 'method', 'paid_on', 'payer_name'])->all()])->all(),
            'transport' => DB::table('student_transport')->where('student_id', $id)->first(['pickup_bus_id', 'dropoff_bus_id']),
        ];
    }
}
