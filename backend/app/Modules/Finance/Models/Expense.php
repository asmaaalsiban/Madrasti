<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A bill of the school (what it owes for something it bought or used). It is paid in one or more payments out of an account. */
class Expense extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['expense_date', 'supplier_id', 'expense_category_id', 'description', 'reference', 'amount', 'tax_amount', 'due_date', 'procurement_request_id', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expense_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'tax_amount' => 'decimal:2'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function paidCents(): int
    {
        return Money::cents((string) $this->payments->sum('amount'));
    }

    public function remainingCents(): int
    {
        return Money::cents($this->amount) - $this->paidCents();
    }

    /** unpaid / partial / paid, and overdue when something is still owed after its due date. */
    public function status(): string
    {
        $remaining = $this->remainingCents();
        if ($remaining <= 0) {
            return 'paid';
        }
        if ($this->due_date !== null && $this->due_date->lt(now()->startOfDay())) {
            return 'overdue';
        }

        return $this->paidCents() > 0 ? 'partial' : 'unpaid';
    }
}
