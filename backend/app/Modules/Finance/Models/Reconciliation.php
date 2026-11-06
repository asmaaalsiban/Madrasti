<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/** One matching of an account's book with the bank's statement: the statement's date and closing balance. */
class Reconciliation extends Model
{
    /** @var list<string> */
    protected $fillable = ['money_account_id', 'statement_date', 'statement_balance', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['statement_date' => 'date:Y-m-d', 'statement_balance' => 'decimal:2'];
    }
}
