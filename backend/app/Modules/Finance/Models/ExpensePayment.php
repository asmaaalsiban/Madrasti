<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money paid on a bill, out of one account. It has one line in that account's book. */
class ExpensePayment extends Model
{
    use Auditable;

    public const METHODS = ['cash', 'bank_transfer', 'cheque', 'card'];

    /** @var list<string> */
    protected $fillable = ['expense_id', 'money_account_id', 'amount', 'paid_on', 'method', 'reference', 'note', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['paid_on' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }
}
