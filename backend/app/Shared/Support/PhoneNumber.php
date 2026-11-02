<?php

declare(strict_types=1);

namespace App\Shared\Support;

/** Phone numbers as people type them: spaces, dashes and brackets vary, "00" means "+". */
final class PhoneNumber
{
    /** The number without formatting; a leading 00 becomes +. */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $clean = (string) preg_replace('/[^\d+]/', '', $phone);
        if (str_starts_with($clean, '00')) {
            $clean = '+'.substr($clean, 2);
        }

        return $clean === '' ? null : $clean;
    }

    /** Whether the text looks like a phone number (and not an email). */
    public static function looks(string $text): bool
    {
        return (bool) preg_match('/^[\d\s+\-().]{7,}$/', $text) && strlen((string) self::normalize($text)) >= 7;
    }

    /** The last digits that identify a line whether or not the country code or the leading 0 was typed. */
    public static function tail(string $phone): string
    {
        return substr((string) preg_replace('/\D/', '', $phone), -9);
    }
}
