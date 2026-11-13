<?php

declare(strict_types=1);

namespace App\Shared\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Writes every failed sign-in to the log (no password, and the login only as a short hash, so the log holds nothing sensitive) and raises
 * one `alert` entry when one address fails too many times in a short while. Point the `alert` level of the log at an e-mail or chat
 * channel (config/logging.php) to be told as it happens.
 */
final class LoginMonitor
{
    public const WINDOW_MINUTES = 15;

    public const ALERT_AT = 10;

    /** @param string $scope the school code, or "central" for the Super Admin area */
    public static function failed(string $scope, string $login, ?string $ip, string $step = 'password'): void
    {
        $key = 'login-fails:'.$scope.':'.($ip ?? 'unknown');
        Cache::add($key, 0, now()->addMinutes(self::WINDOW_MINUTES));
        $count = (int) Cache::increment($key);

        $context = ['scope' => $scope, 'step' => $step, 'ip' => $ip, 'login' => substr(sha1(mb_strtolower(trim($login))), 0, 12), 'failures' => $count, 'minutes' => self::WINDOW_MINUTES];
        Log::warning('Failed sign-in', $context);
        if ($count === self::ALERT_AT) {
            Log::alert('Repeated failed sign-ins from one address', $context);
        }
    }
}
