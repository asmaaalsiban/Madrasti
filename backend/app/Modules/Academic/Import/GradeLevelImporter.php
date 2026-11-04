<?php

declare(strict_types=1);

namespace App\Modules\Academic\Import;

use App\Modules\Academic\Http\Requests\GradeLevelRequest;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\People\Import\Importer;
use Illuminate\Support\Facades\Validator;

/** The grades (Grade 1, Grade 2...), with the same checks as the grade form. */
class GradeLevelImporter extends Importer
{
    use Lookups;

    public function kind(): string
    {
        return 'grade_levels';
    }

    public function columns(): array
    {
        return [
            'name_ar' => ['en' => 'Name (Arabic)', 'ar' => 'الاسم بالعربي', 'required' => true, 'example' => 'الصف الخامس', 'aliases' => ['اسم الصف', 'الصف', 'المرحلة', 'الاسم بالعربي', 'arabic name']],
            'name_en' => ['en' => 'Name (English)', 'ar' => 'الاسم بالإنجليزي', 'required' => false, 'example' => 'Grade 5', 'aliases' => ['الاسم بالانجليزي', 'اسم الصف بالانجليزي', 'english name', 'grade']],
            'sort_order' => ['en' => 'Order', 'ar' => 'الترتيب', 'required' => false, 'example' => '5', 'aliases' => ['ترتيب', 'التسلسل', 'order', 'sort', 'level']],
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
        $row['name_ar'] ??= $row['name_en'];
        $row['name_en'] ??= $row['name_ar'];
        $data = array_filter($row, fn ($v) => $v !== null);
        if (isset($data['sort_order']) && ! ctype_digit($data['sort_order'])) {
            $errors[] = __('import.bad_number', ['value' => $data['sort_order']]);
            unset($data['sort_order']);
        }
        if (isset($data['name_ar'])) {
            if ($this->gradeLevelNamed($data['name_ar']) !== null) {
                $errors[] = __('import.already_exists', ['value' => $data['name_ar']]);
            } elseif ($this->repeated('grade', $data['name_ar'])) {
                $errors[] = __('import.duplicate_in_file', ['value' => $data['name_ar']]);
            }
        }
        $this->counter ??= (int) GradeLevel::max('sort_order');
        $data += ['sort_order' => (string) ++$this->counter];

        $validator = Validator::make($data, (new GradeLevelRequest())->rules());
        $errors = [...$errors, ...$this->messages($validator)];

        return [$errors === [] ? $validator->validated() : null, $errors];
    }

    private ?int $counter = null;

    public function create(array $data): array
    {
        $grade = GradeLevel::create($data);

        return ['name' => $grade->name_ar, 'name_en' => $grade->name_en];
    }
}
