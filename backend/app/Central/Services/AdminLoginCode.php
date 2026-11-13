<?php

declare(strict_types=1);

namespace App\Central\Services;

use App\Central\Models\SuperAdmin;
use App\Mail\SuperAdminLoginCodeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Second step of the Super Admin sign-in: a 6-digit code (valid 10 minutes, 5 tries, one use) is e-mailed after the password is right.
 * Only a hash of the code is stored; the code is never returned or logged.
 */
class AdminLoginCode
{
    public const MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /** Mails a new code and invalidates every older one of this admin. */
    public function issue(SuperAdmin $admin): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($admin, $code): void {
            DB::table('super_admin_login_codes')->where('super_admin_id', $admin->id)->delete();
            DB::table('super_admin_login_codes')->insert([
                'super_admin_id' => $admin->id,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::MINUTES),
                'created_at' => now(),
            ]);
        });

        Mail::to($admin->email)->send(new SuperAdminLoginCodeMail($code, $admin->name, self::MINUTES, app()->getLocale()));
    }

    /** True once, for the right code of the current, unexpired, unused and not locked code; false for every other case. */
    public function verify(SuperAdmin $admin, string $code): bool
    {
        $row = DB::table('super_admin_login_codes')
            ->where('super_admin_id', $admin->id)->whereNull('used_at')->where('expires_at', '>', now())
            ->orderByDesc('id')->first();
        if ($row === null) {
            return false;
        }

        // The try is counted before the code is compared, and only while tries are left, so parallel guesses cannot exceed the limit.
        $counted = DB::table('super_admin_login_codes')->where('id', $row->id)->whereNull('used_at')->where('attempts', '<', self::MAX_ATTEMPTS)->increment('attempts');
        if ($counted !== 1 || ! Hash::check($code, $row->code_hash)) {
            return false;
        }

        // Marking it used is the single winner of a race between two correct submissions.
        return DB::table('super_admin_login_codes')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now()]) === 1;
    }
}
