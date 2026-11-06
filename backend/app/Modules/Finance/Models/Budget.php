<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** The planned spending of one expense category in one academic year. */
class Budget extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['academic_year_id', 'expense_category_id', 'amount'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
