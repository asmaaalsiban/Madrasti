<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Academic\Models\Student;
use App\Modules\People\Models\StaffProfile;
use App\Shared\Enums\Role;
use App\Shared\Traits\Auditable;
use Database\Factories\UserFactory;
use App\Shared\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person inside one school database: admin, teacher, student or parent.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'locale',
        'avatar_path',
        'is_active',
        'must_change_password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /** Phones are stored without spaces or dashes, so the same number is always written the same way. */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => PhoneNumber::normalize($value));
    }

    /**
     * The account a person signs in (or asks for a password reset) with: an email, or a phone number. A phone number
     * matches however it is typed ("0790 000 001", "+962790000001"); two accounts on the same line match nobody.
     */
    public static function findByLogin(string $login): ?self
    {
        $login = trim($login);
        if (! PhoneNumber::looks($login)) {
            return static::whereRaw('lower(email) = ?', [mb_strtolower($login)])->first();
        }

        $phone = PhoneNumber::normalize($login);
        $exact = static::where('phone', $phone)->first();
        if ($exact) {
            return $exact;
        }

        $tail = PhoneNumber::tail($login);
        $matches = strlen($tail) >= 8
            ? static::where('phone', 'like', '%'.$tail)->get()->filter(fn (self $u) => PhoneNumber::tail((string) $u->phone) === $tail)
            : collect();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Staff details (type, subject) of a member of the school staff.
     *
     * @return HasOne<StaffProfile, $this>
     */
    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Modules\HR\Models\StaffContract, $this> */
    public function staffContracts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Modules\HR\Models\StaffContract::class);
    }

    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    /**
     * The student record of a user with the student role.
     *
     * @return HasOne<Student, $this>
     */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /**
     * Children of a parent (one parent account can follow many students).
     *
     * @return BelongsToMany<Student, $this>
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'guardian_student', 'guardian_id', 'student_id')
            ->withPivot(['relation', 'is_primary'])
            ->withTimestamps();
    }
}
