<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\Academic\Models\Student;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What one student owes for a year (tuition, bus...): the total, a discount, and the installments. */
class StudentFee extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['student_id', 'academic_year_id', 'fee_structure_id', 'kind', 'description', 'total_amount', 'discount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['total_amount' => 'decimal:2', 'discount' => 'decimal:2'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StudentFeeItem::class)->orderBy('id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('number');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('paid_on')->orderByDesc('id');
    }
}
