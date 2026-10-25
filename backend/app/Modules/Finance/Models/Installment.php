<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Installment extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['student_fee_id', 'number', 'due_date', 'amount', 'paid_amount', 'status', 'last_reminded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'last_reminded_at' => 'datetime'];
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class, 'student_fee_id');
    }

    public function remainingCents(): int
    {
        return Money::cents($this->amount) - Money::cents($this->paid_amount);
    }

    /** pending / partial / paid, or overdue when a part is still owed after the due date. */
    public function effectiveStatus(): string
    {
        $paid = Money::cents($this->paid_amount);
        if ($paid >= Money::cents($this->amount)) {
            return 'paid';
        }
        if ($this->due_date->lt(now()->startOfDay())) {
            return 'overdue';
        }

        return $paid > 0 ? 'partial' : 'pending';
    }
}
