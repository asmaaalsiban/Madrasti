<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use Auditable;

    public const METHODS = ['cash', 'bank_transfer', 'card', 'cheque', 'online'];

    /** Not money: what was still owed when a year closed and moved to the next year (see YearEndCarryOver). Never chosen by hand, never counted as collected. */
    public const CARRY = 'carry_forward';

    /**
     * @var list<string>
     */
    protected $fillable = ['student_fee_id', 'installment_id', 'amount', 'method', 'reference', 'payer_name', 'paid_on', 'received_by', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date:Y-m-d'];
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class, 'student_fee_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function receiptNumber(): string
    {
        return 'R-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
