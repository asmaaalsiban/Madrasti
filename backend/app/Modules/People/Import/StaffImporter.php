<?php

declare(strict_types=1);

namespace App\Modules\People\Import;

use App\Modules\Academic\Models\Subject;
use App\Modules\People\Actions\SaveStaff;
use App\Modules\People\Http\Requests\StaffRequest;
use App\Shared\Enums\StaffType;
use App\Shared\Import\ColumnMatcher;
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
            'name' => ['en' => 'Name', 'ar' => 'الاسم', 'required' => true, 'example' => 'Sara Khaled', 'aliases' => ['اسم الموظف', 'اسم المعلم', 'اسم المعلمه', 'الاسم الكامل', 'employee name', 'teacher name', 'full name']],
            'email' => ['en' => 'Email', 'ar' => 'البريد الإلكتروني', 'required' => true, 'example' => 'sara@example.com', 'aliases' => ['البريد الالكتروني', 'الايميل', 'البريد', 'email', 'mail', 'e-mail']],
            'phone' => ['en' => 'Phone', 'ar' => 'الهاتف', 'required' => false, 'example' => '0791111111', 'aliases' => ['رقم الهاتف', 'الهاتف', 'الجوال', 'موبايل', 'رقم الجوال', 'phone', 'mobile']],
            'staff_type' => ['en' => 'Position', 'ar' => 'الوظيفة', 'required' => true, 'example' => 'teacher', 'aliases' => ['المسمى الوظيفي', 'الوظيفه', 'المسمى', 'المنصب', 'الدور', 'position', 'job title', 'job', 'role', 'type']],
            'subject' => ['en' => 'Subject', 'ar' => 'المادة', 'required' => false, 'example' => 'Math', 'aliases' => ['التخصص', 'المادة', 'مادة', 'subject', 'specialty', 'specialization']],
        ];
    }

    public function genericName(): array
    {
        return ['keys' => ['name', 'name'], 'aliases' => ['الاسم', 'اسم', 'name']];
    }

    /** @var array<string, mixed> */
    private array $defaults = [];

    public function withDefaults(array $defaults): static
    {
        $this->defaults = $defaults;

        return $this;
    }

    public function missingFields(array $mapped): array
    {
        return in_array('name', $mapped, true) ? [] : ['name'];
    }

    public function check(array $row): array
    {
        $errors = [];
        $row['staff_type'] ??= $this->defaults['staff_type'] ?? null;
        if (! isset($row['email']) && isset($row['name'])) {
            $row['email'] = $this->generateEmail($row['name']);
        }
        $data = array_filter($row, fn ($v) => $v !== null);
        unset($data['subject']);

        if (isset($data['staff_type'])) {
            $type = $this->position($data['staff_type']);
            if ($type === null) {
                $errors[] = __('import.unknown_staff_type', ['value' => $row['staff_type'], 'types' => implode(', ', array_column(StaffType::cases(), 'value'))]);
            }
            $data['staff_type'] = $type ?? $data['staff_type'];
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

    /** A position written as the code ("head_of_department"), a few English words, or the usual Arabic job titles. */
    private function position(string $value): ?string
    {
        $code = str_replace([' ', '-'], '_', mb_strtolower(trim($value)));
        if (StaffType::tryFrom($code) !== null) {
            return $code;
        }
        $matcher = new ColumnMatcher();
        $norm = $matcher->norm($value);
        $titles = [
            'teacher' => ['معلم', 'معلمه', 'مدرس', 'مدرسه', 'استاذ', 'استاذه', 'teacher'],
            'head_of_department' => ['رئيسقسم', 'رئيسه قسم', 'رئيسالقسم', 'head of department', 'hod'],
            'subject_coordinator' => ['منسق', 'منسقه', 'منسقمادة', 'منسقماده'],
            'trainee' => ['معلممتدرب', 'متدرب', 'متدربه', 'trainee'],
            'substitute_teacher' => ['معلمبديل', 'بديل', 'substitute'],
            'principal' => ['مدير', 'مديره', 'مديرالمدرسه', 'مديرةالمدرسة', 'principal', 'headmaster'],
            'vice_principal' => ['مساعدمدير', 'مساعدمديره', 'نائبمدير', 'نائبالمدير', 'vice principal'],
            'accountant' => ['محاسب', 'محاسبه', 'accountant'],
            'registrar' => ['مسجل', 'مسجله', 'سكرتير', 'سكرتيره', 'registrar', 'secretary'],
            'hr' => ['موارد بشريه', 'شؤونموظفين', 'hr'],
            'counselor' => ['مرشد', 'مرشده', 'مرشدطلابي', 'مرشدهطلابيه', 'counselor', 'counsellor'],
            'nurse' => ['ممرض', 'ممرضه', 'nurse'],
            'librarian' => ['امينمكتبه', 'امينهمكتبه', 'librarian'],
            'driver' => ['سائق', 'driver'],
            'security_guard' => ['حارس', 'حارسامن', 'security guard', 'guard'],
            'caretaker' => ['مستخدم', 'مستخدمه', 'عامل', 'عامله', 'caretaker', 'cleaner'],
        ];
        foreach ($titles as $type => $words) {
            if (in_array($norm, array_map(fn ($w) => $matcher->norm($w), $words), true)) {
                return $type;
            }
        }

        return null;
    }

    /** A login email from the name when the file has none: <first>.<last>@<domain>, made unique. Arabic names are transliterated. */
    private function generateEmail(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/u', trim(\Illuminate\Support\Str::ascii($name))) ?: []));
        $slug = fn (string $p) => preg_replace('/[^a-z0-9]/', '', \Illuminate\Support\Str::lower($p)) ?? '';
        $local = trim(($slug($parts[0] ?? '') ?: 'staff').(count($parts) > 1 ? '.'.$slug(end($parts)) : ''), '.');
        $domain = config('accounts.staff_email_domain') ?: (config('accounts.student_email_domain') ?: (tenant('id') ?? 'school').'.edu');

        $email = "{$local}@{$domain}";
        for ($n = 2; \App\Models\User::withTrashed()->where('email', $email)->exists() || $this->wasSeen('email', $email); $n++) {
            $email = "{$local}{$n}@{$domain}";
        }

        return $email;
    }
}
