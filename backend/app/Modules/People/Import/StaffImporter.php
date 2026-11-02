<?php

declare(strict_types=1);

namespace App\Modules\People\Import;

use App\Modules\Academic\Models\Subject;
use App\Modules\People\Actions\SaveStaff;
use App\Modules\People\Http\Requests\StaffRequest;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\Validator;

/** Staff from a spreadsheet: the same checks as the staff form. Everyone starts with the default password. */
class StaffImporter extends Importer
{
    public function __construct(private readonly SaveStaff $save) {}

    public function kind(): string
    {
        return 'staff';
    }

    public function columns(): array
    {
        return [
            'name' => ['en' => 'Name', 'ar' => 'الاسم', 'required' => true, 'example' => 'Sara Khaled'],
            'email' => ['en' => 'Email', 'ar' => 'البريد الإلكتروني', 'required' => true, 'example' => 'sara@example.com'],
            'phone' => ['en' => 'Phone', 'ar' => 'الهاتف', 'required' => false, 'example' => '0791111111'],
            'staff_type' => ['en' => 'Position', 'ar' => 'الوظيفة', 'required' => true, 'example' => 'teacher'],
            'subject' => ['en' => 'Subject', 'ar' => 'المادة', 'required' => false, 'example' => 'Math'],
        ];
    }

    public function check(array $row): array
    {
        $errors = [];
        $data = array_filter($row, fn ($v) => $v !== null);
        unset($data['subject']);

        if (isset($data['staff_type'])) {
            $data['staff_type'] = str_replace([' ', '-'], '_', mb_strtolower(trim($data['staff_type'])));
            if (StaffType::tryFrom($data['staff_type']) === null) {
                $errors[] = __('import.unknown_staff_type', ['value' => $row['staff_type'], 'types' => implode(', ', array_column(StaffType::cases(), 'value'))]);
            }
        }
        if (isset($row['subject'])) {
            $subject = Subject::where('name_ar', trim($row['subject']))->orWhere('name_en', trim($row['subject']))->first();
            if ($subject === null) {
                $errors[] = __('import.unknown_subject', ['name' => $row['subject']]);
            }
            $data['subject_id'] = $subject?->id;
        }
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
            if ($this->repeated('email', $data['email'])) {
                $errors[] = __('import.duplicate_in_file', ['value' => $data['email']]);
            }
        }

        $request = StaffRequest::create('/', 'POST', $data);
        $request->setContainer(app());
        $validator = Validator::make($data, $request->rules());
        $errors = [...$errors, ...$this->messages($validator)];

        return [$errors === [] ? $validator->validated() : null, $errors];
    }

    public function create(array $data): array
    {
        $user = $this->save->create($data);

        return ['name' => $user->name, 'email' => $user->email, 'staff_type' => $data['staff_type']];
    }
}
