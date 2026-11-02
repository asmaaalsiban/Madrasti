<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One person's pay for one month: base salary, additions, deductions, net. */
class Payslip extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['payroll_run_id', 'user_id', 'staff_contract_id', 'base_salary', 'additions', 'deductions', 'net', 'paid_on', 'payment_method', 'reference'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['base_salary' => 'decimal:2', 'additions' => 'decimal:2', 'deductions' => 'decimal:2', 'net' => 'decimal:2', 'paid_on' => 'date:Y-m-d'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayslipItem::class)->orderBy('id');
    }
}
