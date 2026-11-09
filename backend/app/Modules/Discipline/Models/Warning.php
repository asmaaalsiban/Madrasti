<?php

declare(strict_types=1);

namespace App\Modules\Discipline\Models;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A warning to a student or to a member of staff, written by hand or raised by a rule. */
class Warning extends Model
{
    use Auditable;

    public const CATEGORIES = ['absence', 'late', 'behavior', 'violence', 'academic', 'uniform', 'other'];

    public const LEVELS = [1, 2, 3, 4];

    /** @var list<string> */
    protected $fillable = ['subject_type', 'student_id', 'user_id', 'kind', 'rule_id', 'category', 'level', 'title', 'body', 'issued_on', 'status', 'issued_by', 'approved_by', 'notified_at', 'acknowledged_at', 'acknowledged_by', 'withdrawn_at', 'withdrawn_reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['issued_on' => 'date:Y-m-d', 'notified_at' => 'datetime', 'acknowledged_at' => 'datetime', 'withdrawn_at' => 'datetime', 'level' => 'integer'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
