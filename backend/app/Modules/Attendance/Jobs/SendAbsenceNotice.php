<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Jobs;

use App\Modules\Attendance\Models\AbsenceNotification;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Shared\Enums\AttendanceStatus;
use App\Shared\Messaging\MessageSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Tells the parents of an absent student. Safe to run again: a parent who already got the message for this
 * absence is skipped, and a failed one is tried again. A provider failure is recorded, never thrown.
 */
class SendAbsenceNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(public readonly int $attendanceId) {}

    public function handle(MessageSender $sender): void
    {
        $attendance = StudentAttendance::with('student.guardians')->find($this->attendanceId);
        if ($attendance === null || $attendance->status !== AttendanceStatus::Absent || $attendance->student === null) {
            return;
        }

        $student = $attendance->student;
        $text = __('attendance.absent_message', [
            'student' => app()->getLocale() === 'ar' ? $student->name_ar : ($student->name_en ?: $student->name_ar),
            'date' => $attendance->date->format('Y-m-d'),
        ]);

        foreach ($student->guardians as $guardian) {
            $notice = AbsenceNotification::firstOrCreate(
                ['student_attendance_id' => $attendance->id, 'guardian_id' => $guardian->id],
                ['channel' => (string) config('messaging.driver'), 'status' => 'pending'],
            );
            if ($notice->status === 'sent') {
                continue;
            }

            $phone = trim((string) $guardian->phone);
            if ($phone === '') {
                $notice->update(['phone' => null, 'status' => 'skipped', 'error' => 'no phone']);

                continue;
            }

            try {
                $sender->send($phone, $text);
                $notice->update(['phone' => $phone, 'status' => 'sent', 'error' => null, 'sent_at' => now()]);
            } catch (Throwable $e) {
                report($e);
                $notice->update(['phone' => $phone, 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);
            }
        }
    }
}
