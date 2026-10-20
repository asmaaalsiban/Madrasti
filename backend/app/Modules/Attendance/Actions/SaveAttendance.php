<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Actions;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Attendance\Jobs\SendAbsenceNotice;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Shared\Enums\AttendanceStatus;
use Illuminate\Support\Facades\DB;

/** Records (or corrects) one classroom's attendance for one day, then tells the parents of absent students. */
class SaveAttendance
{
    /**
     * @param  list<array{student_id: int, status: string, note?: string|null}>  $records
     */
    public function handle(Classroom $classroom, string $date, array $records, User $by): void
    {
        $absent = DB::transaction(function () use ($classroom, $date, $records, $by): array {
            $absent = [];
            foreach ($records as $record) {
                $attendance = StudentAttendance::updateOrCreate(
                    ['student_id' => $record['student_id'], 'date' => $date],
                    ['classroom_id' => $classroom->id, 'status' => $record['status'], 'note' => $record['note'] ?? null, 'marked_by' => $by->id],
                );
                if ($attendance->status === AttendanceStatus::Absent) {
                    $absent[] = $attendance->id;
                }
            }

            return $absent;
        });

        foreach ($absent as $id) {
            try {
                SendAbsenceNotice::dispatch($id);
            } catch (\Throwable $e) {
                // The attendance is already saved; a messaging problem must not turn it into an error.
                report($e);
            }
        }
    }
}
