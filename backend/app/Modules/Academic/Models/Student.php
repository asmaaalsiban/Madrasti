<?php

declare(strict_types=1);

namespace App\Modules\Academic\Models;

use App\Models\User;
use App\Modules\Finance\Models\StudentFee;
use App\Modules\Transport\Models\StudentTransport;
use App\Shared\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        return ['birth_date' => 'date', 'photo_consent' => 'boolean', 'photo_consent_at' => 'datetime'];
    }

    /** @return HasOne<StudentTransport, $this> */
    public function transport(): HasOne
    {
        return $this->hasOne(StudentTransport::class);
    }

    /**
     * Placements in classrooms, one per academic year.
     *
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
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

    public function fees(): HasMany
    {
        return $this->hasMany(StudentFee::class);
    }
}
