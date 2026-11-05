<?php

declare(strict_types=1);

namespace App\Modules\HR\Import;

use App\Models\User;
use App\Modules\Academic\Import\Lookups;
use App\Modules\HR\Models\StaffContract;
use App\Modules\HR\Services\PayrollService;
use App\Modules\People\Import\Importer;
use App\Shared\Enums\Role;
use App\Shared\Import\Numbers;
use Carbon\Carbon;

/**
 * Staff contracts and base salaries when a school moves to the system: one running contract per staff member (found by
 * email, phone or name), with the salary, the dates, how they are paid and one optional monthly allowance.
 * Someone who already has a running contract is refused, so importing a file twice never doubles anything.
 */
class ContractImporter extends Importer
{
    use Lookups;

    public function __construct(private readonly PayrollService $payroll) {}

    public function kind(): string
    {
        return 'contracts';
    }

    public function columns(): array
    {
        return [
            'staff' => ['en' => 'Staff member', 'ar' => 'الموظف', 'required' => true, 'example' => 'sara@example.com', 'aliases' => ['الموظف', 'الموظفه', 'اسم الموظف', 'ايميل الموظف', 'البريد الالكتروني', 'الاسم', 'staff', 'employee', 'email', 'name']],
            'base_salary' => ['en' => 'Base salary', 'ar' => 'الراتب الأساسي', 'required' => true, 'example' => '600', 'aliases' => ['الراتب الاساسي', 'الراتب', 'الاساسي', 'راتب', 'base salary', 'salary']],
            'starts_on' => ['en' => 'Contract start', 'ar' => 'بداية العقد', 'required' => true, 'example' => '2026-09-01', 'aliases' => ['بدايه العقد', 'تاريخ البدايه', 'تاريخ التعيين', 'تاريخ المباشره', 'يبدا', 'start', 'starts on', 'start date']],
            'ends_on' => ['en' => 'Contract end', 'ar' => 'نهاية العقد', 'required' => false, 'example' => '2027-08-31', 'aliases' => ['نهايه العقد', 'تاريخ النهايه', 'ينتهي', 'end', 'ends on', 'end date']],
            'payment_method' => ['en' => 'Payment method', 'ar' => 'طريقة الدفع', 'required' => false, 'example' => 'bank_transfer', 'aliases' => ['طريقه الدفع', 'طريقة الدفع', 'payment method', 'method']],
            'payment_details' => ['en' => 'Account / details', 'ar' => 'رقم الحساب', 'required' => false, 'example' => 'IBAN JO00...', 'aliases' => ['رقم الحساب', 'الايبان', 'تفاصيل الدفع', 'iban', 'account', 'details']],
            'allowance' => ['en' => 'Monthly allowance', 'ar' => 'بدلات شهرية', 'required' => false, 'example' => '50', 'aliases' => ['بدلات', 'بدل', 'البدلات', 'بدلات شهريه', 'allowance', 'allowances']],
            'notes' => ['en' => 'Notes', 'ar' => 'ملاحظات', 'required' => false, 'example' => '', 'aliases' => ['ملاحظات', 'ملاحظه', 'notes']],
        ];
    }

    public function missingFields(array $mapped): array
    {
        return array_values(array_diff(['staff', 'base_salary', 'starts_on'], $mapped));
    }

    public function check(array $row): array
    {
        $errors = [];
        $staff = isset($row['staff']) && trim((string) $row['staff']) !== '' ? $this->staffNamed((string) $row['staff']) : null;
        $staff === null && $errors[] = __('import.unknown_staff', ['name' => $row['staff'] ?? '']);
        if ($staff !== null) {
            (! $staff->is_active || ! $staff->hasRole(Role::Admin, Role::Teacher)) && $errors[] = __('payroll.not_staff');
            if (StaffContract::where('user_id', $staff->id)->where('status', 'active')->exists() || $this->repeated('contract', (string) $staff->id)) {
                $errors[] = __('payroll.has_active_contract');
            }
        }

        $salary = Numbers::parse($row['base_salary'] ?? null);
        ($salary === null || $salary <= 0 || $salary > 1000000) && $errors[] = __('import.bad_amount', ['value' => $row['base_salary'] ?? '']);
        $allowance = filled($row['allowance'] ?? null) ? Numbers::parse($row['allowance']) : 0.0;
        ($allowance === null || $allowance < 0 || $allowance > 1000000) && $errors[] = __('import.bad_amount', ['value' => $row['allowance'] ?? '']);

        $starts = $this->date($row['starts_on'] ?? null, $errors);
        $ends = filled($row['ends_on'] ?? null) ? $this->date($row['ends_on'], $errors) : null;
        $starts !== null && $ends !== null && $ends <= $starts && $errors[] = __('payroll.dates_order');
        $starts === null && ! filled($row['starts_on'] ?? null) && $errors[] = __('import.bad_date', ['value' => '']);

        $method = $this->method($row['payment_method'] ?? null);
        $method === false && $errors[] = __('import.bad_method', ['value' => $row['payment_method'], 'methods' => 'cash, bank_transfer, cheque']);

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'user_id' => $staff->id, 'base_salary' => round($salary, 2), 'starts_on' => $starts, 'ends_on' => $ends,
            'payment_method' => $method, 'payment_details' => filled($row['payment_details'] ?? null) ? mb_substr(trim((string) $row['payment_details']), 0, 255) : null,
            'allowance' => round((float) $allowance, 2), 'notes' => filled($row['notes'] ?? null) ? mb_substr(trim((string) $row['notes']), 0, 2000) : null,
        ], []];
    }

    public function create(array $data): array
    {
        $staff = User::findOrFail($data['user_id']);
        $items = $data['allowance'] > 0 ? [['kind' => 'allowance', 'label' => __('import.allowance_label'), 'amount' => $data['allowance']]] : [];
        $contract = $this->payroll->createContract($staff, $data, $items, auth()->user());

        return ['name' => $staff->name, 'base_salary' => $contract->base_salary, 'starts_on' => $contract->starts_on->format('Y-m-d'), 'ends_on' => $contract->ends_on?->format('Y-m-d')];
    }

    /** @param  list<string>  $errors */
    private function date(mixed $value, array &$errors): ?string
    {
        if (! filled($value)) {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            $errors[] = __('import.bad_date', ['value' => $value]);

            return null;
        }
    }

    /** null = not given, false = not understood. */
    private function method(?string $value): string|false|null
    {
        $v = $this->norm((string) $value);
        if ($v === '') {
            return null;
        }
        $words = ['cash' => ['cash', 'نقد', 'نقدا', 'كاش'], 'bank_transfer' => ['banktransfer', 'bank transfer', 'تحويل', 'تحويلبنكي', 'بنك', 'حواله', 'حوالة'], 'cheque' => ['cheque', 'check', 'شيك']];
        foreach ($words as $method => $list) {
            if (in_array($v, array_map(fn ($w) => $this->norm($w), $list), true)) {
                return $method;
            }
        }

        return false;
    }
}
