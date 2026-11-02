<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One month's payroll: draft -> submitted -> approved -> paid. */
class PayrollRun extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['month', 'status', 'return_note', 'prepared_by', 'approved_by', 'submitted_at', 'approved_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['month' => 'date:Y-m-d', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
