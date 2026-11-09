<?php

declare(strict_types=1);

namespace App\Modules\Discipline\Services;

use App\Models\User;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Discipline\Models\Warning;

/** Releases a warning to the people it concerns: the parents of a student, or the member of staff. */
class Warnings
{
    public function __construct(private readonly Notifier $notifier) {}

    /** The warning becomes active and the notification goes out (once). */
    public function release(Warning $w, ?User $by = null): void
    {
        $w->update(['status' => 'active', 'approved_by' => $by?->id ?? $w->approved_by]);
        if ($w->notified_at !== null) {
            return;
        }
        $w->load(['student.guardians', 'student', 'user']);
        $params = ['title' => $w->title, 'level' => $w->level];
        if ($w->subject_type === 'student' && $w->student !== null) {
            $name = ['student_ar' => $w->student->name_ar, 'student_en' => $w->student->name_en ?: $w->student->name_ar];
            $this->notifier->toUsers($w->student->guardians->pluck('id'), 'warning_student_parent', $params + $name, "/parent/children/{$w->student_id}/warnings");
            if ($w->student->user_id !== null) {
                $this->notifier->toUsers([$w->student->user_id], 'warning_student_self', $params, '/student/warnings');
            }
        } elseif ($w->user_id !== null) {
            $this->notifier->toUsers([$w->user_id], 'warning_staff', $params, '/teacher/warnings');
        }
        $w->update(['notified_at' => now()]);
    }
}
