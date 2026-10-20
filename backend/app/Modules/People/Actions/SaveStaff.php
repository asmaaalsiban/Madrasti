<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a member of the school staff: the account, and the staff profile (type, subject, floor).
 * The account role follows the type (management positions are admins, everyone else is staff).
 */
class SaveStaff
{
    /**
     * @param  array<string, mixed>  $data  validated StaffRequest data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $type = StaffType::from($data['staff_type']);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'role' => $type->accountRole(),
                'password' => config('accounts.default_password'),
                'must_change_password' => true,
            ]);
            $user->staffProfile()->create($this->profile($type, $data));

            return $user->load('staffProfile.subject');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $type = StaffType::from($data['staff_type']);
            $role = $type->accountRole();
            $roleChanged = $user->role !== $role;

            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'is_active' => $data['is_active'] ?? $user->is_active,
                'role' => $role,
            ]);
            $user->staffProfile()->updateOrCreate(['user_id' => $user->id], $this->profile($type, $data));

            // Someone who can no longer be a homeroom teacher must not stay one (it would block edits of the classroom).
            if (! $type->canBeHomeroom() || ! $user->is_active) {
                Classroom::where('homeroom_teacher_id', $user->id)->update(['homeroom_teacher_id' => null]);
            }

            // A change of access (e.g. teacher -> principal) must not keep working on old sessions.
            if ($roleChanged || ! $user->is_active) {
                $user->tokens()->delete();
            }

            return $user->load('staffProfile.subject');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profile(StaffType $type, array $data): array
    {
        return [
            'staff_type' => $type,
            'subject_id' => $type->needsSubject() ? ($data['subject_id'] ?? null) : null,
            'floor' => $type->needsFloor() ? ($data['floor'] ?? null) : null,
        ];
    }
}
