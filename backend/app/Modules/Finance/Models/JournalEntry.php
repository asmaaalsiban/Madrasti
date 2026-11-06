<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A balanced entry: the debits and the credits of its lines are equal. */
class JournalEntry extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['entry_date', 'memo', 'source_type', 'source_id', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
