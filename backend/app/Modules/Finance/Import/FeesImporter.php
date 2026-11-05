<?php

declare(strict_types=1);

namespace App\Modules\Finance\Import;

use App\Modules\Academic\Import\Lookups;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\StudentFee;
use App\Modules\Finance\Services\FeeBilling;
use App\Modules\People\Import\Importer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What each student owes and has already paid (opening balances when a school moves to the system): one row = one fee of
 * one student, split into equal installments, with what was paid so far recorded as one payment. A student who already has
 * a fee with the same description that year is refused, so importing a file twice never charges anybody twice.
 */
class FeesImporter extends Importer
{
    use Lookups;

    public function __construct(private readonly FeeBilling $billing) {}

    public function kind(): string
    {
        return 'fees';
    }

    public function columns(): array
    {
        return [
            'student_number' => ['en' => 'Student number', 'ar' => 'رقم الطالب', 'required' => true, 'example' => '2026001', 'aliases' => ['رقم الطالب', 'رقم الجلوس', 'رقم', 'student number', 'student id', 'id']],
            'total' => ['en' => 'Total fee', 'ar' => 'إجمالي الرسوم', 'required' => true, 'example' => '1200', 'aliases' => ['اجمالي الرسوم', 'الرسوم', 'المبلغ', 'قيمه الرسوم', 'الاجمالي', 'total', 'fee', 'amount', 'total fee']],
            'description' => ['en' => 'Description', 'ar' => 'البيان', 'required' => false, 'example' => 'Tuition 2026-2027', 'aliases' => ['البيان', 'الوصف', 'نوع الرسوم', 'description', 'fee name']],
            'discount' => ['en' => 'Discount', 'ar' => 'الخصم', 'required' => false, 'example' => '100', 'aliases' => ['الخصم', 'خصم', 'discount']],
            'installments' => ['en' => 'Installments', 'ar' => 'عدد الأقساط', 'required' => false, 'example' => '4', 'aliases' => ['عدد الاقساط', 'الاقساط', 'installments']],
            'first_due' => ['en' => 'First due date', 'ar' => 'تاريخ أول قسط', 'required' => false, 'example' => '2026-09-15', 'aliases' => ['تاريخ اول قسط', 'اول استحقاق', 'تاريخ الاستحقاق', 'first due', 'due date']],
            'every_months' => ['en' => 'Months between installments', 'ar' => 'الأشهر بين الأقساط', 'required' => false, 'example' => '1', 'aliases' => ['الاشهر بين الاقساط', 'كل كم شهر', 'every months']],
            'paid' => ['en' => 'Paid so far', 'ar' => 'المدفوع حتى الآن', 'required' => false, 'example' => '300', 'aliases' => ['المدفوع', 'المدفوع حتى الان', 'دفع', 'paid', 'paid so far']],
            'paid_on' => ['en' => 'Payment date', 'ar' => 'تاريخ الدفع', 'required' => false, 'example' => '2026-09-01', 'aliases' => ['تاريخ الدفع', 'تاريخ الدفعه', 'paid on', 'payment date']],
            'method' => ['en' => 'Payment method', 'ar' => 'طريقة الدفع', 'required' => false, 'example' => 'cash', 'aliases' => ['طريقه الدفع', 'طريقة الدفع', 'method', 'payment method']],
            'year' => ['en' => 'Academic year', 'ar' => 'السنة الدراسية', 'required' => false, 'example' => '2026-2027', 'aliases' => ['السنه الدراسيه', 'السنة', 'العام الدراسي', 'year', 'academic year']],
        ];
    }

    public function missingFields(array $mapped): array
    {
        return array_values(array_diff(['student_number', 'total'], $mapped));
    }

    public function check(array $row): array
    {
        $errors = [];
        $number = trim((string) ($row['student_number'] ?? ''));
        $student = $number === '' ? null : Student::where('student_number', $number)->first();
        $student === null && $errors[] = __('import.unknown_student', ['value' => $number]);

        $named = isset($row['year']) && trim((string) $row['year']) !== '';
        $year = $named ? $this->academicYearNamed((string) $row['year']) : \App\Modules\Academic\Models\AcademicYear::where('is_current', true)->first();
        $year === null && $errors[] = $named ? __('import.unknown_year', ['name' => $row['year']]) : __('import.no_current_year');

        $total = $this->money($row['total'] ?? null);
        ($total === null || $total <= 0) && $errors[] = __('import.bad_amount', ['value' => $row['total'] ?? '']);
        $discount = filled($row['discount'] ?? null) ? $this->money($row['discount']) : 0;
        ($discount === null || $discount < 0) && $errors[] = __('import.bad_amount', ['value' => $row['discount'] ?? '']);
        $net = ($total ?? 0) - ($discount ?? 0);
        $total !== null && $discount !== null && $net <= 0 && $errors[] = __('import.discount_too_big');

        $count = filled($row['installments'] ?? null) ? (int) $row['installments'] : 1;
        (! is_numeric($row['installments'] ?? 1) || $count < 1 || $count > 24) && $errors[] = __('import.bad_installments', ['value' => $row['installments'] ?? '']);
        $every = filled($row['every_months'] ?? null) ? (int) $row['every_months'] : 1;
        (! is_numeric($row['every_months'] ?? 1) || $every < 1 || $every > 12) && $errors[] = __('import.bad_every', ['value' => $row['every_months'] ?? '']);
        $firstDue = $this->date($row['first_due'] ?? null, $year?->starts_on?->format('Y-m-d') ?? now()->toDateString(), $errors);

        $paid = filled($row['paid'] ?? null) ? $this->money($row['paid']) : 0;
        ($paid === null || $paid < 0) && $errors[] = __('import.bad_amount', ['value' => $row['paid'] ?? '']);
        $paid !== null && $total !== null && $discount !== null && $paid > $net && $errors[] = __('import.paid_more_than_owed');
        $paidOn = $this->date($row['paid_on'] ?? null, now()->toDateString(), $errors);
        $method = $this->method($row['method'] ?? null);
        $method === null && $errors[] = __('import.bad_method', ['value' => $row['method'], 'methods' => implode(', ', Payment::METHODS)]);

        $description = trim((string) ($row['description'] ?? '')) ?: __('import.default_fee', ['year' => $year?->name]);
        if ($student && $year) {
            if (StudentFee::where('student_id', $student->id)->where('academic_year_id', $year->id)->where('description', $description)->exists()
                || $this->repeated('fee', $student->id.'|'.$year->id.'|'.$description)) {
                $errors[] = __('import.fee_exists', ['value' => $number, 'description' => $description]);
            }
        }
        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'student_id' => $student->id, 'academic_year_id' => $year->id, 'description' => $description, 'total' => $total, 'discount' => $discount,
            'installments' => $count, 'every_months' => $every, 'first_due' => $firstDue, 'paid' => $paid, 'paid_on' => $paidOn, 'method' => $method,
        ], []];
    }

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $student = Student::findOrFail($data['student_id']);
            $year = \App\Modules\Academic\Models\AcademicYear::findOrFail($data['academic_year_id']);
            $schedule = $this->billing->evenSchedule($data['total'] - $data['discount'], $data['installments'], $data['first_due'], $data['every_months']);
            $fee = $this->billing->createFee($student, $year, $data['description'], Money::amount($data['total']), Money::amount($data['discount']), $schedule, null, 'yearly');
            if ($data['paid'] > 0) {
                $this->billing->recordPayment($fee, Money::amount($data['paid']), $data['method'], $data['paid_on'], auth()->user(), null, __('import.payment_note'));
            }

            return ['student_number' => $student->student_number, 'name' => $student->name_ar, 'total' => Money::amount($data['total'] - $data['discount']), 'paid' => Money::amount($data['paid'])];
        });
    }

    /** An amount in cents, or null when it is not a number. */
    private function money(mixed $value): ?int
    {
        $text = trim(str_replace(['٫', ',', '٬'], ['.', '.', ''], strtr((string) $value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])));

        return $text !== '' && is_numeric($text) ? Money::cents($text) : null;
    }

    /** @param  list<string>  $errors */
    private function date(mixed $value, string $default, array &$errors): string
    {
        if (! filled($value)) {
            return $default;
        }
        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            $errors[] = __('import.bad_date', ['value' => $value]);

            return $default;
        }
    }

    private function method(?string $value): ?string
    {
        $v = $this->norm((string) $value);
        if ($v === '') {
            return 'cash';
        }
        $words = ['cash' => ['cash', 'نقد', 'نقدا', 'كاش'], 'bank_transfer' => ['banktransfer', 'bank transfer', 'تحويل', 'تحويلبنكي', 'حواله', 'حوالة'], 'card' => ['card', 'بطاقه', 'بطاقة', 'فيزا'], 'cheque' => ['cheque', 'check', 'شيك'], 'online' => ['online', 'اونلاين', 'الكتروني']];
        foreach ($words as $method => $list) {
            if (in_array($v, array_map(fn ($w) => $this->norm($w), $list), true)) {
                return $method;
            }
        }

        return null;
    }
}
