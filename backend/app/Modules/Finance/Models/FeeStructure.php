<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\GradeLevel;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The yearly fee of one grade (e.g. "Tuition 2026/2027, grade 5: 1200"). */
class FeeStructure extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $fillable = ['academic_year_id', 'grade_level_id', 'name', 'amount', 'installments_count', 'first_due', 'every_months', 'auto_apply'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'first_due' => 'date:Y-m-d', 'auto_apply' => 'boolean'];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }
}
