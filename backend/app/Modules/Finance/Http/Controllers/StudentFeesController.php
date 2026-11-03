<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Http\Controllers\FinanceController as Finance;
use App\Modules\Finance\Services\AccountReader;
use App\Shared\Payments\PaymentManager;
use Illuminate\Http\JsonResponse;
use App\Modules\Finance\Models\OnlinePayment;
use App\Shared\Payments\BankTransferSettings;
use Illuminate\Support\Facades\Gate;

/** A parent reads the statement of their child: the fees, installments, what was paid and what is still owed. */
class StudentFeesController extends Controller
{
    public function __invoke(Student $student, AccountReader $reader, Finance $finance, PaymentManager $payments): JsonResponse
    {
        Gate::authorize('view-student-fees', $student);

        return response()->json(['data' => $reader->statement($student, AcademicYear::where('is_current', true)->first()) + ['currency' => $finance->currency(), 'online' => [
            'enabled' => $payments->methods() !== [], 'mode' => $payments->driverName(), 'methods' => array_column($payments->methods(), 'key'),
            // The school's account for transfers, only when this way is offered.
            'bank' => in_array('bank_transfer', array_column($payments->methods(), 'key'), true) ? app(BankTransferSettings::class)->get() : null,
            // Transfers this parent sent for this child that are waiting or were refused (the school's answer in their own words).
            'transfers' => OnlinePayment::where('student_id', $student->id)->where('user_id', request()->user()->id)->where('provider', 'bank_transfer')->whereIn('status', ['pending', 'failed'])->latest('id')->limit(10)
                ->get()->map(fn ($t) => ['reference' => $t->reference, 'amount' => $t->amount, 'status' => $t->status, 'review_note' => $t->review_note, 'created_at' => $t->created_at?->toIso8601String()])->values(),
        ]]]);
    }
}
