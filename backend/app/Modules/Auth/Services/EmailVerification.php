<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * A parent who signs in with a phone number can put their own email on the account. A code sent to that email proves it
 * is theirs. If the email already belongs to another parent account (the same parent, registered another way), the two
 * accounts are merged: the children of both, the number and the newest password end up on the account that had the email.
 */
class EmailVerification
{
    public const MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly UserMerger $merger) {}

    /** Only a parent with no email who has already chosen their own password. */
    public function eligible(User $user): bool
    {
        return $user->role === Role::Guardian && blank($user->email) && ! $user->must_change_password;
    }

    /** Sends the code. The answer to the caller is the same whether or not a code was sent (no probing of emails). */
    public function request(User $user, string $email, string $locale): void
    {
        $email = mb_strtolower(trim($email));
        if (! $this->eligible($user) || ! $this->canTake($user, $email)) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::table('email_verifications')->updateOrInsert(
            ['user_id' => $user->id],
            ['email' => $email, 'code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(self::MINUTES), 'created_at' => now(), 'updated_at' => now()],
        );

        Mail::to($email)->send(new EmailVerificationCodeMail($code, $user->name, self::MINUTES, $locale));
    }

    /**
     * Puts the verified email on the account, merging when another parent account already has it.
     *
     * @return ?User the account the person now uses (the kept one after a merge); null for a wrong, expired or used-up code
     */
    public function confirm(User $user, string $email, string $code): ?User
    {
        $email = mb_strtolower(trim($email));
        $row = DB::table('email_verifications')->where('user_id', $user->id)->first();
        if ($row === null || $row->email !== $email || $row->attempts >= self::MAX_ATTEMPTS || now()->gt($row->expires_at) || ! $this->eligible($user) || ! $this->canTake($user, $email)) {
            return null;
        }
        if (! Hash::check($code, $row->code_hash)) {
            DB::table('email_verifications')->where('user_id', $user->id)->increment('attempts');

            return null;
        }

        return DB::transaction(function () use ($user, $email): User {
            $existing = User::where('email', $email)->first();
            if ($existing !== null) {
                $this->merger->merge($user, $existing);

                return $existing->refresh();
            }
            $user->update(['email' => $email]);
            DB::table('email_verifications')->where('user_id', $user->id)->delete();

            return $user->refresh();
        });
    }

    /** The email is free, or it already belongs to a (live) parent account. Staff, students and deleted accounts keep theirs. */
    private function canTake(User $user, string $email): bool
    {
        if (User::onlyTrashed()->where('email', $email)->exists()) {
            return false;
        }
        $existing = User::where('email', $email)->first();

        return $existing === null || ($existing->id !== $user->id && $existing->role === Role::Guardian && $existing->is_active);
    }
}
