<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One account of the chart of accounts (cash, receivables, tuition income, rent...). */
class LedgerAccount extends Model
{
    use Auditable;

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** @var list<string> */
    protected $fillable = ['code', 'name_ar', 'name_en', 'type', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_system' => 'boolean'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
