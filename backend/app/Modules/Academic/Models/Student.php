<?php

declare(strict_types=1);

namespace App\Modules\Academic\Models;

use App\Models\User;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use Auditable, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'student_number',
        'name_ar',
        'name_en',
        'birth_date',
        'gender',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    /**
     * Login account of the student (optional).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Parents who follow this student.
     *
     * @return BelongsToMany<User, $this>
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'guardian_student', 'student_id', 'guardian_id')
            ->withPivot(['relation', 'is_primary'])
            ->withTimestamps();
    }
}
