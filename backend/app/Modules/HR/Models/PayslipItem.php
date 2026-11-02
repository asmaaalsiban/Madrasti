<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** A bonus, allowance or deduction on a payslip. */
class PayslipItem extends Model
{
    use Auditable;

    public const KINDS = ['bonus', 'allowance', 'deduction'];

    /**
     * @var list<string>
     */
    protected $fillable = ['payslip_id', 'kind', 'label', 'amount', 'from_contract', 'auto_code', 'detail', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'from_contract' => 'boolean'];
    }
}
