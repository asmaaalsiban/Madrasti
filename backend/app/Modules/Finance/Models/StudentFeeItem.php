<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a student's fee: the yearly fee, transport, books... or a discount (a negative amount). */
class StudentFeeItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['student_fee_id', 'kind', 'description', 'amount'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class, 'student_fee_id');
    }
}
