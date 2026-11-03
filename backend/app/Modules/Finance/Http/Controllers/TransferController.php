<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Finance\Models\OnlinePayment;
use App\Modules\Finance\Services\FinanceAccess;
use App\Modules\Finance\Services\OnlinePayments;
use App\Shared\Attachments\Attachment;
use App\Shared\Enums\Role;
use App\Shared\Payments\BankTransferSettings;
use App\Shared\Payments\GatewayEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Bank transfers and CliQ payments that parents report: the principal or the accountant confirms (the payment is recorded) or refuses (with a reason). */
class TransferController extends Controller
{
    public function __construct(private readonly OnlinePayments $online, private readonly Notifier $notifier, private readonly BankTransferSettings $settings) {}

    /** Waiting transfers first, then the latest decided ones. */
    public function index(Request $request): JsonResponse
    {
        $this->allow($request);
        $rows = OnlinePayment::with(['fee:id,description', 'payment:id,receipt_number'])->where('provider', 'bank_transfer')->orderByRaw("status = 'pending' desc")->latest('id')->limit(100)->get();
        $students = Student::whereIn('id', $rows->pluck('student_id'))->get(['id', 'name_ar', 'name_en'])->keyBy('id');
        $files = Attachment::groupedFor('online_payment', $rows->pluck('id'));

        return response()->json(['data' => $rows->map(fn (OnlinePayment $p) => [
            'id' => $p->id, 'reference' => $p->reference, 'status' => $p->status, 'amount' => $p->amount, 'payer_name' => $p->payer_name, 'transfer_reference' => $p->transfer_reference,
            'fee' => $p->fee?->description, 'student' => $students[$p->student_id] ?? null, 'review_note' => $p->review_note, 'created_at' => $p->created_at?->toIso8601String(),
            'receipt' => $p->payment?->receiptNumber(), 'attachments' => Attachment::list($files->get($p->id)),
        ])->values(), 'pending' => $rows->where('status', 'pending')->count()]);
    }

    /** The money arrived: the payment is recorded (method "bank transfer", in the payer's name) and the parent is told. */
    public function approve(Request $request, OnlinePayment $payment): JsonResponse
    {
        $by = $this->allow($request);
        $this->mustWait($payment);
        $payment->update(['reviewed_by' => $by->id, 'reviewed_at' => now()]);
        $this->online->settle($payment, new GatewayEvent($payment->reference, true, $payment->transfer_reference));

        return response()->json(['data' => ['status' => $payment->refresh()->status]]);
    }

    public function reject(Request $request, OnlinePayment $payment): JsonResponse
    {
        $by = $this->allow($request);
        $this->mustWait($payment);
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $payment->update(['status' => OnlinePayment::FAILED, 'failure_reason' => 'rejected', 'review_note' => $data['note'], 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
        $student = Student::findOrFail($payment->student_id);
        $this->notifier->toUsers([$payment->user_id], 'transfer_rejected', ['amount' => $payment->amount, 'note' => $data['note'], 'child_ar' => $student->name_ar, 'child_en' => $student->name_en], "/parent/children/{$student->id}/fees");

        return response()->json(['data' => ['status' => $payment->status]]);
    }

    public function settings(Request $request): JsonResponse
    {
        $this->allow($request);

        return response()->json(['data' => $this->settings->get()]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->allow($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'], 'instructions' => ['nullable', 'string', 'max:1000'],
            'accounts' => ['present', 'array', 'max:6'], 'accounts.*.bank' => ['required', 'string', 'max:100'], 'accounts.*.holder' => ['nullable', 'string', 'max:150'],
            'accounts.*.iban' => ['nullable', 'string', 'max:50'], 'accounts.*.cliq' => ['nullable', 'string', 'max:60'],
        ]);
        if ($data['enabled'] && $data['accounts'] === []) {
            throw ValidationException::withMessages(['accounts' => [__('finance.transfer_needs_account')]]);
        }
        $this->settings->save($data);

        return response()->json(['data' => $this->settings->get()]);
    }

    private function mustWait(OnlinePayment $p): void
    {
        abort_unless($p->provider === 'bank_transfer', 404);
        if ($p->status !== OnlinePayment::PENDING) {
            throw ValidationException::withMessages(['payment' => [__('finance.transfer_decided')]]);
        }
    }

    private function allow(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole(Role::Admin) || FinanceAccess::manages($user), 403, __('finance.not_allowed'));

        return $user;
    }
}
