<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Import;

use App\Modules\Academic\Import\Lookups;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\People\Import\Importer;
use Carbon\Carbon;

/**
 * Past attendance of students: one row = one student on one day. The section is the one the student sat in during the
 * academic year that contains the date. Importing the same day again corrects it. It is silent: no message goes to parents.
 */
class AttendanceImporter extends Importer
{
    use Lookups;

    public function kind(): string
    {
        return 'attendance';
    }

    public function columns(): array
    {
        return [
            'student_number' => ['en' => 'Student number', 'ar' => 'رقم الطالب', 'required' => true, 'example' => '2026001', 'aliases' => ['رقم الطالب', 'رقم الجلوس', 'رقم', 'student number', 'student id', 'id']],
            'date' => ['en' => 'Date', 'ar' => 'التاريخ', 'required' => true, 'example' => '2026-10-05', 'aliases' => ['التاريخ', 'اليوم', 'date', 'day']],
            'status' => ['en' => 'Status', 'ar' => 'الحالة', 'required' => true, 'example' => 'absent', 'aliases' => ['الحالة', 'الحضور', 'حاضر غائب', 'status', 'attendance']],
            'note' => ['en' => 'Note', 'ar' => 'ملاحظة', 'required' => false, 'example' => 'Sick', 'aliases' => ['ملاحظه', 'ملاحظة', 'السبب', 'note', 'reason']],
        ];
    }

    public function missingFields(array $mapped): array
    {
        return array_values(array_diff(['student_number', 'date', 'status'], $mapped));
    }

    public function check(array $row): array
    {
        $errors = [];
        $number = trim((string) ($row['student_number'] ?? ''));
        $student = $number === '' ? null : Student::where('student_number', $number)->first();
        $student === null && $errors[] = __('import.unknown_student', ['value' => $number]);

        $date = null;
        try {
            $date = Carbon::parse((string) ($row['date'] ?? ''))->format('Y-m-d');
        } catch (\Throwable) {
            $errors[] = __('import.bad_date', ['value' => $row['date'] ?? '']);
        }
        $date !== null && $date > now()->toDateString() && $errors[] = __('import.date_in_future', ['value' => $date]);

        $status = $this->status($row['status'] ?? null);
        $status === null && $errors[] = __('import.bad_attendance_status', ['value' => $row['status'] ?? '']);

        $classroomId = null;
        if ($student && $date !== null && $errors === []) {
            $year = $this->yearOf($date);
            $year === null && $errors[] = __('attendance.date_outside_year');
            $enrollment = $year ? Enrollment::where('student_id', $student->id)->where('academic_year_id', $year->id)->first() : null;
            $year && $enrollment === null && $errors[] = __('import.student_no_section', ['value' => $number, 'year' => $year->name]);
            $classroomId = $enrollment?->classroom_id;
        }
        if ($student && $date !== null && $this->repeated('attendance', $student->id.'|'.$date)) {
            $errors[] = __('import.duplicate_in_file', ['value' => $number.' / '.$date]);
        }
        if ($errors !== []) {
            return [null, $errors];
        }

        return [['student_id' => $student->id, 'classroom_id' => $classroomId, 'date' => $date, 'status' => $status, 'note' => filled($row['note'] ?? null) ? mb_substr(trim((string) $row['note']), 0, 255) : null], []];
    }

    public function create(array $data): array
    {
        StudentAttendance::updateOrCreate(
            ['student_id' => $data['student_id'], 'date' => $data['date']],
            ['classroom_id' => $data['classroom_id'], 'status' => $data['status'], 'note' => $data['note'], 'marked_by' => auth()->id()],
        );
        $student = Student::find($data['student_id']);

        return ['student_number' => $student->student_number, 'name' => $student->name_ar, 'date' => $data['date'], 'status' => $data['status']];
    }

    /** The academic year that contains the date. */
    private function yearOf(string $date): ?AcademicYear
    {
        return ($this->memo['years'] ??= AcademicYear::all())->first(fn (AcademicYear $y) => $date >= $y->starts_on->format('Y-m-d') && $date <= $y->ends_on->format('Y-m-d'));
    }

    private function status(?string $value): ?string
    {
        $v = $this->norm((string) $value);
        $words = [
            'present' => ['present', 'p', 'حاضر', 'حاضره', 'حضور'],
            'late' => ['late', 'l', 'متاخر', 'متاخره', 'تاخير', 'تأخر'],
            'absent' => ['absent', 'a', 'غائب', 'غايب', 'غائبه', 'غياب'],
            'excused' => ['excused', 'e', 'بعذر', 'غياببعذر', 'غائببعذر', 'معذور', 'اجازه', 'إجازة'],
        ];
        foreach ($words as $status => $list) {
            if (in_array($v, array_map(fn ($w) => $this->norm($w), $list), true)) {
                return $status;
            }
        }

        return null;
    }
}
