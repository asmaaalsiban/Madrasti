<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** A monthly allowance or deduction written on a contract. */
class StaffContractItem extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['staff_contract_id', 'kind', 'label', 'amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
