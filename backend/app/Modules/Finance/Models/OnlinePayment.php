<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A parent's try to pay an amount of a fee online; pending until the provider answers. */
class OnlinePayment extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const REVIEW = 'review';

    /**
     * @var list<string>
     */
    protected $fillable = ['reference', 'student_id', 'student_fee_id', 'installment_id', 'user_id', 'amount', 'payer_name', 'provider', 'provider_reference', 'status', 'failure_reason', 'payment_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class, 'student_fee_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
