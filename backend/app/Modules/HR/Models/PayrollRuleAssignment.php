<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member of staff who gets one of the school's rules in every payroll of a range of months (e.g. a summer club). */
class PayrollRuleAssignment extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['payroll_template_id', 'user_id', 'starts_on', 'ends_on', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PayrollTemplate::class, 'payroll_template_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
