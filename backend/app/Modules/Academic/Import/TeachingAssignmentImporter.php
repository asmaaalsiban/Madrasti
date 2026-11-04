<?php

declare(strict_types=1);

namespace App\Modules\Academic\Import;

use App\Modules\Academic\Http\Requests\TeachingAssignmentRequest;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\People\Import\Importer;
use Illuminate\Support\Facades\Validator;

/** Who teaches which subject in which section, with the same checks as the assignment form (the teacher must teach that subject). */
class TeachingAssignmentImporter extends Importer
{
    use Lookups;

    public function kind(): string
    {
        return 'teaching';
    }

    public function columns(): array
    {
        return [
            'classroom' => ['en' => 'Section', 'ar' => 'الشعبة', 'required' => true, 'example' => '5-A', 'aliases' => ['الشعبه', 'الصف', 'القسم', 'section', 'class', 'classroom']],
            'subject' => ['en' => 'Subject', 'ar' => 'المادة', 'required' => true, 'example' => 'MATH', 'aliases' => ['المادة', 'المادة الدراسية', 'رمز المادة', 'subject', 'subject code']],
            'teacher' => ['en' => 'Teacher', 'ar' => 'المعلم', 'required' => true, 'example' => 'teacher@example.com', 'aliases' => ['المعلم', 'المعلمة', 'اسم المعلم', 'ايميل المعلم', 'teacher', 'teacher email']],
            'weekly_periods' => ['en' => 'Periods per week', 'ar' => 'الحصص بالأسبوع', 'required' => false, 'example' => '5', 'aliases' => ['الحصص', 'عدد الحصص', 'الحصص الاسبوعيه', 'periods', 'weekly periods', 'lessons']],
        ];
    }

    public function missingFields(array $mapped): array
    {
        return array_values(array_diff(['classroom', 'subject', 'teacher'], $mapped));
    }

    public function check(array $row): array
    {
        $errors = [];
        $data = ['weekly_periods' => $row['weekly_periods'] ?? '1'];

        $classroom = isset($row['classroom']) ? $this->classroomNamed($row['classroom']) : null;
        $subject = isset($row['subject']) ? $this->subjectNamed($row['subject']) : null;
        $teacher = isset($row['teacher']) ? $this->staffNamed($row['teacher']) : null;
        isset($row['classroom']) && $classroom === null && $errors[] = __('import.unknown_classroom', ['name' => $row['classroom']]);
        isset($row['subject']) && $subject === null && $errors[] = __('import.unknown_subject', ['name' => $row['subject']]);
        isset($row['teacher']) && $teacher === null && $errors[] = __('import.unknown_staff', ['name' => $row['teacher']]);
        $data += ['classroom_id' => $classroom?->id, 'subject_id' => $subject?->id, 'teacher_id' => $teacher?->id];

        if ($classroom && $subject && $this->repeated('teaching', $classroom->id.'|'.$subject->id)) {
            $errors[] = __('import.duplicate_in_file', ['value' => $row['classroom'].' / '.$row['subject']]);
        }

        // Only what resolved is checked further (the form's own rules, including "this teacher teaches this subject").
        if ($errors === []) {
            $request = TeachingAssignmentRequest::create('/', 'POST', $data);
            $request->setContainer(app());
            $validator = Validator::make($data, $request->rules());
            $validator->after($request->after());
            $errors = $this->messages($validator);
            $data = $errors === [] ? $validator->validated() : $data;
        }

        return [$errors === [] ? $data : null, $errors];
    }

    public function create(array $data): array
    {
        $assignment = TeachingAssignment::create($data)->load('classroom', 'subject', 'teacher');

        return ['classroom' => $assignment->classroom->name, 'subject' => $assignment->subject->name_ar, 'teacher' => $assignment->teacher->name];
    }
}
