<?php

declare(strict_types=1);

namespace App\Modules\Academic\Import;

use App\Modules\Academic\Http\Requests\SubjectRequest;
use App\Modules\Academic\Models\Subject;
use App\Modules\People\Import\Importer;
use Illuminate\Support\Facades\Validator;

/** The school's subjects, with the same checks as the subject form. */
class SubjectImporter extends Importer
{
    public function kind(): string
    {
        return 'subjects';
    }

    public function columns(): array
    {
        return [
            'code' => ['en' => 'Code', 'ar' => 'الرمز', 'required' => false, 'example' => 'MATH', 'aliases' => ['رمز المادة', 'كود المادة', 'الكود', 'الرمز', 'code', 'subject code']],
            'name_ar' => ['en' => 'Name (Arabic)', 'ar' => 'الاسم بالعربي', 'required' => true, 'example' => 'الرياضيات', 'aliases' => ['اسم المادة', 'المادة', 'الاسم بالعربي', 'arabic name']],
            'name_en' => ['en' => 'Name (English)', 'ar' => 'الاسم بالإنجليزي', 'required' => false, 'example' => 'Mathematics', 'aliases' => ['الاسم بالانجليزي', 'اسم المادة بالانجليزي', 'english name', 'subject']],
        ];
    }

    public function genericName(): array
    {
        return ['keys' => ['name_ar', 'name_en'], 'aliases' => ['الاسم', 'اسم', 'name']];
    }

    public function missingFields(array $mapped): array
    {
        return array_intersect($mapped, ['name_ar', 'name_en']) === [] ? ['name_ar'] : [];
    }

    public function check(array $row): array
    {
        $errors = [];
        // A file with one name only still gets both; a missing code is made from the English name.
        $row['name_ar'] ??= $row['name_en'];
        $row['name_en'] ??= $row['name_ar'];
        $row['code'] ??= strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', (string) $row['name_en']) ?: 'SUBJ', 0, 8));
        $data = array_filter($row, fn ($v) => $v !== null);
        if (isset($data['code']) && $this->repeated('subject', $data['code'])) {
            $errors[] = __('import.duplicate_in_file', ['value' => $data['code']]);
        }

        $validator = Validator::make($data, (new SubjectRequest())->rules());
        $errors = [...$errors, ...$this->messages($validator)];

        return [$errors === [] ? $validator->validated() : null, $errors];
    }

    public function create(array $data): array
    {
        $subject = Subject::create($data);

        return ['code' => $subject->code, 'name' => $subject->name_ar];
    }
}
