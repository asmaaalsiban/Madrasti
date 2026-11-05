<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's unpaid balance of one academic year, carried to the next one. */
class FeeCarryover extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['student_id', 'from_year_id', 'to_year_id', 'amount', 'payment_ids', 'new_fee_id', 'created_by', 'reverted_at', 'reverted_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_ids' => 'array', 'reverted_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fromYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'from_year_id');
    }

    public function toYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'to_year_id');
    }

    public function newFee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class, 'new_fee_id');
    }
}
