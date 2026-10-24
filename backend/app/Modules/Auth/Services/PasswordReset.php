<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * "Forgot my password": a 6-digit code (valid 15 minutes, 5 tries, one use) is e-mailed, then the new password is set with it.
 * The code of a student goes to their parent's e-mail; everybody else gets it on their own. The answer never tells whether
 * the account exists or whether an e-mail was sent.
 */
class PasswordReset
{
    public const MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public function request(string $login, string $locale): void
    {
        $user = $this->find($login);
        $to = $user === null || ! $user->is_active ? null : $this->recipient($user);
        if ($user === null || $to === null) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::table('password_reset_codes')->updateOrInsert(
            ['user_id' => $user->id],
            ['code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(self::MINUTES), 'created_at' => now(), 'updated_at' => now()],
        );

        Mail::to($to)->send(new PasswordResetCodeMail($code, $user->name, $user->role === Role::Student, self::MINUTES, $locale));
    }

    /** Sets the new password when the code is right; false for any problem (unknown account, wrong, expired or used up code). */
    public function reset(string $login, string $code, string $password): bool
    {
        $user = $this->find($login);
        $row = $user === null ? null : DB::table('password_reset_codes')->where('user_id', $user->id)->first();
        if ($row === null || $row->attempts >= self::MAX_ATTEMPTS || now()->gt($row->expires_at)) {
            return false;
        }
        if (! Hash::check($code, $row->code_hash)) {
            DB::table('password_reset_codes')->where('user_id', $user->id)->increment('attempts');

            return false;
        }

        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password' => $password, 'must_change_password' => false])->save();
            $user->tokens()->delete();
            DB::table('password_reset_codes')->where('user_id', $user->id)->delete();
        });

        return true;
    }

    private function find(string $login): ?User
    {
        return User::where(fn ($q) => $q->where('email', $login)->orWhere('phone', $login))->first();
    }

    /** The e-mail the code is sent to: a student's primary parent (any parent with an e-mail if there is no primary), else the account itself. */
    private function recipient(User $user): ?string
    {
        if ($user->role !== Role::Student) {
            return filled($user->email) ? $user->email : null;
        }
        $guardians = $user->student?->guardians()->whereNotNull('email')->orderByDesc('guardian_student.is_primary')->get();

        return $guardians?->first()?->email;
    }
}
