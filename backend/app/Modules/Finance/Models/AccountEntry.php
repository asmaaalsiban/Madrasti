<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an account's book: money in (+) or out (-). A payment, a transfer between accounts, or a correction. */
class AccountEntry extends Model
{
    use Auditable;

    public const RECEIPT = 'receipt';

    public const TRANSFER_IN = 'transfer_in';

    public const TRANSFER_OUT = 'transfer_out';

    public const ADJUSTMENT = 'adjustment';

    public const EXPENSE = 'expense';

    public const PAYROLL = 'payroll';

    /** @var list<string> */
    protected $fillable = ['money_account_id', 'entry_date', 'kind', 'amount', 'description', 'payment_id', 'source_type', 'source_id', 'transfer_ref', 'created_by', 'reconciled_at', 'reconciliation_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'reconciled_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
