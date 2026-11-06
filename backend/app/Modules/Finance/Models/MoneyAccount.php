<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A cash box or a bank account of the school. Its balance is the opening balance plus everything that went in and out. */
class MoneyAccount extends Model
{
    use Auditable;

    public const TYPES = ['cash', 'bank'];

    /** @var list<string> */
    protected $fillable = ['name', 'type', 'opening_balance', 'opened_on', 'bank_name', 'iban', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'opened_on' => 'date:Y-m-d', 'is_active' => 'boolean'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AccountEntry::class);
    }

    public function balanceCents(): int
    {
        return Money::cents($this->opening_balance) + Money::cents((string) $this->entries()->sum('amount'));
    }
}
